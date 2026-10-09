-- =====================================================================
-- TP séance 6 – Agent RH : chiffres généraux de l'entreprise (lecture seule, agrégés)
-- Vue public.v_infos_entreprise : UNE ligne de compteurs, aucun nom, aucune donnée personnelle.
-- Même règle que le tableau de bord (« Employés actifs » = comptes actifs).
-- Lue par l'outil n8n « infos_entreprise » (clé service_role).
-- =====================================================================

create or replace view public.v_infos_entreprise
with (security_invoker = on)
as
select
    (select count(*) from public.users u where u.actif)    as nb_employes_actifs,
    (now() at time zone 'Europe/Paris')::date               as chiffres_au;

comment on view public.v_infos_entreprise is 'Agent RH (TP séance 6) : compteurs généraux, sans donnée personnelle';

revoke all on public.v_infos_entreprise from public, anon, authenticated;
grant select on public.v_infos_entreprise to service_role;
