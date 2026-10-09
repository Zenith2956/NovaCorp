<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A2 délégation pendant les absences + A3 alerte « équipe trop absente » (voir database/sql/2026_10_09_000013_absences.sql). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('suppleant_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('demandes', function (Blueprint $table) {
            $table->foreignId('manager_titulaire_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('parametres', function (Blueprint $table) {
            $table->string('cle')->primary();
            $table->string('valeur');
            $table->string('libelle')->nullable();
            $table->timestamp('updated_at')->nullable()->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_09_000013_absences.sql')));
        } else {
            DB::table('parametres')->insert(['cle' => 'seuil_absences_equipe_pct', 'valeur' => '50',
                'libelle' => "Alerte absences : % de l'équipe absente un même jour"]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
SELECT cron.unschedule(jobname) FROM cron.job WHERE jobname = 'novacorp-delegations';
DROP TRIGGER IF EXISTS demandes_delegation ON public.demandes;
DROP FUNCTION IF EXISTS automation.deleguer_nouvelle_demande(), automation.transferer_delegations(),
                        automation.absences_equipe(bigint), automation.suppleant_de(bigint, date, bigint), automation.est_absent(bigint, date);
DROP INDEX IF EXISTS public.mails_sortants_delegation_unique;
SQL);
        }
        Schema::dropIfExists('parametres');
        Schema::table('demandes', fn (Blueprint $table) => $table->dropConstrainedForeignId('manager_titulaire_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('suppleant_id'));
    }
};
