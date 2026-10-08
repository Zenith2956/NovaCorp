-- =====================================================================
-- Scénarios S4 (récapitulatif) et S5 (bienvenue) – docs/tests-scenarios.md
-- S4 : chaque projet a un jour de réunion (hebdomadaire, toutes les 2 semaines, ou 1er <jour> du mois).
--      Le jour de la réunion à 8 h (Paris), récapitulatif du projet depuis la réunion précédente :
--      au chef de projet (tâches + demandes de son équipe + chiffres clés) et aux membres (tâches + chiffres).
--      Jour férié : pas de réunion, pas de récapitulatif.
-- S5 : un employé ajouté dans users → mail de bienvenue + checklist du premier jour, son manager en copie.
-- Les mails sont rédigés par l'Edge Function « envoyer-mails » (types recap_projet et bienvenue).
-- =====================================================================

-- Boîte d'envoi : un mail peut maintenant viser un projet ou un utilisateur
ALTER TABLE public.mails_sortants DROP CONSTRAINT IF EXISTS mails_sortants_cible_check;
ALTER TABLE public.mails_sortants ADD CONSTRAINT mails_sortants_cible_check
    CHECK (num_nonnulls(demande_id, tache_id, projet_id, user_id) >= 1);

CREATE UNIQUE INDEX IF NOT EXISTS mails_sortants_recap_unique
    ON public.mails_sortants (projet_id, type, (donnees ->> 'role'), (donnees ->> 'date'))
    WHERE type = 'recap_projet';
CREATE UNIQUE INDEX IF NOT EXISTS mails_sortants_bienvenue_unique
    ON public.mails_sortants (user_id, type) WHERE type = 'bienvenue';

-- ---------- S4 : jour de réunion ----------
CREATE OR REPLACE FUNCTION automation.est_jour_reunion(frequence text, jour smallint, depuis date, d date)
RETURNS boolean LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT frequence IS NOT NULL AND jour IS NOT NULL
       AND extract(isodow FROM d) = jour
       AND automation.est_jour_ouvre(d)
       AND CASE frequence
             WHEN 'hebdomadaire' THEN true
             WHEN 'bimensuelle'  THEN depuis IS NOT NULL AND d >= depuis AND (d - depuis) % 14 = 0
             WHEN 'mensuelle'    THEN extract(day FROM d) <= 7     -- 1er <jour> du mois
             ELSE false
           END;
$$;

-- Cron (jours ouvrés, 8 h Paris) ; p_forcer = true pour les tests (ignore l'heure)
CREATE OR REPLACE FUNCTION automation.planifier_recaps(p_forcer boolean DEFAULT false)
RETURNS integer LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE j date := automation.aujourdhui(); n integer := 0; k integer;
BEGIN
    IF NOT p_forcer AND extract(hour FROM now() AT TIME ZONE 'Europe/Paris') < 8 THEN
        RETURN 0;
    END IF;

    -- Chef de projet : tâches + demandes de son équipe + chiffres clés
    INSERT INTO public.mails_sortants (projet_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT p.id, 'recap_projet', jsonb_build_array(c.email), '[]'::jsonb,
           jsonb_build_object('role', 'chef', 'date', j,
                              'depuis', coalesce(p.dernier_recap_le, j - CASE p.reunion_frequence WHEN 'hebdomadaire' THEN 7 WHEN 'bimensuelle' THEN 14 ELSE 28 END)),
           now(), now()
      FROM public.projets p JOIN public.users c ON c.id = p.chef_projet_id AND c.actif
     WHERE p.statut = 'en_cours'
       AND automation.est_jour_reunion(p.reunion_frequence, p.reunion_jour, p.reunion_depuis, j)
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

    -- Membres : tâches + chiffres clés (un seul mail pour tous les membres)
    INSERT INTO public.mails_sortants (projet_id, type, destinataires, copies, donnees, created_at, updated_at)
    SELECT p.id, 'recap_projet', m.emails, '[]'::jsonb,
           jsonb_build_object('role', 'membres', 'date', j,
                              'depuis', coalesce(p.dernier_recap_le, j - CASE p.reunion_frequence WHEN 'hebdomadaire' THEN 7 WHEN 'bimensuelle' THEN 14 ELSE 28 END)),
           now(), now()
      FROM public.projets p
      JOIN LATERAL (SELECT jsonb_agg(u.email ORDER BY u.id) AS emails
                      FROM public.projet_user pu JOIN public.users u ON u.id = pu.user_id AND u.actif
                     WHERE pu.projet_id = p.id AND u.id IS DISTINCT FROM p.chef_projet_id) m ON m.emails IS NOT NULL
     WHERE p.statut = 'en_cours'
       AND automation.est_jour_reunion(p.reunion_frequence, p.reunion_jour, p.reunion_depuis, j)
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS k = ROW_COUNT; n := n + k;

    UPDATE public.projets p SET dernier_recap_le = j
     WHERE p.statut = 'en_cours'
       AND automation.est_jour_reunion(p.reunion_frequence, p.reunion_jour, p.reunion_depuis, j)
       AND p.dernier_recap_le IS DISTINCT FROM j;
    RETURN n;
END;
$$;

-- 6 h et 7 h UTC = 8 h Paris en été / en hiver (la fonction ignore l'appel trop tôt ; pas de doublon grâce à l'index)
SELECT cron.schedule('novacorp-recaps', '0 6,7 * * 1-5', $$ SELECT automation.planifier_recaps(); $$);

-- ---------- S5 : bienvenue ----------
-- Désactivable pour un import en masse (seeders) : SET novacorp.sans_mails = 'on'
CREATE OR REPLACE FUNCTION automation.mail_bienvenue()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
BEGIN
    IF NOT NEW.actif OR coalesce(current_setting('novacorp.sans_mails', true), '') = 'on' THEN
        RETURN NEW;
    END IF;
    INSERT INTO public.mails_sortants (user_id, type, destinataires, copies, created_at, updated_at)
    VALUES (NEW.id, 'bienvenue', jsonb_build_array(NEW.email),
            coalesce((SELECT jsonb_build_array(m.email) FROM public.users m WHERE m.id = NEW.manager_id), '[]'::jsonb),
            now(), now())
    ON CONFLICT DO NOTHING;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS users_bienvenue ON public.users;
CREATE TRIGGER users_bienvenue AFTER INSERT ON public.users
    FOR EACH ROW EXECUTE FUNCTION automation.mail_bienvenue();
