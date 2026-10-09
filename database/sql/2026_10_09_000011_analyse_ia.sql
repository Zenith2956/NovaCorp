-- =====================================================================
-- Tri automatique des demandes (D1) – complète le résumé IA (D2, migration 000010)
-- Guide : docs/guide-n8n-openrouter-ngrok.md (étape 6)
--
-- Le même appel IA renvoie maintenant un JSON :
--   { "resume": "...", "points_attention": ["..."], "urgence_suggeree": true|false, "type_suggere": "materiel"|null }
-- L'Edge Function enregistrer-resume le découpe et appelle public.enregistrer_resume_ia(id, resume, analyse).
-- Rien n'est modifié ni bloqué automatiquement : ce sont des « points d'attention » montrés au valideur.
--
-- Changement du déclencheur : il part maintenant À LA FIN de la transaction (constraint trigger
-- DEFERRABLE INITIALLY DEFERRED), pour que les pièces jointes enregistrées par Laravel juste après
-- la demande soient connues de l'IA (« note de frais sans justificatif »).
-- =====================================================================

-- Nouvelle version (3 paramètres) ; l'ancienne (2 paramètres, utilisée par le rôle n8n_novacorp) est supprimée
DROP FUNCTION IF EXISTS public.enregistrer_resume_ia(bigint, text);

CREATE OR REPLACE FUNCTION public.enregistrer_resume_ia(p_demande bigint, p_resume text, p_analyse jsonb DEFAULT NULL)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
BEGIN
    IF p_resume IS NULL OR length(trim(p_resume)) = 0 THEN
        RETURN false;
    END IF;
    UPDATE public.demandes
       SET resume_ia = left(trim(p_resume), 500), analyse_ia = p_analyse, resume_ia_at = now()
     WHERE id = p_demande;
    IF NOT FOUND THEN
        RETURN false;
    END IF;
    -- Le mail au manager, mis en attente, part tout de suite avec le résumé
    UPDATE public.mails_sortants SET prochain_essai_at = now(), updated_at = now()
     WHERE demande_id = p_demande AND type = 'nouvelle_demande' AND statut = 'a_envoyer';
    IF FOUND THEN
        PERFORM automation.appeler_envoyer_mails();
    END IF;
    RETURN true;
END;
$$;
REVOKE ALL ON FUNCTION public.enregistrer_resume_ia(bigint, text, jsonb) FROM PUBLIC, anon, authenticated;

-- Le compte n8n_novacorp ne sert plus (n8n passe par l'Edge Function enregistrer-resume)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'n8n_novacorp') THEN
        REVOKE USAGE ON SCHEMA public FROM n8n_novacorp;
        DROP ROLE n8n_novacorp;
    END IF;
END $$;

-- Envoi de la demande à n8n, avec le code du type et la liste des pièces jointes
CREATE OR REPLACE FUNCTION automation.demander_resume_ia()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
DECLARE v_url text; v_secret text;
BEGIN
    SELECT decrypted_secret INTO v_url FROM vault.decrypted_secrets WHERE name = 'n8n_resume_url';
    SELECT decrypted_secret INTO v_secret FROM vault.decrypted_secrets WHERE name = 'n8n_secret';
    IF v_url IS NULL OR v_secret IS NULL OR NEW.objet LIKE '[DÉMO%' THEN
        RETURN NULL;
    END IF;

    -- Le mail au manager attend l'analyse 30 s au plus (le cron l'enverra sans sinon)
    UPDATE public.mails_sortants SET prochain_essai_at = now() + interval '30 seconds'
     WHERE demande_id = NEW.id AND type = 'nouvelle_demande' AND statut = 'a_envoyer';

    PERFORM net.http_post(
        url := v_url,
        headers := jsonb_build_object('Content-Type', 'application/json',
                                      'x-novacorp-secret', v_secret,
                                      'ngrok-skip-browser-warning', '1'),
        body := jsonb_build_object(
            'demande_id', NEW.id,
            'type_code', NEW.type,
            'type', coalesce((SELECT t.libelle FROM public.types_demande t WHERE t.code = NEW.type), NEW.type),
            'objet', NEW.objet,
            'message', NEW.message,
            'montant', NEW.montant,
            'date_debut', NEW.date_debut,
            'date_fin', NEW.date_fin,
            'nb_jours_ouvres', NEW.nb_jours_ouvres,
            'date_souhaitee', NEW.date_souhaitee,
            'urgente', NEW.urgente,
            'pieces_jointes', coalesce((SELECT jsonb_agg(jsonb_build_object('nom', p.nom_original, 'categorie', p.categorie) ORDER BY p.id)
                                          FROM public.pieces_jointes p WHERE p.demande_id = NEW.id), '[]'::jsonb)),
        timeout_milliseconds := 30000);
    RETURN NULL;
EXCEPTION WHEN others THEN
    RAISE WARNING 'analyse IA non demandée : %', SQLERRM;   -- jamais bloquant
    RETURN NULL;
END;
$$;

-- Déclenché en fin de transaction (après l'enregistrement des pièces jointes)
DROP TRIGGER IF EXISTS demandes_resume_ia ON public.demandes;
CREATE CONSTRAINT TRIGGER demandes_resume_ia AFTER INSERT ON public.demandes
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION automation.demander_resume_ia();
