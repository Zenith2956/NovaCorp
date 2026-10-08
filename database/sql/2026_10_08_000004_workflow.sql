-- =====================================================================
-- Workflow automatisé – partie PostgreSQL (Supabase)
-- Exécuté par la migration 2026_10_08_000004_workflow. Spécification : docs/propositions-workflow.md
-- =====================================================================

ALTER TABLE public.etapes_circuit ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.transitions    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.historique     ENABLE ROW LEVEL SECURITY;

-- Relances / rappels / escalades : une fois par demande ET par étape du circuit
DROP INDEX IF EXISTS public.mails_sortants_palier_unique;
CREATE UNIQUE INDEX mails_sortants_palier_unique
    ON public.mails_sortants (demande_id, type)
    WHERE type IN ('nouvelle_demande', 'expiration');
CREATE UNIQUE INDEX mails_sortants_palier_etape_unique
    ON public.mails_sortants (demande_id, type, (donnees ->> 'etape'))
    WHERE type IN ('relance', 'rappel_echeance', 'escalade');

-- ---------- Machine à états : seuls les passages listés dans « transitions » sont permis ----------
CREATE OR REPLACE FUNCTION automation.controler_transition()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
BEGIN
    IF NEW.statut IS DISTINCT FROM OLD.statut AND NOT EXISTS (
        SELECT 1 FROM public.transitions t
         WHERE t.objet = TG_ARGV[0] AND t.de = OLD.statut AND t.vers = NEW.statut) THEN
        RAISE EXCEPTION 'Transition interdite : % → %', OLD.statut, NEW.statut;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER demandes_transition BEFORE UPDATE OF statut ON public.demandes
    FOR EACH ROW EXECUTE FUNCTION automation.controler_transition('demande');
CREATE TRIGGER taches_transition BEFORE UPDATE OF statut ON public.taches
    FOR EACH ROW EXECUTE FUNCTION automation.controler_transition('tache');

