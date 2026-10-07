<?php

namespace App\Http\Controllers;

use App\Mail\DemandeEnvoyee;
use App\Models\Demande;
use App\Models\PieceJointe;
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
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('demandes.index', compact('demandes'));
    }

    public function create(Request $request)
    {
        return view('demandes.create', [
            'types' => Demande::TYPES,
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
                    'chemin' => $fichier->store("demandes/{$demande->id}", 'local'),
                    'mime_type' => $fichier->getMimeType(),
                    'categorie' => PieceJointe::categorieDepuisMime($fichier->getMimeType()),
                    'taille' => $fichier->getSize(),
                ]);
            }

            return $demande;
        });

        // Envoi du mail au manager (avec pièces jointes)
        $demande->load(['demandeur', 'manager', 'piecesJointes']);
        Mail::to($demande->manager->email)->send(new DemandeEnvoyee($demande));
        $demande->update(['envoyee_at' => now()]);

        return redirect()->route('demandes.show', $demande)
            ->with('success', "Demande envoyée par mail à {$demande->manager->nom_complet}.");
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
            'statut' => ['required', Rule::in(array_keys(Demande::STATUTS))],
        ]);

        $demande->update($data);

        return back()->with('success', 'Statut mis à jour.');
    }

    public function telechargerPieceJointe(Request $request, PieceJointe $pieceJointe)
    {
        $this->autoriserLecture($request->user(), $pieceJointe->demande);

        return Storage::disk('local')->download($pieceJointe->chemin, $pieceJointe->nom_original);
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
