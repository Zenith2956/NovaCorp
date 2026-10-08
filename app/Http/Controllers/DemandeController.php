<?php

namespace App\Http\Controllers;

use App\Mail\DemandeEnvoyee;
use App\Models\Demande;
use App\Models\PieceJointe;
use App\Models\TypeDemande;
use App\Support\Calendrier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DemandeController extends Controller
{
    /** Extensions autorisées : documents, vocaux, photos, vidéos. */
    private const EXTENSIONS = 'pdf,doc,docx,xls,xlsx,odt,ods,txt,csv,jpg,jpeg,png,gif,webp,heic,mp3,wav,ogg,m4a,aac,webm,mp4,mov,avi,mkv';

    public function index(Request $request)
    {
        $user = $request->user();

        $demandes = Demande::with(['demandeur', 'manager'])
            ->withCount('piecesJointes')
            // RH / admin / direction voient tout ; manager voit les siennes + celles reçues
            ->when(! $user->hasRole('rh', 'admin', 'direction'), fn ($q) => $q->where(
                fn ($q) => $q->where('demandeur_id', $user->id)->orWhere('manager_id', $user->id)
            ))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
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

        return view('demandes.create', [
            'types' => Demande::TYPES,
            'delais' => TypeDemande::all()->keyBy('code'),
            'modele' => $modele,
            'managers' => User::whereHas('role', fn ($q) => $q->whereIn('slug', ['manager', 'direction']))
                ->orderBy('nom')->get(),
            'managerParDefaut' => $request->user()->manager_id,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Demande::TYPES))],
            'objet' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'manager_id' => ['required', 'exists:users,id'],
            'date_souhaitee' => ['nullable', 'date', 'after_or_equal:'.Calendrier::aujourdhui()->toDateString()],
            'pieces_jointes' => ['nullable', 'array', 'max:5'],
            'pieces_jointes.*' => ['file', 'max:51200', 'extensions:'.self::EXTENSIONS], // 50 Mo / fichier
        ]);

        $demande = DB::transaction(function () use ($request, $data) {
            $demande = Demande::create([
                ...collect($data)->except('pieces_jointes')->all(),
                'demandeur_id' => $request->user()->id,
                'statut' => 'en_attente',
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

        // Relance, échéance et deadline viennent d'être calculées (trigger PostgreSQL)
        $demande->refresh()->load(['demandeur', 'manager', 'piecesJointes']);

        if (config('novacorp.envoi_mail_direct')) {
            // Ancien fonctionnement : Laravel envoie le mail lui-même
            Mail::to($demande->manager->email)->send(new DemandeEnvoyee($demande));
            $demande->update(['envoyee_at' => now()]);
            $message = "Demande envoyée par mail à {$demande->manager->nom_complet}.";
        } else {
            // Nouveau fonctionnement : un trigger a mis le mail dans la boîte d'envoi,
            // l'Edge Function « envoyer-mails » l'envoie dans la minute.
            $message = "Demande enregistrée : le mail à {$demande->manager->nom_complet} part dans la minute.";
        }

        return redirect()->route('demandes.show', $demande)->with('success', $message);
    }

    public function show(Request $request, Demande $demande)
    {
        $this->autoriserLecture($request->user(), $demande);
        $demande->load(['demandeur.role', 'manager', 'piecesJointes']);

        return view('demandes.show', compact('demande'));
    }

    /**
     * Validation MANUELLE : le manager a répondu par mail ou par téléphone,
     * on reporte sa décision dans l'application.
     */
    public function updateStatut(Request $request, Demande $demande)
    {
        $user = $request->user();
        abort_unless($user->id === $demande->manager_id || $user->hasRole('rh', 'admin'), 403);

        $data = $request->validate([
            'statut' => ['required', Rule::in(Demande::STATUTS_MANUELS)],
        ]);
        abort_if($demande->statut === 'expiree' && ! $user->hasRole('rh', 'admin'), 403, 'Demande expirée.');

        $demande->update([
            ...$data,
            ...($data['statut'] === 'en_attente' ? [] : [
                'decision_at' => now(),
                'decision_par' => $user->id,
                'jeton_decision' => null, // les liens du mail ne servent plus
            ]),
        ]);

        return back()->with('success', 'Statut mis à jour.');
    }

    public function telechargerPieceJointe(Request $request, PieceJointe $pieceJointe)
    {
        $this->autoriserLecture($request->user(), $pieceJointe->demande);

        return Storage::disk(config('novacorp.disque_pieces_jointes'))->download($pieceJointe->chemin, $pieceJointe->nom_original);
    }

    private function autoriserLecture(User $user, Demande $demande): void
    {
        abort_unless(
            $user->id === $demande->demandeur_id
            || $user->id === $demande->manager_id
            || $user->hasRole('rh', 'admin', 'direction'),
            403
        );
    }
}
