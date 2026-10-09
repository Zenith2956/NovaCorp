-- =====================================================================
-- C1 – Bilan de santé quotidien (mail à l'admin SEULEMENT s'il y a un problème)
-- A1 – Relance des demandes « à traiter » et des tâches « à valider » oubliées
--
-- C1 : chaque jour à 7 h (Paris), automation.bilan_sante() vérifie les dernières 24 h :
--      mails en échec / bloqués / rejetés par la messagerie, tâches planifiées (pg_cron) en erreur,
--      erreurs serveur des Edge Functions, demandes qui auraient dû expirer.
--      S'il trouve une alerte : un mail « bilan_sante » aux admins (direction à défaut).
--      Les informations seules (ex. demandes sans résumé IA car n8n éteint) ne déclenchent pas de mail.
--      Test à la main : select automation.bilan_sante(true);  → renvoie le bilan sans rien envoyer.
-- A1 : chaque jour ouvré à 9 h, automation.planifier_relances_oublis() :
--      demande validée non prise en charge par le service : relance au service à J+2 ouvrés, escalade direction à J+5 ;
--      tâche « à valider » oubliée par le chef de projet : relance au chef à J+2, escalade à son supérieur (RH à défaut) à J+5.
-- =====================================================================

-- ---------- Un seul mail par palier (et par passage dans l'état, une tâche pouvant être renvoyée puis resoumise) ----------
CREATE UNIQUE INDEX IF NOT EXISTS mails_sortants_oubli_demande_unique
    ON public.mails_sortants (demande_id, type, (donnees ->> 'depuis'))
    WHERE type IN ('relance_a_traiter', 'escalade_a_traiter');
CREATE UNIQUE INDEX IF NOT EXISTS mails_sortants_oubli_tache_unique
    ON public.mails_sortants (tache_id, type, (donnees ->> 'depuis'))
    WHERE type IN ('tache_relance_validation', 'tache_escalade_validation');
CREATE UNIQUE INDEX IF NOT EXISTS mails_sortants_bilan_unique
    ON public.mails_sortants (type, (donnees ->> 'date'))
    WHERE type = 'bilan_sante';

-- ---------- Ce qui attend : demandes validées non prises en charge, tâches non validées par le chef ----------
-- « depuis » = jour (Paris) d'entrée dans l'état actuel, d'après l'historique
CREATE OR REPLACE FUNCTION automation.demandes_a_traiter_oubliees()
RETURNS TABLE (demande_id bigint, service text, depuis date) LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT d.id, td.role_traitement,
           automation.date_paris(coalesce(
               (SELECT max(h.created_at) FROM public.historique h
                 WHERE h.objet = 'demande' AND h.objet_id = d.id AND h.nouveau_statut = 'validee'),
               d.decision_at, d.updated_at))
      FROM public.demandes d JOIN public.types_demande td ON td.code = d.type
     WHERE d.statut = 'validee' AND td.role_traitement IS NOT NULL
       AND d.objet NOT LIKE '[DÉMO%';   -- données de démonstration des statistiques : pas de relance
$$;

CREATE OR REPLACE FUNCTION automation.taches_a_valider_oubliees()
RETURNS TABLE (tache_id bigint, chef_email text, sup_email text, depuis date) LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT t.id, c.email, s.email,
           automation.date_paris(coalesce(
               (SELECT max(h.created_at) FROM public.historique h
                 WHERE h.objet = 'tache' AND h.objet_id = t.id AND h.nouveau_statut = 'a_valider'),
               t.updated_at))
      FROM public.taches t
      JOIN public.projets p ON p.id = t.projet_id
      JOIN public.users c ON c.id = p.chef_projet_id
      LEFT JOIN public.users s ON s.id = c.manager_id AND s.actif
     WHERE t.statut = 'a_valider' AND p.nom NOT LIKE '[DÉMO%';
$$;

-- ---------- A1 ----------
CREATE OR REPLACE FUNCTION automation.planifier_relances_oublis()
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
DECLARE j date := automation.aujourdhui(); total integer := 0; n integer;
BEGIN
    -- Demande validée non prise en charge : relance au service (J+2 ouvrés)…
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT o.demande_id, 'relance_a_traiter', automation.emails_role(o.service), '[]'::jsonb,
           jsonb_build_object('service', o.service, 'depuis', o.depuis), now(), now()
      FROM automation.demandes_a_traiter_oubliees() o
     WHERE automation.ajouter_jours_ouvres(o.depuis, 2) <= j
       AND jsonb_array_length(automation.emails_role(o.service)) > 0
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- … puis escalade à la direction, service en copie (J+5 ouvrés)
    INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT o.demande_id, 'escalade_a_traiter', automation.emails_role('direction'), automation.emails_role(o.service),
           jsonb_build_object('service', o.service, 'depuis', o.depuis), now(), now()
      FROM automation.demandes_a_traiter_oubliees() o
     WHERE automation.ajouter_jours_ouvres(o.depuis, 5) <= j
       AND jsonb_array_length(automation.emails_role('direction')) > 0
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- Tâche « à valider » : relance au chef de projet (J+2 ouvrés)…
    INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT o.tache_id, 'tache_relance_validation', jsonb_build_array(o.chef_email), '[]'::jsonb,
           jsonb_build_object('depuis', o.depuis), now(), now()
      FROM automation.taches_a_valider_oubliees() o
     WHERE automation.ajouter_jours_ouvres(o.depuis, 2) <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    -- … puis escalade à son supérieur (RH à défaut), chef en copie (J+5 ouvrés)
    INSERT INTO public.mails_sortants (tache_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT o.tache_id, 'tache_escalade_validation',
           CASE WHEN o.sup_email IS NOT NULL THEN jsonb_build_array(o.sup_email) ELSE automation.emails_role('rh') END,
           jsonb_build_array(o.chef_email), jsonb_build_object('depuis', o.depuis), now(), now()
      FROM automation.taches_a_valider_oubliees() o
     WHERE automation.ajouter_jours_ouvres(o.depuis, 5) <= j
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT; total := total + n;

    RETURN total;
END;
$$;

-- ---------- C1 ----------
CREATE OR REPLACE FUNCTION automation.bilan_sante(p_test boolean DEFAULT false)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
DECLARE
    j date := automation.aujourdhui();
    pb jsonb := '[]'::jsonb;
    n integer; detail text; dest jsonb; admin_id bigint; bilan jsonb;
BEGIN
    -- 1. Mails abandonnés après 5 tentatives
    SELECT count(*), string_agg(DISTINCT left(coalesce(derniere_erreur, '?'), 150), ' | ')
      INTO n, detail FROM public.mails_sortants
     WHERE statut = 'echec' AND updated_at > now() - interval '24 hours';
    IF n > 0 THEN
        pb := pb || jsonb_build_object('niveau', 'alerte', 'titre', n || ' mail(s) abandonné(s) après 5 tentatives', 'detail', detail);
    END IF;

    -- 2. Mails bloqués dans la boîte d'envoi (l'envoi ne tourne plus ?)
    SELECT count(*) INTO n FROM public.mails_sortants
     WHERE statut IN ('a_envoyer', 'en_cours') AND coalesce(prochain_essai_at, created_at) < now() - interval '30 minutes';
    IF n > 0 THEN
        pb := pb || jsonb_build_object('niveau', 'alerte', 'titre', n || ' mail(s) en attente depuis plus de 30 minutes',
                                       'detail', 'Vérifier l''Edge Function envoyer-mails, la clé Resend et la tâche novacorp-envoyer-mails.');
    END IF;

    -- 3. Mails rejetés par la messagerie du destinataire (adresse invalide, spam…)
    SELECT count(*), string_agg(DISTINCT livraison || ' : ' || (destinataires ->> 0), ', ')
      INTO n, detail FROM public.mails_sortants
     WHERE livraison IN ('rebond', 'plainte', 'echec') AND livraison_at > now() - interval '24 hours';
    IF n > 0 THEN
        pb := pb || jsonb_build_object('niveau', 'alerte', 'titre', n || ' mail(s) non distribué(s) ou signalé(s) comme spam', 'detail', detail);
    END IF;

    -- 4. Tâches planifiées (pg_cron) en erreur
    SELECT count(*), string_agg(DISTINCT jb.jobname || ' : ' || left(coalesce(r.return_message, '?'), 120), ' | ')
      INTO n, detail
      FROM cron.job_run_details r JOIN cron.job jb ON jb.jobid = r.jobid
     WHERE r.status = 'failed' AND r.start_time > now() - interval '24 hours';
    IF n > 0 THEN
        pb := pb || jsonb_build_object('niveau', 'alerte', 'titre', n || ' exécution(s) de tâche planifiée en erreur', 'detail', detail);
    END IF;

    -- 5. Erreurs serveur des appels HTTP (Edge Functions) – les délais dépassés, fréquents et sans gravité, sont ignorés
    SELECT count(*), string_agg(DISTINCT status_code::text || ' ' || left(coalesce(content::text, ''), 100), ' | ')
      INTO n, detail FROM net._http_response
     WHERE status_code >= 500 AND created > now() - interval '24 hours';
    IF n > 0 THEN
        pb := pb || jsonb_build_object('niveau', 'alerte', 'titre', n || ' erreur(s) serveur d''Edge Function', 'detail', detail);
    END IF;

    -- 6. Demandes qui auraient dû expirer (la tâche d'expiration ne tourne plus ?)
    SELECT count(*) INTO n FROM public.demandes
     WHERE statut IN ('en_attente', 'a_completer') AND deadline < j - 1;
    IF n > 0 THEN
        pb := pb || jsonb_build_object('niveau', 'alerte', 'titre', n || ' demande(s) non expirée(s) malgré une date limite dépassée',
                                       'detail', 'Vérifier la tâche planifiée novacorp-expiration.');
    END IF;

    -- 7. (information) Demandes sans résumé IA : n8n / ngrok / PC éteints – n'envoie pas de mail à lui seul
    IF EXISTS (SELECT 1 FROM vault.decrypted_secrets WHERE name = 'n8n_resume_url') THEN
        SELECT count(*) INTO n FROM public.demandes
         WHERE created_at > now() - interval '24 hours' AND created_at < now() - interval '5 minutes'
           AND resume_ia IS NULL AND objet NOT LIKE '[DÉMO%';
        IF n > 0 THEN
            pb := pb || jsonb_build_object('niveau', 'info', 'titre', n || ' demande(s) sans résumé IA',
                                           'detail', 'n8n, ngrok ou le PC qui les héberge étaient sans doute éteints : les mails sont partis sans résumé.');
        END IF;
    END IF;

    bilan := jsonb_build_object('date', j, 'problemes', pb,
                                'alertes', (SELECT count(*) FROM jsonb_array_elements(pb) e WHERE e ->> 'niveau' = 'alerte'));

    IF p_test OR (bilan ->> 'alertes')::int = 0 THEN
        RETURN bilan;   -- tout va bien (ou test) : pas de mail
    END IF;

    dest := automation.emails_role('admin');
    IF jsonb_array_length(dest) = 0 THEN dest := automation.emails_role('direction'); END IF;
    SELECT u.id INTO admin_id FROM public.users u WHERE u.email = dest ->> 0;
    IF admin_id IS NULL THEN
        RAISE WARNING 'bilan de santé : aucun admin ni direction pour recevoir le mail';
        RETURN bilan;
    END IF;

    INSERT INTO public.mails_sortants (user_id, type, destinataires, copies, donnees, created_at, updated_at)
    VALUES (admin_id, 'bilan_sante', dest, '[]'::jsonb, bilan, now(), now())
    ON CONFLICT DO NOTHING;
    RETURN bilan;
END;
$$;

REVOKE ALL ON FUNCTION automation.planifier_relances_oublis(), automation.bilan_sante(boolean),
                       automation.demandes_a_traiter_oubliees(), automation.taches_a_valider_oubliees()
    FROM PUBLIC, anon, authenticated;

-- ---------- Planification (heure de Paris garantie été comme hiver) ----------
DO $$
BEGIN
    PERFORM cron.unschedule(jobname) FROM cron.job WHERE jobname IN ('novacorp-bilan-sante', 'novacorp-relances-oublis');
    PERFORM cron.schedule('novacorp-bilan-sante', '0 5,6 * * *',
        $c$ select automation.bilan_sante() where extract(hour from now() at time zone 'Europe/Paris') = 7 $c$);
    PERFORM cron.schedule('novacorp-relances-oublis', '0 7,8 * * 1-5',
        $c$ select automation.planifier_relances_oublis() where extract(hour from now() at time zone 'Europe/Paris') = 9 $c$);
END $$;
