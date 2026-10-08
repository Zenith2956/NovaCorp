<?php

namespace App\Http\Controllers;

use App\Mail\DemandeEnvoyee;
use App\Models\Demande;
use App\Models\EtapeCircuit;
use App\Models\PieceJointe;
use App\Models\TypeDemande;
use App\Models\User;
use App\Services\WorkflowDemande;
use App\Support\Calendrier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DemandeController extends Controller
{
    /** Extensions autorisées : documents, vocaux, photos, vidéos. */
    private const EXTENSIONS = 'pdf,doc,docx,xls,xlsx,odt,ods,txt,csv,jpg,jpeg,png,gif,webp,heic,mp3,wav,ogg,m4a,aac,webm,mp4,mov,avi,mkv';

    public function __construct(private WorkflowDemande $workflow) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $demandes = Demande::with(['demandeur', 'manager'])
            ->withCount('piecesJointes')
            ->visiblePar($user)
            ->when($request->boolean('a_traiter'), fn ($q) => $q->aTraiterPar($user))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->boolean('retard'), fn ($q) => $q->where('statut', 'en_attente')
                ->whereDate('echeance_le', '<', Calendrier::aujourdhui()->toDateString()))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('demandes.index', compact('demandes'));
    }

    public function create(Request $request)
    {
        // « Refaire la demande » : pré-remplissage à partir d'une demande expirée de l'employé
        $modele = null;
        if ($request->filled('refaire')) {
            $modele = Demande::where('id', $request->integer('refaire'))
                ->where('demandeur_id', $request->user()->id)
                ->where('statut', 'expiree')
                ->first();
        }

        $user = $request->user();

        return view('demandes.create', [
            'types' => Demande::TYPES,
            'delais' => TypeDemande::all()->keyBy('code'),
            'circuits' => EtapeCircuit::orderBy('ordre')->get()->groupBy('type_code'),
            'modele' => $modele,
            // Assignation automatique : le manager de l'employé, à défaut la direction
            'managerPrevu' => $user->manager
                ?? User::whereHas('role', fn ($q) => $q->where('slug', 'direction'))->where('actif', true)->orderBy('id')->first(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Demande::TYPES))],
            'objet' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'montant' => [Rule::requiredIf(in_array($request->type, Demande::TYPES_AVEC_MONTANT, true)), 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'date_debut' => [Rule::requiredIf(in_array($request->type, Demande::TYPES_AVEC_DATES, true)), 'nullable', 'date'],
            'date_fin' => [Rule::requiredIf(in_array($request->type, Demande::TYPES_AVEC_DATES, true)), 'nullable', 'date', 'after_or_equal:date_debut'],
            'date_souhaitee' => ['nullable', 'date', 'after_or_equal:'.Calendrier::aujourdhui()->toDateString()],
            'pieces_jointes' => ['nullable', 'array', 'max:5'],
            'pieces_jointes.*' => ['file', 'max:51200', 'extensions:'.self::EXTENSIONS], // 50 Mo / fichier
        ], [
            'montant.required' => 'Le montant est obligatoire pour ce type de demande.',
            'date_debut.required' => 'La date de début du congé est obligatoire.',
            'date_fin.required' => 'La date de fin du congé est obligatoire.',
            'date_fin.after_or_equal' => 'La date de fin doit être après la date de début.',
        ]);

        // On ne garde que les champs utiles au type choisi
        if (! in_array($data['type'], Demande::TYPES_AVEC_MONTANT, true)) {
            $data['montant'] = null;
        }
        if (! in_array($data['type'], Demande::TYPES_AVEC_DATES, true)) {
            $data['date_debut'] = $data['date_fin'] = null;
        }

        $demande = DB::transaction(function () use ($request, $data) {
            $demande = Demande::create([
                ...collect($data)->except('pieces_jointes')->all(),
                'demandeur_id' => $request->user()->id,
                'statut' => 'en_attente',
                'derniere_action_par' => $request->user()->id,
                'derniere_action_canal' => 'appli',
            ]);

            foreach ($request->file('pieces_jointes', []) as $fichier) {
                $demande->piecesJointes()->create([
                    'nom_original' => $fichier->getClientOriginalName(),
                    'chemin' => $fichier->store("demandes/{$demande->id}", config('novacorp.disque_pieces_jointes')),
                    'mime_type' => $fichier->getMimeType(),
                    'categorie' => PieceJointe::categorieDepuisMime($fichier->getMimeType()),
                    'taille' => $fichier->getSize(),
                ]);
            }

            return $demande;
        });

        // Manager, jours de congé, relance, échéance et deadline viennent d'être calculés (trigger PostgreSQL)
        $demande->refresh()->load(['demandeur', 'manager', 'piecesJointes']);
        $destinataire = $demande->manager?->nom_complet ?? 'la direction';

        if (config('novacorp.envoi_mail_direct')) {
            // Ancien fonctionnement : Laravel envoie le mail lui-même
            if ($demande->manager) {
                Mail::to($demande->manager->email)->send(new DemandeEnvoyee($demande));
            }
            $demande->update(['envoyee_at' => now()]);
            $message = "Demande envoyée par mail à {$destinataire}.";
        } else {
            // Un trigger a mis le mail dans la boîte d'envoi, l'Edge Function « envoyer-mails » l'envoie dans la minute.
            $message = "Demande enregistrée et assignée à {$destinataire} : le mail part dans la minute.";
        }

        return redirect()->route('demandes.show', $demande)->with('success', $message);
    }

    public function show(Request $request, Demande $demande)
    {
        $this->autoriserLecture($request->user(), $demande);
        $demande->load(['demandeur.role', 'manager', 'piecesJointes', 'traitePar', 'historique.auteur']);

        return view('demandes.show', [
            'demande' => $demande,
            'etapes' => $this->workflow->etapes($demande),
            'applicables' => $this->workflow->etapesApplicables($demande)->pluck('ordre')->all(),
            'actions' => $this->workflow->actionsPossibles($request->user(), $demande),
            'roleTraitement' => $this->workflow->roleTraitement($demande),
        ]);
    }

    /** Valider, refuser, demander un complément, compléter, annuler, traiter, corriger (depuis l'application). */
    public function action(Request $request, Demande $demande)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(WorkflowDemande::ACTIONS))],
            'commentaire' => [Rule::requiredIf($request->action === 'completer'), 'nullable', 'string', 'max:2000'],
        ], ['commentaire.required' => 'Décrivez le complément apporté.']);

        $message = $this->workflow->executer($data['action'], $demande, $request->user(), $data['commentaire'] ?? null);

        return redirect()->route('demandes.show', $demande)->with('success', $message);
    }

    public function telechargerPieceJointe(Request $request, PieceJointe $pieceJointe)
    {
        $this->autoriserLecture($request->user(), $pieceJointe->demande);

        return Storage::disk(config('novacorp.disque_pieces_jointes'))->download($pieceJointe->chemin, $pieceJointe->nom_original);
    }

    private function autoriserLecture(User $user, Demande $demande): void
    {
        abort_unless(Demande::whereKey($demande->id)->visiblePar($user)->exists(), 403);
    }
}
