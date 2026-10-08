-- Nettoyage des données de démonstration : statistiques (équipe « Démo », demandes « [DÉMO STATS] »),
-- projet « [DÉMO] Refonte du site » (récapitulatif S4) et employée « Camille » (bienvenue S5).
-- À exécuter dans Supabase > SQL Editor quand elles ne sont plus utiles.
begin;
delete from public.mails_evenements where mail_id in
    (select m.id from public.mails_sortants m join public.demandes d on d.id = m.demande_id where d.objet like '[DÉMO STATS]%');
delete from public.historique where objet = 'demande' and objet_id in (select id from public.demandes where objet like '[DÉMO STATS]%');
delete from public.demandes where objet like '[DÉMO STATS]%';          -- mails liés supprimés en cascade
-- Projet de démonstration du récapitulatif (S4)
delete from public.historique where objet = 'tache' and objet_id in
    (select t.id from public.taches t join public.projets p on p.id = t.projet_id where p.nom like '[DÉMO]%');
delete from public.taches where projet_id in (select id from public.projets where nom like '[DÉMO]%');   -- mails liés en cascade
delete from public.projet_user where projet_id in (select id from public.projets where nom like '[DÉMO]%');
delete from public.projets where nom like '[DÉMO]%';                                                    -- récapitulatifs en cascade
delete from public.users where email like '%@demo.novacorp.fr';                                         -- dont Camille (bienvenue)
commit;
select automation.rafraichir_stats();
select (select count(*) from public.demandes where objet like '[DÉMO STATS]%') as demandes_demo,
       (select count(*) from public.users where email like '%@demo.novacorp.fr') as utilisateurs_demo;
