<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StatistiquesWorkflow;
use App\Support\Calendrier;
use Illuminate\Http\Request;

/**
 * Page « Statistiques » du back-office : temps moyen, volume, services sollicités (graphiques).
 * RH / direction / admin : toute l'entreprise ou l'équipe d'un manager ; manager : son équipe.
 * Mêmes chiffres que le dashboard Flutter (fonction PostgreSQL automation.calculer_stats).
 */
class StatistiqueController extends Controller
{
    public const PERIODES = [7 => '7 jours', 30 => '30 jours', 90 => '3 mois', 365 => '12 mois'];

    public function __construct(private StatistiquesWorkflow $stats) {}

    public function index(Request $request)
    {
        $toutVoir = $request->user()->hasRole('rh', 'direction', 'admin');

        return view('statistiques.index', [
            'toutVoir' => $toutVoir,
            'managers' => $toutVoir
                ? User::whereHas('role', fn ($q) => $q->where('slug', 'manager'))->where('actif', true)->orderBy('nom')->get()
                : collect(),
            'periodes' => self::PERIODES,
        ]);
    }

    /** Données JSON (rafraîchies par la page toutes les minutes). */
    public function donnees(Request $request)
    {
        $user = $request->user();
        $jours = (int) $request->query('jours', 30);
        abort_unless(array_key_exists($jours, self::PERIODES), 422, 'Période invalide.');

        $manager = $user->hasRole('rh', 'direction', 'admin')
            ? ($request->filled('manager') ? $request->integer('manager') : null)
            : $user->id;                                        // un manager ne voit que son équipe

        $fin = Calendrier::aujourdhui();

        return response()->json($this->stats->calculer($manager, $fin->subDays($jours - 1), $fin));
    }
}
