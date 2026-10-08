-- Correctifs du workflow (tests du 08/10/2026) :
--  1. le mail « décision » ne reprend le commentaire que pour un refus (pas le message d'un ancien « à compléter ») ;
--  2. l'historique d'une tâche ne garde le commentaire du chef que lorsqu'elle est renvoyée.

CREATE OR REPLACE FUNCTION automation.historiser()
RETURNS trigger LANGUAGE plpgsql SET search_path = '' AS $$
DECLARE
    v_etape smallint;
    v_commentaire text;
BEGIN
    IF TG_ARGV[0] = 'demande' THEN
        v_etape := NEW.etape;
        v_commentaire := CASE WHEN NEW.statut IN ('refusee', 'a_completer') THEN NEW.commentaire_decision END;
        IF TG_OP = 'UPDATE' AND NEW.statut IS NOT DISTINCT FROM OLD.statut AND NEW.etape IS NOT DISTINCT FROM OLD.etape THEN
            RETURN NEW;
        END IF;
    ELSE
        v_commentaire := CASE WHEN TG_OP = 'UPDATE' AND OLD.statut = 'a_valider' AND NEW.statut = 'en_cours'
                              THEN NEW.commentaire_validation END;
        IF TG_OP = 'UPDATE' AND NEW.statut IS NOT DISTINCT FROM OLD.statut THEN
            RETURN NEW;
        END IF;
    END IF;

    INSERT INTO public.historique (objet, objet_id, ancien_statut, nouveau_statut, etape, auteur_id, canal, commentaire, created_at)
    VALUES (TG_ARGV[0], NEW.id, CASE WHEN TG_OP = 'UPDATE' THEN OLD.statut END, NEW.statut, v_etape,
            NEW.derniere_action_par, coalesce(NEW.derniere_action_canal, 'systeme'), v_commentaire, now());
    RETURN NEW;
END;
$$;

DO $c$
DECLARE src text;
BEGIN
    -- Remplace « jsonb_build_object('commentaire', NEW.commentaire_decision) » du mail « décision »
    -- par le motif uniquement en cas de refus, sans réécrire toute la fonction.
    SELECT pg_get_functiondef('automation.mail_decision()'::regprocedure) INTO src;
    src := replace(src,
        $r$VALUES (NEW.id, 'decision', jsonb_build_array(e_email), '[]'::jsonb,
                jsonb_build_object('commentaire', NEW.commentaire_decision), now(), now());$r$,
        $r$VALUES (NEW.id, 'decision', jsonb_build_array(e_email), '[]'::jsonb,
                jsonb_build_object('commentaire', CASE WHEN NEW.statut = 'refusee' THEN NEW.commentaire_decision END), now(), now());$r$);
    EXECUTE src;
END $c$;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA automation FROM PUBLIC;
