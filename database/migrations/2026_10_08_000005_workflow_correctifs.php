<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Correctifs du workflow trouvés par les tests (voir le fichier SQL). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_08_000005_workflow_correctifs.sql')));
        }
    }

    public function down(): void
    {
        // Rien : les versions corrigées restent valables.
    }
};
