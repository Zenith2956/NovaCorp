<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class EmployeController extends Controller
{
    public function index(Request $request)
    {
        $recherche = $request->string('q')->trim();

        $employes = User::with(['role', 'manager', 'suppleant'])->withCount('equipe')
            ->when($recherche->isNotEmpty(), fn ($q) => $q->where(fn ($q) => $q
                // whereLike = insensible à la casse (ILIKE sous PostgreSQL)
                ->whereLike('nom', "%{$recherche}%")
                ->orWhereLike('prenom', "%{$recherche}%")
                ->orWhereLike('email', "%{$recherche}%")))
            ->when($request->filled('role'), fn ($q) => $q->where('role_id', $request->role))
            ->orderBy('nom')
            ->paginate(20)
            ->withQueryString();

        return view('employes.index', [
            'employes' => $employes,
            'roles' => Role::orderBy('libelle')->get(),
            // A2 – la RH / l'admin choisissent le suppléant de chaque manager
            'peutChoisirSuppleant' => $peut = $request->user()->hasRole('rh', 'admin'),
            'candidats' => $peut ? User::where('actif', true)->orderBy('nom')->orderBy('prenom')->get(['id', 'nom', 'prenom']) : collect(),
        ]);
    }
}
