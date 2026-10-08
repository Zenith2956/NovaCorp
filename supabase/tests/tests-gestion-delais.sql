-- =====================================================================
-- NovaCorp – suite de tests de la gestion des délais (règles PostgreSQL)
-- À exécuter dans Supabase > SQL Editor. Rien n'est conservé : tout est annulé à la fin
-- (le résultat s'affiche comme une « erreur » « TESTS … x / y OK », c'est voulu).
-- Données de test créées à la volée ; « aujourd'hui » simulé (lundi 09/11/2026, le 11/11 est férié).
-- =====================================================================
DO $suite$
DECLARE
    r_dir bigint; r_man bigint; r_dev bigint; r_rh bigint;
    u_dir bigint; u_chef bigint; u_membre bigint; u_ext bigint; u_rh bigint;
    p bigint; d bigint; t bigint; t_fin bigint; x record; n int; ok boolean;
    nb_ok int := 0; nb_ko int := 0; echecs text := '';
BEGIN
    -- ---------- outil d'assertion ----------
    CREATE TEMP TABLE resultats (code text, libelle text, obtenu text, attendu text);
    CREATE FUNCTION pg_temp.verif(code text, libelle text, obtenu anyelement, attendu anyelement) RETURNS void
    LANGUAGE sql AS $f$ INSERT INTO resultats VALUES (code, libelle, obtenu::text, attendu::text) $f$;

    -- « aujourd'hui » simulé (annulé avec le reste)
    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-09' $f$;

    -- ---------- données de test ----------
    SELECT id INTO r_dir FROM public.roles WHERE slug = 'direction';
    SELECT id INTO r_man FROM public.roles WHERE slug = 'manager';
    SELECT id INTO r_dev FROM public.roles WHERE slug = 'dev';
    SELECT id INTO r_rh  FROM public.roles WHERE slug = 'rh';
    INSERT INTO public.users (nom, prenom, email, password, role_id, actif, created_at, updated_at)
        VALUES ('Test', 'Direction', 'test.dir@test.local', 'x', r_dir, true, now(), now()) RETURNING id INTO u_dir;
    INSERT INTO public.users (nom, prenom, email, password, role_id, manager_id, actif, created_at, updated_at)
        VALUES ('Test', 'Chef', 'test.chef@test.local', 'x', r_man, u_dir, true, now(), now()) RETURNING id INTO u_chef;
    INSERT INTO public.users (nom, prenom, email, password, role_id, manager_id, actif, created_at, updated_at)
        VALUES ('Test', 'Membre', 'test.membre@test.local', 'x', r_dev, u_chef, true, now(), now()) RETURNING id INTO u_membre;
    INSERT INTO public.users (nom, prenom, email, password, role_id, manager_id, actif, created_at, updated_at)
        VALUES ('Test', 'Exterieur', 'test.ext@test.local', 'x', r_dev, u_chef, true, now(), now()) RETURNING id INTO u_ext;
    INSERT INTO public.users (nom, prenom, email, password, role_id, actif, created_at, updated_at)
        VALUES ('Test', 'RH', 'test.rh@test.local', 'x', r_rh, true, now(), now()) RETURNING id INTO u_rh;
    INSERT INTO public.projets (nom, statut, chef_projet_id, created_at, updated_at)
        VALUES ('Projet de test', 'en_cours', u_chef, now(), now()) RETURNING id INTO p;
    INSERT INTO public.projet_user (projet_id, user_id) VALUES (p, u_membre);
    -- Les vraies données en attente ne doivent pas fausser les compteurs : on ne compte que nos utilisateurs
    -- (planifier_rappels / expirer agissent sur toute la base, mais tout est annulé à la fin).

    -- =================================================================
    -- 1. Calendrier
    -- =================================================================
    PERFORM pg_temp.verif('C1', 'Pâques 2025', automation.paques(2025), date '2025-04-20');
    PERFORM pg_temp.verif('C2', 'Pâques 2026', automation.paques(2026), date '2026-04-05');
    PERFORM pg_temp.verif('C3', 'Pâques 2027', automation.paques(2027), date '2027-03-28');
    PERFORM pg_temp.verif('C4', 'Ascension 2026 fériée (14/05)', (SELECT count(*) FROM public.jours_feries WHERE jour = '2026-05-14'), 1::bigint);
    PERFORM pg_temp.verif('C5', 'Lundi de Pentecôte 2026 férié (25/05)', (SELECT count(*) FROM public.jours_feries WHERE jour = '2026-05-25'), 1::bigint);
    PERFORM pg_temp.verif('C6', '11 jours fériés en 2026', (SELECT count(*) FROM public.jours_feries WHERE extract(year FROM jour) = 2026), 11::bigint);
    PERFORM pg_temp.verif('C7', 'samedi non ouvré', automation.est_jour_ouvre('2026-11-14'), false);
    PERFORM pg_temp.verif('C8', '11/11 non ouvré', automation.est_jour_ouvre('2026-11-11'), false);
    PERFORM pg_temp.verif('C9', 'lundi 09/11 + 2 j ouvrés (férié sauté)', automation.ajouter_jours_ouvres('2026-11-09', 2), date '2026-11-12');
    PERFORM pg_temp.verif('C10', 'vendredi 13/11 + 1 j ouvré (week-end sauté)', automation.ajouter_jours_ouvres('2026-11-13', 1), date '2026-11-16');
    PERFORM pg_temp.verif('C11', '+0 jour = même date', automation.ajouter_jours_ouvres('2026-11-09', 0), date '2026-11-09');
    PERFORM pg_temp.verif('C12', 'veille ouvrée du 12/11 (férié sauté)', automation.jour_ouvre_precedent('2026-11-12'), date '2026-11-10');
    PERFORM pg_temp.verif('C13', 'veille ouvrée du lundi 16/11', automation.jour_ouvre_precedent('2026-11-16'), date '2026-11-13');
    PERFORM pg_temp.verif('C14', 'date de Paris d''un horodatage UTC à 23 h 30', automation.date_paris('2026-11-09 23:30'), date '2026-11-10');

    -- =================================================================
    -- 2. Demandes : dates calculées à la création (base lundi 09/11)
    -- =================================================================
    INSERT INTO public.demandes (demandeur_id, manager_id, type, objet, message, statut, jeton_decision, created_at, updated_at, envoyee_at)
    VALUES (u_membre, u_chef, 'conge', 'D-conge', 'x', 'en_attente', gen_random_uuid(), '2026-11-09 09:00', '2026-11-09 09:00', '2026-11-09 09:00') RETURNING id INTO d;
    SELECT * INTO x FROM public.demandes WHERE id = d;
    PERFORM pg_temp.verif('D1', 'congé : relance après 2 j ouvrés', x.relance_le, date '2026-11-12');
    PERFORM pg_temp.verif('D2', 'congé : échéance après 5 j ouvrés', x.echeance_le, date '2026-11-17');
    PERFORM pg_temp.verif('D3', 'congé : deadline = échéance + 5 j ouvrés', x.deadline, date '2026-11-24');
    PERFORM pg_temp.verif('D4', 'congé : pas urgente', x.urgente, false);

    INSERT INTO public.demandes (demandeur_id, manager_id, type, objet, message, statut, created_at, updated_at, envoyee_at)
    VALUES (u_membre, u_chef, 'materiel', 'D-mat', 'x', 'en_attente', '2026-11-09 09:00', '2026-11-09 09:00', '2026-11-09 09:00') RETURNING id INTO n;
    SELECT * INTO x FROM public.demandes WHERE id = n;
    PERFORM pg_temp.verif('D5', 'matériel : relance 1 j / échéance 3 j / deadline', x.relance_le || ' ' || x.echeance_le || ' ' || x.deadline, '2026-11-10 2026-11-13 2026-11-20');

    INSERT INTO public.demandes (demandeur_id, manager_id, type, objet, message, statut, created_at, updated_at, envoyee_at)
    VALUES (u_membre, u_chef, 'formation', 'D-form', 'x', 'en_attente', '2026-11-09 09:00', '2026-11-09 09:00', '2026-11-09 09:00') RETURNING id INTO n;
    SELECT * INTO x FROM public.demandes WHERE id = n;
    PERFORM pg_temp.verif('D6', 'formation : relance 4 j / échéance 10 j / deadline', x.relance_le || ' ' || x.echeance_le || ' ' || x.deadline, '2026-11-16 2026-11-24 2026-12-01');

    INSERT INTO public.demandes (demandeur_id, manager_id, type, objet, message, statut, date_souhaitee, created_at, updated_at, envoyee_at)
    VALUES (u_membre, u_chef, 'conge', 'D-urgente', 'x', 'en_attente', '2026-11-12', '2026-11-09 09:00', '2026-11-09 09:00', '2026-11-09 09:00') RETURNING id INTO n;
    SELECT * INTO x FROM public.demandes WHERE id = n;
    PERFORM pg_temp.verif('D7', 'date souhaitée proche (12/11) : urgente, relance et échéance la veille ouvrée, deadline = date souhaitée',
        x.urgente || ' ' || x.relance_le || ' ' || x.echeance_le || ' ' || x.deadline, 'true 2026-11-10 2026-11-10 2026-11-12');

    INSERT INTO public.demandes (demandeur_id, manager_id, type, objet, message, statut, date_souhaitee, created_at, updated_at, envoyee_at)
    VALUES (u_membre, u_chef, 'conge', 'D-lointaine', 'x', 'en_attente', '2026-11-30', '2026-11-09 09:00', '2026-11-09 09:00', '2026-11-09 09:00') RETURNING id INTO n;
    SELECT * INTO x FROM public.demandes WHERE id = n;
    PERFORM pg_temp.verif('D8', 'date souhaitée lointaine (30/11) : pas urgente, échéance normale, deadline = date souhaitée',
        x.urgente || ' ' || x.echeance_le || ' ' || x.deadline, 'false 2026-11-17 2026-11-30');

    INSERT INTO public.demandes (demandeur_id, manager_id, type, objet, message, statut, date_souhaitee, created_at, updated_at, envoyee_at)
    VALUES (u_membre, u_chef, 'conge', 'D-jour-meme', 'x', 'en_attente', '2026-11-09', '2026-11-09 09:00', '2026-11-09 09:00', '2026-11-09 09:00') RETURNING id INTO n;
    SELECT * INTO x FROM public.demandes WHERE id = n;
    PERFORM pg_temp.verif('D9', 'date souhaitée = aujourd''hui : urgente, échéance et deadline le jour même',
        x.urgente || ' ' || x.echeance_le || ' ' || x.deadline, 'true 2026-11-09 2026-11-09');

    INSERT INTO public.demandes (demandeur_id, manager_id, type, objet, message, statut, created_at, updated_at, envoyee_at)
    VALUES (u_membre, u_chef, 'type_inconnu', 'D-inconnu', 'x', 'en_attente', '2026-11-09 09:00', '2026-11-09 09:00', '2026-11-09 09:00') RETURNING id INTO n;
    SELECT * INTO x FROM public.demandes WHERE id = n;
    PERFORM pg_temp.verif('D10', 'type absent de types_demande : délais par défaut 2 / 5', x.relance_le || ' ' || x.echeance_le, '2026-11-12 2026-11-17');

    -- Les autres demandes de test sont refusées : seule D-conge suit la chronologie
    UPDATE public.demandes SET statut = 'refusee' WHERE demandeur_id = u_membre AND id <> d;

    -- =================================================================
    -- 3. Demandes : chronologie relance → rappel → escalade → expiration
    -- =================================================================
    PERFORM pg_temp.verif('R1', 'création : mail « nouvelle demande » au manager',
        (SELECT string_agg(type || '>' || (destinataires ->> 0), ',') FROM public.mails_sortants WHERE demande_id = d), 'nouvelle_demande>test.chef@test.local');

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-10' $f$;
    PERFORM automation.planifier_rappels();
    PERFORM pg_temp.verif('R2', '10/11 : aucun mail (relance prévue le 12/11)',
        (SELECT count(*) FROM public.mails_sortants WHERE demande_id = d AND type <> 'nouvelle_demande'), 0::bigint);

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-12' $f$;
    PERFORM automation.planifier_rappels();
    PERFORM pg_temp.verif('R3', '12/11 : relance au manager',
        (SELECT string_agg(type, ',' ORDER BY id) FROM public.mails_sortants WHERE demande_id = d AND type <> 'nouvelle_demande'), 'relance');

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-16' $f$;
    PERFORM automation.planifier_rappels();
    PERFORM pg_temp.verif('R4', '16/11 : rappel « échéance demain »',
        (SELECT string_agg(type, ',' ORDER BY id) FROM public.mails_sortants WHERE demande_id = d AND type <> 'nouvelle_demande'), 'relance,rappel_echeance');

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-17' $f$;
    PERFORM automation.planifier_rappels();
    SELECT * INTO x FROM public.mails_sortants WHERE demande_id = d AND type = 'escalade';
    PERFORM pg_temp.verif('R5', '17/11 (échéance) : escalade', x.type, 'escalade');
    PERFORM pg_temp.verif('R6', 'escalade : RH et N+2 en destinataires, manager en copie',
        (x.destinataires ? 'test.rh@test.local') AND (x.destinataires ? 'test.dir@test.local') AND (x.copies ? 'test.chef@test.local'), true);
    PERFORM automation.planifier_rappels();
    PERFORM pg_temp.verif('R7', 'second passage le même jour : aucun doublon',
        (SELECT count(*) FROM public.mails_sortants WHERE demande_id = d), 4::bigint);

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-24' $f$;
    PERFORM automation.expirer();
    PERFORM pg_temp.verif('R8', '24/11 (jour de la deadline) : pas encore expirée', (SELECT statut FROM public.demandes WHERE id = d), 'en_attente');

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-25' $f$;
    PERFORM automation.expirer();
    SELECT * INTO x FROM public.demandes WHERE id = d;
    PERFORM pg_temp.verif('R9', '25/11 : expirée, liens Valider / Refuser désactivés', x.statut || ' ' || (x.jeton_decision IS NULL), 'expiree true');
    SELECT * INTO x FROM public.mails_sortants WHERE demande_id = d AND type = 'expiration';
    PERFORM pg_temp.verif('R10', 'mail d''expiration à l''employé, manager et RH en copie',
        (x.destinataires ->> 0 = 'test.membre@test.local') AND (x.copies ? 'test.chef@test.local') AND (x.copies ? 'test.rh@test.local'), true);
    PERFORM automation.planifier_rappels(); PERFORM automation.expirer();
    PERFORM pg_temp.verif('R11', 'demande expirée : plus aucun mail ensuite',
        (SELECT count(*) FROM public.mails_sortants WHERE demande_id = d), 5::bigint);

    -- Demande validée avant sa relance
    INSERT INTO public.demandes (demandeur_id, manager_id, type, objet, message, statut, created_at, updated_at, envoyee_at)
    VALUES (u_membre, u_chef, 'materiel', 'D-validee', 'x', 'en_attente', '2026-11-20 09:00', '2026-11-20 09:00', '2026-11-20 09:00') RETURNING id INTO n;
    UPDATE public.demandes SET statut = 'validee' WHERE id = n;
    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-12-15' $f$;
    PERFORM automation.planifier_rappels(); PERFORM automation.expirer();
    PERFORM pg_temp.verif('R12', 'demande validée : mail « décision », ni relance ni expiration',
        (SELECT string_agg(type, ',' ORDER BY id) FROM public.mails_sortants WHERE demande_id = n) || ' ' || (SELECT statut FROM public.demandes WHERE id = n),
        'nouvelle_demande,decision validee');

    -- =================================================================
    -- 4. Tâches : règles de deadline (aujourd'hui = lundi 09/11)
    -- =================================================================
    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-09' $f$;

    ok := false; BEGIN INSERT INTO public.taches (titre, responsable_id, cree_par, deadline, created_at, updated_at)
        VALUES ('hp passé', u_membre, u_membre, '2026-11-06', '2026-11-09 09:00', now()); EXCEPTION WHEN raise_exception THEN ok := true; END;
    PERFORM pg_temp.verif('T1', 'hors projet : deadline dans le passé refusée', ok, true);
    ok := false; BEGIN INSERT INTO public.taches (titre, responsable_id, cree_par, created_at, updated_at)
        VALUES ('hp sans', u_membre, u_membre, '2026-11-09 09:00', now()); EXCEPTION WHEN raise_exception THEN ok := true; END;
    PERFORM pg_temp.verif('T2', 'hors projet : deadline obligatoire', ok, true);

    INSERT INTO public.taches (titre, responsable_id, cree_par, deadline, created_at, updated_at)
        VALUES ('hp', u_membre, u_membre, '2026-11-09', '2026-11-09 09:00', now()) RETURNING id INTO t;
    PERFORM pg_temp.verif('T3', 'hors projet : deadline aujourd''hui acceptée, aucun mail', (SELECT count(*) FROM public.mails_sortants WHERE tache_id = t), 0::bigint);
    UPDATE public.taches SET deadline = '2026-11-20' WHERE id = t;
    UPDATE public.taches SET deadline = '2026-11-18' WHERE id = t;
    SELECT * INTO x FROM public.mails_sortants WHERE tache_id = t AND type = 'tache_deadline_modifiee' ORDER BY id DESC LIMIT 1;
    PERFORM pg_temp.verif('T4', 'hors projet : modification libre (même plus tôt), manager prévenu avec ancienne → nouvelle date',
        (x.destinataires ->> 0) || ' ' || (x.donnees ->> 'ancienne') || '>' || (x.donnees ->> 'nouvelle'), 'test.chef@test.local 2026-11-20>2026-11-18');
    PERFORM pg_temp.verif('T5', 'hors projet : 2 modifications = 2 mails au manager',
        (SELECT count(*) FROM public.mails_sortants WHERE tache_id = t AND type = 'tache_deadline_modifiee'), 2::bigint);
    ok := false; BEGIN UPDATE public.taches SET deadline = '2026-11-06' WHERE id = t; EXCEPTION WHEN raise_exception THEN ok := true; END;
    PERFORM pg_temp.verif('T6', 'hors projet : modification vers le passé refusée', ok, true);

    ok := false; BEGIN INSERT INTO public.taches (titre, projet_id, responsable_id, cree_par, created_at, updated_at)
        VALUES ('ext', p, u_ext, u_ext, '2026-11-09 09:00', now()); EXCEPTION WHEN raise_exception THEN ok := true; END;
    PERFORM pg_temp.verif('T7', 'projet : un non-membre ne peut pas être responsable', ok, true);

    INSERT INTO public.taches (titre, projet_id, responsable_id, cree_par, created_at, updated_at)
        VALUES ('membre', p, u_membre, u_membre, '2026-11-09 09:00', now()) RETURNING id INTO t;
    SELECT * INTO x FROM public.taches WHERE id = t;
    PERFORM pg_temp.verif('T8', 'créée par un membre : sans deadline, jeton créé', (x.deadline IS NULL) || ' ' || (x.jeton_deadline IS NOT NULL), 'true true');
    PERFORM pg_temp.verif('T9', 'mail « deadline à fixer » au chef de projet',
        (SELECT type || '>' || (destinataires ->> 0) FROM public.mails_sortants WHERE tache_id = t), 'tache_deadline_a_fixer>test.chef@test.local');
    ok := false; BEGIN UPDATE public.taches SET deadline = '2026-11-16' WHERE id = t; EXCEPTION WHEN raise_exception THEN ok := true; END;
    PERFORM pg_temp.verif('T10', 'première deadline à création + 4 j ouvrés (16/11) refusée', ok, true);
    UPDATE public.taches SET deadline = '2026-11-17' WHERE id = t;
    SELECT * INTO x FROM public.taches WHERE id = t;
    PERFORM pg_temp.verif('T11', 'première deadline à création + 5 j ouvrés (17/11) acceptée, initiale gardée, jeton consommé',
        x.deadline || ' ' || x.deadline_initiale || ' ' || (x.jeton_deadline IS NULL) || ' ' || x.nb_reports, '2026-11-17 2026-11-17 true 0');
    ok := false; BEGIN UPDATE public.taches SET deadline = '2026-11-16' WHERE id = t; EXCEPTION WHEN raise_exception THEN ok := true; END;
    PERFORM pg_temp.verif('T12', 'projet : avancer la deadline refusé', ok, true);
    UPDATE public.taches SET deadline = '2026-11-18' WHERE id = t;
    SELECT * INTO x FROM public.taches WHERE id = t;
    PERFORM pg_temp.verif('T13', 'projet : report d''un seul jour accepté (1 report, initiale inchangée)',
        x.deadline || ' ' || x.nb_reports || ' ' || x.deadline_initiale, '2026-11-18 1 2026-11-17');
    PERFORM pg_temp.verif('T14', 'mails au responsable : deadline fixée, puis repoussée (avec ancienne date)',
        (SELECT string_agg(type || coalesce('(' || (donnees ->> 'ancienne') || ')', ''), ',' ORDER BY id) FROM public.mails_sortants WHERE tache_id = t AND type = 'tache_deadline_fixee'),
        'tache_deadline_fixee,tache_deadline_fixee(2026-11-17)');
    ok := false; BEGIN UPDATE public.taches SET deadline = NULL WHERE id = t; EXCEPTION WHEN raise_exception THEN ok := true; END;
    PERFORM pg_temp.verif('T15', 'deadline impossible à retirer', ok, true);

    ok := false; BEGIN INSERT INTO public.taches (titre, projet_id, responsable_id, cree_par, deadline, created_at, updated_at)
        VALUES ('assign', p, u_membre, u_chef, '2026-11-16', '2026-11-09 09:00', now()); EXCEPTION WHEN raise_exception THEN ok := true; END;
    PERFORM pg_temp.verif('T16', 'assignation par le chef à moins de 5 j ouvrés refusée', ok, true);
    INSERT INTO public.taches (titre, projet_id, responsable_id, cree_par, deadline, created_at, updated_at)
        VALUES ('assign', p, u_membre, u_chef, '2026-11-17', '2026-11-09 09:00', now()) RETURNING id INTO t_fin;
    PERFORM pg_temp.verif('T17', 'assignation : deadline fixée par le chef, mail « assignation » au responsable',
        (SELECT deadline_fixee_par FROM public.taches WHERE id = t_fin) = u_chef
        AND (SELECT donnees ->> 'assignation' FROM public.mails_sortants WHERE tache_id = t_fin) = 'true', true);
    UPDATE public.taches SET statut = 'terminee' WHERE id = t_fin;
    PERFORM pg_temp.verif('T18', 'tâche terminée : date de fin enregistrée', (SELECT termine_at IS NOT NULL FROM public.taches WHERE id = t_fin), true);

    -- =================================================================
    -- 5. Tâches : relances, rappels, escalade, expiration
    -- =================================================================
    INSERT INTO public.taches (titre, projet_id, responsable_id, cree_par, created_at, updated_at)
        VALUES ('sans deadline', p, u_membre, u_membre, '2026-11-09 09:00', now()) RETURNING id INTO n;
    INSERT INTO public.taches (titre, responsable_id, cree_par, deadline, created_at, updated_at)
        VALUES ('rappel', u_membre, u_membre, '2026-11-13', '2026-11-09 09:00', now()) RETURNING id INTO d;

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-10' $f$;
    PERFORM automation.planifier_rappels();
    PERFORM pg_temp.verif('S1', '10/11 : ni relance du chef (2 j) ni rappel (veille = 12/11)',
        (SELECT count(*) FROM public.mails_sortants WHERE tache_id IN (n, d) AND type IN ('tache_relance_deadline', 'tache_rappel')), 0::bigint);

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-12' $f$;
    PERFORM automation.planifier_rappels();
    PERFORM pg_temp.verif('S2', '12/11 : relance du chef (deadline toujours à fixer)',
        (SELECT count(*) FROM public.mails_sortants WHERE tache_id = n AND type = 'tache_relance_deadline'), 1::bigint);
    PERFORM pg_temp.verif('S3', '12/11 : rappel la veille de la deadline du 13/11',
        (SELECT count(*) FROM public.mails_sortants WHERE tache_id = d AND type = 'tache_rappel'), 1::bigint);

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-16' $f$;
    PERFORM automation.planifier_rappels();
    PERFORM pg_temp.verif('S4', '16/11 : pas encore d''escalade (5 j ouvrés = 17/11)',
        (SELECT count(*) FROM public.mails_sortants WHERE tache_id = n AND type = 'tache_escalade_deadline'), 0::bigint);
    PERFORM automation.expirer();
    PERFORM pg_temp.verif('S5', '16/11 : tâche non terminée (deadline 13/11) expirée + mail',
        (SELECT statut FROM public.taches WHERE id = d) || ' ' || (SELECT count(*) FROM public.mails_sortants WHERE tache_id = d AND type = 'tache_expiration'), 'expiree 1');

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-17' $f$;
    PERFORM automation.planifier_rappels();
    SELECT * INTO x FROM public.mails_sortants WHERE tache_id = n AND type = 'tache_escalade_deadline';
    PERFORM pg_temp.verif('S6', '17/11 : escalade au supérieur du chef, chef en copie',
        (x.destinataires ->> 0) || ' / ' || (x.copies ->> 0), 'test.dir@test.local / test.chef@test.local');

    CREATE OR REPLACE FUNCTION automation.aujourdhui() RETURNS date LANGUAGE sql STABLE SET search_path = '' AS $f$ SELECT date '2026-11-25' $f$;
    PERFORM automation.expirer();
    PERFORM pg_temp.verif('S7', 'tâche terminée : jamais expirée, même deadline dépassée', (SELECT statut FROM public.taches WHERE id = t_fin), 'terminee');
    UPDATE public.taches SET deadline = '2026-11-30' WHERE id = d;
    PERFORM pg_temp.verif('S8', 'tâche expirée dont la deadline est repoussée : redevient « à faire »', (SELECT statut FROM public.taches WHERE id = d), 'a_faire');

    -- =================================================================
    -- Bilan (tout est annulé par l'exception finale)
    -- =================================================================
    SELECT count(*) FILTER (WHERE obtenu IS NOT DISTINCT FROM attendu), count(*) FILTER (WHERE obtenu IS DISTINCT FROM attendu)
      INTO nb_ok, nb_ko FROM resultats;
    SELECT coalesce(string_agg(code || ' ' || libelle || ' : obtenu « ' || coalesce(obtenu, 'NULL') || ' », attendu « ' || coalesce(attendu, 'NULL') || ' »', E'\n'), '')
      INTO echecs FROM resultats WHERE obtenu IS DISTINCT FROM attendu;
    RAISE EXCEPTION E'TESTS GESTION DES DÉLAIS : % / % OK%', nb_ok, nb_ok + nb_ko,
        CASE WHEN nb_ko = 0 THEN ' – tout est conforme (rien n''a été enregistré)' ELSE E'\nÉCHECS :\n' || echecs END;
END $suite$;
