<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Workflow – étape 4 : statistiques pour le tableau de bord Flutter (PostgreSQL / Supabase uniquement).
 * Fonction RPC stats_workflow(), table stats_quotidiennes en Realtime. Voir docs/stats-flutter.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_08_000006_stats_workflow.sql')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
SELECT cron.unschedule('novacorp-stats') WHERE EXISTS (SELECT 1 FROM cron.job WHERE jobname = 'novacorp-stats');
DROP TRIGGER IF EXISTS demandes_stats ON public.demandes;
DROP TRIGGER IF EXISTS taches_stats ON public.taches;
DROP FUNCTION IF EXISTS automation.stats_apres_modification(), automation.rafraichir_stats(date),
    public.stats_workflow(date, date), automation.calculer_stats(bigint, date, date);
DROP TABLE IF EXISTS public.stats_quotidiennes;
DROP FUNCTION IF EXISTS public.novacorp_profil();
SQL);
        }
    }
};
