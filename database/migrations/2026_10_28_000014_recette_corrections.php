<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections de la recette du 28/10/2026 (R1 comptes créés par l'admin, R2 tâches en pause, R4 correction).
 * Règles PostgreSQL : database/sql/2026_10_28_000014_recette_corrections.sql
 */
return new class extends Migration
{
    private const TRANSITIONS = [
        ['a_faire', 'en_pause'], ['en_cours', 'en_pause'], ['a_valider', 'en_pause'], ['expiree', 'en_pause'],
        ['en_pause', 'a_faire'],
    ];

    public function up(): void
    {
        // R1 : lien d'activation envoyé avec le mail de bienvenue (choix du mot de passe)
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('jeton_activation')->nullable()->unique();
            $table->timestamp('jeton_activation_expire_at')->nullable();
        });

        // R2 : tâche mise en pause quand son responsable est retiré du projet
        Schema::table('taches', function (Blueprint $table) {
            $table->timestamp('pause_le')->nullable();
            $table->string('pause_motif')->nullable();
            $table->foreignId('pause_ancien_responsable_id')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('transitions')->insert(array_map(fn ($t) => [
            'objet' => 'tache', 'de' => $t[0], 'vers' => $t[1], 'acteur' => 'chef',
        ], self::TRANSITIONS));

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_28_000014_recette_corrections.sql')));
        }
    }

    public function down(): void
    {
        foreach (self::TRANSITIONS as [$de, $vers]) {
            DB::table('transitions')->where(['objet' => 'tache', 'de' => $de, 'vers' => $vers])->delete();
        }
        if (DB::getDriverName() === 'pgsql') {
            // Les fonctions reprennent leur version précédente en rejouant les fichiers SQL d'origine si besoin
            DB::unprepared('DROP INDEX IF EXISTS public.mails_sortants_pause_unique;');
        }
        Schema::table('taches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pause_ancien_responsable_id');
            $table->dropColumn(['pause_le', 'pause_motif']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['jeton_activation']);
            $table->dropColumn(['jeton_activation', 'jeton_activation_expire_at']);
        });
    }
};
