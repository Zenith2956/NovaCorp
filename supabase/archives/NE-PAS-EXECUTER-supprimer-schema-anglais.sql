-- ⚠ NE PAS EXÉCUTER : le schéma anglais appartient au collègue d'Arthur et doit être conservé (décision du 08/10/2026).
-- Fichier gardé uniquement pour mémoire.
-- Suppression du schéma « anglais » (décision d'Arthur, 08/10/2026).
-- Sauvegarde complète : supabase/archives/schema-anglais-2026-10-08.sql
-- À exécuter UNE fois dans Supabase > SQL Editor.
begin;
drop trigger if exists on_auth_user_created on auth.users;
drop trigger if exists on_auth_user_login on auth.users;
drop table if exists public.attachments, public.requests, public.projects, public.audit_logs,
                     public.request_types, public.role_rules, public.employees cascade;
drop function if exists public.audit_attachments(), public.audit_requests(), public.handle_new_user(),
                        public.log_login(), public.my_role(), public.resolve_role(text);
drop type if exists public.attachment_type, public.request_status, public.app_role;
commit;
