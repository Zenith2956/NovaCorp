-- =====================================================================
-- NovaCorp – automatisation des mails : configuration côté Supabase
-- Appliqué le 08/10/2026 sur le projet dcqnefjzlkwphhytwczr (déjà en place).
-- À rejouer UNIQUEMENT sur un nouveau projet Supabase, après `php artisan migrate`.
-- (Les tables, triggers et automation.planifier_relances viennent de la migration
--  Laravel 2026_10_08_000002_create_mails_sortants_table.)
-- =====================================================================

-- Extensions
create extension if not exists pg_net with schema extensions;
create extension if not exists pg_cron;

-- Bucket privé des pièces jointes (50 Mo par fichier)
insert into storage.buckets (id, name, public, file_size_limit)
values ('pieces-jointes', 'pieces-jointes', false, 52428800)
on conflict (id) do nothing;

-- Secrets Vault (le secret cron est généré aléatoirement, jamais affiché)
select vault.create_secret('https://<REF_PROJET>.supabase.co', 'project_url', 'URL du projet pour appeler les Edge Functions')
where not exists (select 1 from vault.secrets where name = 'project_url');
select vault.create_secret(encode(extensions.gen_random_bytes(32), 'hex'), 'cron_secret', 'Secret partagé entre pg_cron et l''Edge Function envoyer-mails')
where not exists (select 1 from vault.secrets where name = 'cron_secret');

-- Appel de l'Edge Function envoyer-mails (aussi utilisable à la main)
create or replace function automation.appeler_envoyer_mails()
returns bigint language sql set search_path = '' as $$
  select net.http_post(
    url := (select decrypted_secret from vault.decrypted_secrets where name = 'project_url') || '/functions/v1/envoyer-mails',
    headers := jsonb_build_object(
      'Content-Type', 'application/json',
      'x-cron-secret', (select decrypted_secret from vault.decrypted_secrets where name = 'cron_secret')),
    body := '{}'::jsonb,
    timeout_milliseconds := 60000);
$$;
revoke all on function automation.appeler_envoyer_mails() from public;

-- Crons
-- 1. Envoi des mails en attente : chaque minute
select cron.schedule('novacorp-envoyer-mails', '* * * * *',
  $$ select automation.appeler_envoyer_mails(); $$);

-- 2. Relances (2 j ouvrés) / escalades (5 j ouvrés) : jours ouvrés, 9 h heure de Paris
--    (pg_cron est en UTC : lancé à 7 h et 8 h UTC, seul le passage de 9 h à Paris agit)
select cron.schedule('novacorp-relances', '0 7,8 * * 1-5',
  $$ select automation.planifier_relances(2, 5)
     where extract(hour from now() at time zone 'Europe/Paris') = 9; $$);

-- 3. Ménage de l'historique pg_cron (7 jours) : dimanche 3 h UTC
select cron.schedule('novacorp-purge-historique-cron', '0 3 * * 0',
  $$ delete from cron.job_run_details where end_time < now() - interval '7 days'; $$);

-- ---------------------------------------------------------------------
-- Commandes utiles
--   Envoyer tout de suite :       select automation.appeler_envoyer_mails();
--   Lancer les relances :         select automation.planifier_relances(2, 5);
--   Suivi des mails :             select id, demande_id, type, statut, tentatives, derniere_erreur from public.mails_sortants order by id desc;
--   Mettre l'envoi en pause :     select cron.alter_job((select jobid from cron.job where jobname = 'novacorp-envoyer-mails'), active := false);
--   Historique des crons :        select * from cron.job_run_details order by start_time desc limit 20;
-- ---------------------------------------------------------------------
