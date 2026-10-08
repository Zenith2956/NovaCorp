-- =====================================================================
-- Gestion des délais + module Tâches – partie PostgreSQL (Supabase)
-- Exécuté par la migration 2026_10_08_000003_gestion_delais_et_taches.
-- =====================================================================

-- ---------- Boîte d'envoi : un mail concerne une demande OU une tâche ----------
ALTER TABLE public.mails_sortants
    ADD CONSTRAINT mails_sortants_cible_check CHECK (demande_id IS NOT NULL OR tache_id IS NOT NULL);

DROP INDEX IF EXISTS public.mails_sortants_palier_unique;
CREATE UNIQUE INDEX mails_sortants_palier_unique
    ON public.mails_sortants (demande_id, type)
    WHERE type IN ('nouvelle_demande', 'relance', 'escalade', 'rappel_echeance', 'expiration');
CREATE UNIQUE INDEX mails_sortants_palier_tache_unique
    ON public.mails_sortants (tache_id, type)
    WHERE type IN ('tache_deadline_a_fixer', 'tache_relance_deadline', 'tache_escalade_deadline');
-- Un seul rappel par tâche et par valeur de deadline (une deadline reportée a droit à son rappel)
CREATE UNIQUE INDEX mails_sortants_rappel_tache_unique
    ON public.mails_sortants (tache_id, type, (donnees ->> 'deadline'))
    WHERE type = 'tache_rappel';

ALTER TABLE public.jours_feries  ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.types_demande ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.taches        ENABLE ROW LEVEL SECURITY;

-- ---------- Calendrier ----------
CREATE OR REPLACE FUNCTION automation.aujourdhui()
RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT (now() AT TIME ZONE 'Europe/Paris')::date;
$$;

-- Date de Pâques (algorithme de Meeus / Jones / Butcher)
CREATE OR REPLACE FUNCTION automation.paques(annee integer)
RETURNS date LANGUAGE plpgsql IMMUTABLE SET search_path = '' AS $$
DECLARE a int; b int; c int; d int; e int; f int; g int; h int; i int; k int; l int; m int;
BEGIN
    a := annee % 19; b := annee / 100; c := annee % 100; d := b / 4; e := b % 4;
    f := (b + 8) / 25; g := (b - f + 1) / 3; h := (19 * a + b - d - g + 15) % 30;
    i := c / 4; k := c % 4; l := (32 + 2 * e + 2 * i - h - k) % 7; m := (a + 11 * h + 22 * l) / 451;
    RETURN make_date(annee, (h + l - 7 * m + 114) / 31, ((h + l - 7 * m + 114) % 31) + 1);
END;
$$;

-- Jours fériés français d'une année (sans doublon)
CREATE OR REPLACE FUNCTION automation.remplir_jours_feries(annee integer)
RETURNS integer LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE p date := automation.paques(annee); n integer;
BEGIN
    INSERT INTO public.jours_feries (jour, libelle, created_at, updated_at)
    SELECT v.jour, v.libelle, now(), now() FROM (VALUES
        (make_date(annee, 1, 1),   'Jour de l''an'),
        (p + 1,                    'Lundi de Pâques'),
        (make_date(annee, 5, 1),   'Fête du travail'),
        (make_date(annee, 5, 8),   'Victoire 1945'),
        (p + 39,                   'Ascension'),
        (p + 50,                   'Lundi de Pentecôte'),
        (make_date(annee, 7, 14),  'Fête nationale'),
        (make_date(annee, 8, 15),  'Assomption'),
        (make_date(annee, 11, 1),  'Toussaint'),
        (make_date(annee, 11, 11), 'Armistice 1918'),
        (make_date(annee, 12, 25), 'Noël')) AS v(jour, libelle)
    ON CONFLICT (jour) DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END;
$$;

CREATE OR REPLACE FUNCTION automation.est_jour_ouvre(d date)
RETURNS boolean LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT extract(isodow FROM d) < 6
       AND NOT EXISTS (SELECT 1 FROM public.jours_feries f WHERE f.jour = d);
$$;

-- n jours ouvrés après une date (n = 0 : la date elle-même)
CREATE OR REPLACE FUNCTION automation.ajouter_jours_ouvres(depart date, n integer)
RETURNS date LANGUAGE plpgsql STABLE SET search_path = '' AS $$
DECLARE d date := depart; restant integer := n;
BEGIN
    WHILE restant > 0 LOOP
        d := d + 1;
        IF automation.est_jour_ouvre(d) THEN restant := restant - 1; END IF;
    END LOOP;
    RETURN d;
