<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A2 – Suppléant d'un manager pendant ses congés validés.
 * Le manager choisit le sien (tableau de bord) ; la RH / l'admin peuvent le choisir pour n'importe quel manager (page Employés).
 * Vide = règle par défaut : son N+1, puis la direction.
 */
class SuppleantController extends Controller
{
    public function __invoke(Request $request, ?User $user = null)
    {
        $acteur = $request->user();
        $manager = $user ?? $acteur;
        abort_if($user && ! $acteur->hasRole('rh', 'admin'), 403);
        abort_unless($manager->equipe()->exists(), 403, "Seul un manager (qui a une équipe) a un suppléant.");

        $data = $request->validate([
            'suppleant_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('actif', true), Rule::notIn([$manager->id])],
        ], ['suppleant_id.not_in' => 'On ne peut pas être son propre suppléant.']);

        $manager->update(['suppleant_id' => $data['suppleant_id'] ?? null]);
        $nom = $manager->fresh('suppleant')->suppleant?->nom_complet;

        return back()->with('success', $nom
            ? "Suppléant de {$manager->nom_complet} pendant ses congés : {$nom}."
            : "Suppléant de {$manager->nom_complet} : règle par défaut (son N+1, sinon la direction).");
    }
}