-- ---------- Historique (création, changement de statut ou d'étape) ----------
CREATE OR REPLACE FUNCTION automation.historiser()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE
    v_etape smallint;
    v_commentaire text;
BEGIN
    IF TG_ARGV[0] = 'demande' THEN
        v_etape := NEW.etape;
        v_commentaire := CASE WHEN NEW.statut IN ('refusee', 'a_completer') THEN NEW.commentaire_decision END;
        IF TG_OP = 'UPDATE' AND NEW.statut IS NOT DISTINCT FROM OLD.statut AND NEW.etape IS NOT DISTINCT FROM OLD.etape THEN
            RETURN NEW;
        END IF;
    ELSE
        v_commentaire := CASE WHEN TG_OP = 'UPDATE' AND OLD.statut = 'a_valider' THEN NEW.commentaire_validation END;
        IF TG_OP = 'UPDATE' AND NEW.statut IS NOT DISTINCT FROM OLD.statut THEN
            RETURN NEW;
        END IF;
    END IF;

    INSERT INTO public.historique (objet, objet_id, ancien_statut, nouveau_statut, etape, auteur_id, canal, commentaire, created_at)
    VALUES (TG_ARGV[0], NEW.id, CASE WHEN TG_OP = 'UPDATE' THEN OLD.statut END, NEW.statut, v_etape,
            NEW.derniere_action_par, coalesce(NEW.derniere_action_canal, 'systeme'), v_commentaire, now());
    RETURN NEW;
END;
$$;

CREATE TRIGGER demandes_historique AFTER INSERT OR UPDATE ON public.demandes
    FOR EACH ROW EXECUTE FUNCTION automation.historiser('demande');
CREATE TRIGGER taches_historique AFTER INSERT OR UPDATE ON public.taches
    FOR EACH ROW EXECUTE FUNCTION automation.historiser('tache');

-- ---------- Étape courante et valideurs ----------
-- Étape d'ordre « ordre » du circuit du type (null si étape 1 implicite = manager)
CREATE OR REPLACE FUNCTION automation.valideurs_etape(p_demande bigint)
RETURNS jsonb LANGUAGE sql STABLE SET search_path = '' AS $$
    WITH d AS (SELECT * FROM public.demandes WHERE id = p_demande),
         e AS (SELECT ec.valideur FROM public.etapes_circuit ec, d
                WHERE ec.type_code = d.type AND ec.ordre = d.etape)
    SELECT CASE
        WHEN coalesce((SELECT valideur FROM e), 'manager') = 'manager' THEN
            (SELECT coalesce(jsonb_agg(m.email), '[]'::jsonb) FROM public.users m, d WHERE m.id = d.manager_id)
        ELSE
            (SELECT coalesce(jsonb_agg(u.email ORDER BY u.id), '[]'::jsonb)
               FROM public.users u JOIN public.roles r ON r.id = u.role_id
              WHERE r.slug = (SELECT valideur FROM e) AND u.actif)
    END;
$$;

CREATE OR REPLACE FUNCTION automation.emails_role(p_role text)
RETURNS jsonb LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT coalesce(jsonb_agg(u.email ORDER BY u.id), '[]'::jsonb)
      FROM public.users u JOIN public.roles r ON r.id = u.role_id
     WHERE r.slug = p_role AND u.actif;
$$;

-- Nombre de jours ouvrés d'une période (bornes incluses)
CREATE OR REPLACE FUNCTION automation.jours_ouvres_periode(debut date, fin date)
RETURNS integer LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT count(*)::integer FROM generate_series(debut, fin, interval '1 day') AS j
     WHERE automation.est_jour_ouvre(j::date);
$$;

-- ---------- Création : manager assigné automatiquement, jours de congé, dates ----------
CREATE OR REPLACE FUNCTION automation.calculer_dates_demande()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE base date; d_relance integer; d_escalade integer; normale date;
BEGIN
    -- Assignation automatique : le manager de l'employé, à défaut un membre de la direction
    NEW.manager_id := coalesce(NEW.manager_id,
        (SELECT u.manager_id FROM public.users u WHERE u.id = NEW.demandeur_id),
        (SELECT u.id FROM public.users u JOIN public.roles r ON r.id = u.role_id
          WHERE r.slug = 'direction' AND u.actif ORDER BY u.id LIMIT 1));
    NEW.etape := coalesce(NEW.etape, 1);
    IF NEW.date_debut IS NOT NULL AND NEW.date_fin IS NOT NULL THEN
        NEW.nb_jours_ouvres := automation.jours_ouvres_periode(NEW.date_debut, NEW.date_fin);
    END IF;

    SELECT t.delai_relance, t.delai_escalade INTO d_relance, d_escalade
      FROM public.types_demande t WHERE t.code = NEW.type;
    d_relance  := coalesce(d_relance, 2);
    d_escalade := coalesce(d_escalade, 5);
    base := automation.date_paris(coalesce(NEW.envoyee_at, NEW.created_at, now()::timestamp));

    NEW.relance_le  := coalesce(NEW.relance_le,  automation.ajouter_jours_ouvres(base, d_relance));
    normale         := automation.ajouter_jours_ouvres(base, d_escalade);
    NEW.echeance_le := coalesce(NEW.echeance_le, normale);

    IF NEW.date_souhaitee IS NOT NULL AND NEW.date_souhaitee <= normale THEN
        NEW.urgente     := true;
        NEW.echeance_le := greatest(base, automation.jour_ouvre_precedent(NEW.date_souhaitee));
        NEW.relance_le  := least(NEW.relance_le, NEW.echeance_le);
    END IF;

    NEW.deadline := coalesce(NEW.deadline, NEW.date_souhaitee, automation.ajouter_jours_ouvres(NEW.echeance_le, 5));
    RETURN NEW;
END;
$$;

-- ---------- Mails des demandes à chaque transition ----------
CREATE OR REPLACE FUNCTION automation.mail_decision()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE
    e_email text;
    role_traitement text;
    libelle_etape text;
BEGIN
    SELECT u.email INTO e_email FROM public.users u WHERE u.id = NEW.demandeur_id;

    -- Passage à l'étape suivante du circuit (statut toujours « en attente »)
    IF NEW.statut = 'en_attente' AND OLD.statut = 'en_attente' AND NEW.etape IS DISTINCT FROM OLD.etape THEN
        SELECT ec.libelle INTO libelle_etape FROM public.etapes_circuit ec WHERE ec.type_code = NEW.type AND ec.ordre = NEW.etape;
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'etape_suivante', automation.valideurs_etape(NEW.id), '[]'::jsonb,
                jsonb_build_object('etape', NEW.etape, 'libelle', libelle_etape), now(), now());
        RETURN NEW;
    END IF;

    IF NEW.statut IS NOT DISTINCT FROM OLD.statut THEN
        RETURN NEW;
    END IF;

    IF OLD.statut = 'en_attente' AND NEW.statut IN ('validee', 'refusee') THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'decision', jsonb_build_array(e_email), '[]'::jsonb,
                jsonb_build_object('commentaire', NEW.commentaire_decision), now(), now());
        IF NEW.statut = 'validee' THEN
            SELECT t.role_traitement INTO role_traitement FROM public.types_demande t WHERE t.code = NEW.type;
            IF role_traitement IS NOT NULL THEN
                INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
                VALUES (NEW.id, 'a_traiter', automation.emails_role(role_traitement), '[]'::jsonb,
                        jsonb_build_object('service', role_traitement), now(), now());
            END IF;
        END IF;

    ELSIF NEW.statut = 'a_completer' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'a_completer', jsonb_build_array(e_email), '[]'::jsonb,
                jsonb_build_object('commentaire', NEW.commentaire_decision, 'etape', NEW.etape), now(), now());

    ELSIF OLD.statut = 'a_completer' AND NEW.statut = 'en_attente' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'complement_recu', automation.valideurs_etape(NEW.id), '[]'::jsonb,
                jsonb_build_object('etape', NEW.etape), now(), now());

    ELSIF NEW.statut = 'annulee' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        VALUES (NEW.id, 'annulation', automation.valideurs_etape(NEW.id), '[]'::jsonb, now(), now());

    ELSIF NEW.statut = 'expiree' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        SELECT NEW.id, 'expiration', jsonb_build_array(e_email),
               (SELECT coalesce(jsonb_agg(DISTINCT x.email), '[]'::jsonb) FROM (
                    SELECT m.email FROM public.users m WHERE m.id = NEW.manager_id
                    UNION SELECT jsonb_array_elements_text(automation.emails_role('rh'))) AS x(email)),
               now(), now()
        ON CONFLICT DO NOTHING;

    ELSIF OLD.statut = 'en_traitement' AND NEW.statut = 'terminee' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        VALUES (NEW.id, 'traitement_termine', jsonb_build_array(e_email), '[]'::jsonb, now(), now());
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS demandes_mail_decision ON public.demandes;
CREATE TRIGGER demandes_mail_decision
    AFTER UPDATE OF statut, etape ON public.demandes
    FOR EACH ROW EXECUTE FUNCTION automation.mail_decision();

