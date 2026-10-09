-- =====================================================================
-- A2 – Délégation pendant les absences d'un manager
-- A3 – Alerte « équipe trop absente » sur les demandes de congé
--
-- A2 : un manager est « absent » un jour donné s'il a un congé validé couvrant ce jour.
--      Son suppléant : celui qu'il a choisi (users.suppleant_id), sinon son propre manager (N+1),
--      sinon un membre de la direction – en sautant les personnes absentes, inactives ou demandeuses.
--      * Nouvelle demande pendant l'absence → directement au suppléant (trigger demandes_delegation).
--      * Demandes déjà en attente au 1er jour d'absence → transférées au suppléant (automation.transferer_delegations,
--        toutes les heures) : nouveau lien de décision, anciens liens désactivés, demandeur prévenu.
--      demandes.manager_titulaire_id garde le manager d'origine (il continue de voir la demande). Pas de retour arrière.
-- A3 : automation.absences_equipe(demande) : collègues (même manager) absents sur la période d'un congé ;
--      alerte si, avec ce congé, le pourcentage d'absents un même jour atteint le seuil (parametres.seuil_absences_equipe_pct).
-- =====================================================================

-- ---------- Paramètres réglables par la RH ----------
CREATE TABLE IF NOT EXISTS public.parametres (
    cle text PRIMARY KEY,
    valeur text NOT NULL,
    libelle text,
    updated_at timestamp DEFAULT now()
);
ALTER TABLE public.parametres ENABLE ROW LEVEL SECURITY;
INSERT INTO public.parametres (cle, valeur, libelle)
VALUES ('seuil_absences_equipe_pct', '50', 'Alerte absences : % de l''équipe absente un même jour')
ON CONFLICT (cle) DO NOTHING;

-- ---------- A2 : absence et suppléant ----------
CREATE OR REPLACE FUNCTION automation.est_absent(p_user bigint, p_jour date)
RETURNS boolean LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT EXISTS (
        SELECT 1 FROM public.demandes c
         WHERE c.demandeur_id = p_user AND c.type = 'conge'
           AND c.statut IN ('validee', 'en_traitement', 'terminee')
           AND p_jour BETWEEN c.date_debut AND coalesce(c.date_fin, c.date_debut));
$$;

-- Premier candidat disponible : suppléant choisi, N+1, puis la direction (p_exclure = le demandeur)
CREATE OR REPLACE FUNCTION automation.suppleant_de(p_manager bigint, p_jour date, p_exclure bigint DEFAULT NULL)
RETURNS bigint LANGUAGE sql STABLE SET search_path = '' AS $$
    SELECT c.id FROM (
        SELECT s.id, 1 AS rang FROM public.users m JOIN public.users s ON s.id = m.suppleant_id WHERE m.id = p_manager
        UNION ALL
        SELECT n.id, 2 FROM public.users m JOIN public.users n ON n.id = m.manager_id WHERE m.id = p_manager
        UNION ALL
        SELECT u.id, 3 + row_number() OVER (ORDER BY u.id) FROM public.users u JOIN public.roles r ON r.id = u.role_id
         WHERE r.slug = 'direction'
    ) c JOIN public.users u ON u.id = c.id
     WHERE u.actif AND c.id <> p_manager AND c.id IS DISTINCT FROM p_exclure
       AND NOT automation.est_absent(c.id, p_jour)
     ORDER BY c.rang LIMIT 1;
$$;

-- Nouvelle demande pendant l'absence du manager : directement au suppléant
-- (nom choisi pour passer APRÈS demandes_dates, qui fixe manager_id)
CREATE OR REPLACE FUNCTION automation.deleguer_nouvelle_demande()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
DECLARE j date := automation.aujourdhui(); v_sup bigint;
BEGIN
    IF NEW.manager_id IS NULL OR NOT automation.est_absent(NEW.manager_id, j) THEN
        RETURN NEW;
    END IF;
    v_sup := automation.suppleant_de(NEW.manager_id, j, NEW.demandeur_id);
    IF v_sup IS NOT NULL THEN
        NEW.manager_titulaire_id := NEW.manager_id;
        NEW.manager_id := v_sup;
    END IF;
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS demandes_delegation ON public.demandes;
CREATE TRIGGER demandes_delegation BEFORE INSERT ON public.demandes
    FOR EACH ROW EXECUTE FUNCTION automation.deleguer_nouvelle_demande();

-- Demandes déjà en attente (étape « manager ») d'un manager qui part en congé : transfert au suppléant
CREATE OR REPLACE FUNCTION automation.transferer_delegations()
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
DECLARE j date := automation.aujourdhui(); x record; v_sup bigint; n integer := 0;
BEGIN
    FOR x IN
        SELECT d.id, d.manager_id, d.demandeur_id, d.statut
          FROM public.demandes d
          LEFT JOIN public.etapes_circuit ec ON ec.type_code = d.type AND ec.ordre = d.etape
         WHERE d.statut IN ('en_attente', 'a_completer')
           AND coalesce(ec.valideur, 'manager') = 'manager'
           AND d.manager_id IS NOT NULL
           AND automation.est_absent(d.manager_id, j)
         FOR UPDATE OF d SKIP LOCKED
    LOOP
        v_sup := automation.suppleant_de(x.manager_id, j, x.demandeur_id);
        CONTINUE WHEN v_sup IS NULL;

        UPDATE public.demandes
           SET manager_titulaire_id = coalesce(manager_titulaire_id, manager_id),
               manager_id = v_sup,
               jeton_decision = gen_random_uuid(),   -- les liens des anciens mails au manager absent ne marchent plus
               updated_at = now()
         WHERE id = x.id;

        -- Le suppléant reçoit la demande (avec les boutons de décision) ; le demandeur est prévenu
        IF x.statut = 'en_attente' THEN
            INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
            SELECT x.id, 'delegation', jsonb_build_array(s.email), '[]'::jsonb,
                   jsonb_build_object('titulaire', x.manager_id, 'suppleant', v_sup), now(), now()
              FROM public.users s WHERE s.id = v_sup
            ON CONFLICT DO NOTHING;
        END IF;
        INSERT INTO public.mails_sortants (demande_id, type, destinataires, copies, donnees, created_at, updated_at)
        SELECT x.id, 'delegation_info', jsonb_build_array(e.email), '[]'::jsonb,
               jsonb_build_object('titulaire', x.manager_id, 'suppleant', v_sup), now(), now()
          FROM public.users e WHERE e.id = x.demandeur_id
        ON CONFLICT DO NOTHING;
        n := n + 1;
    END LOOP;
    RETURN n;
