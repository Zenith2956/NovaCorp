<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S4 – récapitulatif au jour de réunion du projet ; S5 – mail de bienvenue.
 * Voir database/sql/2026_10_08_000009_recap_bienvenue.sql et docs/tests-scenarios.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projets', function (Blueprint $table) {
            $table->string('reunion_frequence', 20)->nullable();      // hebdomadaire | bimensuelle | mensuelle
            $table->unsignedSmallInteger('reunion_jour')->nullable();  // 1 = lundi … 5 = vendredi
            $table->date('reunion_depuis')->nullable();                // première réunion (rythme « toutes les 2 semaines »)
            $table->date('dernier_recap_le')->nullable();
        });

        Schema::table('mails_sortants', function (Blueprint $table) {
            $table->foreignId('projet_id')->nullable()->constrained('projets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_08_000009_recap_bienvenue.sql')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
SELECT cron.unschedule('novacorp-recaps') WHERE EXISTS (SELECT 1 FROM cron.job WHERE jobname = 'novacorp-recaps');
DROP TRIGGER IF EXISTS users_bienvenue ON public.users;
DROP FUNCTION IF EXISTS automation.mail_bienvenue(), automation.planifier_recaps(boolean),
    automation.est_jour_reunion(text, smallint, date, date);
DROP INDEX IF EXISTS public.mails_sortants_recap_unique;
DROP INDEX IF EXISTS public.mails_sortants_bienvenue_unique;
SQL);
        }
        Schema::table('mails_sortants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('projet_id');
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('projets', function (Blueprint $table) {
            $table->dropColumn(['reunion_frequence', 'reunion_jour', 'reunion_depuis', 'dernier_recap_le']);
        });
    }
};
