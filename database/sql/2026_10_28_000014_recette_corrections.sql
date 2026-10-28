-- =====================================================================
-- Corrections issues de la recette du 28/10/2026 (docs/plan-de-tests.md, remarques R1, R2, R4)
--
-- R4 – « Corriger » (RH / admin) : la demande revient à l'étape 1
--      * motif obligatoire (côté Laravel), gardé dans commentaire_decision et copié dans l'historique ;
--      * mail « correction » au valideur de l'étape 1 (nouveaux liens Valider / Refuser) ;
--      * mail « correction_info » à l'employé (sa décision est annulée) ;
--      * relances / rappel / escalade de nouveau possibles après une correction (ou un complément) :
--        les paliers sont désormais uniques par étape ET par échéance.
-- R1 – Plus d'inscription libre : l'admin crée les comptes. Le mail de bienvenue contient un lien
--      d'activation (users.jeton_activation, 7 jours) pour choisir son mot de passe ; l'admin peut le renvoyer
--      (mail « activation »).
-- R2 – Retirer d'un projet un membre qui a des tâches non terminées : ses tâches passent « en pause »
--      (taches.pause_le / pause_motif / pause_ancien_responsable_id), sans relance ni expiration,
--      mises en avant pour le chef de projet qui les réattribue (→ « à faire », mail au nouveau responsable).
--      Sans réattribution après 3 jours ouvrés : un rappel au chef de projet.
-- Colonnes et transitions : migration Laravel 2026_10_28_000014_recette_corrections.php.
-- =====================================================================

-- ---------- Paliers de relance : par étape et par échéance ----------
UPDATE public.mails_sortants m
   SET donnees = coalesce(m.donnees, '{}'::jsonb) || jsonb_build_object('echeance', d.echeance_le)
  FROM public.demandes d
 WHERE d.id = m.demande_id
   AND m.type IN ('relance', 'rappel_echeance', 'escalade')
   AND NOT (coalesce(m.donnees, '{}'::jsonb) ? 'echeance');

DROP INDEX IF EXISTS public.mails_sortants_palier_etape_unique;
CREATE UNIQUE INDEX mails_sortants_palier_etape_unique
    ON public.mails_sortants (demande_id, type, (donnees ->> 'etape'), (donnees ->> 'echeance'))
    WHERE type IN ('relance', 'rappel_echeance', 'escalade');

CREATE UNIQUE INDEX IF NOT EXISTS mails_sortants_pause_unique
    ON public.mails_sortants (tache_id, type, (donnees ->> 'depuis'))
    WHERE type = 'tache_pause_relance';

-- ---------- Historique : motif de correction, mise en pause, réattribution ----------
CREATE OR REPLACE FUNCTION automation.historiser()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE
    v_etape smallint;
    v_commentaire text;
BEGIN
    IF TG_ARGV[0] = 'demande' THEN
        v_etape := NEW.etape;
        v_commentaire := CASE
            WHEN NEW.statut IN ('refusee', 'a_completer') THEN NEW.commentaire_decision
            WHEN TG_OP = 'UPDATE' AND OLD.statut IN ('validee', 'refusee') AND NEW.statut = 'en_attente'
                THEN 'Correction : ' || coalesce(NEW.commentaire_decision, '')
        END;
        IF TG_OP = 'UPDATE' AND NEW.statut IS NOT DISTINCT FROM OLD.statut AND NEW.etape IS NOT DISTINCT FROM OLD.etape THEN
            RETURN NEW;
        END IF;
    ELSE
        v_commentaire := CASE
            WHEN TG_OP = 'UPDATE' AND OLD.statut = 'a_valider' AND NEW.statut = 'en_cours' THEN NEW.commentaire_validation
            WHEN NEW.statut = 'en_pause' THEN NEW.pause_motif
            WHEN TG_OP = 'UPDATE' AND OLD.statut = 'en_pause' THEN
                'Réattribuée à ' || (SELECT u.prenom || ' ' || u.nom FROM public.users u WHERE u.id = NEW.responsable_id)
        END;
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

-- ---------- R4 : mails après une correction ----------
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
                jsonb_build_object('commentaire', CASE WHEN NEW.statut = 'refusee' THEN NEW.commentaire_decision END), now(), now());
        IF NEW.statut = 'validee' THEN
            SELECT t.role_traitement INTO role_traitement FROM public.types_demande t WHERE t.code = NEW.type;
            IF role_traitement IS NOT NULL THEN
                INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
                VALUES (NEW.id, 'a_traiter', automation.emails_role(role_traitement), '[]'::jsonb,
                        jsonb_build_object('service', role_traitement), now(), now());
            END IF;
        END IF;

    -- R4 : décision corrigée par la RH / l'admin → retour au valideur de l'étape 1, employé informé
    ELSIF OLD.statut IN ('validee', 'refusee') AND NEW.statut = 'en_attente' THEN
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'correction', automation.valideurs_etape(NEW.id), '[]'::jsonb,
                jsonb_build_object('etape', NEW.etape, 'ancien_statut', OLD.statut, 'commentaire', NEW.commentaire_decision,
                                   'par', NEW.derniere_action_par), now(), now());
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'correction_info', jsonb_build_array(e_email), '[]'::jsonb,
                jsonb_build_object('ancien_statut', OLD.statut, 'commentaire', NEW.commentaire_decision,
                                   'par', NEW.derniere_action_par), now(), now());

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

