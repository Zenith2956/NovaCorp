<?php

namespace App\Http\Controllers;

use App\Models\Demande;
use App\Models\User;
use App\Services\WorkflowDemande;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Liens « Valider / Refuser / Demander un complément » des mails envoyés au valideur de l'étape en cours.
 *
 * Étape « Manager » : pas besoin d'être connecté, le jeton à usage unique sert d'autorisation.
 * Étape d'un service (RH, Comptabilité…) : le mail part à tout le service, il faut donc se connecter
 * pour savoir qui décide (retour automatique sur la page après connexion).
 * Le GET n'affiche qu'une page de confirmation (les messageries ouvrent parfois les liens automatiquement) ;
 * la décision n'est enregistrée qu'au POST.
 */
class DecisionController extends Controller
{
    private const CHOIX = ['validee' => 'valider', 'refusee' => 'refuser', 'a_completer' => 'demander_complement'];

    public function __construct(private WorkflowDemande $workflow) {}

    public function show(Request $request, Demande $demande, string $jeton)
    {
        if (! $this->jetonValide($demande, $jeton)) {
            return view('decision.resultat', ['demande' => $demande, 'erreur' => true]);
        }
        if (($connexion = $this->connexionRequise($demande)) !== null) {
            return $connexion;
        }
        $valideur = $this->valideur($demande);
        abort_unless($valideur && $this->workflow->peutDecider($valideur, $demande), 403, 'Vous ne validez pas cette étape.');

        $choix = $request->query('choix');

        return view('decision.confirmer', [
            'demande' => $demande->load(['demandeur', 'piecesJointes']),
            'etape' => $this->workflow->etapeCourante($demande),
            'jeton' => $jeton,
            'choix' => array_key_exists((string) $choix, self::CHOIX) ? $choix : null,
        ]);
    }

    public function store(Request $request, Demande $demande, string $jeton)
    {
        $data = $request->validate([
            'choix' => ['required', 'in:'.implode(',', array_keys(self::CHOIX))],
            'commentaire' => ['required_if:choix,refusee,a_completer', 'nullable', 'string', 'max:2000'],
        ], ['commentaire.required_if' => 'Un commentaire est obligatoire pour refuser ou demander un complément.']);

        if (! $this->jetonValide($demande, $jeton)) {
            return view('decision.resultat', ['demande' => $demande, 'erreur' => true]);
        }
        if (($connexion = $this->connexionRequise($demande, route('decision.show', [$demande, $jeton]))) !== null) {
            return $connexion;
        }

        $valideur = $this->valideur($demande);
        abort_unless($valideur && $this->workflow->peutDecider($valideur, $demande), 403, 'Vous ne validez pas cette étape.');

        // Les triggers PostgreSQL historisent la décision et préparent le mail suivant
        $message = $this->workflow->executer(self::CHOIX[$data['choix']], $demande, $valideur, $data['commentaire'] ?? null, 'mail');

        return view('decision.resultat', ['demande' => $demande->refresh()->load('demandeur'), 'erreur' => false, 'message' => $message]);
    }

    /** Étape « Manager » : le manager assigné. Étape d'un service : l'utilisateur connecté. */
    private function valideur(Demande $demande): ?User
    {
        return ($this->workflow->etapeCourante($demande)?->valideur ?? 'manager') === 'manager'
            ? $demande->manager
            : Auth::user();
    }

    private function connexionRequise(Demande $demande, ?string $retour = null)
    {
        $parService = ($this->workflow->etapeCourante($demande)?->valideur ?? 'manager') !== 'manager';
        if (! $parService || Auth::check()) {
            return null;
        }
        if ($retour) {
            session()->put('url.intended', $retour);

            return redirect()->route('login')->with('success', 'Connectez-vous pour décider sur la demande #'.$demande->id.'.');
        }

        return redirect()->guest(route('login'))->with('success', 'Connectez-vous pour décider sur la demande #'.$demande->id.'.');
    }

    private function jetonValide(Demande $demande, string $jeton): bool
    {
        return $demande->statut === 'en_attente'
            && $demande->jeton_decision !== null
            && hash_equals($demande->jeton_decision, $jeton);
    }
}
