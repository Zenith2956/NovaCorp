<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Résumé IA des demandes via n8n + OpenRouter (voir database/sql/2026_10_09_000010_resume_ia.sql). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes', function (Blueprint $table) {
            $table->text('resume_ia')->nullable();
            $table->timestamp('resume_ia_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_09_000010_resume_ia.sql')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS demandes_resume_ia ON public.demandes;
DROP FUNCTION IF EXISTS automation.demander_resume_ia(), public.enregistrer_resume_ia(bigint, text);
SQL);
        }
        Schema::table('demandes', fn (Blueprint $table) => $table->dropColumn(['resume_ia', 'resume_ia_at']));
    }
};
