<?php

namespace App\Services;

use App\Models\Demande;
use App\Models\EtapeCircuit;
use App\Models\TypeDemande;
use App\Models\User;
use App\Support\Calendrier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Circuit de validation des demandes (spécification : docs/propositions-workflow.md).
 *
 * Toutes les actions passent par ici, qu'elles viennent de l'application ou du lien du mail.
 * Sous PostgreSQL, les triggers contrôlent en plus les transitions (table « transitions »),
 * remplissent l'historique et préparent les mails ; ici on vérifie les droits et on calcule l'étape suivante.
 */
class WorkflowDemande
{
    /** Actions proposées dans l'application => libellé du bouton. */
    public const ACTIONS = [
        'valider' => 'Valider',
        'refuser' => 'Refuser',
        'demander_complement' => 'Demander un complément',
        'completer' => 'Renvoyer la demande complétée',
        'annuler' => 'Annuler la demande',
        'prendre_en_charge' => 'Prendre en charge',
        'terminer' => 'Marquer comme traitée',
        'corriger' => 'Remettre en attente (correction)',
    ];

    /** Étapes du circuit du type (avec l'étape 1 « Manager » par défaut si le type n'a pas de circuit). */
    public function etapes(Demande $demande): Collection
    {
        $etapes = EtapeCircuit::where('type_code', $demande->type)->orderBy('ordre')->get();

        if ($etapes->where('ordre', 1)->isEmpty()) {
            $etapes->prepend(new EtapeCircuit([
                'type_code' => $demande->type, 'ordre' => 1, 'libelle' => 'Manager', 'valideur' => 'manager',
            ] + TypeDemande::delais($demande->type)));
        }

        return $etapes->values();
    }

    /** Étapes qui s'appliquent à cette demande (conditions de montant / nombre de jours). */
    public function etapesApplicables(Demande $demande): Collection
    {
        return $this->etapes($demande)->filter(fn (EtapeCircuit $e) => $e->ordre === 1 || $e->sApplique($demande))->values();
    }

    public function etapeCourante(Demande $demande): ?EtapeCircuit
    {
        return $this->etapes($demande)->firstWhere('ordre', $demande->etape);
    }

    public function etapeSuivante(Demande $demande): ?EtapeCircuit
    {
        return $this->etapesApplicables($demande)->first(fn (EtapeCircuit $e) => $e->ordre > $demande->etape);
    }

    public function roleTraitement(Demande $demande): ?string
    {
        return TypeDemande::where('code', $demande->type)->value('role_traitement');
    }

    /** L'utilisateur peut-il décider à l'étape en cours ? (manager assigné, ou membre du service) */
    public function peutDecider(?User $user, Demande $demande): bool
    {
        if (! $user || $demande->statut !== 'en_attente') {
            return false;
        }
        $valideur = $this->etapeCourante($demande)?->valideur ?? 'manager';

        return $valideur === 'manager'
            ? $user->id === $demande->manager_id
            : $user->hasRole($valideur) && $user->id !== $demande->demandeur_id;
    }

    public function peutTraiter(User $user, Demande $demande): bool
    {
        $role = $this->roleTraitement($demande);

        return $role !== null && $user->hasRole($role);
    }

    /** Actions possibles pour cet utilisateur, dans l'état actuel de la demande. */
    public function actionsPossibles(User $user, Demande $demande): array
    {
        $estDemandeur = $user->id === $demande->demandeur_id;

        return array_values(array_filter(array_keys(self::ACTIONS), fn (string $action) => match ($action) {
            'valider', 'refuser', 'demander_complement' => $this->peutDecider($user, $demande),
            'completer' => $estDemandeur && $demande->statut === 'a_completer',
            'annuler' => $estDemandeur && in_array($demande->statut, ['en_attente', 'a_completer'], true),
            'prendre_en_charge' => $demande->statut === 'validee' && $this->peutTraiter($user, $demande),
            'terminer' => $demande->statut === 'en_traitement' && $this->peutTraiter($user, $demande),
            'corriger' => in_array($demande->statut, ['validee', 'refusee'], true) && $user->hasRole('rh', 'admin'),
        }));
    }

