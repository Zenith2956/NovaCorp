<?php

namespace App\Services;

use App\Models\Demande;
use App\Models\EtapeCircuit;
use App\Models\Historique;
use App\Models\Role;
use App\Models\Tache;
use App\Models\TypeDemande;
use App\Support\Calendrier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques du workflow (mêmes chiffres que la fonction PostgreSQL automation.calculer_stats(),
 * utilisée aussi par le dashboard Flutter via stats_workflow()).
 * Sous PostgreSQL on appelle directement la fonction SQL ; ailleurs (tests SQLite) on calcule en PHP.
 */
class StatistiquesWorkflow
{
    /** @param int|null $manager null = toute l'entreprise, sinon l'équipe de ce manager */
    public function calculer(?int $manager, CarbonImmutable $debut, CarbonImmutable $fin): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $ligne = DB::selectOne('select automation.calculer_stats(?, ?::date, ?::date) as stats',
                [$manager, $debut->toDateString(), $fin->toDateString()]);

            return json_decode($ligne->stats, true);
        }

        return $this->calculerEnPhp($manager, $debut->toDateString(), $fin->toDateString());
    }

    private function calculerEnPhp(?int $manager, string $debut, string $fin): array
    {
        $jour = fn ($t) => $t ? Calendrier::date($t->copy()->timezone('Europe/Paris'))->toDateString() : null;
        $dans = fn (?string $j) => $j !== null && $j >= $debut && $j <= $fin;

        $demandes = Demande::when($manager, fn ($q) => $q->where('manager_id', $manager))->get();
        $creees = $demandes->filter(fn ($d) => $dans($jour($d->created_at)));
        $decidees = $demandes->filter(fn ($d) => $d->decision_at && $dans($jour($d->decision_at)))
            ->map(function ($d) use ($jour) {
                $d->duree = Calendrier::joursOuvresEntre(
                    ($d->envoyee_at ?? $d->created_at)->copy()->timezone('Europe/Paris'),
                    $d->decision_at->copy()->timezone('Europe/Paris'));
                $d->jour_decision = $jour($d->decision_at);

                return $d;
            });

        $parJour = [];
        for ($j = CarbonImmutable::parse($debut); $j->toDateString() <= $fin; $j = $j->addDay()) {
            $s = $j->toDateString();
            $parJour[] = [
                'jour' => $s,
                'creees' => $creees->filter(fn ($d) => $jour($d->created_at) === $s)->count(),
                'decisions' => $decidees->where('jour_decision', $s)->count(),
            ];
        }

        // Temps passé dans chaque état (historique), attribué au service qui devait agir
        $etapes = EtapeCircuit::all()->keyBy(fn ($e) => $e->type_code.'-'.$e->ordre);
        $traitement = TypeDemande::pluck('role_traitement', 'code');
        $passages = collect();
        Historique::where('objet', 'demande')->whereIn('objet_id', $demandes->pluck('id'))->orderBy('id')->get()
            ->groupBy('objet_id')->each(function ($lignes, $id) use ($demandes, $etapes, $traitement, $jour, $dans, $passages) {
                $type = $demandes->firstWhere('id', $id)->type;
                $lignes = $lignes->values();
                foreach ($lignes as $i => $h) {
                    $suivant = $lignes[$i + 1] ?? null;
                    if (! $suivant || ! $dans($jour($suivant->created_at))) {
                        continue;
                    }
                    $service = match (true) {
                        $h->nouveau_statut === 'en_attente' => $etapes[$type.'-'.$h->etape]->valideur ?? 'manager',
                        $h->nouveau_statut === 'a_completer' => 'employe',
                        in_array($h->nouveau_statut, ['validee', 'en_traitement'], true) => $traitement[$type] ?? null,
                        default => null,
                    };
                    if ($service) {
                        $passages->push(['service' => $service, 'jours' => $h->created_at->diffInSeconds($suivant->created_at) / 86400]);
                    }
                }
            });
        $libelles = Role::pluck('libelle', 'slug');
        $services = $passages->groupBy('service')->map(fn ($g, $s) => [
            'service' => $s,
            'libelle' => match ($s) { 'manager' => 'Managers', 'employe' => 'Employés (compléments)', default => $libelles[$s] ?? $s },
            'passages' => $g->count(),
            'jours_moyens' => round($g->avg('jours'), 1),
        ])->sortByDesc('passages')->values()->all();

        $aujourdhui = Calendrier::aujourdhui()->toDateString();
        $taches = Tache::when($manager, fn ($q) => $q->whereHas('projet', fn ($p) => $p->where('chef_projet_id', $manager)))->get();

        return [
            'perimetre' => $manager ? 'equipe' : 'entreprise',
            'periode' => ['debut' => $debut, 'fin' => $fin],
            'volume' => [
                'creees' => $creees->count(),
                'par_type' => $creees->countBy('type')->all(),
                'par_statut' => $creees->countBy('statut')->all(),
            ],
            'par_jour' => $parJour,
            'decisions' => [
                'total' => $decidees->count(),
                'validees' => $decidees->where('statut', '!=', 'refusee')->count(),
                'refusees' => $decidees->where('statut', 'refusee')->count(),
                'temps_moyen_jours_ouvres' => $decidees->isEmpty() ? null : round($decidees->avg('duree'), 1),
                'temps_moyen_par_type' => $decidees->groupBy('type')->map(fn ($g) => round($g->avg('duree'), 1))->all(),
                'dans_les_temps_pct' => $decidees->isEmpty() ? null
                    : round(100 * $decidees->filter(fn ($d) => $d->jour_decision <= $d->echeance_le?->toDateString())->count() / $decidees->count(), 1),
            ],
            'services' => $services,
            'en_cours' => [
                'en_attente' => $demandes->where('statut', 'en_attente')->count(),
                'en_retard' => $demandes->where('statut', 'en_attente')->filter(fn ($d) => $d->echeance_le && $d->echeance_le->toDateString() < $aujourdhui)->count(),
                'a_completer' => $demandes->where('statut', 'a_completer')->count(),
                'a_traiter' => $demandes->where('statut', 'validee')->filter(fn ($d) => ! empty($traitement[$d->type]))->count(),
                'en_traitement' => $demandes->where('statut', 'en_traitement')->count(),
            ],
            'taches' => [
                'a_faire' => $taches->where('statut', 'a_faire')->count(),
                'en_cours' => $taches->where('statut', 'en_cours')->count(),
                'a_valider' => $taches->where('statut', 'a_valider')->count(),
                'expirees' => $taches->where('statut', 'expiree')->count(),
                'terminees_periode' => $taches->where('statut', 'terminee')->filter(fn ($t) => $dans($jour($t->termine_at)))->count(),
            ],
            'calcule_le' => now()->toIso8601String(),
        ];
    }
}
