<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** C1 bilan de santé quotidien + A1 relances des « à traiter » / « à valider » (voir database/sql/2026_10_09_000012_bilan_relances_oublis.sql). */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_09_000012_bilan_relances_oublis.sql')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
SELECT cron.unschedule(jobname) FROM cron.job WHERE jobname IN ('novacorp-bilan-sante', 'novacorp-relances-oublis');
DROP FUNCTION IF EXISTS automation.bilan_sante(boolean), automation.planifier_relances_oublis(),
                        automation.demandes_a_traiter_oubliees(), automation.taches_a_valider_oubliees();
DROP INDEX IF EXISTS public.mails_sortants_oubli_demande_unique, public.mails_sortants_oubli_tache_unique, public.mails_sortants_bilan_unique;
SQL);
        }
    }
};
