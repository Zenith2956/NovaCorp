<?php

namespace App\Http\Controllers;

use App\Models\ChiffreAffaire;
use App\Models\Projet;

class ProjetController extends Controller
{
    public function index()
    {
        return view('projets.index', [
            'projets' => Projet::with('chefProjet')
                ->withCount('membres')
                ->withSum('chiffresAffaires', 'montant')
                ->orderBy('nom')
                ->get(),
            'caParMois' => ChiffreAffaire::selectRaw('periode, SUM(montant) as total')
                ->groupBy('periode')
                ->orderBy('periode')
                ->get(),
        ]);
    }
}
