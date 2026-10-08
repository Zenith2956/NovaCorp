-- =====================================================================
-- Workflow – étape 4 : statistiques pour le tableau de bord Flutter
-- Spécification : docs/propositions-workflow.md · mode d'emploi Flutter : docs/stats-flutter.md
--
-- Les tables de l'appli gardent le RLS SANS règle : Flutter ne voit jamais les demandes.
-- Il n'a accès qu'à des chiffres agrégés (rien de nominatif) :
--   A. public.stats_workflow(debut, fin) : fonction RPC, droits vérifiés via le compte connecté ;
--   C. public.stats_quotidiennes : une ligne par jour et par périmètre, tenue à jour par trigger,
--      diffusée en Realtime (le graphique bouge dès qu'une demande change).
-- Comptes : un compte Supabase Auth (e-mail confirmé) est relié à l'employé qui a la même adresse.
-- =====================================================================

-- ---------- Lien compte Supabase ↔ employé NovaCorp ----------
CREATE OR REPLACE FUNCTION public.novacorp_profil()
RETURNS TABLE (user_id bigint, role text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = '' AS $$
    SELECT u.id, r.slug::text
      FROM auth.users a
      JOIN public.users u ON lower(u.email) = lower(a.email) AND u.actif
      JOIN public.roles r ON r.id = u.role_id
     WHERE a.id = auth.uid()
       AND a.email_confirmed_at IS NOT NULL   -- pas de lien sur une adresse non vérifiée
     LIMIT 1;
$$;
REVOKE ALL ON FUNCTION public.novacorp_profil() FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.novacorp_profil() TO authenticated;

-- ---------- Calcul (privé) : périmètre = toute l'entreprise (p_manager null) ou l'équipe d'un manager ----------
CREATE OR REPLACE FUNCTION automation.calculer_stats(p_manager bigint, p_debut date, p_fin date)
RETURNS jsonb LANGUAGE sql STABLE SET search_path = '' AS $$
WITH d AS (
    SELECT x.*,
           automation.date_paris(x.created_at) AS jour_creation,
           automation.date_paris(coalesce(x.envoyee_at, x.created_at)) AS jour_envoi,
           automation.date_paris(x.decision_at) AS jour_decision
      FROM public.demandes x
     WHERE p_manager IS NULL OR x.manager_id = p_manager
),
creees AS (SELECT * FROM d WHERE jour_creation BETWEEN p_debut AND p_fin),
decidees AS (
    SELECT d.*, automation.jours_ouvres_periode(d.jour_envoi + 1, d.jour_decision) AS duree
      FROM d WHERE d.decision_at IS NOT NULL AND d.jour_decision BETWEEN p_debut AND p_fin
),
-- Temps passé dans chaque état, d'après l'historique : attribué au service qui devait agir
etats AS (
    SELECT h.*, d.type, lead(h.created_at) OVER (PARTITION BY h.objet_id ORDER BY h.id) AS fin_etat
      FROM public.historique h JOIN d ON d.id = h.objet_id
     WHERE h.objet = 'demande'
),
passages AS (
    SELECT CASE
               WHEN e.nouveau_statut = 'en_attente' THEN coalesce(ec.valideur, 'manager')
               WHEN e.nouveau_statut = 'a_completer' THEN 'employe'
               WHEN e.nouveau_statut IN ('validee', 'en_traitement') THEN td.role_traitement
           END AS service,
           extract(epoch FROM (e.fin_etat - e.created_at)) / 86400.0 AS jours
      FROM etats e
      LEFT JOIN public.etapes_circuit ec ON ec.type_code = e.type AND ec.ordre = e.etape
      LEFT JOIN public.types_demande td ON td.code = e.type
     WHERE e.fin_etat IS NOT NULL AND automation.date_paris(e.fin_etat) BETWEEN p_debut AND p_fin
),
services AS (
    SELECT p.service, count(*) AS passages, round(avg(p.jours)::numeric, 1) AS jours_moyens
      FROM passages p WHERE p.service IS NOT NULL GROUP BY p.service
),
t AS (
    SELECT tk.* FROM public.taches tk LEFT JOIN public.projets pr ON pr.id = tk.projet_id
     WHERE p_manager IS NULL OR pr.chef_projet_id = p_manager
)
SELECT jsonb_build_object(
    'perimetre', CASE WHEN p_manager IS NULL THEN 'entreprise' ELSE 'equipe' END,
    'periode', jsonb_build_object('debut', p_debut, 'fin', p_fin),
    'volume', jsonb_build_object(
        'creees', (SELECT count(*) FROM creees),
        'par_type', (SELECT coalesce(jsonb_object_agg(type, n), '{}'::jsonb) FROM (SELECT type, count(*) AS n FROM creees GROUP BY type) v),
        'par_statut', (SELECT coalesce(jsonb_object_agg(statut, n), '{}'::jsonb) FROM (SELECT statut, count(*) AS n FROM creees GROUP BY statut) v)),
    'par_jour', (SELECT coalesce(jsonb_agg(jsonb_build_object(
                        'jour', g.j,
                        'creees', (SELECT count(*) FROM creees c WHERE c.jour_creation = g.j),
                        'decisions', (SELECT count(*) FROM decidees x WHERE x.jour_decision = g.j)) ORDER BY g.j), '[]'::jsonb)
                   FROM (SELECT s::date AS j FROM generate_series(p_debut::timestamp, p_fin::timestamp, interval '1 day') AS s) g),
    'decisions', jsonb_build_object(
        'total', (SELECT count(*) FROM decidees),
        'validees', (SELECT count(*) FROM decidees WHERE statut <> 'refusee'),
        'refusees', (SELECT count(*) FROM decidees WHERE statut = 'refusee'),
        'temps_moyen_jours_ouvres', (SELECT round(avg(duree), 1) FROM decidees),
        'temps_moyen_par_type', (SELECT coalesce(jsonb_object_agg(type, m), '{}'::jsonb)
                                   FROM (SELECT type, round(avg(duree), 1) AS m FROM decidees GROUP BY type) v),
        'dans_les_temps_pct', (SELECT round(100.0 * avg(CASE WHEN jour_decision <= echeance_le THEN 1 ELSE 0 END), 1) FROM decidees)),
    'services', (SELECT coalesce(jsonb_agg(jsonb_build_object(
                        'service', s.service,
                        'libelle', CASE s.service WHEN 'manager' THEN 'Managers' WHEN 'employe' THEN 'Employés (compléments)'
                                                  ELSE coalesce(r.libelle, s.service) END,
                        'passages', s.passages, 'jours_moyens', s.jours_moyens) ORDER BY s.passages DESC), '[]'::jsonb)
                   FROM services s LEFT JOIN public.roles r ON r.slug = s.service),
    'en_cours', jsonb_build_object(
        'en_attente', (SELECT count(*) FROM d WHERE statut = 'en_attente'),
        'en_retard', (SELECT count(*) FROM d WHERE statut = 'en_attente' AND echeance_le < automation.aujourdhui()),
        'a_completer', (SELECT count(*) FROM d WHERE statut = 'a_completer'),
        'a_traiter', (SELECT count(*) FROM d WHERE statut = 'validee'
                        AND EXISTS (SELECT 1 FROM public.types_demande y WHERE y.code = d.type AND y.role_traitement IS NOT NULL)),
        'en_traitement', (SELECT count(*) FROM d WHERE statut = 'en_traitement')),
    'taches', (SELECT jsonb_build_object(
                    'a_faire', count(*) FILTER (WHERE statut = 'a_faire'),
                    'en_cours', count(*) FILTER (WHERE statut = 'en_cours'),
                    'a_valider', count(*) FILTER (WHERE statut = 'a_valider'),
                    'expirees', count(*) FILTER (WHERE statut = 'expiree'),
                    'terminees_periode', count(*) FILTER (WHERE statut = 'terminee'
                        AND automation.date_paris(termine_at) BETWEEN p_debut AND p_fin))
                 FROM t),
    'calcule_le', now()
);
$$;

-- ---------- A. Fonction RPC pour Flutter ----------
CREATE OR REPLACE FUNCTION public.stats_workflow(p_debut date DEFAULT NULL, p_fin date DEFAULT NULL)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = '' AS $$
DECLARE
    v_user bigint;
    v_role text;
    v_fin date := coalesce(p_fin, automation.aujourdhui());
    v_debut date := coalesce(p_debut, coalesce(p_fin, automation.aujourdhui()) - 29);
BEGIN
    SELECT p.user_id, p.role INTO v_user, v_role FROM public.novacorp_profil() p;
    IF v_user IS NULL THEN
        RAISE EXCEPTION 'Accès refusé : ce compte n''est relié à aucun employé NovaCorp (même e-mail, adresse confirmée)'
            USING ERRCODE = '42501';
    END IF;
    IF v_debut > v_fin OR v_fin - v_debut > 366 THEN
        RAISE EXCEPTION 'Période invalide (un an maximum)' USING ERRCODE = '22023';
    END IF;

    IF v_role IN ('rh', 'direction', 'admin') THEN
        RETURN automation.calculer_stats(NULL, v_debut, v_fin);
    ELSIF v_role = 'manager' THEN
        RETURN automation.calculer_stats(v_user, v_debut, v_fin);   -- son équipe uniquement
    END IF;
    RAISE EXCEPTION 'Accès refusé : statistiques réservées aux RH, à la direction et aux managers' USING ERRCODE = '42501';
END;
$$;
REVOKE ALL ON FUNCTION public.stats_workflow(date, date) FROM PUBLIC, anon;
GRANT EXECUTE ON FUNCTION public.stats_workflow(date, date) TO authenticated;

-- ---------- C. Table de statistiques en temps réel ----------
CREATE TABLE IF NOT EXISTS public.stats_quotidiennes (
    id bigserial PRIMARY KEY,
    jour date NOT NULL,
    manager_id bigint REFERENCES public.users(id) ON DELETE CASCADE,  -- null = toute l'entreprise
    demandes_creees integer NOT NULL DEFAULT 0,      -- ce jour-là
    decisions integer NOT NULL DEFAULT 0,            -- ce jour-là
    en_attente integer NOT NULL DEFAULT 0,           -- état actuel
    en_retard integer NOT NULL DEFAULT 0,
    temps_moyen_jours numeric(6,1),                  -- 30 derniers jours, jours ouvrés
    dans_les_temps_pct numeric(5,1),                 -- 30 derniers jours
    detail jsonb NOT NULL DEFAULT '{}'::jsonb,       -- même contenu que stats_workflow() sur 30 jours
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX IF NOT EXISTS stats_quotidiennes_jour_perimetre ON public.stats_quotidiennes (jour, (coalesce(manager_id, 0)));
ALTER TABLE public.stats_quotidiennes ENABLE ROW LEVEL SECURITY;
REVOKE ALL ON public.stats_quotidiennes FROM anon;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON public.stats_quotidiennes FROM authenticated;
GRANT SELECT ON public.stats_quotidiennes TO authenticated;

DROP POLICY IF EXISTS stats_lecture ON public.stats_quotidiennes;
CREATE POLICY stats_lecture ON public.stats_quotidiennes FOR SELECT TO authenticated USING (
    EXISTS (SELECT 1 FROM public.novacorp_profil() p
             WHERE (stats_quotidiennes.manager_id IS NULL AND p.role IN ('rh', 'direction', 'admin'))
                OR (stats_quotidiennes.manager_id = p.user_id AND p.role = 'manager'))
);

-- Diffusion Realtime (le RLS ci-dessus s'applique aussi aux abonnés)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_publication WHERE pubname = 'supabase_realtime')
       AND NOT EXISTS (SELECT 1 FROM pg_publication_tables
                        WHERE pubname = 'supabase_realtime' AND schemaname = 'public' AND tablename = 'stats_quotidiennes') THEN
        ALTER PUBLICATION supabase_realtime ADD TABLE public.stats_quotidiennes;
    END IF;
END $$;

-- ---------- Mise à jour : entreprise + chaque manager ----------
CREATE OR REPLACE FUNCTION automation.rafraichir_stats(p_jour date DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
DECLARE v_jour date := coalesce(p_jour, automation.aujourdhui());
BEGIN
    INSERT INTO public.stats_quotidiennes AS s
           (jour, manager_id, demandes_creees, decisions, en_attente, en_retard, temps_moyen_jours, dans_les_temps_pct, detail, updated_at)
    SELECT v_jour, p.manager_id,
           coalesce((c.detail -> 'par_jour' -> -1 ->> 'creees')::integer, 0),
           coalesce((c.detail -> 'par_jour' -> -1 ->> 'decisions')::integer, 0),
           (c.detail -> 'en_cours' ->> 'en_attente')::integer,
           (c.detail -> 'en_cours' ->> 'en_retard')::integer,
           (c.detail -> 'decisions' ->> 'temps_moyen_jours_ouvres')::numeric,
           (c.detail -> 'decisions' ->> 'dans_les_temps_pct')::numeric,
           c.detail, now()
      FROM (SELECT NULL::bigint AS manager_id
            UNION SELECT u.id FROM public.users u JOIN public.roles r ON r.id = u.role_id WHERE r.slug = 'manager' AND u.actif
            UNION SELECT DISTINCT x.manager_id FROM public.demandes x WHERE x.manager_id IS NOT NULL) p
     CROSS JOIN LATERAL (SELECT automation.calculer_stats(p.manager_id, v_jour - 29, v_jour) AS detail) c
    ON CONFLICT (jour, (coalesce(manager_id, 0))) DO UPDATE
       SET demandes_creees = EXCLUDED.demandes_creees, decisions = EXCLUDED.decisions,
           en_attente = EXCLUDED.en_attente, en_retard = EXCLUDED.en_retard,
           temps_moyen_jours = EXCLUDED.temps_moyen_jours, dans_les_temps_pct = EXCLUDED.dans_les_temps_pct,
           detail = EXCLUDED.detail, updated_at = now()
     WHERE s.detail - 'calcule_le' IS DISTINCT FROM EXCLUDED.detail - 'calcule_le';  -- pas d'événement Realtime inutile
END;
$$;

-- Après chaque changement de demande ou de tâche (une fois par requête, jamais bloquant)
CREATE OR REPLACE FUNCTION automation.stats_apres_modification()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
BEGIN
    PERFORM automation.rafraichir_stats();
    RETURN NULL;
EXCEPTION WHEN others THEN
    RAISE WARNING 'stats non mises à jour : %', SQLERRM;   -- les statistiques ne bloquent jamais le métier
    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS demandes_stats ON public.demandes;
CREATE TRIGGER demandes_stats AFTER INSERT OR DELETE OR UPDATE OF statut, etape ON public.demandes
    FOR EACH STATEMENT EXECUTE FUNCTION automation.stats_apres_modification();
DROP TRIGGER IF EXISTS taches_stats ON public.taches;
CREATE TRIGGER taches_stats AFTER INSERT OR DELETE OR UPDATE OF statut ON public.taches
    FOR EACH STATEMENT EXECUTE FUNCTION automation.stats_apres_modification();

-- Chaque nuit : clôture de la veille et ligne du jour (retards qui apparaissent sans changement de statut)
SELECT cron.schedule('novacorp-stats', '10 0 * * *',
    $$ SELECT automation.rafraichir_stats(automation.aujourdhui() - 1); SELECT automation.rafraichir_stats(); $$);

-- Première ligne
SELECT automation.rafraichir_stats();
