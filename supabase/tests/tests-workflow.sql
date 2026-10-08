-- =====================================================================
-- NovaCorp – suite de tests du workflow (circuits, transitions, historique, mails, relances, tâches, statistiques)
-- À exécuter dans Supabase > SQL Editor. Rien n'est conservé : tout est annulé à la fin
-- (le résultat s'affiche comme une « erreur » « TESTS WORKFLOW … x / y OK », c'est voulu).
-- Données de test créées à la volée ; « aujourd'hui » simulé (lundi 09/11/2026, le 11/11 est férié).
-- Partie statistiques : seulement si la migration 2026_10_08_000006 (stats) est appliquée.
-- =====================================================================
DO $suite$
DECLARE
    u_dir bigint; u_man bigint; u_man2 bigint; u_dev bigint; u_dev2 bigint; u_rh bigint; u_compta bigint; u_seul bigint;
    p bigint; d bigint; d2 bigint; t bigint; x record; v jsonb; msg text;
    a_rh uuid := gen_random_uuid(); a_man uuid := gen_random_uuid(); a_dev uuid := gen_random_uuid();
    nb_ok int := 0; nb_ko int := 0; echecs text := ''; stats_installees boolean;
BEGIN
    -- ---------- outil d'assertion ----------
    CREATE TEMP TABLE resultats (code text, libelle text, obtenu text, attendu text);
    GRANT ALL ON resultats TO authenticated, anon;   -- les tests d'accès se font sous ces rôles
    CREATE FUNCTION pg_temp.verif(code text, libelle text, obtenu anyelement, attendu anyelement) RETURNS void
    LANGUAGE sql AS $f$ INSERT INTO resultats VALUES (code, libelle, obtenu::text, attendu::text) $f$;

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-09' $f$;

    -- ---------- données de test ----------
    INSERT INTO public.users (nom, prenom, email, password, role_id, actif, created_at, updated_at)
        VALUES ('Test', 'Direction', 'test.dir@test.local', 'x', (SELECT id FROM public.roles WHERE slug = 'direction'), true, now(), now()) RETURNING id INTO u_dir;
    INSERT INTO public.users (nom, prenom, email, password, role_id, manager_id, actif, created_at, updated_at)
        VALUES ('Test', 'Manager', 'test.man@test.local', 'x', (SELECT id FROM public.roles WHERE slug = 'manager'), u_dir, true, now(), now()) RETURNING id INTO u_man;
    INSERT INTO public.users (nom, prenom, email, password, role_id, manager_id, actif, created_at, updated_at)
        VALUES ('Test', 'Manager2', 'test.man2@test.local', 'x', (SELECT id FROM public.roles WHERE slug = 'manager'), u_dir, true, now(), now()) RETURNING id INTO u_man2;
    INSERT INTO public.users (nom, prenom, email, password, role_id, manager_id, actif, created_at, updated_at)
        VALUES ('Test', 'Dev', 'test.dev@test.local', 'x', (SELECT id FROM public.roles WHERE slug = 'dev'), u_man, true, now(), now()) RETURNING id INTO u_dev;
    INSERT INTO public.users (nom, prenom, email, password, role_id, manager_id, actif, created_at, updated_at)
        VALUES ('Test', 'Dev2', 'test.dev2@test.local', 'x', (SELECT id FROM public.roles WHERE slug = 'dev'), u_man2, true, now(), now()) RETURNING id INTO u_dev2;
    INSERT INTO public.users (nom, prenom, email, password, role_id, actif, created_at, updated_at)
        VALUES ('Test', 'SansManager', 'test.seul@test.local', 'x', (SELECT id FROM public.roles WHERE slug = 'dev'), true, now(), now()) RETURNING id INTO u_seul;
    INSERT INTO public.users (nom, prenom, email, password, role_id, actif, created_at, updated_at)
        VALUES ('Test', 'RH', 'test.rh@test.local', 'x', (SELECT id FROM public.roles WHERE slug = 'rh'), true, now(), now()) RETURNING id INTO u_rh;
    INSERT INTO public.users (nom, prenom, email, password, role_id, actif, created_at, updated_at)
        VALUES ('Test', 'Compta', 'test.compta@test.local', 'x', (SELECT id FROM public.roles WHERE slug = 'comptable'), true, now(), now()) RETURNING id INTO u_compta;
    INSERT INTO public.projets (nom, statut, chef_projet_id, created_at, updated_at)
        VALUES ('Projet de test workflow', 'en_cours', u_man, now(), now()) RETURNING id INTO p;
    INSERT INTO public.projet_user (projet_id, user_id) VALUES (p, u_dev);

    -- =================================================================
    -- W. Création : assignation automatique, jours de congé, historique, premier mail
    -- =================================================================
    INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, date_debut, date_fin, derniere_action_par, derniere_action_canal, created_at, updated_at)
        VALUES (u_dev, 'conge', 'Congé long', 'x', 'en_attente', '2026-11-16', '2026-11-25', u_dev, 'appli', now(), now()) RETURNING id INTO d;
    SELECT * INTO x FROM public.demandes WHERE id = d;
    PERFORM pg_temp.verif('W1', 'manager assigné automatiquement (manager de l''employé)', x.manager_id, u_man);
    PERFORM pg_temp.verif('W2', 'congé du 16/11 au 25/11 = 8 jours ouvrés', x.nb_jours_ouvres::int, 8);
    PERFORM pg_temp.verif('W3', 'étape 1 au départ', x.etape::int, 1);
    PERFORM pg_temp.verif('W4', 'historique : création par l''employé, depuis l''appli',
        (SELECT coalesce(ancien_statut, '∅') || ' → ' || nouveau_statut || ' / ' || (auteur_id = u_dev) || ' / ' || canal
           FROM public.historique WHERE objet = 'demande' AND objet_id = d), '∅ → en_attente / true / appli');
    PERFORM pg_temp.verif('W5', 'mail « nouvelle demande » au manager',
        (SELECT destinataires ->> 0 FROM public.mails_sortants WHERE demande_id = d AND type = 'nouvelle_demande'), 'test.man@test.local');
    PERFORM pg_temp.verif('W6', 'congé du 21/12 au 01/01 = 8 jours ouvrés (Noël et 1er janvier exclus)',
        automation.jours_ouvres_periode('2026-12-21', '2027-01-01'), 8);
    INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, created_at, updated_at)
        VALUES (u_seul, 'autre', 'Sans manager', 'x', 'en_attente', now(), now()) RETURNING id INTO d2;
    PERFORM pg_temp.verif('W7', 'employé sans manager : demande assignée à la direction',
        (SELECT manager_id FROM public.demandes WHERE id = d2),
        (SELECT min(u.id) FROM public.users u JOIN public.roles r ON r.id = u.role_id WHERE r.slug = 'direction' AND u.actif));

    -- =================================================================
    -- T. Machine à états
    -- =================================================================
    BEGIN UPDATE public.demandes SET statut = 'terminee' WHERE id = d2; msg := 'acceptée';
    EXCEPTION WHEN others THEN msg := SQLERRM; END;
    PERFORM pg_temp.verif('T1', 'en attente → terminée interdit', msg, 'Transition interdite : en_attente → terminee');
    UPDATE public.demandes SET statut = 'annulee', derniere_action_par = u_seul, derniere_action_canal = 'appli' WHERE id = d2;
    BEGIN UPDATE public.demandes SET statut = 'en_attente' WHERE id = d2; msg := 'acceptée';
    EXCEPTION WHEN others THEN msg := SQLERRM; END;
    PERFORM pg_temp.verif('T2', 'annulée → en attente interdit', msg, 'Transition interdite : annulee → en_attente');
    PERFORM pg_temp.verif('T3', 'annulation : mail aux valideurs de l''étape',
        (SELECT count(*) FROM public.mails_sortants WHERE demande_id = d2 AND type = 'annulation'), 1::bigint);
    PERFORM pg_temp.verif('T4', '25 transitions déclarées', (SELECT count(*) FROM public.transitions), 25::bigint);

    -- =================================================================
    -- C. Circuit congé > 5 jours : manager → RH → traitement RH
    -- =================================================================
    UPDATE public.demandes SET etape = 2, derniere_action_par = u_man, derniere_action_canal = 'mail' WHERE id = d;
    SELECT * INTO x FROM public.mails_sortants WHERE demande_id = d AND type = 'etape_suivante';
    PERFORM pg_temp.verif('C1', 'étape 2 : mail « étape suivante » à tout le service RH', x.destinataires ? 'test.rh@test.local', true);
    PERFORM pg_temp.verif('C2', 'étape 2 : le manager n''est pas destinataire', x.destinataires ? 'test.man@test.local', false);
    PERFORM pg_temp.verif('C3', 'étape 2 : données du mail', x.donnees ->> 'libelle', 'Ressources humaines');
    PERFORM pg_temp.verif('C4', 'historique : étape 2 par le manager, via le mail',
        (SELECT etape || ' / ' || (auteur_id = u_man) || ' / ' || canal FROM public.historique
          WHERE objet = 'demande' AND objet_id = d ORDER BY id DESC LIMIT 1), '2 / true / mail');
    UPDATE public.demandes SET statut = 'validee', decision_at = now(), decision_par = u_rh, derniere_action_par = u_rh, derniere_action_canal = 'appli' WHERE id = d;
    PERFORM pg_temp.verif('C5', 'validée : mail « décision » à l''employé, sans commentaire',
        (SELECT (destinataires ->> 0) || ' / ' || coalesce(donnees ->> 'commentaire', '∅') FROM public.mails_sortants WHERE demande_id = d AND type = 'decision'),
        'test.dev@test.local / ∅');
    PERFORM pg_temp.verif('C6', 'validée : mail « à traiter » au service RH',
        (SELECT destinataires ? 'test.rh@test.local' FROM public.mails_sortants WHERE demande_id = d AND type = 'a_traiter'), true);
    UPDATE public.demandes SET statut = 'en_traitement', traite_par = u_rh WHERE id = d;
    UPDATE public.demandes SET statut = 'terminee', traite_at = now() WHERE id = d;
    PERFORM pg_temp.verif('C7', 'terminée : mail « traitement terminé » à l''employé',
        (SELECT destinataires ->> 0 FROM public.mails_sortants WHERE demande_id = d AND type = 'traitement_termine'), 'test.dev@test.local');
    PERFORM pg_temp.verif('C8', 'historique complet (5 lignes)',
        (SELECT string_agg(nouveau_statut, ' > ' ORDER BY id) FROM public.historique WHERE objet = 'demande' AND objet_id = d),
        'en_attente > en_attente > validee > en_traitement > terminee');
    BEGIN UPDATE public.demandes SET statut = 'en_attente' WHERE id = d; msg := 'acceptée';
    EXCEPTION WHEN others THEN msg := SQLERRM; END;
    PERFORM pg_temp.verif('C9', 'terminée → en attente interdit', msg, 'Transition interdite : terminee → en_attente');

    -- Matériel > 500 € : étape comptabilité
    INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, montant, created_at, updated_at)
        VALUES (u_dev, 'materiel', 'Écran', 'x', 'en_attente', 900, now(), now()) RETURNING id INTO d2;
    UPDATE public.demandes SET etape = 2 WHERE id = d2;
    PERFORM pg_temp.verif('C10', 'matériel 900 € : étape 2 envoyée à la comptabilité',
        (SELECT destinataires ? 'test.compta@test.local' FROM public.mails_sortants WHERE demande_id = d2 AND type = 'etape_suivante'), true);

    -- =================================================================
    -- D. Refus motivé, complément
    -- =================================================================
    INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, created_at, updated_at)
        VALUES (u_dev, 'autre', 'Refus', 'x', 'en_attente', now(), now()) RETURNING id INTO d2;
    UPDATE public.demandes SET statut = 'refusee', commentaire_decision = 'Budget épuisé', decision_at = now(), decision_par = u_man,
           derniere_action_par = u_man, derniere_action_canal = 'appli' WHERE id = d2;
    PERFORM pg_temp.verif('D1', 'refus : motif dans le mail', (SELECT donnees ->> 'commentaire' FROM public.mails_sortants WHERE demande_id = d2 AND type = 'decision'), 'Budget épuisé');
    PERFORM pg_temp.verif('D2', 'refus : motif dans l''historique',
        (SELECT commentaire FROM public.historique WHERE objet = 'demande' AND objet_id = d2 ORDER BY id DESC LIMIT 1), 'Budget épuisé');

    INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, created_at, updated_at)
        VALUES (u_dev, 'autre', 'Complément', 'x', 'en_attente', now(), now()) RETURNING id INTO d2;
    UPDATE public.demandes SET statut = 'a_completer', commentaire_decision = 'Joindre le devis' WHERE id = d2;
    PERFORM pg_temp.verif('D3', 'à compléter : mail à l''employé avec la demande du manager',
        (SELECT (destinataires ->> 0) || ' / ' || (donnees ->> 'commentaire') FROM public.mails_sortants WHERE demande_id = d2 AND type = 'a_completer'),
        'test.dev@test.local / Joindre le devis');
    UPDATE public.demandes SET statut = 'en_attente', commentaire_decision = NULL WHERE id = d2;
    PERFORM pg_temp.verif('D4', 'complément reçu : mail au manager',
        (SELECT destinataires ->> 0 FROM public.mails_sortants WHERE demande_id = d2 AND type = 'complement_recu'), 'test.man@test.local');

    -- =================================================================
    -- R. Relances, escalades et expiration à l'étape en cours (cron)
    -- =================================================================
    INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, created_at, updated_at)
        VALUES (u_dev, 'formation', 'Formation', 'x', 'en_attente', now(), now()) RETURNING id INTO d2;
    UPDATE public.demandes SET etape = 2, relance_le = '2026-11-09', echeance_le = '2026-11-12', deadline = '2026-11-19' WHERE id = d2;
    PERFORM automation.planifier_rappels();
    SELECT * INTO x FROM public.mails_sortants WHERE demande_id = d2 AND type = 'relance';
    PERFORM pg_temp.verif('R1', 'relance à l''étape RH : envoyée au service RH, étape 2 notée',
        (x.destinataires ? 'test.rh@test.local') || ' / ' || (x.donnees ->> 'etape'), 'true / 2');
    PERFORM automation.planifier_rappels();
    PERFORM pg_temp.verif('R2', 'cron relancé le même jour : pas de doublon',
        (SELECT count(*) FROM public.mails_sortants WHERE demande_id = d2 AND type = 'relance'), 1::bigint);
    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-12' $f$;
    PERFORM automation.planifier_rappels();
    SELECT * INTO x FROM public.mails_sortants WHERE demande_id = d2 AND type = 'escalade';
    PERFORM pg_temp.verif('R3', 'escalade à l''étape RH : direction, service RH en copie',
        (x.destinataires ? 'test.dir@test.local') || ' / ' || (x.copies ? 'test.rh@test.local'), 'true / true');

    INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, created_at, updated_at)
        VALUES (u_dev, 'autre', 'Escalade manager', 'x', 'en_attente', now(), now()) RETURNING id INTO d2;
    UPDATE public.demandes SET relance_le = '2026-11-10', echeance_le = '2026-11-12', deadline = '2026-11-19' WHERE id = d2;
    PERFORM automation.planifier_rappels();
    SELECT * INTO x FROM public.mails_sortants WHERE demande_id = d2 AND type = 'escalade';
    PERFORM pg_temp.verif('R4', 'escalade à l''étape manager : RH + N+2, manager en copie',
        (x.destinataires ? 'test.rh@test.local') || ' / ' || (x.destinataires ? 'test.dir@test.local') || ' / ' || (x.copies ? 'test.man@test.local'), 'true / true / true');

    INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, created_at, updated_at)
        VALUES (u_dev, 'autre', 'Expiration', 'x', 'en_attente', now(), now()) RETURNING id INTO d2;
    UPDATE public.demandes SET statut = 'a_completer', commentaire_decision = 'Précisez', deadline = '2026-11-11' WHERE id = d2;
    PERFORM automation.expirer();
    PERFORM pg_temp.verif('R5', 'à compléter sans réponse : expirée par le cron + mail',
        (SELECT statut FROM public.demandes WHERE id = d2) || ' / ' || (SELECT count(*) FROM public.mails_sortants WHERE demande_id = d2 AND type = 'expiration'), 'expiree / 1');
    PERFORM pg_temp.verif('R6', 'historique : expiration par le cron, sans auteur',
        (SELECT canal || ' / ' || coalesce(auteur_id::text, '∅') FROM public.historique WHERE objet = 'demande' AND objet_id = d2 ORDER BY id DESC LIMIT 1), 'cron / ∅');
    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-09' $f$;

    -- =================================================================
    -- V. Tâches de projet validées par le chef
    -- =================================================================
    INSERT INTO public.taches (titre, projet_id, responsable_id, cree_par, statut, deadline, nb_reports, created_at, updated_at)
        VALUES ('Tâche à valider', p, u_dev, u_man, 'en_cours', '2026-11-20', 0, now(), now()) RETURNING id INTO t;
    UPDATE public.taches SET statut = 'a_valider', derniere_action_par = u_dev WHERE id = t;
    PERFORM pg_temp.verif('V1', 'à valider : mail au chef de projet',
        (SELECT destinataires ->> 0 FROM public.mails_sortants WHERE tache_id = t AND type = 'tache_a_valider'), 'test.man@test.local');
    PERFORM pg_temp.verif('V2', 'à valider : pas encore terminée', (SELECT termine_at IS NULL FROM public.taches WHERE id = t), true);
    UPDATE public.taches SET statut = 'en_cours', commentaire_validation = 'Ajouter les tests', derniere_action_par = u_man WHERE id = t;
    PERFORM pg_temp.verif('V3', 'renvoyée : mail au responsable avec le commentaire',
        (SELECT donnees ->> 'commentaire' FROM public.mails_sortants WHERE tache_id = t AND type = 'tache_renvoyee'), 'Ajouter les tests');
    PERFORM pg_temp.verif('V4', 'renvoyée : commentaire dans l''historique',
        (SELECT commentaire FROM public.historique WHERE objet = 'tache' AND objet_id = t ORDER BY id DESC LIMIT 1), 'Ajouter les tests');
    UPDATE public.taches SET statut = 'a_valider' WHERE id = t;
    UPDATE public.taches SET statut = 'terminee', commentaire_validation = NULL WHERE id = t;
    PERFORM pg_temp.verif('V5', 'validée : terminée + mail au responsable',
        (SELECT (termine_at IS NOT NULL) || ' / ' || (SELECT count(*) FROM public.mails_sortants WHERE tache_id = t AND type = 'tache_validee') FROM public.taches WHERE id = t), 'true / 1');
    PERFORM pg_temp.verif('V6', 'la 2e remise ne recopie pas l''ancien commentaire dans l''historique',
        (SELECT count(*) FROM public.historique WHERE objet = 'tache' AND objet_id = t AND commentaire IS NOT NULL), 1::bigint);
    BEGIN UPDATE public.taches SET statut = 'a_faire' WHERE id = t; msg := 'acceptée';
    EXCEPTION WHEN others THEN msg := SQLERRM; END;
    PERFORM pg_temp.verif('V7', 'terminée → à faire interdit', msg, 'Transition interdite : terminee → a_faire');

    -- =================================================================
    -- S. Statistiques (si l'étape 4 est installée)
    -- =================================================================
    stats_installees := to_regprocedure('public.stats_workflow(date,date)') IS NOT NULL;
    IF stats_installees THEN
        -- Manager 2 : une seule demande, envoyée le lundi 02/11, décidée le lundi 09/11 (5 jours ouvrés), échéance le 06/11
        INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, envoyee_at, created_at, updated_at)
            VALUES (u_dev2, 'autre', 'Stats', 'x', 'en_attente', '2026-11-02 09:00', '2026-11-02 09:00', now()) RETURNING id INTO d2;
        UPDATE public.demandes SET echeance_le = '2026-11-06' WHERE id = d2;
        UPDATE public.demandes SET statut = 'validee', decision_at = '2026-11-09 10:00', decision_par = u_man2 WHERE id = d2;
        v := automation.calculer_stats(u_man2, '2026-10-11', '2026-11-09');
        PERFORM pg_temp.verif('S1', 'temps moyen de décision = 5 jours ouvrés', v -> 'decisions' ->> 'temps_moyen_jours_ouvres', '5.0');
        PERFORM pg_temp.verif('S2', 'décision après l''échéance : 0 % dans les temps', v -> 'decisions' ->> 'dans_les_temps_pct', '0.0');
        PERFORM pg_temp.verif('S3', 'périmètre équipe : seule la demande du manager 2', v -> 'volume' ->> 'creees', '1');
        PERFORM pg_temp.verif('S4', 'ligne du jour du manager 2 tenue à jour par trigger',
            (SELECT decisions FROM public.stats_quotidiennes WHERE manager_id = u_man2 AND jour = '2026-11-09'), 1);
        PERFORM pg_temp.verif('S5', 'ligne « entreprise » du jour présente',
            (SELECT count(*) FROM public.stats_quotidiennes WHERE manager_id IS NULL AND jour = '2026-11-09'), 1::bigint);

        INSERT INTO auth.users (id, instance_id, aud, role, email, email_confirmed_at, raw_app_meta_data, raw_user_meta_data, created_at, updated_at) VALUES
            (a_rh,  '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'TEST.RH@test.local',  now(), '{}', '{}', now(), now()),
            (a_man, '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'test.man2@test.local', now(), '{}', '{}', now(), now()),
            (a_dev, '00000000-0000-0000-0000-000000000000', 'authenticated', 'authenticated', 'test.dev@test.local',  now(), '{}', '{}', now(), now());

        PERFORM set_config('request.jwt.claims', json_build_object('sub', a_rh, 'role', 'authenticated')::text, true);
        SET LOCAL ROLE authenticated;
        v := public.stats_workflow();
        PERFORM pg_temp.verif('S6', 'compte RH (e-mail en majuscules) : toute l''entreprise', v ->> 'perimetre', 'entreprise');
        PERFORM pg_temp.verif('S7', 'compte RH : aucune demande lisible directement', (SELECT count(*) FROM public.demandes), 0::bigint);
        PERFORM pg_temp.verif('S8', 'compte RH : lignes « entreprise » seulement',
            (SELECT count(*) FILTER (WHERE manager_id IS NOT NULL) FROM public.stats_quotidiennes), 0::bigint);
        RESET ROLE;

        PERFORM set_config('request.jwt.claims', json_build_object('sub', a_man, 'role', 'authenticated')::text, true);
        SET LOCAL ROLE authenticated;
        v := public.stats_workflow('2026-10-11', '2026-11-09');
        PERFORM pg_temp.verif('S9', 'compte manager : son équipe seulement',
            (v ->> 'perimetre') || ' / ' || (v -> 'volume' ->> 'creees'), 'equipe / 1');
        PERFORM pg_temp.verif('S10', 'compte manager : seule sa ligne de statistiques',
            (SELECT count(*) FROM public.stats_quotidiennes WHERE manager_id IS DISTINCT FROM u_man2), 0::bigint);
        RESET ROLE;

        PERFORM set_config('request.jwt.claims', json_build_object('sub', a_dev, 'role', 'authenticated')::text, true);
        SET LOCAL ROLE authenticated;
        BEGIN v := public.stats_workflow(); msg := 'accepté'; EXCEPTION WHEN insufficient_privilege THEN msg := 'refusé'; END;
        PERFORM pg_temp.verif('S11', 'compte employé : accès refusé', msg, 'refusé');
        RESET ROLE;

        SET LOCAL ROLE anon;
        BEGIN v := public.stats_workflow(); msg := 'accepté'; EXCEPTION WHEN insufficient_privilege THEN msg := 'refusé'; END;
        PERFORM pg_temp.verif('S12', 'visiteur anonyme : accès refusé', msg, 'refusé');
        RESET ROLE;
    END IF;

    -- =================================================================
    -- Bilan (tout est annulé par l'exception finale)
    -- =================================================================
    SELECT count(*) FILTER (WHERE obtenu IS NOT DISTINCT FROM attendu), count(*) FILTER (WHERE obtenu IS DISTINCT FROM attendu)
      INTO nb_ok, nb_ko FROM resultats;
    SELECT coalesce(string_agg(code || ' ' || libelle || ' : obtenu « ' || coalesce(obtenu, 'NULL') || ' », attendu « ' || coalesce(attendu, 'NULL') || ' »', E'\n'), '')
      INTO echecs FROM resultats WHERE obtenu IS DISTINCT FROM attendu;
    RAISE EXCEPTION E'TESTS WORKFLOW : % / % OK%', nb_ok, nb_ok + nb_ko,
        CASE WHEN stats_installees THEN '' ELSE ' (statistiques non installées : partie S ignorée)' END ||
        CASE WHEN nb_ko = 0 THEN ' – tout est conforme (rien n''a été enregistré)' ELSE E'\nÉCHECS :\n' || echecs END;
END $suite$;
