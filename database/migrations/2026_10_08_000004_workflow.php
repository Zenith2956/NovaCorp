<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow automatisé – étape 1 (base de données). Spécification : docs/propositions-workflow.md
 * Partie PostgreSQL : database/sql/2026_10_08_000004_workflow.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Circuits de validation ----------
        Schema::create('etapes_circuit', function (Blueprint $table) {
            $table->id();
            $table->string('type_code', 50);
            $table->unsignedSmallInteger('ordre');                 // 1 = manager
            $table->string('libelle', 100);
            $table->string('valideur', 50);                        // 'manager' ou slug d'un rôle (rh, comptable…)
            $table->string('condition_champ', 50)->nullable();     // montant | nb_jours_ouvres (null = toujours)
            $table->decimal('condition_seuil', 12, 2)->nullable(); // l'étape s'applique si champ > seuil
            $table->unsignedSmallInteger('delai_relance')->default(2);
            $table->unsignedSmallInteger('delai_escalade')->default(5);
            $table->timestamps();
            $table->unique(['type_code', 'ordre']);
        });

        $etapes = [
            // type, ordre, libellé, valideur, condition, seuil, relance, escalade
            ['conge', 1, 'Manager', 'manager', null, null, 2, 5],
            ['conge', 2, 'Ressources humaines', 'rh', 'nb_jours_ouvres', 5, 2, 5],
            ['materiel', 1, 'Manager', 'manager', null, null, 1, 3],
            ['materiel', 2, 'Comptabilité', 'comptable', 'montant', 500, 2, 5],
            ['note_de_frais', 1, 'Manager', 'manager', null, null, 2, 5],
            ['note_de_frais', 2, 'Comptabilité', 'comptable', null, null, 2, 5],
            ['formation', 1, 'Manager', 'manager', null, null, 4, 10],
            ['formation', 2, 'Ressources humaines', 'rh', null, null, 2, 5],
            ['autre', 1, 'Manager', 'manager', null, null, 2, 5],
        ];
        DB::table('etapes_circuit')->insert(array_map(fn ($e) => [
            'type_code' => $e[0], 'ordre' => $e[1], 'libelle' => $e[2], 'valideur' => $e[3],
            'condition_champ' => $e[4], 'condition_seuil' => $e[5], 'delai_relance' => $e[6], 'delai_escalade' => $e[7],
            'created_at' => now(), 'updated_at' => now(),
        ], $etapes));

        // Service qui traite la demande une fois validée
        Schema::table('types_demande', function (Blueprint $table) {
            $table->string('role_traitement', 50)->nullable();
        });
        foreach (['conge' => 'rh', 'formation' => 'rh', 'note_de_frais' => 'comptable', 'materiel' => 'admin'] as $code => $role) {
            DB::table('types_demande')->where('code', $code)->update(['role_traitement' => $role]);
        }

        // ---------- Machine à états ----------
        Schema::create('transitions', function (Blueprint $table) {
            $table->id();
            $table->string('objet', 20);   // demande | tache
            $table->string('de', 30);
            $table->string('vers', 30);
            $table->string('acteur', 30);  // qui peut la déclencher (contrôlé par l'application)
            $table->unique(['objet', 'de', 'vers']);
        });
        $transitions = [
            ['demande', 'en_attente', 'validee', 'valideur'],
            ['demande', 'en_attente', 'refusee', 'valideur'],
            ['demande', 'en_attente', 'a_completer', 'valideur'],
            ['demande', 'a_completer', 'en_attente', 'demandeur'],
            ['demande', 'en_attente', 'annulee', 'demandeur'],
            ['demande', 'a_completer', 'annulee', 'demandeur'],
            ['demande', 'en_attente', 'expiree', 'systeme'],
            ['demande', 'a_completer', 'expiree', 'systeme'],
            ['demande', 'validee', 'en_traitement', 'traitement'],
            ['demande', 'en_traitement', 'terminee', 'traitement'],
            ['demande', 'validee', 'en_attente', 'correction'],
            ['demande', 'refusee', 'en_attente', 'correction'],
            ['tache', 'a_faire', 'en_cours', 'responsable'],
            ['tache', 'en_cours', 'a_faire', 'responsable'],
            ['tache', 'a_faire', 'a_valider', 'responsable'],
            ['tache', 'en_cours', 'a_valider', 'responsable'],
            ['tache', 'a_faire', 'terminee', 'responsable'],   // hors projet, ou chef de projet
            ['tache', 'en_cours', 'terminee', 'responsable'],
            ['tache', 'a_valider', 'terminee', 'chef'],
            ['tache', 'a_valider', 'en_cours', 'chef'],
            ['tache', 'a_faire', 'expiree', 'systeme'],
            ['tache', 'en_cours', 'expiree', 'systeme'],
            ['tache', 'expiree', 'a_faire', 'systeme'],        // deadline repoussée
            ['tache', 'expiree', 'terminee', 'responsable'],   // terminée en retard (hors projet)
            ['tache', 'expiree', 'a_valider', 'responsable'],  // terminée en retard (projet)
        ];
        DB::table('transitions')->insert(array_map(fn ($t) => [
            'objet' => $t[0], 'de' => $t[1], 'vers' => $t[2], 'acteur' => $t[3],
        ], $transitions));

        // ---------- Historique ----------
        Schema::create('historique', function (Blueprint $table) {
            $table->id();
            $table->string('objet', 20);                  // demande | tache
            $table->unsignedBigInteger('objet_id');
            $table->string('ancien_statut', 30)->nullable();
            $table->string('nouveau_statut', 30);
            $table->unsignedSmallInteger('etape')->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('canal', 20)->default('systeme'); // appli | mail | cron | systeme
            $table->text('commentaire')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['objet', 'objet_id']);
        });

        // ---------- Nouveaux champs ----------
        Schema::table('demandes', function (Blueprint $table) {
            $table->decimal('montant', 10, 2)->nullable();            // matériel, note de frais
            $table->date('date_debut')->nullable();                  // congé
            $table->date('date_fin')->nullable();
            $table->unsignedSmallInteger('nb_jours_ouvres')->nullable();
            $table->unsignedSmallInteger('etape')->default(1);       // étape en cours du circuit
            $table->text('commentaire_decision')->nullable();        // motif de refus / complément demandé
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_at')->nullable();
            $table->foreignId('derniere_action_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('derniere_action_canal', 20)->nullable();
        });

        Schema::table('taches', function (Blueprint $table) {
            $table->text('commentaire_validation')->nullable();      // motif de renvoi par le chef
            $table->foreignId('derniere_action_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('derniere_action_canal', 20)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(file_get_contents(database_path('sql/2026_10_08_000004_workflow.sql')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS demandes_transition ON public.demandes;
DROP TRIGGER IF EXISTS taches_transition ON public.taches;
DROP TRIGGER IF EXISTS demandes_historique ON public.demandes;
DROP TRIGGER IF EXISTS taches_historique ON public.taches;
DROP FUNCTION IF EXISTS automation.controler_transition(), automation.historiser(),
    automation.valideurs_etape(bigint), automation.emails_role(text), automation.jours_ouvres_periode(date, date);
DROP INDEX IF EXISTS public.mails_sortants_palier_etape_unique;
SQL);
            // Les fonctions remplacées (mail_decision, mail_tache, planifier_rappels, expirer, calculer_dates_demande)
            // retrouvent leur version précédente en rejouant database/sql/2026_10_08_000003_delais_taches.sql si besoin.
        }

        Schema::table('taches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('derniere_action_par');
            $table->dropColumn(['commentaire_validation', 'derniere_action_canal']);
        });
        Schema::table('demandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('traite_par');
            $table->dropConstrainedForeignId('derniere_action_par');
            $table->dropColumn(['montant', 'date_debut', 'date_fin', 'nb_jours_ouvres', 'etape',
                'commentaire_decision', 'traite_at', 'derniere_action_canal']);
        });
        Schema::dropIfExists('historique');
        Schema::dropIfExists('transitions');
        Schema::table('types_demande', fn (Blueprint $table) => $table->dropColumn('role_traitement'));
        Schema::dropIfExists('etapes_circuit');
    }
};
