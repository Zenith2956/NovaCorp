<?php

namespace App\Http\Controllers;

use App\Models\ChiffreAffaire;
use App\Models\Demande;
use App\Models\Projet;
use App\Models\User;
use App\Support\Calendrier;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        return view('dashboard', [
            'nbEmployes' => User::where('actif', true)->count(),
            'nbProjetsEnCours' => Projet::where('statut', 'en_cours')->count(),
            'mesDemandesEnAttente' => $user->demandes()->where('statut', 'en_attente')->count(),
            'demandesARecevoir' => $user->demandesRecues()->where('statut', 'en_attente')->count(),
            'demandesEnRetard' => $user->demandesRecues()->where('statut', 'en_attente')
                ->whereDate('echeance_le', '<', Calendrier::aujourdhui()->toDateString())->count(),
            'caDouzeMois' => ChiffreAffaire::where('periode', '>=', now()->startOfMonth()->subMonths(11))->sum('montant'),
            'dernieresDemandes' => $user->demandes()->with('manager')->latest()->take(5)->get(),
        ]);
    }
}
