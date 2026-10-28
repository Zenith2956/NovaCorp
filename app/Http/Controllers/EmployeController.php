<?php

namespace App\Http\Controllers;

use App\Models\MailSortant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'estAdmin' => $request->user()->hasRole('admin'),
        ]);
    }

    /** R1 – seul l'admin ajoute des personnes à l'entreprise (plus d'inscription libre). */
    public function create()
    {
        return view('employes.create', [
            'roles' => Role::orderBy('libelle')->get(),
            'managers' => User::where('actif', true)
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['manager', 'direction']))
                ->orderBy('nom')->orderBy('prenom')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'prenom' => ['required', 'string', 'max:100'],
            'nom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', Rule::exists('roles', 'id')],
            'manager_id' => ['nullable', Rule::exists('users', 'id')],
        ], [], ['role_id' => 'rôle', 'manager_id' => 'manager', 'prenom' => 'prénom', 'telephone' => 'téléphone']);

        // Mot de passe inutilisable tant que l'employé ne l'a pas choisi via le lien d'activation.
        // Le mail de bienvenue (avec ce lien) part tout seul : trigger users_bienvenue → Edge Function envoyer-mails.
        $user = DB::transaction(fn () => User::create($data + [
            'actif' => true,
            'password' => Hash::make(Str::random(40)),
            'jeton_activation' => (string) Str::uuid(),
            'jeton_activation_expire_at' => now()->addDays(User::VALIDITE_ACTIVATION_JOURS),
        ]));

        return redirect()->route('employes.index', ['q' => $user->email])
            ->with('success', "Compte de {$user->nom_complet} créé : le mail de bienvenue avec le lien d'activation part dans la minute.");
    }

    /** R1 – nouveau lien d'activation (perdu, expiré…) : les anciens liens ne marchent plus. */
    public function renvoyerActivation(User $user)
    {
        abort_unless($user->en_attente_activation && $user->actif, 422, 'Ce compte est déjà activé.');

        DB::transaction(function () use ($user) {
            $user->nouveauJetonActivation();
            MailSortant::create([
                'user_id' => $user->id, 'type' => 'activation', 'statut' => 'a_envoyer',
                'destinataires' => [$user->email], 'copies' => [],
            ]);
        });

        return back()->with('success', "Nouveau lien d'activation envoyé à {$user->email} (valable ".User::VALIDITE_ACTIVATION_JOURS.' jours).');
    }
}
