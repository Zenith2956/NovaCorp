-- =====================================================================
-- Données de démonstration des statistiques (08/10/2026) – à conserver pour l'affichage.
-- Équipe fictive « Démo » : 5 employés (e-mails @demo.novacorp.fr, connexion impossible) rattachés à manager@novacorp.fr
-- et 48 demandes réparties du 09/09 au 08/10/2026, avec décisions, étapes RH / comptabilité,
-- compléments, annulations, traitements et historique horodaté de façon réaliste.
-- Les mails générés par les triggers sont annulés (aucun envoi).
-- Attention : les demandes encore « en attente » recevront relances / escalades / expiration
-- comme de vraies demandes (mails en mode test). Nettoyage : supabase/sql/nettoyer-demo-stats.sql
-- =====================================================================
DO $demo$
DECLARE
    u_man bigint; devs bigint[] := '{}'; u bigint; u_rh bigint; u_compta bigint; u_admin bigint;
    prenoms text[] := ARRAY['Lina', 'Hugo', 'Sarah', 'Nathan', 'Inès'];
    types text[] := ARRAY['conge', 'materiel', 'note_de_frais', 'formation', 'autre', 'conge', 'materiel', 'autre'];
    i int; d bigint; typ text; jour date; t0 timestamp; t1 timestamp; t2 timestamp; t3 timestamp; t4 timestamp;
    deux_etapes boolean; en_suspens boolean; montant numeric; ddeb date; dfin date; ts timestamp[]; ids bigint[]; k int; sort int;
