<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatisation des mails – étape 1 : la « boîte d'envoi ».
 *
 * Chaque mail à envoyer est une ligne de `mails_sortants`. Elle est créée :
 *  - à la création d'une demande (trigger)            -> type nouvelle_demande -> manager
 *  - quand le statut passe à validée / refusée (trigger) -> type decision      -> employé
 *  - par automation.planifier_relances() (cron 9 h)    -> types relance / escalade
 * L'Edge Function « envoyer-mails » (étape 3) envoie les lignes `a_envoyer`.
 *
 * Les triggers et fonctions n'existent que sous PostgreSQL (Supabase) ;
 * les tests (SQLite) n'ont que les tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demandes', function (Blueprint $table) {
            // Jeton à usage unique pour les liens Valider / Refuser du mail
            $table->uuid('jeton_decision')->nullable()->unique();
            $table->timestamp('decision_at')->nullable();
            $table->foreignId('decision_par')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('mails_sortants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('demandes')->cascadeOnDelete();
            $table->string('type', 30);                 // nouvelle_demande | decision | relance | escalade
            $table->jsonb('destinataires');             // ["a@novacorp.fr", ...]
            $table->jsonb('copies')->nullable();        // ["b@novacorp.fr", ...]
            $table->string('statut', 20)->default('a_envoyer'); // a_envoyer | envoye | echec
            $table->unsignedSmallInteger('tentatives')->default(0);
            $table->timestamp('prochain_essai_at')->nullable();
            $table->text('derniere_erreur')->nullable();
            $table->string('fournisseur_id')->nullable(); // id renvoyé par Resend
            $table->timestamp('envoye_at')->nullable();
            $table->timestamps();

            $table->index(['statut', 'prochain_essai_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
-- Une seule relance, une seule escalade, un seul mail de création par demande
CREATE UNIQUE INDEX mails_sortants_palier_unique
    ON public.mails_sortants (demande_id, type)
    WHERE type IN ('nouvelle_demande', 'relance', 'escalade');

ALTER TABLE public.mails_sortants ENABLE ROW LEVEL SECURITY;

-- Schéma privé : non exposé par l'API Supabase
CREATE SCHEMA IF NOT EXISTS automation;
REVOKE ALL ON SCHEMA automation FROM PUBLIC;

-- Nombre de jours ouvrés (lundi-vendredi, heure de Paris) écoulés depuis une date
CREATE OR REPLACE FUNCTION automation.jours_ouvres_depuis(debut timestamptz)
RETURNS integer LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT count(*)::integer
    FROM generate_series(
        (debut AT TIME ZONE 'Europe/Paris')::date + 1,
        (now() AT TIME ZONE 'Europe/Paris')::date,
        interval '1 day'
    ) AS jour
    WHERE extract(isodow FROM jour) < 6;
$$;

-- Trigger : nouvelle demande -> mail au manager
CREATE OR REPLACE FUNCTION automation.mail_nouvelle_demande()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
BEGIN
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
    SELECT NEW.id, 'nouvelle_demande', jsonb_build_array(m.email), '[]'::jsonb, now(), now()
    FROM public.users m
    WHERE m.id = NEW.manager_id
    ON CONFLICT DO NOTHING;
    RETURN NEW;
END;
$$;

CREATE TRIGGER demandes_mail_creation
    AFTER INSERT ON public.demandes
    FOR EACH ROW EXECUTE FUNCTION automation.mail_nouvelle_demande();

-- Trigger : validée / refusée -> mail à l'employé
CREATE OR REPLACE FUNCTION automation.mail_decision()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
BEGIN
    IF OLD.statut = 'en_attente' AND NEW.statut IN ('validee', 'refusee') THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        SELECT NEW.id, 'decision', jsonb_build_array(e.email), '[]'::jsonb, now(), now()
        FROM public.users e
        WHERE e.id = NEW.demandeur_id;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER demandes_mail_decision
    AFTER UPDATE OF statut ON public.demandes
    FOR EACH ROW EXECUTE FUNCTION automation.mail_decision();

-- Cron quotidien : relance après x jours ouvrés, escalade après y jours ouvrés
-- Escalade : RH actifs + N+2 (manager du manager), manager en copie.
CREATE OR REPLACE FUNCTION automation.planifier_relances(x integer DEFAULT 2, y integer DEFAULT 5)
RETURNS integer LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE
    total integer := 0;
    lignes integer;
BEGIN
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
    SELECT d.id, 'relance', jsonb_build_array(m.email), '[]'::jsonb, now(), now()
    FROM public.demandes d
    JOIN public.users m ON m.id = d.manager_id
    WHERE d.statut = 'en_attente'
      AND automation.jours_ouvres_depuis(coalesce(d.envoyee_at, d.created_at)) >= x
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS lignes = ROW_COUNT;
    total := total + lignes;

    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
    SELECT d.id, 'escalade',
        (SELECT coalesce(jsonb_agg(DISTINCT dest.email), '[]'::jsonb)
           FROM (SELECT u.email FROM public.users u
                   JOIN public.roles r ON r.id = u.role_id
                  WHERE r.slug = 'rh' AND u.actif
                 UNION
                 SELECT n2.email FROM public.users n2 WHERE n2.id = m.manager_id) AS dest),
        jsonb_build_array(m.email), now(), now()
    FROM public.demandes d
    JOIN public.users m ON m.id = d.manager_id
    WHERE d.statut = 'en_attente'
      AND automation.jours_ouvres_depuis(coalesce(d.envoyee_at, d.created_at)) >= y
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS lignes = ROW_COUNT;
    total := total + lignes;

    RETURN total;
END;
$$;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA automation FROM PUBLIC;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS demandes_mail_creation ON public.demandes;
DROP TRIGGER IF EXISTS demandes_mail_decision ON public.demandes;
DROP SCHEMA IF EXISTS automation CASCADE;
SQL);
        }

        Schema::dropIfExists('mails_sortants');

        Schema::table('demandes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decision_par');
            $table->dropUnique(['jeton_decision']);
            $table->dropColumn(['jeton_decision', 'decision_at']);
        });
    }
};
