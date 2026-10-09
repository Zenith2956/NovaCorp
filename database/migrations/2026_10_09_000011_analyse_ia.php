<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tri automatique des demandes par IA (voir database/sql/2026_10_09_000011_analyse_ia.sql). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes', function (Blueprint $table) {
            $table->jsonb('analyse_ia')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_09_000011_analyse_ia.sql')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Retour à la version 000010 (résumé seul, déclencheur immédiat)
            DB::unprepared('DROP FUNCTION IF EXISTS public.enregistrer_resume_ia(bigint, text, jsonb);');
            DB::unprepared(file_get_contents(database_path('sql/2026_10_09_000010_resume_ia.sql')));
        }
        Schema::table('demandes', fn (Blueprint $table) => $table->dropColumn('analyse_ia'));
    }
};