-- ---------- R2 : champs de pause tenus par la base ----------
CREATE OR REPLACE FUNCTION automation.controler_tache()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE chef bigint; mini date;
BEGIN
    -- Mise en pause / sortie de pause
    IF TG_OP = 'UPDATE' AND NEW.statut = 'en_pause' AND OLD.statut IS DISTINCT FROM 'en_pause' THEN
        NEW.pause_le := now();
        NEW.pause_ancien_responsable_id := coalesce(NEW.pause_ancien_responsable_id, OLD.responsable_id);
    ELSIF TG_OP = 'UPDATE' AND OLD.statut = 'en_pause' AND NEW.statut IS DISTINCT FROM 'en_pause' THEN
        -- (le nouveau responsable doit être membre : contrôlé juste après)
        NEW.pause_le := NULL;
        NEW.pause_motif := NULL;
        NEW.pause_ancien_responsable_id := NULL;
    END IF;

    -- Une tâche en pause n'a plus de responsable membre du projet : on ne contrôle pas l'appartenance
    IF NEW.projet_id IS NOT NULL AND NEW.statut IS DISTINCT FROM 'en_pause' THEN
        SELECT p.chef_projet_id INTO chef FROM public.projets p WHERE p.id = NEW.projet_id;
        IF NEW.responsable_id IS DISTINCT FROM chef AND NOT EXISTS (
            SELECT 1 FROM public.projet_user pu WHERE pu.projet_id = NEW.projet_id AND pu.user_id = NEW.responsable_id) THEN
            RAISE EXCEPTION 'Le responsable doit être membre du projet';
        END IF;
    ELSIF NEW.projet_id IS NOT NULL THEN
        SELECT p.chef_projet_id INTO chef FROM public.projets p WHERE p.id = NEW.projet_id;
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

-- ---------- R2 : mail au nouveau responsable après réattribution ----------
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

    -- Réattribution d'une tâche en pause : un seul mail au nouveau responsable (deadline comprise)
    IF OLD.statut = 'en_pause' AND NEW.statut IS DISTINCT FROM 'en_pause' THEN
        INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
        VALUES (NEW.id, 'tache_reattribuee', jsonb_build_array(resp_email), '[]'::jsonb,
                jsonb_build_object('deadline', NEW.deadline, 'ancien_responsable', OLD.pause_ancien_responsable_id), now(), now());
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

-- ---------- Relances : échéance dans la clé (R4) + rappel des tâches en pause (R2) ----------
CREATE OR REPLACE FUNCTION automation.planifier_rappels()
RETURNS integer LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE j date := automation.aujourdhui(); total integer := 0; n integer;
BEGIN
    -- Demandes : relance aux valideurs de l'étape en cours
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT d.id, 'relance', automation.valideurs_etape(d.id), '[]'::jsonb,
           jsonb_build_object('etape', d.etape, 'echeance', d.echeance_le), now(), now()
      FROM public.demandes d
     WHERE d.statut = 'en_attente' AND d.relance_le <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Demandes : rappel la veille (ouvrée) de l'échéance de l'étape
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT d.id, 'rappel_echeance', automation.valideurs_etape(d.id), '[]'::jsonb,
           jsonb_build_object('etape', d.etape, 'echeance', d.echeance_le), now(), now()
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
           jsonb_build_object('etape', d.etape, 'echeance', d.echeance_le), now(), now()
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

    -- R2 : tâches en pause depuis 3 jours ouvrés sans réattribution → rappel au chef de projet (une fois par pause)
    INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT t.id, 'tache_pause_relance', jsonb_build_array(c.email), '[]'::jsonb,
           jsonb_build_object('depuis', automation.date_paris(t.pause_le)), now(), now()
      FROM public.taches t JOIN public.projets p ON p.id = t.projet_id JOIN public.users c ON c.id = p.chef_projet_id
     WHERE t.statut = 'en_pause' AND t.pause_le IS NOT NULL
       AND automation.ajouter_jours_ouvres(automation.date_paris(t.pause_le), 3) <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    RETURN total;
END;
$$;
