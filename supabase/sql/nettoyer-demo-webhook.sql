-- Nettoyage du test des webhooks du 08/10/2026 (demande « [DÉMO WEBHOOK] »).
-- À exécuter dans Supabase > SQL Editor.
begin;
delete from public.mails_evenements
 where mail_id in (select m.id from public.mails_sortants m join public.demandes d on d.id = m.demande_id where d.objet like '[DÉMO WEBHOOK]%');
delete from public.historique
 where objet = 'demande' and objet_id in (select id from public.demandes where objet like '[DÉMO WEBHOOK]%');
delete from public.demandes where objet like '[DÉMO WEBHOOK]%';   -- les mails liés sont supprimés en cascade
commit;
-- Vérification : doit renvoyer 0
select count(*) as demandes_demo from public.demandes where objet like '[DÉMO WEBHOOK]%';
