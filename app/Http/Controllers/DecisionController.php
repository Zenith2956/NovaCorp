<?php

namespace App\Http\Controllers;

use App\Models\Demande;
use App\Models\User;
use App\Services\AbsencesEquipe;
use App\Services\BrouillonIa;
use App\Services\WorkflowDemande;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            'absencesEquipe' => app(AbsencesEquipe::class)->pour($demande),
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

    /** D3 – Brouillon IA depuis la page ouverte par le lien du mail (même autorisation que la décision). */
    public function brouillonIa(Request $request, Demande $demande, string $jeton, BrouillonIa $brouillon)
    {
        $data = $request->validate([
            'intention' => ['required', 'in:refuser,demander_complement'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_unless($this->jetonValide($demande, $jeton), 403);
        $parService = ($this->workflow->etapeCourante($demande)?->valideur ?? 'manager') !== 'manager';
        abort_if($parService && ! Auth::check(), 401);
        $valideur = $this->valideur($demande);
        abort_unless($valideur && $this->workflow->peutDecider($valideur, $demande), 403);

        if (! $brouillon->estConfigure()) {
            return response()->json(['erreur' => "L'assistant IA n'est pas configuré (N8N_BROUILLON_URL / N8N_SECRET)."], 503);
        }
        try {
            return response()->json(['texte' => $brouillon->rediger($demande, $valideur, $data['intention'], $data['notes'] ?? null)]);
        } catch (Throwable $e) {
            Log::warning('Brouillon IA indisponible', ['demande' => $demande->id, 'erreur' => $e->getMessage()]);

            return response()->json(['erreur' => "L'assistant IA est indisponible pour le moment (n8n éteint ?). Rédigez le commentaire vous-même."], 503);
        }
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
