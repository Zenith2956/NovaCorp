-- =====================================================================
-- Architecture – webhooks (docs/architecture-technique.md, proposition E)
-- 1) Webhook base de données : dès qu'un mail entre dans la boîte d'envoi, l'Edge Function
--    « envoyer-mails » est appelée (pg_net, après validation de la transaction).
--    Le cron de chaque minute reste en filet de sécurité (appel perdu, nouvel essai programmé…).
-- 2) Webhook Resend → Edge Function « webhook-resend » : statut de livraison réel de chaque mail
--    (tables : colonnes livraison* de mails_sortants, journal mails_evenements – créés par la migration Laravel).
-- =====================================================================

CREATE OR REPLACE FUNCTION automation.webhook_mails_sortants()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
BEGIN
    -- Une seule requête par instruction SQL, et seulement s'il y a vraiment un mail à envoyer
    IF EXISTS (SELECT 1 FROM nouveaux WHERE statut = 'a_envoyer') THEN
        PERFORM automation.appeler_envoyer_mails();
    END IF;
    RETURN NULL;
EXCEPTION WHEN others THEN
    RAISE WARNING 'webhook envoyer-mails non appelé : %', SQLERRM;  -- jamais bloquant : le cron prendra le relais
    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS mails_sortants_webhook ON public.mails_sortants;
CREATE TRIGGER mails_sortants_webhook
    AFTER INSERT ON public.mails_sortants
    REFERENCING NEW TABLE AS nouveaux
    FOR EACH STATEMENT EXECUTE FUNCTION automation.webhook_mails_sortants();

-- Webhook Resend : rang des statuts (un événement plus ancien n'écrase pas un plus récent)
CREATE OR REPLACE FUNCTION automation.rang_livraison(statut text)
RETURNS integer LANGUAGE sql IMMUTABLE SET search_path = '' AS $$
    SELECT CASE statut WHEN 'envoye' THEN 1 WHEN 'retarde' THEN 2 WHEN 'delivre' THEN 3
                       WHEN 'echec' THEN 4 WHEN 'rebond' THEN 4 WHEN 'plainte' THEN 5 ELSE 0 END;
$$;

ALTER TABLE public.mails_evenements ENABLE ROW LEVEL SECURITY;
