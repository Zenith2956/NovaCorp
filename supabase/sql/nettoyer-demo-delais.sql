-- Nettoyage de la démonstration « gestion des délais » du 08/10/2026.
-- À exécuter dans Supabase > SQL Editor AVANT le vendredi 09/10 à 9 h
-- (sinon le cron de 9 h relancerait / escaladerait les demandes et tâches de démo).
-- Les mails liés sont supprimés en cascade.
begin;
delete from public.demandes where objet like '[DÉMO %' and id between 15 and 18;
delete from public.taches   where titre like '[DÉMO T%' and id between 8 and 13;
commit;
-- Vérification : doit renvoyer 0 et 0
select (select count(*) from public.demandes where objet like '[DÉMO%') as demandes_demo,
       (select count(*) from public.taches where titre like '[DÉMO%') as taches_demo;
