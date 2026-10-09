-- =====================================================================
-- TP séance 6 – Partie 5 : ce que l'agent RH a le droit de lire sur les demandes
-- Vue public.v_demandes_employe : une ligne par demande, avec l'email de l'employé (pour filtrer)
-- et uniquement des colonnes utiles pour répondre « où en est ma demande ? ».
-- Pas le message complet, pas les jetons, pas les pièces jointes : « surface d'exposition » minimale.
--
-- Lue par le sous-workflow n8n « TOOL – Consulter demandes employé » avec la clé service_role,
-- toujours filtrée sur l'email transmis par le serveur (jamais sur un email tapé dans le chat).
-- =====================================================================

create or replace view public.v_demandes_employe
with (security_invoker = on)   -- la vue applique les droits de celui qui la lit (le RLS des tables reste actif)
as
select
    u.email                                                as email,
    d.id                                                   as numero,
    coalesce(td.libelle, d.type)                           as type,
    d.objet,
    case d.statut
        when 'en_attente'    then 'En attente de validation'
        when 'a_completer'   then 'À compléter par l''employé'
        when 'validee'       then 'Validée (à traiter par le service)'
        when 'en_traitement' then 'En cours de traitement par le service'
        when 'terminee'      then 'Traitée'
        when 'refusee'       then 'Refusée'
        when 'annulee'       then 'Annulée'
        when 'expiree'       then 'Expirée (sans réponse dans les délais)'
        else d.statut
    end                                                    as statut,
    case when d.statut = 'en_attente' then ec.libelle end  as etape_en_cours,
    case when d.statut = 'en_attente' and coalesce(ec.valideur, 'manager') = 'manager'
         then m.prenom || ' ' || m.nom end                 as valideur,
    d.date_debut,
    d.date_fin,
    d.nb_jours_ouvres,
    d.montant,
    d.urgente,
    (coalesce(d.envoyee_at, d.created_at) at time zone 'UTC' at time zone 'Europe/Paris')::date as envoyee_le,
    case when d.statut = 'en_attente' then d.echeance_le end    as reponse_attendue_avant_le,
    (d.decision_at at time zone 'UTC' at time zone 'Europe/Paris')::date as decision_le,
    case when d.statut in ('refusee', 'a_completer') then d.commentaire_decision end as commentaire
from public.demandes d
join public.users u              on u.id = d.demandeur_id
left join public.types_demande td on td.code = d.type
left join public.etapes_circuit ec on ec.type_code = d.type and ec.ordre = d.etape
left join public.users m         on m.id = d.manager_id;

comment on view public.v_demandes_employe is 'Agent RH (TP séance 6) : demandes d''un employé, colonnes minimales, filtrées par email dans n8n';

-- Pas d'accès via les clés publiques (anon / authenticated) : seule la clé service_role (n8n) lit la vue
revoke all on public.v_demandes_employe from public, anon, authenticated;
grant select on public.v_demandes_employe to service_role;
