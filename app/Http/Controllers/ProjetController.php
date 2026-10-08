<?php

namespace App\Http\Controllers;

use App\Models\ChiffreAffaire;
use App\Models\Projet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjetController extends Controller
{
    public function index()
    {
        return view('projets.index', [
            'peutCreer' => request()->user()->hasRole(...self::ROLES_CREATION),
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

    /** Managers, direction et admin peuvent créer un projet. */
    public const ROLES_CREATION = ['manager', 'direction', 'admin'];

    public const STATUTS = ['a_venir' => 'À venir', 'en_cours' => 'En cours', 'termine' => 'Terminé'];

    public function create(Request $request)
    {
        abort_unless($request->user()->hasRole(...self::ROLES_CREATION), 403);

        return view('projets.form', $this->donneesFormulaire($request, new Projet(['statut' => 'en_cours'])));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasRole(...self::ROLES_CREATION), 403);
        $data = $this->valider($request);

        $projet = DB::transaction(function () use ($data, $request) {
            $projet = Projet::create([
                ...collect($data)->except('membres')->all(),
                // Un manager crée son propre projet ; direction / admin choisissent le chef de projet
                'chef_projet_id' => $request->user()->hasRole('direction', 'admin')
                    ? $data['chef_projet_id'] : $request->user()->id,
            ]);
            $projet->membres()->sync(collect($data['membres'] ?? [])->reject(fn ($id) => (int) $id === $projet->chef_projet_id)->all());

            return $projet;
        });

        return redirect()->route('projets.show', $projet)->with('success', 'Projet créé.');
    }

    public function edit(Request $request, Projet $projet)
    {
        abort_unless($projet->gerablePar($request->user()), 403);

        return view('projets.form', $this->donneesFormulaire($request, $projet));
    }

    public function update(Request $request, Projet $projet)
    {
        abort_unless($projet->gerablePar($request->user()), 403);
        $data = $this->valider($request);
        $projet->update([
            ...collect($data)->except(['membres', 'chef_projet_id'])->all(),
            // Seules la direction et l'admin peuvent changer le chef de projet
            ...($request->user()->hasRole('direction', 'admin') ? ['chef_projet_id' => $data['chef_projet_id']] : []),
        ]);

        return redirect()->route('projets.show', $projet)->with('success', 'Projet modifié.');
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'statut' => ['required', Rule::in(array_keys(self::STATUTS))],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'chef_projet_id' => $request->user()->hasRole('direction', 'admin')
                ? ['required', Rule::exists('users', 'id')] : ['nullable'],
            'membres' => ['nullable', 'array'],
            'membres.*' => ['integer', Rule::exists('users', 'id')],
        ]);
    }

    private function donneesFormulaire(Request $request, Projet $projet): array
    {
        return [
            'projet' => $projet,
            'statuts' => self::STATUTS,
            'choisitChef' => $request->user()->hasRole('direction', 'admin'),
            'chefs' => User::whereHas('role', fn ($q) => $q->whereIn('slug', ['manager', 'direction']))->orderBy('nom')->get(),
            'employes' => User::with('role')->where('actif', true)->orderBy('nom')->get(),
        ];
    }

    /** Un projet = un groupe : chef de projet, membres, tâches. */
    public function show(Request $request, Projet $projet)
    {
        $projet->load(['chefProjet', 'membres.role', 'taches.responsable']);
        $user = $request->user();

        return view('projets.show', [
            'projet' => $projet,
            'estMembre' => $projet->aPourMembre($user),
            'peutGerer' => $projet->gerablePar($user),
            'candidats' => $projet->gerablePar($user)
                ? User::with('role')->where('actif', true)->whereNotIn('id', $projet->membres->pluck('id')->push($projet->chef_projet_id))
                    ->orderBy('nom')->get()
                : collect(),
        ]);
    }

    public function ajouterMembre(Request $request, Projet $projet)
    {
        abort_unless($projet->gerablePar($request->user()), 403);
        $data = $request->validate([
            'user_ids' => ['required_without:user_id', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ], ['user_ids.required_without' => 'Cochez au moins un membre.']);
        $ids = collect($data['user_ids'] ?? [])->push($data['user_id'] ?? null)->filter()
            ->reject(fn ($id) => (int) $id === $projet->chef_projet_id)->unique();
        $projet->membres()->syncWithoutDetaching($ids->all());

        return back()->with('success', $ids->count() > 1 ? "{$ids->count()} membres ajoutés au projet." : 'Membre ajouté au projet.');
    }

    public function retirerMembre(Request $request, Projet $projet, User $user)
    {
        abort_unless($projet->gerablePar($request->user()), 403);
        abort_if($projet->taches()->where('responsable_id', $user->id)->whereIn('statut', ['a_faire', 'en_cours'])->exists(),
            422, 'Ce membre a encore des tâches en cours dans le projet.');
        $projet->membres()->detach($user->id);

        return back()->with('success', 'Membre retiré du projet.');
    }
}
