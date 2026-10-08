# 7. Architecture technique – vérification (08/10/2026, branche `develop`)

> Demande d'Arthur : vérifier ou mettre en place l'architecture cible.
> Cible : **Front-end Flutter** · **Back-end Supabase** (Auth, DB, Storage, Edge Functions, Cron, Realtime) · **Automatisation** : triggers Postgres, Edge Functions, cron jobs, webhooks.
> Décisions d'Arthur (08/10) : **Laravel reste en back-office web**, Flutter servira aux employés et managers ; Flutter travaille sur **notre schéma** (demandes, users, taches…) ; on commence par les **webhooks** (E).

## 1. État actuel, brique par brique

| Brique | État | Constat |
| --- | --- | --- |
| **Front-end Flutter** | 🟡 Démarré : dashboard des statistiques (`flutter_app/`) | Aucun projet Flutter dans le dépôt. Le front actuel est l'appli **Laravel / Blade**, qui fait aussi le back-end métier : connexion, droits, actions du workflow, envoi des fichiers. |
| **Supabase DB** | ✅ En place | PostgreSQL 17 : nos 22 tables + les tables d'Ashley (requests, employees, projects…). Deux schémas parallèles pour le même métier (demandes ↔ requests, users ↔ employees, projets ↔ projects). |
| **Supabase Auth** | ⚠️ Partiel | Nos employés se connectent par Laravel (table `users`, mots de passe Laravel). Supabase Auth ne compte que 2 comptes, ceux du schéma d'Ashley, reliés à `employees` ; aucun n'est relié à nos `users`. |
| **Supabase Storage** | ✅ En place | Bucket `pieces-jointes` (nos pièces jointes, envoyées par Laravel en S3) + bucket `attachments` (Ashley, avec règles d'accès). |
| **Edge Functions** | ✅ En place | `envoyer-mails` v6 : 22 modèles de mails via Resend, file d'attente, mails devenus inutiles annulés automatiquement. |
| **Cron** | ✅ En place | 6 tâches : envoi des mails (chaque minute), relances / escalades (jours ouvrés 9 h), expiration (toutes les heures), statistiques (nuit), jours fériés (1er déc.), purge des journaux. |
| **Realtime** | ⚠️ Partiel | Seule `stats_quotidiennes` est diffusée. Les demandes et les tâches ne le sont pas, et ne peuvent pas l'être sans règles RLS. |
| **Triggers Postgres** | ✅ En place | 12 triggers sur nos tables : dates et délais, machine à états, historique, mails, statistiques, contrôle des tâches. Plus ceux d'Ashley : audit, comptes `auth.users`. |
| **Webhooks** | ✅ En place (E) | Aucun webhook. Le cron appelle l'Edge Function chaque minute avec `pg_net`, mais ce n'est pas un webhook. Environ 1 appel sur 4 expire. |

**Bilan :** le socle Supabase est fait à environ 80 %, et presque toute la logique métier est déjà dans PostgreSQL, ce qui la rend réutilisable par Flutter.
Ce qui manque : **Flutter**, **l'accès de Flutter aux données** (Auth relié, règles RLS), **le Realtime sur les données métier** et **les webhooks**.

## 2. Ce qui empêche aujourd'hui un front Flutter

1. **Comptes** : Flutter se connecte avec Supabase Auth. Nos 120 employés n'y ont pas de compte.
2. **RLS** : toutes nos tables ont le RLS activé **sans aucune règle**, volontairement. Avec la clé publique, Flutter ne voit donc rien, sauf les statistiques.
3. **Logique en PHP** : les droits et les actions du workflow (qui peut valider, passage à l'étape suivante, recalcul des délais, compléter, annuler, traiter…) sont dans `app/Services/WorkflowDemande.php`. Flutter ne peut pas appeler du PHP.
4. **Pages des liens de mail** (Valider / Refuser, Fixer la deadline) : servies par Laravel.
5. **Deux schémas** : il faut choisir sur lequel Flutter travaille, le nôtre ou celui d'Ashley.

## 3. Propositions (à valider avant de mettre en place)

| # | Proposition | Contenu |
| --- | --- | --- |
| A | **Comptes Supabase Auth pour les employés** | Colonne `users.auth_id` ; création des comptes Supabase (invitation par mail, l'employé choisit son mot de passe) ; profil relié par `auth_id` plutôt que par e-mail. Laravel peut rester tel quel pendant la transition. |
| B | **RLS « métier »** | Règles de lecture et d'écriture sur demandes, tâches, projets, historique, pièces jointes, calquées sur les droits actuels de Laravel : employé = les siennes, manager = son équipe et ses projets, service = les types qu'il valide ou traite, RH / direction / admin = tout. |
| C | **Workflow en RPC** | Porter `WorkflowDemande` en fonctions SQL appelables par Flutter (`demande_action(id, action, commentaire)`, `tache_action(...)`) avec les mêmes contrôles. Laravel pourrait les appeler aussi : une seule source de vérité. |
| D | **Realtime métier** | Publier `demandes`, `taches`, `historique` (filtrés par les règles RLS) : la liste « à traiter » se met à jour en direct dans Flutter. |
| E | **Webhooks** | 1) **Database Webhook** sur `mails_sortants` (à l'insertion) qui appelle `envoyer-mails` tout de suite, au lieu d'attendre la minute du cron ; le cron reste en filet de sécurité. 2) **Webhook Resend** → nouvelle Edge Function `webhook-resend` : statut réel de chaque mail (délivré, rebond, plainte), avec vérification de signature. |
| F | **Liens des mails** | Les pages Valider / Refuser et Fixer la deadline passent dans Flutter (web) ou dans une Edge Function. |
| G | **Projet Flutter** | Squelette `flutter/` dans le dépôt : connexion, tableau de bord (statistiques déjà prêtes), demandes, tâches. À voir avec Ashley si elle a déjà une appli. |

### Ordre conseillé
E (webhooks, indépendant, petit) → A (comptes) → B (RLS) → C (RPC du workflow) → D (Realtime) → G / F (Flutter).

## 4. Décisions à prendre

1. **Rôle de Laravel** : remplacé à terme par Flutter, ou gardé comme back-office web à côté de Flutter ?
2. **Schéma de référence** pour Flutter : le nôtre (demandes, users…) ou celui d'Ashley (requests, employees…) ?
3. **Qui développe le Flutter**, et existe-t-il déjà une appli chez Ashley ?
4. **Par quoi commencer** ?

## 5. Mise en place – E. Webhooks (08/10/2026)

| Élément | Fichier | État |
| --- | --- | --- |
| Webhook base de données : à chaque insertion dans `mails_sortants` (une fois par instruction, seulement s'il y a un mail « à envoyer »), appel immédiat de `envoyer-mails` par `pg_net`, envoyé après validation de la transaction. Le cron de chaque minute reste en filet de sécurité. | `database/sql/2026_10_08_000007_webhooks.sql` | Testé (transaction annulée) : mail annulé → 0 appel ; 2 mails en une instruction → 1 appel ; nouvelle demande → 1 appel |
| Suivi de livraison : colonnes `livraison`, `livraison_at`, `livraison_detail` sur `mails_sortants` ; journal `mails_evenements` (RLS, `svix_id` unique) | `database/migrations/2026_10_08_000007_webhooks.php` | Appliquée |
| Edge Function `webhook-resend` : signature Svix vérifiée (HMAC-SHA256, horodatage ± 5 min), événements rejoués ignorés, un statut plus ancien n'écrase pas un plus récent (envoyé < retardé < délivré < échec / rebond < plainte) | `supabase/functions/webhook-resend/index.ts` | Déployée (v1) |
| Configuration Resend : endpoint + secret | Resend > Webhooks ; Supabase > Edge Functions > Secrets `RESEND_WEBHOOK_SECRET` | Fait par Arthur |

### Test de bout en bout (08/10/2026, 16 h 14)

| Étape | Résultat |
| --- | --- |
| Demande #86 « [DÉMO WEBHOOK] » créée → mail « nouvelle demande » ajouté | 16:14:09 |
| Webhook base de données → `envoyer-mails` → Resend | mail **envoyé en 1 seconde** (au lieu d'attendre jusqu'à 1 minute) |
| Resend → `webhook-resend` | `email.delivered` puis `email.sent` reçus à 16:14:14 ; statut du mail **« délivré »**, non écrasé par « envoyé » arrivé après (ordre respecté) |
| Appel sans signature | refusé (401 « signature absente ») |
| Appel avec une fausse signature | refusé (401 « signature invalide ») |

Nettoyage de la demande de test : `supabase/sql/nettoyer-demo-webhook.sql`.

## 6. Statistiques visibles (08/10/2026) – « 4. Statistiques → Dashboard Flutter → Realtime → Graphiques »

Les statistiques existaient seulement côté Supabase (fonction `stats_workflow`, table `stats_quotidiennes` en Realtime) : aucun écran ne les affichait. Décision d'Arthur : les deux affichages.

| Écran | Contenu | Fichiers |
| --- | --- | --- |
| **Laravel – menu « Statistiques »** (RH, direction, admin, managers) | 6 indicateurs + 4 graphiques (Chart.js) : volume par jour, temps moyen par type, services sollicités, demandes par type ; période 7 j / 30 j / 3 mois / 12 mois ; RH / direction / admin : entreprise ou équipe d'un manager ; manager : son équipe ; rafraîchi chaque minute | `app/Services/StatistiquesWorkflow.php` (appelle `automation.calculer_stats` : mêmes chiffres que Flutter), `app/Http/Controllers/StatistiqueController.php`, `resources/views/statistiques/index.blade.php`, `tests/Feature/StatistiqueTest.php` |
| **Flutter – dashboard** | Connexion Supabase Auth, mêmes indicateurs et graphiques (fl_chart), **temps réel** : abonnement à `stats_quotidiennes`, les graphiques se redessinent dès qu'une demande change | `flutter_app/` (voir `flutter_app/README.md`) |

### Données de démonstration des statistiques (08/10/2026, conservées)

Script `supabase/sql/donnees-demo-stats.sql` (déjà exécuté) : équipe « Démo » (5 employés `@demo.novacorp.fr`, connexion impossible) **rattachée à `manager@novacorp.fr`** (visible en se connectant avec ce compte, ou en RH / direction / admin) et **48 demandes** du 09/09 au 08/10/2026, historique horodaté, aucun mail envoyé (mails annulés). Résultat (30 derniers jours, équipe Démo) :

| Indicateur | Valeur |
| --- | --- |
| Demandes créées | 48 (congé 12, matériel 12, autre 12, formation 6, note de frais 6) |
| Décisions | 37 : 31 validées, 6 refusées ; 18 traitées, 2 en traitement, 4 annulées |
| Temps moyen de décision | **2,8 jours ouvrés** (autre 1,5 · formation 2,6 · congé 2,9 · matériel 3,8 · note de frais 4,0) |
| Réponses avant l'échéance | **86,5 %** |
| Services sollicités | Managers 47 passages / 2,5 j · RH 28 / 0,9 j · Comptabilité 15 / 1,2 j · Admin (traitement) 11 / 0,6 j · Employés (compléments) 4 / 3,7 j |
| En cours | 6 en attente, 1 à compléter, 2 à traiter |

Les demandes encore en cours recevront relances / escalades / expiration comme de vraies demandes (mails en mode test). Nettoyage : `supabase/sql/nettoyer-demo-stats.sql`.

**Incident corrigé pendant la génération** : les 48 insertions ont déclenché 48 appels simultanés du webhook → ~50 connexions ouvertes par l'Edge Function, base saturée quelques secondes (« remaining connection slots are reserved »), revenue à la normale seule. Correctif `2026_10_08_000008_webhook_anti_rafale` : au plus un appel toutes les 2 secondes (testé : 20 insertions rapides → 1 appel ; 2,2 s plus tard → 1 appel ; mail annulé → 0). Déjà actif sur Supabase ; la migration Laravel le rejoue sans risque.
