<?php

namespace App\Services;

use App\Models\Demande;
use Illuminate\Support\Facades\DB;

/** A3 – Collègues déjà absents sur la période d'un congé (calcul : automation.absences_equipe, PostgreSQL). */
class AbsencesEquipe
{
    public function pour(Demande $demande): ?array
    {
        if ($demande->type !== 'conge' || ! $demande->date_debut || DB::getDriverName() !== 'pgsql') {
            return null;
        }
        $json = DB::selectOne('select automation.absences_equipe(?) as a', [$demande->id])?->a;

        return $json ? json_decode($json, true) : null;
    }
}
