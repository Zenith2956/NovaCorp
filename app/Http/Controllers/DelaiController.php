<?php

namespace App\Http\Controllers;

use App\Models\Demande;
use App\Models\TypeDemande;
use App\Models\User;
use App\Support\Calendrier;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Page « Délais » : RH / direction / admin voient tout, un manager voit les demandes de son équipe.
 */
class DelaiController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $toutVoir = $user->hasRole('rh', 'direction', 'admin');

        $demandes = Demande::with(['demandeur', 'manager'])
            ->when(! $toutVoir, fn ($q) => $q->where('manager_id', $user->id))
            ->get();

        $traitees = $demandes->whereNotNull('decision_at');
        $enAttente = $demandes->where('statut', 'en_attente');

        return view('delais.index', [
            'toutVoir' => $toutVoir,
            'global' => $this->indicateurs($demandes),
            'parType' => $demandes->groupBy('type')->map(fn ($g) => $this->indicateurs($g))->sortKeys(),
            'parManager' => $toutVoir
                ? $demandes->groupBy('manager_id')->map(fn ($g) => ['manager' => $g->first()->manager] + $this->indicateurs($g))
                    ->sortByDesc('en_retard')
                : collect(),
            'enRetard' => $enAttente->filter(fn ($d) => $d->indicateur_delai === 'en_retard')->sortBy('echeance_le'),
            'bientot' => $enAttente->filter(fn ($d) => $d->indicateur_delai === 'echeance_proche')->sortBy('echeance_le'),
            'types' => TypeDemande::orderBy('libelle')->get(),
            'peutRegler' => $user->hasRole('rh', 'admin'),
            'aujourdhui' => Calendrier::aujourdhui(),
            'nbTraitees' => $traitees->count(),
        ]);
    }

    /** Les RH ajustent les délais par type, sans toucher au code. */
    public function updateTypes(Request $request)
    {
        abort_unless($request->user()->hasRole('rh', 'admin'), 403);

        $data = $request->validate([
            'types' => ['required', 'array'],
            'types.*.delai_relance' => ['required', 'integer', 'min:1', 'max:60'],
            'types.*.delai_escalade' => ['required', 'integer', 'min:1', 'max:60', 'gte:types.*.delai_relance'],
        ]);

        foreach ($data['types'] as $id => $delais) {
            TypeDemande::whereKey($id)->update($delais);
        }

        return back()->with('success', 'Délais mis à jour (ils s\'appliquent aux nouvelles demandes).');
    }

    /** @param Collection<int, Demande> $demandes */
    private function indicateurs(Collection $demandes): array
    {
        $traitees = $demandes->whereNotNull('decision_at');
        $delais = $traitees->map(fn ($d) => $d->delai_traitement)->filter(fn ($v) => $v !== null);
        $dansLesTemps = $traitees->filter(fn ($d) => $d->traitee_dans_les_temps)->count();

        return [
            'total' => $demandes->count(),
            'en_attente' => $demandes->where('statut', 'en_attente')->count(),
            'en_retard' => $demandes->filter(fn ($d) => $d->indicateur_delai === 'en_retard')->count(),
            'expirees' => $demandes->where('statut', 'expiree')->count(),
            'traitees' => $traitees->count(),
            'delai_moyen' => $delais->isEmpty() ? null : round($delais->avg(), 1),
            'pct_dans_les_temps' => $traitees->isEmpty() ? null : (int) round(100 * $dansLesTemps / $traitees->count()),
        ];
    }
}