-- ---------- Tâches : mails (existants + validation par le chef de projet) ----------
CREATE OR REPLACE FUNCTION automation.mail_tache()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE chef_email text; resp_email text; sup_email text;
BEGIN
    SELECT u.email INTO resp_email FROM public.users u WHERE u.id = NEW.responsable_id;
    IF NEW.projet_id IS NOT NULL THEN
        SELECT u.email INTO chef_email
          FROM public.projets p JOIN public.users u ON u.id = p.chef_projet_id WHERE p.id = NEW.projet_id;
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.projet_id IS NOT NULL AND NEW.deadline IS NULL AND chef_email IS NOT NULL THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
            VALUES (NEW.id, 'tache_deadline_a_fixer', jsonb_build_array(chef_email), '[]'::jsonb, now(), now())
            ON CONFLICT DO NOTHING;
        ELSIF NEW.projet_id IS NOT NULL AND NEW.deadline IS NOT NULL AND NEW.responsable_id IS DISTINCT FROM NEW.cree_par THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
            VALUES (NEW.id, 'tache_deadline_fixee', jsonb_build_array(resp_email), '[]'::jsonb,
                    jsonb_build_object('deadline', NEW.deadline, 'assignation', true), now(), now());
        END IF;
        RETURN NEW;
    END IF;

    IF OLD.deadline IS NULL AND NEW.deadline IS NOT NULL THEN
        INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'tache_deadline_fixee', jsonb_build_array(resp_email), '[]'::jsonb,
                jsonb_build_object('deadline', NEW.deadline), now(), now());
    ELSIF OLD.deadline IS NOT NULL AND NEW.deadline IS DISTINCT FROM OLD.deadline THEN
        IF NEW.projet_id IS NULL THEN
            SELECT m.email INTO sup_email
              FROM public.users e JOIN public.users m ON m.id = e.manager_id WHERE e.id = NEW.responsable_id;
            IF sup_email IS NOT NULL THEN
                INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
                VALUES (NEW.id, 'tache_deadline_modifiee', jsonb_build_array(sup_email), '[]'::jsonb,
                        jsonb_build_object('ancienne', OLD.deadline, 'nouvelle', NEW.deadline), now(), now());
            END IF;
        ELSE
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
            VALUES (NEW.id, 'tache_deadline_fixee', jsonb_build_array(resp_email), '[]'::jsonb,
                    jsonb_build_object('deadline', NEW.deadline, 'ancienne', OLD.deadline), now(), now());
        END IF;
    END IF;

    IF NEW.statut IS DISTINCT FROM OLD.statut THEN
        IF NEW.statut = 'expiree' THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
            VALUES (NEW.id, 'tache_expiration', jsonb_build_array(resp_email),
                    CASE WHEN chef_email IS NOT NULL AND chef_email <> resp_email THEN jsonb_build_array(chef_email) ELSE '[]'::jsonb END,
                    jsonb_build_object('deadline', NEW.deadline), now(), now());
        ELSIF NEW.statut = 'a_valider' AND chef_email IS NOT NULL THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
            VALUES (NEW.id, 'tache_a_valider', jsonb_build_array(chef_email), '[]'::jsonb, now(), now());
        ELSIF OLD.statut = 'a_valider' AND NEW.statut = 'en_cours' THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
            VALUES (NEW.id, 'tache_renvoyee', jsonb_build_array(resp_email), '[]'::jsonb,
                    jsonb_build_object('commentaire', NEW.commentaire_validation), now(), now());
        ELSIF OLD.statut = 'a_valider' AND NEW.statut = 'terminee' THEN
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
            VALUES (NEW.id, 'tache_validee', jsonb_build_array(resp_email), '[]'::jsonb, now(), now());
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

