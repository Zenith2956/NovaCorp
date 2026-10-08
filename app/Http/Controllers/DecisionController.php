<?php

namespace App\Http\Controllers;

use App\Models\Demande;
use Illuminate\Http\Request;

/**
 * Liens « Valider / Refuser » du mail envoyé au manager.
 *
 * Pas besoin d'être connecté : le jeton à usage unique sert d'autorisation.
 * Le GET n'affiche qu'une page de confirmation (les messageries ouvrent parfois
 * les liens automatiquement) ; la décision n'est enregistrée qu'au POST.
 */
class DecisionController extends Controller
{
    public function show(Request $request, Demande $demande, string $jeton)
    {
        $choix = $request->query('choix');

        if (! $this->jetonValide($demande, $jeton)) {
            return view('decision.resultat', ['demande' => $demande, 'erreur' => true]);
        }

        return view('decision.confirmer', [
            'demande' => $demande->load(['demandeur', 'piecesJointes']),
            'jeton' => $jeton,
            'choix' => in_array($choix, ['validee', 'refusee'], true) ? $choix : null,
        ]);
    }

    public function store(Request $request, Demande $demande, string $jeton)
    {
        $data = $request->validate(['choix' => ['required', 'in:validee,refusee']]);

        if (! $this->jetonValide($demande, $jeton)) {
            return view('decision.resultat', ['demande' => $demande, 'erreur' => true]);
        }

        // Le trigger PostgreSQL ajoute alors le mail à l'employé dans la boîte d'envoi
        $demande->update([
            'statut' => $data['choix'],
            'decision_at' => now(),
            'decision_par' => $demande->manager_id,
            'jeton_decision' => null, // usage unique
        ]);

        return view('decision.resultat', ['demande' => $demande, 'erreur' => false]);
    }

    private function jetonValide(Demande $demande, string $jeton): bool
    {
        return $demande->statut === 'en_attente'
            && $demande->jeton_decision !== null
            && hash_equals($demande->jeton_decision, $jeton);
    }
}
