<?php

namespace App\Support;

use App\Models\JourFerie;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Jours ouvrés (lundi-vendredi, hors jours fériés de la table jours_feries), heure de Paris.
 * Même logique que les fonctions SQL automation.* (database/sql/2026_10_08_000003_delais_taches.sql) :
 * sous PostgreSQL c'est la base qui calcule les dates des demandes ; cette classe sert à l'affichage,
 * aux statistiques et aux tests (SQLite).
 */
class Calendrier
{
    /** @var array<string, true>|null */
    private static ?array $feries = null;

    /** Aujourd'hui (heure de Paris), sous forme de date sans heure. */
    public static function aujourdhui(): CarbonImmutable
    {
        return self::date(CarbonImmutable::now('Europe/Paris'));
    }

    /** Ne garde que le jour (AAAA-MM-JJ), à minuit, pour comparer des dates sans souci de fuseau. */
    public static function date(CarbonInterface $jour): CarbonImmutable
    {
        return CarbonImmutable::parse($jour->toDateString());
    }

    public static function estOuvre(CarbonInterface $jour): bool
    {
        self::$feries ??= JourFerie::pluck('jour')
            ->mapWithKeys(fn ($j) => [CarbonImmutable::parse($j)->toDateString() => true])
            ->all();

        return ! $jour->isWeekend() && ! isset(self::$feries[$jour->toDateString()]);
    }

    public static function ajouterJoursOuvres(CarbonInterface $depart, int $n): CarbonImmutable
    {
        $jour = self::date($depart);
        while ($n > 0) {
            $jour = $jour->addDay();
            if (self::estOuvre($jour)) {
                $n--;
            }
        }

        return $jour;
    }

    public static function jourOuvrePrecedent(CarbonInterface $depart): CarbonImmutable
    {
        $jour = self::date($depart)->subDay();
        while (! self::estOuvre($jour)) {
            $jour = $jour->subDay();
        }

        return $jour;
    }

    /** Nombre de jours ouvrés écoulés entre deux dates (la date de début n'est pas comptée). */
    public static function joursOuvresEntre(CarbonInterface $debut, CarbonInterface $fin): int
    {
        $jour = self::date($debut);
        $fin = self::date($fin);
        $n = 0;
        while ($jour->lt($fin)) {
            $jour = $jour->addDay();
            if (self::estOuvre($jour)) {
                $n++;
            }
        }

        return $n;
    }

    /** À appeler quand la table des jours fériés change (et dans les tests). */
    public static function oublier(): void
    {
        self::$feries = null;
    }
}
