-- Nettoyage des données de démonstration du workflow (étape 3, 08/10/2026)
-- À exécuter dans Supabase > SQL Editor. Ne touche qu'aux lignes « [DÉMO WF] ».
BEGIN;

DELETE FROM public.mails_sortants
 WHERE demande_id IN (SELECT id FROM public.demandes WHERE objet LIKE '[DÉMO WF]%')
    OR tache_id   IN (SELECT id FROM public.taches   WHERE titre LIKE '[DÉMO WF]%');

DELETE FROM public.historique
 WHERE (objet = 'demande' AND objet_id IN (SELECT id FROM public.demandes WHERE objet LIKE '[DÉMO WF]%'))
    OR (objet = 'tache'   AND objet_id IN (SELECT id FROM public.taches   WHERE titre LIKE '[DÉMO WF]%'));

DELETE FROM public.demandes WHERE objet LIKE '[DÉMO WF]%';
DELETE FROM public.taches   WHERE titre LIKE '[DÉMO WF]%';

-- Vérification : doit afficher 0 et 0
SELECT (SELECT count(*) FROM public.demandes WHERE objet LIKE '[DÉMO WF]%') AS demandes_demo,
       (SELECT count(*) FROM public.taches   WHERE titre LIKE '[DÉMO WF]%') AS taches_demo;
COMMIT;