-- ---------- Cron : expiration (inclut « à compléter »), auteur = cron ----------
CREATE OR REPLACE FUNCTION automation.expirer()
RETURNS integer LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE total integer := 0; n integer;
BEGIN
    UPDATE public.demandes
       SET statut = 'expiree', jeton_decision = NULL, derniere_action_par = NULL, derniere_action_canal = 'cron', updated_at = now()
     WHERE statut IN ('en_attente', 'a_completer') AND deadline < automation.aujourdhui();
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    UPDATE public.taches
       SET statut = 'expiree', derniere_action_par = NULL, derniere_action_canal = 'cron', updated_at = now()
     WHERE statut IN ('a_faire', 'en_cours') AND deadline < automation.aujourdhui();
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;
    RETURN total;
END;
$$;

-- ---------- Cron de 9 h : relances / rappels / escalades à l'étape en cours ----------
CREATE OR REPLACE FUNCTION automation.planifier_rappels()
RETURNS integer LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE j date := automation.aujourdhui(); total integer := 0; n integer;
BEGIN
    -- Demandes : relance aux valideurs de l'étape en cours
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT d.id, 'relance', automation.valideurs_etape(d.id), '[]'::jsonb, jsonb_build_object('etape', d.etape), now(), now()
      FROM public.demandes d
     WHERE d.statut = 'en_attente' AND d.relance_le <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Demandes : rappel la veille (ouvrée) de l'échéance de l'étape
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT d.id, 'rappel_echeance', automation.valideurs_etape(d.id), '[]'::jsonb, jsonb_build_object('etape', d.etape), now(), now()
      FROM public.demandes d
     WHERE d.statut = 'en_attente' AND d.echeance_le > j
       AND automation.jour_ouvre_precedent(d.echeance_le) <= j AND d.relance_le < j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Demandes : escalade à l'échéance de l'étape
    --   étape « manager » : RH + N+2, manager en copie ; étape « service » : direction, service en copie
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT d.id, 'escalade',
           CASE WHEN coalesce(ec.valideur, 'manager') = 'manager' THEN
                (SELECT coalesce(jsonb_agg(DISTINCT x.email), '[]'::jsonb) FROM (
                     SELECT jsonb_array_elements_text(automation.emails_role('rh'))
                     UNION SELECT n2.email FROM public.users m JOIN public.users n2 ON n2.id = m.manager_id WHERE m.id = d.manager_id
                 ) AS x(email))
                ELSE automation.emails_role('direction') END,
           automation.valideurs_etape(d.id),
           jsonb_build_object('etape', d.etape), now(), now()
      FROM public.demandes d
      LEFT JOIN public.etapes_circuit ec ON ec.type_code = d.type AND ec.ordre = d.etape
     WHERE d.statut = 'en_attente' AND d.echeance_le <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Tâches de projet sans deadline : relance du chef de projet (2 j ouvrés)
    INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
    SELECT t.id, 'tache_relance_deadline', jsonb_build_array(c.email), '[]'::jsonb, now(), now()
      FROM public.taches t JOIN public.projets p ON p.id = t.projet_id JOIN public.users c ON c.id = p.chef_projet_id
     WHERE t.deadline IS NULL AND t.statut IN ('a_faire', 'en_cours')
       AND automation.ajouter_jours_ouvres(automation.date_paris(t.created_at), 2) <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- ... puis escalade au supérieur du chef de projet (5 j ouvrés), RH à défaut ; chef en copie
    INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, created_at, updated_at)
    SELECT t.id, 'tache_escalade_deadline',
           coalesce((SELECT jsonb_build_array(s.email) FROM public.users s WHERE s.id = c.manager_id), automation.emails_role('rh')),
           jsonb_build_array(c.email), now(), now()
      FROM public.taches t JOIN public.projets p ON p.id = t.projet_id JOIN public.users c ON c.id = p.chef_projet_id
     WHERE t.deadline IS NULL AND t.statut IN ('a_faire', 'en_cours')
       AND automation.ajouter_jours_ouvres(automation.date_paris(t.created_at), 5) <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Tâches : rappel la veille (ouvrée) de la deadline au responsable, chef de projet en copie
    INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT t.id, 'tache_rappel', jsonb_build_array(r.email),
           CASE WHEN c.email IS NOT NULL AND c.email <> r.email THEN jsonb_build_array(c.email) ELSE '[]'::jsonb END,
           jsonb_build_object('deadline', t.deadline), now(), now()
      FROM public.taches t
      JOIN public.users r ON r.id = t.responsable_id
      LEFT JOIN public.projets p ON p.id = t.projet_id
      LEFT JOIN public.users c ON c.id = p.chef_projet_id
     WHERE t.statut IN ('a_faire', 'en_cours') AND t.deadline IS NOT NULL
       AND t.deadline >= j AND automation.jour_ouvre_precedent(t.deadline) <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    RETURN total;
END;
$$;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA automation FROM PUBLIC;

-- Demandes existantes : étape 1, nombre de jours pour les congés datés
UPDATE public.demandes SET etape = 1 WHERE etape IS NULL;
