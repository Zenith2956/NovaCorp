-- =====================================================================
-- Résumé IA des demandes (D2) – NovaCorp → n8n (via ngrok) → OpenRouter → NovaCorp
-- Guide : docs/guide-n8n-openrouter-ngrok.md (étape 5)
--
-- 1) À la création d'une demande, un trigger envoie la demande à n8n (pg_net), avec un secret partagé.
--    Le mail « nouvelle demande » au manager attend le résumé 30 secondes au plus.
-- 2) n8n fait résumer la demande par l'IA puis appelle public.enregistrer_resume_ia(id, texte)
--    avec le compte dédié n8n_novacorp (il ne peut RIEN faire d'autre) ; le mail part aussitôt.
-- Désactivé tant que les secrets Vault « n8n_resume_url » et « n8n_secret » n'existent pas.
-- Si n8n / ngrok / le PC sont éteints : le mail part après 30 s, sans résumé. NovaCorp ne dépend pas de n8n.
-- =====================================================================

-- Compte de connexion dédié à n8n (mot de passe à définir par Arthur dans le SQL Editor)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'n8n_novacorp') THEN
        CREATE ROLE n8n_novacorp LOGIN NOINHERIT;
    END IF;
END $$;
GRANT USAGE ON SCHEMA public TO n8n_novacorp;

-- La seule action permise à n8n
CREATE OR REPLACE FUNCTION public.enregistrer_resume_ia(p_demande bigint, p_resume text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
BEGIN
    IF p_resume IS NULL OR length(trim(p_resume)) = 0 THEN
        RETURN false;
    END IF;
    UPDATE public.demandes SET resume_ia = left(trim(p_resume), 500), resume_ia_at = now()
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
REVOKE ALL ON FUNCTION public.enregistrer_resume_ia(bigint, text) FROM PUBLIC, anon, authenticated;
GRANT EXECUTE ON FUNCTION public.enregistrer_resume_ia(bigint, text) TO n8n_novacorp;

-- Envoi de la demande à n8n à la création
CREATE OR REPLACE FUNCTION automation.demander_resume_ia()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
DECLARE v_url text; v_secret text;
BEGIN
    SELECT decrypted_secret INTO v_url FROM vault.decrypted_secrets WHERE name = 'n8n_resume_url';
    SELECT decrypted_secret INTO v_secret FROM vault.decrypted_secrets WHERE name = 'n8n_secret';
    IF v_url IS NULL OR v_secret IS NULL OR NEW.objet LIKE '[DÉMO%' THEN
        RETURN NULL;
    END IF;

    -- Le mail au manager attend le résumé 30 s au plus (le cron l'enverra sans résumé sinon)
    UPDATE public.mails_sortants SET prochain_essai_at = now() + interval '30 seconds'
     WHERE demande_id = NEW.id AND type = 'nouvelle_demande' AND statut = 'a_envoyer';

    PERFORM net.http_post(
        url := v_url,
        headers := jsonb_build_object('Content-Type', 'application/json',
                                      'x-novacorp-secret', v_secret,
                                      'ngrok-skip-browser-warning', '1'),
        body := jsonb_build_object(
            'demande_id', NEW.id,
            'type', coalesce((SELECT t.libelle FROM public.types_demande t WHERE t.code = NEW.type), NEW.type),
            'objet', NEW.objet,
            'message', NEW.message,
            'montant', NEW.montant,
            'date_debut', NEW.date_debut,
            'date_fin', NEW.date_fin,
            'nb_jours_ouvres', NEW.nb_jours_ouvres,
            'date_souhaitee', NEW.date_souhaitee,
            'urgente', NEW.urgente),
        timeout_milliseconds := 30000);
    RETURN NULL;
EXCEPTION WHEN others THEN
    RAISE WARNING 'résumé IA non demandé : %', SQLERRM;   -- jamais bloquant
    RETURN NULL;
END;
$$;

-- Nom choisi pour passer APRÈS demandes_mail_creation (ordre alphabétique) : le mail existe déjà
DROP TRIGGER IF EXISTS demandes_resume_ia ON public.demandes;
CREATE TRIGGER demandes_resume_ia AFTER INSERT ON public.demandes
    FOR EACH ROW EXECUTE FUNCTION automation.demander_resume_ia();