    /**
     * Applique une action. $commentaire : motif de refus / complément demandé (obligatoire pour ces deux actions).
     * $canal : appli | mail.
     */
    public function executer(string $action, Demande $demande, User $user, ?string $commentaire = null, string $canal = 'appli'): string
    {
        abort_unless(in_array($action, $this->actionsPossibles($user, $demande), true), 403, 'Action non autorisée.');

        if (in_array($action, ['refuser', 'demander_complement'], true) && blank($commentaire)) {
            throw ValidationException::withMessages([
                'commentaire' => $action === 'refuser' ? 'Le motif du refus est obligatoire.' : 'Précisez ce qu\'il faut compléter.',
            ]);
        }

        $trace = ['derniere_action_par' => $user->id, 'derniere_action_canal' => $canal];

        return DB::transaction(function () use ($action, $demande, $user, $commentaire, $trace) {
            switch ($action) {
                case 'valider':
                    $suivante = $this->etapeSuivante($demande);
                    if ($suivante) {
                        $demande->update($trace + $this->nouveauxDelais($demande, $suivante) + [
                            'etape' => $suivante->ordre,
                            'jeton_decision' => (string) Str::uuid(),
                            'commentaire_decision' => null,
                        ]);

                        return "Étape validée : la demande passe à l'étape « {$suivante->libelle} ».";
                    }
                    $demande->update($trace + $this->decision($user) + ['statut' => 'validee', 'commentaire_decision' => null]);

                    return 'Demande validée.';

                case 'refuser':
                    $demande->update($trace + $this->decision($user) + ['statut' => 'refusee', 'commentaire_decision' => $commentaire]);

                    return 'Demande refusée : le demandeur est prévenu avec le motif.';

                case 'demander_complement':
                    $demande->update($trace + ['statut' => 'a_completer', 'commentaire_decision' => $commentaire, 'jeton_decision' => null]);

                    return 'Complément demandé : le demandeur est prévenu.';

                case 'completer':
                    $demande->update($trace + $this->nouveauxDelais($demande, $this->etapeCourante($demande)) + [
                        'statut' => 'en_attente',
                        'jeton_decision' => (string) Str::uuid(),
                        'commentaire_decision' => null,
                        // Le complément est ajouté au message, visible du valideur et dans le mail
                        'message' => filled($commentaire)
                            ? $demande->message."\n\n— Complément du ".now()->timezone('Europe/Paris')->format('d/m/Y')." —\n".$commentaire
                            : $demande->message,
                    ]);

                    return 'Demande complétée et renvoyée au valideur.';

                case 'annuler':
                    $demande->update($trace + ['statut' => 'annulee', 'jeton_decision' => null]);

                    return 'Demande annulée.';

                case 'prendre_en_charge':
                    $demande->update($trace + ['statut' => 'en_traitement', 'traite_par' => $user->id]);

                    return 'Demande prise en charge.';

                case 'terminer':
                    $demande->update($trace + ['statut' => 'terminee', 'traite_par' => $demande->traite_par ?? $user->id, 'traite_at' => now()]);

                    return 'Traitement terminé : le demandeur est prévenu.';

                case 'corriger':
                    $demande->update($trace + $this->nouveauxDelais($demande, $this->etapes($demande)->first()) + [
                        'statut' => 'en_attente', 'etape' => 1, 'jeton_decision' => (string) Str::uuid(),
                        'decision_at' => null, 'decision_par' => null, 'commentaire_decision' => null,
                        'traite_par' => null, 'traite_at' => null,
                    ]);

                    return 'Demande remise en attente à l\'étape 1.';
            }

            return '';
        });
    }

    /** Relance / échéance / expiration recalculées à partir d'aujourd'hui avec les délais de l'étape. */
    private function nouveauxDelais(Demande $demande, ?EtapeCircuit $etape): array
    {
        $delais = (! $etape || $etape->ordre === 1)
            ? TypeDemande::delais($demande->type)                      // étape 1 : délais réglés par les RH
            : ['delai_relance' => $etape->delai_relance, 'delai_escalade' => $etape->delai_escalade];
        $aujourdhui = Calendrier::aujourdhui();
        $echeance = Calendrier::ajouterJoursOuvres($aujourdhui, $delais['delai_escalade']);
        $deadline = Calendrier::ajouterJoursOuvres($echeance, TypeDemande::MARGE_DEADLINE);

        // La date souhaitée par l'employé reste la limite si elle n'est pas dépassée
        if ($demande->date_souhaitee && $demande->date_souhaitee->toDateString() >= $aujourdhui->toDateString()) {
            $deadline = Calendrier::date($demande->date_souhaitee);
            if ($echeance->toDateString() > $deadline->toDateString()) {
                $echeance = Calendrier::jourOuvrePrecedent($deadline);
                $echeance = $echeance->lt($aujourdhui) ? $aujourdhui : $echeance;
            }
        }

        $relance = Calendrier::ajouterJoursOuvres($aujourdhui, $delais['delai_relance']);

        return [
            'relance_le' => $relance->toDateString() > $echeance->toDateString() ? $echeance : $relance,
            'echeance_le' => $echeance,
            'deadline' => $deadline,
        ];
    }

    private function decision(User $user): array
    {
        return ['decision_at' => now(), 'decision_par' => $user->id, 'jeton_decision' => null];
    }
}
