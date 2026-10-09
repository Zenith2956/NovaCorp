-- Webhook base de données : pas de rafale d'appels.
-- Constat (08/10, génération des données de démo) : 48 insertions dans une même transaction
-- = 48 appels simultanés de l'Edge Function = ~50 connexions ouvertes, base saturée quelques secondes.
-- Correctif : au plus un appel toutes les 2 secondes (horodatage dans automation.webhook_dernier_appel).
-- Une même transaction ne déclenche donc qu'un appel ; un appel traite jusqu'à 20 mails et
-- le cron de chaque minute rattrape ce qui resterait.
CREATE TABLE IF NOT EXISTS automation.webhook_dernier_appel (
    nom text PRIMARY KEY,
    appele_at timestamptz NOT NULL
);
INSERT INTO automation.webhook_dernier_appel (nom, appele_at) VALUES ('envoyer-mails', '-infinity')
ON CONFLICT (nom) DO NOTHING;

CREATE OR REPLACE FUNCTION automation.webhook_mails_sortants()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = '' AS $$
DECLARE autorise boolean;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM nouveaux WHERE statut = 'a_envoyer') THEN
        RETURN NULL;
    END IF;
    -- SKIP LOCKED : si une autre transaction vient de prendre la main, elle fait l'appel
    UPDATE automation.webhook_dernier_appel w SET appele_at = clock_timestamp()
     WHERE w.nom = (SELECT x.nom FROM automation.webhook_dernier_appel x
                     WHERE x.nom = 'envoyer-mails' AND x.appele_at < clock_timestamp() - interval '2 seconds'
                     FOR UPDATE SKIP LOCKED)
    RETURNING true INTO autorise;
    IF autorise THEN
        PERFORM automation.appeler_envoyer_mails();
    END IF;
    RETURN NULL;
EXCEPTION WHEN others THEN
    RAISE WARNING 'webhook envoyer-mails non appelé : %', SQLERRM;
    RETURN NULL;
END;
$$;