END;
$$;

CREATE UNIQUE INDEX IF NOT EXISTS mails_sortants_delegation_unique
    ON public.mails_sortants (demande_id, type, (donnees ->> 'suppleant'))
    WHERE type IN ('delegation', 'delegation_info');

-- ---------- A3 : absences de l'équipe sur la période d'un congé ----------
CREATE OR REPLACE FUNCTION automation.absences_equipe(p_demande bigint)
RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = '' AS $$
DECLARE d record; v_taille int; v_seuil int; v_max int := 0; v_jour date; v_absents jsonb; x record;
BEGIN
    SELECT c.id, c.demandeur_id, c.date_debut, coalesce(c.date_fin, c.date_debut) AS date_fin, u.manager_id
      INTO d FROM public.demandes c JOIN public.users u ON u.id = c.demandeur_id
     WHERE c.id = p_demande AND c.type = 'conge' AND c.date_debut IS NOT NULL;
    IF d.id IS NULL OR d.manager_id IS NULL THEN
        RETURN NULL;   -- pas un congé, ou employé sans équipe
    END IF;

    -- Équipe = les personnes actives qui ont le même manager (demandeur compris)
    SELECT count(*) INTO v_taille FROM public.users u WHERE u.manager_id = d.manager_id AND u.actif;
    SELECT coalesce((SELECT valeur::int FROM public.parametres WHERE cle = 'seuil_absences_equipe_pct'), 50) INTO v_seuil;

    -- Collègues absents (congé validé) ou qui ont demandé un congé (en attente) sur une partie de la période
    SELECT coalesce(jsonb_agg(jsonb_build_object(
               'nom', u.prenom || ' ' || u.nom, 'debut', c.date_debut, 'fin', coalesce(c.date_fin, c.date_debut),
               'statut', CASE WHEN c.statut = 'en_attente' THEN 'en_attente' ELSE 'valide' END)
               ORDER BY c.date_debut, u.nom), '[]'::jsonb)
      INTO v_absents
      FROM public.demandes c JOIN public.users u ON u.id = c.demandeur_id
     WHERE c.type = 'conge' AND c.id <> d.id AND u.manager_id = d.manager_id AND u.id <> d.demandeur_id AND u.actif
       AND c.statut IN ('en_attente', 'validee', 'en_traitement', 'terminee')
       AND c.date_debut <= d.date_fin AND coalesce(c.date_fin, c.date_debut) >= d.date_debut;

    -- Jour ouvré le plus chargé : absents validés + ce congé
    FOR x IN SELECT g::date AS jour FROM generate_series(d.date_debut, d.date_fin, interval '1 day') g LOOP
        CONTINUE WHEN NOT automation.est_jour_ouvre(x.jour);
        DECLARE k int;
        BEGIN
            SELECT 1 + count(DISTINCT c.demandeur_id) INTO k
              FROM public.demandes c JOIN public.users u ON u.id = c.demandeur_id
             WHERE c.type = 'conge' AND c.id <> d.id AND u.manager_id = d.manager_id AND u.id <> d.demandeur_id AND u.actif
               AND c.statut IN ('validee', 'en_traitement', 'terminee')
               AND x.jour BETWEEN c.date_debut AND coalesce(c.date_fin, c.date_debut);
            IF k > v_max THEN v_max := k; v_jour := x.jour; END IF;
        END;
    END LOOP;

    RETURN jsonb_build_object(
        'taille_equipe', v_taille,
        'absents', v_absents,
        'max_simultanes', v_max,
        'jour_max', v_jour,
        'pct', CASE WHEN v_taille > 0 THEN round(100.0 * v_max / v_taille) ELSE 0 END,
        'seuil', v_seuil,
        'alerte', v_taille > 1 AND v_max > 1 AND 100.0 * v_max / v_taille >= v_seuil);
END;
$$;

REVOKE ALL ON FUNCTION automation.est_absent(bigint, date), automation.suppleant_de(bigint, date, bigint),
                       automation.transferer_delegations(), automation.absences_equipe(bigint)
    FROM PUBLIC, anon, authenticated;

-- ---------- Planification : toutes les heures (un congé validé tard le jour même est pris en compte) ----------
DO $$
BEGIN
    PERFORM cron.unschedule(jobname) FROM cron.job WHERE jobname = 'novacorp-delegations';
    PERFORM cron.schedule('novacorp-delegations', '20 * * * *', $c$ select automation.transferer_delegations() $c$);
END $$;