BEGIN
    -- Équipe rattachée au compte de test manager@novacorp.fr (pour la voir en se connectant avec ce compte)
    SELECT id INTO u_man FROM public.users WHERE email = 'manager@novacorp.fr';
    FOR i IN 1..5 LOOP
        INSERT INTO public.users (nom, prenom, email, password, role_id, manager_id, actif, created_at, updated_at)
        VALUES ('Démo', prenoms[i], lower(prenoms[i]) || '.demo@demo.novacorp.fr', '!',
                (SELECT id FROM public.roles WHERE slug = CASE WHEN i <= 3 THEN 'dev' ELSE 'commercial' END), u_man, true, now(), now())
        RETURNING id INTO u;
        devs := devs || u;
    END LOOP;
    SELECT min(u2.id) INTO u_rh FROM public.users u2 JOIN public.roles r ON r.id = u2.role_id WHERE r.slug = 'rh' AND u2.actif;
    SELECT min(u2.id) INTO u_compta FROM public.users u2 JOIN public.roles r ON r.id = u2.role_id WHERE r.slug = 'comptable' AND u2.actif;
    SELECT min(u2.id) INTO u_admin FROM public.users u2 JOIN public.roles r ON r.id = u2.role_id WHERE r.slug = 'admin' AND u2.actif;

    FOR i IN 1..48 LOOP
        typ := types[1 + (i * 5) % 8];
        -- jour ouvré de création : du 09/09 au 08/10, ~1,6 demande par jour ouvré
        jour := automation.ajouter_jours_ouvres(date '2026-09-08', 1 + (i * 21) / 48);
        t0 := jour + make_interval(hours => 8 + (i * 7) % 9, mins => (i * 13) % 60) - interval '2 hours'; -- heure de Paris → UTC
        montant := CASE typ WHEN 'materiel' THEN CASE WHEN i % 3 = 0 THEN 1290 ELSE 180 + (i * 37) % 300 END
                            WHEN 'note_de_frais' THEN 25 + (i * 19) % 160 END;
        ddeb := CASE WHEN typ = 'conge' THEN automation.ajouter_jours_ouvres(jour, 15 + i % 10) END;
        dfin := CASE WHEN typ = 'conge' THEN automation.ajouter_jours_ouvres(ddeb, CASE WHEN i % 2 = 0 THEN 7 ELSE 2 END) END;

        INSERT INTO public.demandes (demandeur_id, type, objet, message, statut, montant, date_debut, date_fin,
                                     derniere_action_par, derniere_action_canal, jeton_decision, envoyee_at, created_at, updated_at)
        VALUES (devs[1 + i % 5], typ, '[DÉMO STATS] ' || CASE typ WHEN 'conge' THEN 'Congés' WHEN 'materiel' THEN 'Équipement'
                    WHEN 'note_de_frais' THEN 'Frais de déplacement' WHEN 'formation' THEN 'Formation' ELSE 'Demande diverse' END || ' n°' || i,
                'Donnée de démonstration des statistiques.', 'en_attente', montant, ddeb, dfin,
                devs[1 + i % 5], 'appli', gen_random_uuid(), t0, t0, t0)
        RETURNING id INTO d;
        ts := ARRAY[t0];
        en_suspens := false;

        -- Les 6 demandes les plus récentes restent en cours (en attente / à compléter)
        IF i > 42 THEN
            IF i = 44 THEN
                t1 := t0 + interval '5 hours';
                UPDATE public.demandes SET statut = 'a_completer', commentaire_decision = 'Merci de préciser le besoin.',
                       derniere_action_par = u_man, derniere_action_canal = 'mail' WHERE id = d;
                ts := ts || t1;
            END IF;
        ELSE
            sort := i % 12;
            t1 := (automation.ajouter_jours_ouvres(jour, 1 + (i * 3) % 4) + make_interval(hours => 7 + i % 8)) ::timestamp;  -- décision du manager
            deux_etapes := (typ = 'conge' AND i % 2 = 0) OR (typ = 'materiel' AND i % 3 = 0) OR typ IN ('note_de_frais', 'formation');

            IF t1 > now() THEN          -- le manager n'a pas encore répondu (demande récente)
                NULL;
            ELSIF sort = 5 THEN         -- annulée par l'employé
                UPDATE public.demandes SET statut = 'annulee', jeton_decision = NULL, derniere_action_par = devs[1 + i % 5], derniere_action_canal = 'appli' WHERE id = d;
                ts := ts || (t0 + interval '1 day');
            ELSIF sort = 7 THEN         -- refusée par le manager
                UPDATE public.demandes SET statut = 'refusee', commentaire_decision = 'Période trop chargée.', decision_at = t1,
                       decision_par = u_man, jeton_decision = NULL, derniere_action_par = u_man, derniere_action_canal = 'appli' WHERE id = d;
                ts := ts || t1;
            ELSE
                IF sort = 3 THEN        -- complément demandé puis fourni
                    UPDATE public.demandes SET statut = 'a_completer', commentaire_decision = 'Pièce justificative manquante.',
                           derniere_action_par = u_man, derniere_action_canal = 'mail' WHERE id = d;
                    UPDATE public.demandes SET statut = 'en_attente', commentaire_decision = NULL,
                           derniere_action_par = devs[1 + i % 5], derniere_action_canal = 'appli' WHERE id = d;
                    ts := ts || (t0 + interval '6 hours') || (t1 - interval '3 hours');
                END IF;
                IF deux_etapes THEN
                    UPDATE public.demandes SET etape = 2, derniere_action_par = u_man, derniere_action_canal = 'mail' WHERE id = d;
                    ts := ts || t1;
                    t2 := (automation.ajouter_jours_ouvres(t1::date, 1 + (i * 5) % 5) + make_interval(hours => 8 + i % 6))::timestamp;  -- RH / compta
                ELSE
                    t2 := t1;
                END IF;
                IF t2 > now() THEN      -- le service n'a pas encore répondu : la demande reste à l'étape 2
                    en_suspens := true;
                END IF;
                IF NOT en_suspens THEN
                UPDATE public.demandes SET statut = CASE WHEN i % 13 = 0 THEN 'refusee' ELSE 'validee' END,
                       commentaire_decision = CASE WHEN i % 13 = 0 THEN 'Budget de l''équipe dépassé.' END,
                       decision_at = t2, jeton_decision = NULL,
                       decision_par = CASE WHEN NOT deux_etapes THEN u_man WHEN typ IN ('conge', 'formation') THEN u_rh ELSE u_compta END,
                       derniere_action_par = CASE WHEN NOT deux_etapes THEN u_man WHEN typ IN ('conge', 'formation') THEN u_rh ELSE u_compta END,
                       derniere_action_canal = CASE WHEN i % 2 = 0 THEN 'mail' ELSE 'appli' END
                 WHERE id = d;
                ts := ts || t2;
                -- traitement par le service (sauf « autre », sans service de traitement)
                IF i % 13 <> 0 AND typ <> 'autre' AND i < 38 AND t2 + interval '1 day' < now() THEN
                    t3 := t2 + make_interval(hours => 3 + i % 20);
                    t4 := t3 + make_interval(days => i % 3, hours => 2);
                    u := CASE typ WHEN 'conge' THEN u_rh WHEN 'formation' THEN u_rh WHEN 'note_de_frais' THEN u_compta ELSE u_admin END;
                    UPDATE public.demandes SET statut = 'en_traitement', traite_par = u, derniere_action_par = u, derniere_action_canal = 'appli' WHERE id = d;
                    ts := ts || t3;
                    IF i < 34 AND t4 < now() THEN
                        UPDATE public.demandes SET statut = 'terminee', traite_at = t4 WHERE id = d;
                        ts := ts || t4;
                    END IF;
                END IF;
                END IF;
            END IF;
        END IF;

        -- Horodatage réaliste de l'historique (dans l'ordre des événements)
        SELECT array_agg(h.id ORDER BY h.id) INTO ids FROM public.historique h WHERE h.objet = 'demande' AND h.objet_id = d;
        FOR k IN 1..coalesce(array_length(ids, 1), 0) LOOP
            UPDATE public.historique SET created_at = ts[least(k, array_length(ts, 1))] WHERE id = ids[k];
        END LOOP;
    END LOOP;

    -- Aucun mail pour les données de démonstration
    UPDATE public.mails_sortants SET statut = 'annule', derniere_erreur = 'données de démonstration', updated_at = now()
     WHERE statut = 'a_envoyer' AND demande_id IN (SELECT id FROM public.demandes WHERE objet LIKE '[DÉMO STATS]%');

    -- Statistiques recalculées pour chaque jour de la période (historique jour par jour pour le temps réel)
    FOR k IN 0..29 LOOP
        PERFORM automation.rafraichir_stats(automation.aujourdhui() - k);
    END LOOP;
END $demo$;
