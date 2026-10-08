<?php

namespace App\Http\Controllers;

use App\Models\Projet;
use App\Models\Tache;
use App\Models\User;
use App\Support\Calendrier;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TacheController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('taches.index', [
            'mesTaches' => $user->taches()->with('projet')
                ->when(! $request->boolean('toutes'), fn ($q) => $q->whereIn('statut', ['a_faire', 'en_cours', 'expiree']))
                ->orderByRaw('deadline is null')->orderBy('deadline')->get(),
            // Tâches de mes projets (chef de projet), deadline à fixer en premier
            'tachesProjets' => Tache::with(['projet', 'responsable'])
                ->whereIn('projet_id', $user->projetsDiriges()->pluck('id'))
                ->whereIn('statut', ['a_faire', 'en_cours', 'expiree'])
                ->orderByRaw('deadline is not null')->orderBy('deadline')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $projet = $request->filled('projet') ? Projet::with(['membres', 'chefProjet'])->findOrFail($request->integer('projet')) : null;
        abort_if($projet && ! $projet->aPourMembre($user), 403, 'Vous ne faites pas partie de ce projet.');

        return view('taches.create', [
            'projet' => $projet,
            'estChef' => $projet && $user->id === $projet->chef_projet_id,
            'minimumProjet' => Calendrier::ajouterJoursOuvres(Calendrier::aujourdhui(), Tache::DELAI_MINIMUM_PROJET),
            'aujourdhui' => Calendrier::aujourdhui(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $projet = $request->filled('projet_id') ? Projet::findOrFail($request->integer('projet_id')) : null;
        abort_if($projet && ! $projet->aPourMembre($user), 403, 'Vous ne faites pas partie de ce projet.');
        $estChef = $projet && $user->id === $projet->chef_projet_id;
        $aujourdhui = Calendrier::aujourdhui()->toDateString();
        $minimumProjet = Calendrier::ajouterJoursOuvres(Calendrier::aujourdhui(), Tache::DELAI_MINIMUM_PROJET);

        $data = $request->validate([
            'titre' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'projet_id' => ['nullable', 'exists:projets,id'],
            // Le chef de projet assigne à un membre ; un membre crée sa propre tâche
            'responsable_id' => $estChef
                ? ['required', Rule::in($projet->membres()->pluck('users.id')->push($projet->chef_projet_id)->all())]
                : ['prohibited'],
            'deadline' => match (true) {
                ! $projet => ['required', 'date', 'after_or_equal:'.$aujourdhui],                // hors projet : libre
                $estChef => ['required', 'date', 'after_or_equal:'.$minimumProjet->toDateString()], // assignation : +5 j ouvrés
                default => ['prohibited'],                                                          // membre : le chef fixera
            },
        ], [
            'deadline.after_or_equal' => $projet
                ? 'Deadline de projet : au plus tôt le '.$minimumProjet->format('d/m/Y').' (création + 5 jours ouvrés).'
                : 'La deadline doit être aujourd\'hui ou plus tard.',
        ]);

        $tache = $this->enregistrer(fn () => Tache::create([
            ...$data,
            'responsable_id' => $data['responsable_id'] ?? $user->id,
            'cree_par' => $user->id,
            'statut' => 'a_faire',
            'deadline_fixee_par' => isset($data['deadline']) ? $user->id : null,
        ]));

        $message = match (true) {
            ! $projet => 'Tâche créée.',
            $estChef => 'Tâche assignée : le responsable est prévenu par mail.',
            default => 'Tâche créée : le chef de projet est prévenu par mail pour fixer la deadline.',
        };

        return redirect()->route('taches.show', $tache)->with('success', $message);
    }

    public function show(Request $request, Tache $tache)
    {
        $tache->load(['projet.chefProjet', 'responsable', 'createur']);
        abort_unless($tache->visiblePar($request->user()), 403);

        return view('taches.show', [
            'tache' => $tache,
            'peutModifierDeadline' => $tache->deadlineModifiablePar($request->user()) && $tache->statut !== 'terminee',
            'peutChangerStatut' => in_array($request->user()->id, [$tache->responsable_id, $tache->chefProjetId()], true),
            'minimum' => $tache->deadlineMinimum(),
        ]);
    }

    public function updateStatut(Request $request, Tache $tache)
    {
        abort_unless(in_array($request->user()->id, [$tache->responsable_id, $tache->chefProjetId()], true), 403);
        $data = $request->validate(['statut' => ['required', Rule::in(Tache::STATUTS_MANUELS)]]);
        $tache->update($data);

        return back()->with('success', 'Statut mis à jour.');
    }

    /** Fixer / repousser / modifier la deadline depuis l'application. */
    public function updateDeadline(Request $request, Tache $tache)
    {
        abort_unless($tache->deadlineModifiablePar($request->user()), 403);
        $premiere = $tache->deadline === null;
        $this->appliquerDeadline($request, $tache, $request->user());

        $message = match (true) {
            ! $tache->projet_id => 'Deadline modifiée : votre manager est prévenu par mail.',
            $premiere => 'Deadline fixée : le responsable est prévenu par mail.',
            default => 'Deadline repoussée : le responsable est prévenu par mail.',
        };

        return back()->with('success', $message);
    }

    /** Lien « Fixer la deadline » reçu par mail par le chef de projet (sans connexion, jeton à usage unique). */
    public function formulaireJeton(Tache $tache, string $jeton)
    {
        $tache->load(['projet', 'responsable']);

        return view('taches.fixer-deadline', [
            'tache' => $tache,
            'jeton' => $jeton,
            'valide' => $this->jetonValide($tache, $jeton),
            'minimum' => $tache->deadlineMinimum(),
        ]);
    }

    public function fixerParJeton(Request $request, Tache $tache, string $jeton)
    {
        abort_unless($this->jetonValide($tache, $jeton), 403, 'Lien expiré : la deadline a déjà été fixée.');
        $this->appliquerDeadline($request, $tache, User::find($tache->chefProjetId()));

        return view('taches.fixer-deadline', [
            'tache' => $tache->fresh(['projet', 'responsable']), 'jeton' => $jeton, 'valide' => false,
            'minimum' => $tache->deadlineMinimum(), 'fixee' => true,
        ]);
    }

    private function appliquerDeadline(Request $request, Tache $tache, ?User $auteur): void
    {
        $minimum = $tache->deadlineMinimum();
        $request->validate(
            ['deadline' => ['required', 'date', 'after_or_equal:'.$minimum->toDateString()]],
            ['deadline.after_or_equal' => match (true) {
                ! $tache->projet_id => 'La deadline doit être aujourd\'hui ou plus tard.',
                ! $tache->deadline => 'Deadline de projet : au plus tôt le '.$minimum->format('d/m/Y').' (création + 5 jours ouvrés).',
                default => 'Une deadline de projet ne peut être que repoussée : au plus tôt le '.$minimum->format('d/m/Y').'.',
            }],
        );

        $this->enregistrer(fn () => $tache->update([
            'deadline' => $request->date('deadline'),
            'deadline_fixee_par' => $auteur?->id,
        ]));
    }

    private function jetonValide(Tache $tache, string $jeton): bool
    {
        return $tache->deadline === null && $tache->jeton_deadline !== null && hash_equals($tache->jeton_deadline, $jeton);
    }

    /** Les règles sont aussi vérifiées par la base : on transforme son refus en message lisible. */
    private function enregistrer(callable $action): mixed
    {
        try {
            return $action();
        } catch (QueryException $e) {
            if (preg_match('/ERROR:\s+(.+?)(\n|CONTEXT|$)/u', $e->getMessage(), $m)) {
                throw ValidationException::withMessages(['deadline' => trim($m[1])]);
            }
            throw $e;
        }
    }
}