END;
$$;

CREATE OR REPLACE FUNCTION automation.jour_ouvre_precedent(depart date)
RETURNS date LANGUAGE plpgsql STABLE SET search_path = '' AS $$
DECLARE d date := depart - 1;
BEGIN
    WHILE NOT automation.est_jour_ouvre(d) LOOP d := d - 1; END LOOP;
    RETURN d;
END;
$$;

-- Version qui exclut désormais les jours fériés (utilisée par planifier_relances)
CREATE OR REPLACE FUNCTION automation.jours_ouvres_depuis(debut timestamptz)
RETURNS integer LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT count(*)::integer
    FROM generate_series((debut AT TIME ZONE 'Europe/Paris')::date + 1, automation.aujourdhui(), interval '1 day') AS jour
    WHERE automation.est_jour_ouvre(jour::date);
$$;

-- Date « locale Paris » d'une colonne timestamp stockée en UTC
CREATE OR REPLACE FUNCTION automation.date_paris(ts timestamp)
RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT ((ts AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Paris')::date;
$$;

-- ---------- Demandes : relance, échéance, deadline calculées à la création ----------
CREATE OR REPLACE FUNCTION automation.calculer_dates_demande()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE base date; d_relance integer; d_escalade integer; normale date;
BEGIN
    SELECT t.delai_relance, t.delai_escalade INTO d_relance, d_escalade
      FROM public.types_demande t WHERE t.code = NEW.type;
    d_relance  := coalesce(d_relance, 2);
    d_escalade := coalesce(d_escalade, 5);
    base := automation.date_paris(coalesce(NEW.envoyee_at, NEW.created_at, now()::timestamp));

    NEW.relance_le  := coalesce(NEW.relance_le,  automation.ajouter_jours_ouvres(base, d_relance));
    normale         := automation.ajouter_jours_ouvres(base, d_escalade);
    NEW.echeance_le := coalesce(NEW.echeance_le, normale);

    -- Date souhaitée plus proche que l'échéance normale : demande urgente
    IF NEW.date_souhaitee IS NOT NULL AND NEW.date_souhaitee <= normale THEN
        NEW.urgente     := true;
        NEW.echeance_le := greatest(base, automation.jour_ouvre_precedent(NEW.date_souhaitee));
        NEW.relance_le  := least(NEW.relance_le, NEW.echeance_le);
    END IF;

    NEW.deadline := coalesce(NEW.deadline, NEW.date_souhaitee, automation.ajouter_jours_ouvres(NEW.echeance_le, 5));
    RETURN NEW;
END;
$$;

CREATE TRIGGER demandes_dates
    BEFORE INSERT ON public.demandes
    FOR EACH ROW EXECUTE FUNCTION automation.calculer_dates_demande();

-- Mails sur changement de statut : décision (existant) + expiration (nouveau)
CREATE OR REPLACE FUNCTION automation.mail_decision()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
BEGIN
    IF OLD.statut = 'en_attente' AND NEW.statut IN ('validee', 'refusee') THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        SELECT NEW.id, 'decision', jsonb_build_array(e.email), '[]'::jsonb, now(), now()
        FROM public.users e WHERE e.id = NEW.demandeur_id;
    ELSIF OLD.statut = 'en_attente' AND NEW.statut = 'expiree' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
        SELECT NEW.id, 'expiration', jsonb_build_array(e.email),
               (SELECT coalesce(jsonb_agg(DISTINCT x.email), '[]'::jsonb) FROM (
                    SELECT m.email FROM public.users m WHERE m.id = NEW.manager_id
                    UNION
                    SELECT u.email FROM public.users u JOIN public.roles r ON r.id = u.role_id
                     WHERE r.slug = 'rh' AND u.actif) AS x),
               now(), now()
        FROM public.users e WHERE e.id = NEW.demandeur_id
        ON CONFLICT DO NOTHING;
    END IF;
    RETURN NEW;
END;
$$;

-- ---------- Tâches : règles de deadline ----------
CREATE OR REPLACE FUNCTION automation.controler_tache()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE chef bigint; mini date;
BEGIN
    IF NEW.projet_id IS NOT NULL THEN
        SELECT p.chef_projet_id INTO chef FROM public.projets p WHERE p.id = NEW.projet_id;
        IF NEW.responsable_id IS DISTINCT FROM chef AND NOT EXISTS (
            SELECT 1 FROM public.projet_user pu WHERE pu.projet_id = NEW.projet_id AND pu.user_id = NEW.responsable_id) THEN
            RAISE EXCEPTION 'Le responsable doit être membre du projet';
        END IF;
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.projet_id IS NULL THEN
            -- Tâche hors projet : deadline libre, choisie par l'employé
            IF NEW.deadline IS NULL THEN RAISE EXCEPTION 'Une tâche hors projet doit avoir une deadline'; END IF;
            IF NEW.deadline < automation.aujourdhui() THEN RAISE EXCEPTION 'La deadline doit être aujourd''hui ou plus tard'; END IF;
            NEW.deadline_fixee_par := coalesce(NEW.deadline_fixee_par, NEW.cree_par);
            NEW.deadline_fixee_at  := now();
        ELSIF NEW.deadline IS NOT NULL THEN
            -- Tâche assignée par le chef de projet : au moins création + 5 jours ouvrés
            mini := automation.ajouter_jours_ouvres(automation.aujourdhui(), 5);
            IF NEW.deadline < mini THEN
                RAISE EXCEPTION 'Deadline de projet : au plus tôt le % (création + 5 jours ouvrés)', to_char(mini, 'DD/MM/YYYY');
            END IF;
            NEW.deadline_fixee_par := coalesce(NEW.deadline_fixee_par, chef);
            NEW.deadline_fixee_at  := now();
        ELSE
            -- Tâche créée par un membre : le chef de projet fixera la deadline (lien à usage unique)
            NEW.jeton_deadline := coalesce(NEW.jeton_deadline, gen_random_uuid());
        END IF;
        NEW.deadline_initiale := NEW.deadline;

    ELSIF NEW.deadline IS DISTINCT FROM OLD.deadline THEN
        IF NEW.deadline IS NULL THEN RAISE EXCEPTION 'La deadline ne peut pas être retirée'; END IF;

        IF OLD.deadline IS NULL THEN
            -- Première deadline d'une tâche de projet : création + 5 jours ouvrés minimum
            IF NEW.projet_id IS NOT NULL THEN
                mini := automation.ajouter_jours_ouvres(automation.date_paris(OLD.created_at), 5);
                IF NEW.deadline < mini THEN
                    RAISE EXCEPTION 'Deadline de projet : au plus tôt le % (création + 5 jours ouvrés)', to_char(mini, 'DD/MM/YYYY');
                END IF;
            END IF;
            NEW.deadline_initiale := NEW.deadline;
            NEW.deadline_fixee_at := now();
            NEW.jeton_deadline    := NULL;
        ELSE
            -- Modification : projet = report d'au moins 1 jour ; hors projet = libre (future)
            IF NEW.projet_id IS NOT NULL AND NEW.deadline < OLD.deadline + 1 THEN
                RAISE EXCEPTION 'Une deadline de projet ne peut être que repoussée (au moins 1 jour)';
            END IF;
            IF NEW.deadline < automation.aujourdhui() THEN
                RAISE EXCEPTION 'La deadline doit être aujourd''hui ou plus tard';
            END IF;
            IF NEW.deadline > OLD.deadline THEN NEW.nb_reports := OLD.nb_reports + 1; END IF;
            -- Une tâche expirée dont la deadline est repoussée redevient « à faire »
            IF OLD.statut = 'expiree' AND NEW.statut = 'expiree' THEN NEW.statut := 'a_faire'; END IF;
        END IF;
    END IF;

    IF NEW.statut = 'terminee' AND (TG_OP = 'INSERT' OR OLD.statut IS DISTINCT FROM 'terminee') THEN
        NEW.termine_at := now();
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER taches_controle
    BEFORE INSERT OR UPDATE ON public.taches
    FOR EACH ROW EXECUTE FUNCTION automation.controler_tache();

-- ---------- Tâches : mails ----------
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
            -- Tâche assignée par le chef de projet
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
            -- Hors projet : le supérieur de l'employé est prévenu
            SELECT m.email INTO sup_email
              FROM public.users e JOIN public.users m ON m.id = e.manager_id WHERE e.id = NEW.responsable_id;
            IF sup_email IS NOT NULL THEN
                INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
                VALUES (NEW.id, 'tache_deadline_modifiee', jsonb_build_array(sup_email), '[]'::jsonb,
                        jsonb_build_object('ancienne', OLD.deadline, 'nouvelle', NEW.deadline), now(), now());
            END IF;
        ELSE
            -- Projet : le responsable est prévenu du report
            INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
            VALUES (NEW.id, 'tache_deadline_fixee', jsonb_build_array(resp_email), '[]'::jsonb,
                    jsonb_build_object('deadline', NEW.deadline, 'ancienne', OLD.deadline), now(), now());
        END IF;
    END IF;

    IF OLD.statut IS DISTINCT FROM 'expiree' AND NEW.statut = 'expiree' THEN
        INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'tache_expiration', jsonb_build_array(resp_email),
                CASE WHEN chef_email IS NOT NULL AND chef_email <> resp_email THEN jsonb_build_array(chef_email) ELSE '[]'::jsonb END,
                jsonb_build_object('deadline', NEW.deadline), now(), now());
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER taches_mails
    AFTER INSERT OR UPDATE ON public.taches
    FOR EACH ROW EXECUTE FUNCTION automation.mail_tache();

-- ---------- Fonctions appelées par les crons (planifiés à l'étape 5) ----------

-- Expiration : demandes en attente et tâches non terminées dont la deadline est passée
CREATE OR REPLACE FUNCTION automation.expirer()
RETURNS integer LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE total integer := 0; n integer;
BEGIN
    UPDATE public.demandes SET statut = 'expiree', jeton_decision = NULL, updated_at = now()
     WHERE statut = 'en_attente' AND deadline < automation.aujourdhui();
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    UPDATE public.taches SET statut = 'expiree', updated_at = now()
     WHERE statut IN ('a_faire', 'en_cours') AND deadline < automation.aujourdhui();
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;
    RETURN total;
END;
$$;

-- Relances, rappels et escalades à partir des dates stockées (remplacera planifier_relances)
CREATE OR REPLACE FUNCTION automation.planifier_rappels()
RETURNS integer LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE j date := automation.aujourdhui(); total integer := 0; n integer;
BEGIN
    -- Demandes : relance
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
    SELECT d.id, 'relance', jsonb_build_array(m.email), '[]'::jsonb, now(), now()
      FROM public.demandes d JOIN public.users m ON m.id = d.manager_id
     WHERE d.statut = 'en_attente' AND d.relance_le <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Demandes : rappel la veille (ouvrée) de l'échéance, si la relance est déjà passée
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
    SELECT d.id, 'rappel_echeance', jsonb_build_array(m.email), '[]'::jsonb, now(), now()
      FROM public.demandes d JOIN public.users m ON m.id = d.manager_id
     WHERE d.statut = 'en_attente' AND d.echeance_le > j
       AND automation.jour_ouvre_precedent(d.echeance_le) <= j AND d.relance_le < j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Demandes : escalade à l'échéance (RH + N+2, manager en copie)
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, created_at, updated_at)
    SELECT d.id, 'escalade',
           (SELECT coalesce(jsonb_agg(DISTINCT x.email), '[]'::jsonb) FROM (
                SELECT u.email FROM public.users u JOIN public.roles r ON r.id = u.role_id WHERE r.slug = 'rh' AND u.actif
                UNION SELECT n2.email FROM public.users n2 WHERE n2.id = m.manager_id) AS x),
           jsonb_build_array(m.email), now(), now()
      FROM public.demandes d JOIN public.users m ON m.id = d.manager_id
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
           coalesce((SELECT jsonb_build_array(s.email) FROM public.users s WHERE s.id = c.manager_id),
                    (SELECT jsonb_agg(u.email) FROM public.users u JOIN public.roles r ON r.id = u.role_id WHERE r.slug = 'rh' AND u.actif)),
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

-- ---------- Données initiales ----------
SELECT automation.remplir_jours_feries(extract(year FROM now())::integer);
SELECT automation.remplir_jours_feries(extract(year FROM now())::integer + 1);

-- Dates des demandes existantes
UPDATE public.demandes d
   SET relance_le  = automation.ajouter_jours_ouvres(automation.date_paris(coalesce(d.envoyee_at, d.created_at)), coalesce(t.delai_relance, 2)),
       echeance_le = automation.ajouter_jours_ouvres(automation.date_paris(coalesce(d.envoyee_at, d.created_at)), coalesce(t.delai_escalade, 5))
  FROM public.demandes d2 LEFT JOIN public.types_demande t ON t.code = d2.type
 WHERE d2.id = d.id AND d.echeance_le IS NULL;
UPDATE public.demandes SET deadline = automation.ajouter_jours_ouvres(echeance_le, 5) WHERE deadline IS NULL;
