<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion des délais + module Tâches – étape 1 (base de données).
 * Spécification : docs/propositions-gestion-delais.md
 *
 * Tables (toutes bases) + triggers/fonctions PostgreSQL dans
 * database/sql/2026_10_08_000003_delais_taches.sql (Supabase uniquement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jours_feries', function (Blueprint $table) {
            $table->date('jour')->primary();
            $table->string('libelle', 100);
            $table->timestamps();
        });

        // Délais par type de demande (en jours ouvrés), modifiables par les RH
        Schema::create('types_demande', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('libelle', 100);
            $table->unsignedSmallInteger('delai_relance');
            $table->unsignedSmallInteger('delai_escalade');
            $table->timestamps();
        });

        DB::table('types_demande')->insert(array_map(fn ($t) => [
            'code' => $t[0], 'libelle' => $t[1], 'delai_relance' => $t[2], 'delai_escalade' => $t[3],
            'created_at' => now(), 'updated_at' => now(),
        ], [
            ['conge', 'Congé', 2, 5],
            ['materiel', 'Matériel', 1, 3],
            ['formation', 'Formation', 4, 10],
            ['note_de_frais', 'Note de frais', 2, 5],
            ['autre', 'Autre', 2, 5],
        ]));

        Schema::table('demandes', function (Blueprint $table) {
            $table->date('date_souhaitee')->nullable();   // saisie par l'employé (facultative)
            $table->boolean('urgente')->default(false);
            $table->date('relance_le')->nullable();       // calculées à la création (trigger)
            $table->date('echeance_le')->nullable();
            $table->date('deadline')->nullable();         // au-delà : statut « expiree »
        });

        Schema::create('taches', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->text('description')->nullable();
            $table->foreignId('projet_id')->nullable()->constrained('projets')->cascadeOnDelete();
            $table->foreignId('responsable_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('statut', 20)->default('a_faire'); // a_faire | en_cours | terminee | expiree
            $table->date('deadline')->nullable();             // null = à fixer par le chef de projet
            $table->date('deadline_initiale')->nullable();
            $table->unsignedSmallInteger('nb_reports')->default(0);
            $table->foreignId('deadline_fixee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deadline_fixee_at')->nullable();
            $table->uuid('jeton_deadline')->nullable()->unique(); // lien « Fixer la deadline » du chef de projet
            $table->timestamp('termine_at')->nullable();
            $table->timestamps();

            $table->index(['statut', 'deadline']);
        });

        Schema::table('mails_sortants', function (Blueprint $table) {
            $table->foreignId('demande_id')->nullable()->change();
            $table->foreignId('tache_id')->nullable()->after('demande_id')->constrained('taches')->cascadeOnDelete();
            $table->jsonb('donnees')->nullable(); // ex. ancienne / nouvelle deadline
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_08_000003_delais_taches.sql')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS demandes_dates ON public.demandes;
DROP TRIGGER IF EXISTS taches_controle ON public.taches;
DROP TRIGGER IF EXISTS taches_mails ON public.taches;
DROP FUNCTION IF EXISTS automation.calculer_dates_demande(), automation.controler_tache(), automation.mail_tache(),
    automation.expirer(), automation.planifier_rappels(), automation.remplir_jours_feries(integer),
    automation.paques(integer), automation.ajouter_jours_ouvres(date, integer), automation.jour_ouvre_precedent(date),
    automation.date_paris(timestamp), automation.aujourdhui();
DELETE FROM public.mails_sortants WHERE demande_id IS NULL;
ALTER TABLE public.mails_sortants DROP CONSTRAINT IF EXISTS mails_sortants_cible_check;
DROP INDEX IF EXISTS public.mails_sortants_palier_tache_unique;
DROP INDEX IF EXISTS public.mails_sortants_rappel_tache_unique;
DROP INDEX IF EXISTS public.mails_sortants_palier_unique;
CREATE UNIQUE INDEX mails_sortants_palier_unique ON public.mails_sortants (demande_id, type)
    WHERE type IN ('nouvelle_demande', 'relance', 'escalade');

-- Versions de l'étape précédente (sans jours fériés, sans expiration)
CREATE OR REPLACE FUNCTION automation.jours_ouvres_depuis(debut timestamptz)
RETURNS integer LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT count(*)::integer FROM generate_series((debut AT TIME ZONE 'Europe/Paris')::date + 1,
        (now() AT TIME ZONE 'Europe/Paris')::date, interval '1 day') AS jour
    WHERE extract(isodow FROM jour) < 6;
$$;
DROP FUNCTION IF EXISTS automation.est_jour_ouvre(date);
CREATE OR REPLACE FUNCTION automation.mail_decision()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
BEGIN
    IF OLD.statut = 'en_attente' AND NEW.statut IN ('validee', 'refusee') THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        SELECT NEW.id, 'decision', jsonb_build_array(e.email), '[]'::jsonb, now(), now()
        FROM public.users e WHERE e.id = NEW.demandeur_id;
    END IF;
    RETURN NEW;
END;
$$;
SQL);
        }

        Schema::table('mails_sortants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tache_id');
            $table->dropColumn('donnees');
        });
        Schema::dropIfExists('taches');
        Schema::table('demandes', function (Blueprint $table) {
            $table->dropColumn(['date_souhaitee', 'urgente', 'relance_le', 'echeance_le', 'deadline']);
        });
        Schema::dropIfExists('types_demande');
        Schema::dropIfExists('jours_feries');
    }
};
