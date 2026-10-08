# Automatisation du workflow – propositions (non mises en place)

Demande d'Arthur, 08/10/2026 : automatiser le workflow, dernière étape avant la jonction avec la version de sa collègue. Propositions de Claude, à comparer avec celle du groupe avant toute mise en place.

## Point de départ

| Aujourd'hui | Limite |
| --- | --- |
| Statuts des demandes : en attente → validée / refusée / expirée | Pas d'étape intermédiaire (à compléter, en traitement, livrée, payée…), pas d'annulation par l'employé |
| Une seule validation : le manager (lien du mail ou appli) ; RH / admin peuvent changer le statut à la main | Une note de frais ou un congé long ne passe jamais par la comptabilité ou les RH |
| Traçabilité : seulement `decision_at` / `decision_par` | Pas d'historique : qui a fait quoi, quand, par quel canal (mail, appli, cron), avec quel commentaire |
| Règles de passage dispersées (contrôleurs, triggers, Edge Function) | Difficile de faire évoluer le circuit sans toucher au code à plusieurs endroits |
| Tâches : à faire → en cours → terminée / expirée | « Terminée » n'est jamais vérifiée par le chef de projet |
| Version de la collègue : `requests` (pending / validated / refused / expired) + `audit_logs` | Même idée de statuts, mais deux schémas en parallèle : la jonction doit trancher |

## Ce qui est commun aux trois propositions

| Brique | Rôle |
| --- | --- |
| **Machine à états** : une table `transitions` (statut de départ, statut d'arrivée, qui peut la déclencher) vérifiée par un trigger | Un passage interdit (ex. expirée → validée par un manager) est refusé par la base, quel que soit le canal |
| **Historique** : table `historique` (élément, ancien → nouveau statut, auteur, canal, commentaire, date), rempli par trigger | Traçabilité complète ; même principe que les `audit_logs` de la collègue → base commune pour la jonction |
| **Commentaire obligatoire au refus** | L'employé sait pourquoi ; le commentaire part dans le mail « décision » |
| **Annulation par l'employé** tant que la demande est en attente | Fin des relances inutiles (les mails en attente sont annulés automatiquement) |
| Réutilisation de l'existant | Boîte d'envoi, Edge Function, délais par type, crons : chaque nouvelle étape = un nouveau type de mail, pas une nouvelle mécanique |

## Proposition 1 – Statuts enrichis + historique (minimum)

Nouveaux statuts : `brouillon` (facultatif), `a_completer`, `annulee`, puis après validation `en_traitement` → `terminee`.

```
brouillon → en_attente → validée → en_traitement → terminée
                ↓  ↑ (à compléter)       
           refusée / annulée / expirée
```

- Le manager peut demander un complément (« À compléter ») : mail à l'employé, les délais sont suspendus jusqu'à sa réponse.
- Après validation, le service concerné (RH, comptabilité, IT…) passe la demande « en traitement » puis « terminée » (ex. matériel commandé puis livré).
- Historique affiché sur la fiche de la demande.

Peu de travail, mais toujours **un seul niveau de validation**.

## Proposition 2 – Circuits de validation par type (recommandée)

Tout ce qui précède, plus des **circuits** : chaque type de demande suit une suite d'étapes, chacune avec son valideur et son délai.

| Type | Étape 1 | Étape 2 (si condition) | Après validation |
| --- | --- | --- | --- |
| Congé | Manager | RH si plus de 5 jours ouvrés ou chevauchement d'équipe | RH : « enregistré » |
| Matériel | Manager | Comptabilité si montant > 500 € | IT : commandé → livré |
| Note de frais | Manager | Comptabilité (toujours) | Comptabilité : payée |
| Formation | Manager | RH (budget formation) | RH : inscription faite |
| Autre | Manager | – | – |

- Tables `circuits` / `etapes_circuit` (type, ordre, rôle valideur, condition simple : montant, nombre de jours, délai de l'étape).
- Champs ajoutés au formulaire selon le type : **dates de début / fin** (congé), **montant** (matériel, note de frais).
- À chaque étape : mail au valideur avec liens Valider / Refuser / À compléter (même mécanique de jeton à usage unique), relance / rappel / escalade avec le délai de l'étape.
- Refus à n'importe quelle étape = fin du circuit ; l'employé voit où en est sa demande (« Étape 2 / 3 : Comptabilité »).
- **Tâches** : passage « Terminée » → « À valider » par le chef de projet, qui accepte ou renvoie la tâche (avec commentaire).

## Proposition 3 – Moteur de workflow complet

Tout ce qui précède, plus :

1. **Écran d'administration** des circuits (ajouter une étape, une condition, un valideur) sans toucher au code.
2. **Délégation** : un valideur absent désigne un suppléant (lien avec les absences / congés validés).
3. **Validations en parallèle** (ex. RH et comptabilité en même temps) et règles « l'un ou l'autre ».
4. **Tableau de bord du workflow** : demandes bloquées par étape, temps moyen par étape, goulots d'étranglement ; export de l'historique (CSV) pour audit.

Très complet, mais beaucoup de travail pour 120 employés.

## Comparaison

| Critère | 1. Statuts + historique | 2. Circuits par type | 3. Moteur complet |
| --- | --- | --- | --- |
| Historique / traçabilité | Oui | Oui | Oui + export |
| Plusieurs niveaux de validation | Non | **Oui, par type et selon conditions** | Oui + parallèle |
| Suivi après validation (livré, payé…) | Oui | Oui | Oui |
| Absence d'un valideur | Non géré | Escalade existante | **Suppléant** |
| Modifiable sans code | Non | Données en base (SQL) | Écran d'administration |
| Travail | Faible | Moyen | Élevé |

## Point d'attention : la jonction avec la version de la collègue

Les deux versions ont les mêmes notions : statuts (`pending` / `validated` / `refused` / `expired` ↔ `en_attente` / `validee` / `refusee` / `expiree`), types de demande (`request_types` ↔ `types_demande`), journal (`audit_logs` ↔ futur `historique`). Avant d'ajouter le workflow, il faut décider **sur quel schéma** il sera construit, sinon il faudra le faire deux fois. Pistes :

- garder le schéma Laravel (déjà relié aux mails, délais, tâches) et reprendre de la version de la collègue ce qui manque (rôles automatiques par e-mail `role_rules`, journal `audit_logs`) ;
- ou l'inverse, mais il faudrait alors réécrire l'application Laravel et l'automatisation sur les tables anglaises.

## Décisions à prendre

1. Proposition retenue (1, 2, 3 ou celle du groupe).
2. Circuits par type : valider ou ajuster le tableau (seuils 500 €, 5 jours ouvrés…).
3. Qui traite après validation (RH, comptabilité, IT) : rôles existants ou nouveaux rôles ?
4. Tâches : validation de « Terminée » par le chef de projet, oui / non.
5. Schéma de référence pour la jonction avec la version de la collègue.

> Si « workflow » désignait plutôt l'automatisation du **développement** (tests lancés automatiquement à chaque envoi sur GitHub, déploiement), c'est un autre sujet : GitHub Actions. À préciser.

## Proposition du groupe (transmise par Arthur, 08/10/2026)

1. Employé crée une demande → trigger Postgres → assignation automatique du manager → mail automatique au manager
2. Manager valide / refuse dans l'interface → update Supabase → mail automatique à l'employé → log automatique
3. Relances automatiques → cron Supabase → relance mail → escalade si nécessaire
4. Statistiques → dashboard Flutter → Realtime → graphiques : temps moyen, volume, services sollicités

## Comparaison groupe / existant / Claude

Les points 1 à 3 du groupe sont **déjà presque entièrement en place** ; le point 4 est nouveau et c'est lui qui fait le lien avec la version de la collègue.

| Étape du groupe | Déjà en place | Manque | Proposition Claude liée |
| --- | --- | --- | --- |
| 1. Trigger à la création | Trigger `demandes_mail_creation` → mail au manager | **Assignation automatique** : aujourd'hui l'employé choisit le manager (pré-rempli avec le sien) | Ajout simple : le trigger prend le manager de l'employé si rien n'est choisi, et le formulaire ne propose plus le choix |
| 2. Validation dans l'interface + mail + log | Liens Valider / Refuser (mail) et bouton dans l'appli ; trigger → mail « décision » | **Log** : seulement `decision_at` / `decision_par` | Brique commune « historique » (= `audit_logs` de la collègue) |
| 3. Relances + escalade par cron | Fait et testé (délais par type, jours fériés, rappel, escalade RH + N+2, expiration) | – | – |
| 4. Dashboard Flutter + Realtime | Page Délais (Laravel) : temps moyen, volumes par type et par manager, en tableaux | Graphiques, temps réel, notion de **service** (RH, comptabilité, IT…), application Flutter | Proposition 2 (circuits) apporte les services ; voir ci-dessous pour l'accès de Flutter |

### Point bloquant pour Flutter + Realtime : l'accès aux données

Une application Flutter lit Supabase directement avec la clé publique et un compte **Supabase Auth**. Or :

- les tables de l'appli Laravel ont le RLS activé **sans règle d'accès** (volontaire, pour la sécurité) : Flutter n'y verrait **rien** ;
- Realtime applique aussi le RLS : pas de règle = pas d'événement ;
- les comptes de l'appli Laravel (`users`) ne sont pas des comptes Supabase Auth — c'est justement ce que fait la version de la collègue (`employees` relié à `auth.users`, rôles par `role_rules`, `my_role()`).

Trois façons d'ouvrir **uniquement les statistiques** à Flutter, sans exposer les demandes :

| Option | Principe | Avis |
| --- | --- | --- |
| A. Fonction RPC sécurisée `stats_workflow()` | Renvoie les chiffres agrégés ; vérifie le rôle via `auth.uid()` → employé → rôle (RH, direction, manager pour son équipe) | **Recommandée** : rien de nominatif n'est exposé |
| B. Vues statistiques + règles RLS | Vues agrégées lisibles par les rôles autorisés | Plus souple pour les graphiques, un peu plus de règles à écrire |
| C. Table `stats_quotidiennes` mise à jour par cron + Realtime dessus | Flutter s'abonne à cette petite table : le graphique bouge quand un chiffre change | Idéale pour le « temps réel » sans exposer les demandes ; à combiner avec A ou B |

Dans tous les cas il faut **relier les comptes** : ajouter `auth_id` sur `users` (ou faire correspondre `employees` de la collègue à `users` par l'e-mail).

## Synthèse proposée : groupe + proposition 2

| # | Élément | Source |
| --- | --- | --- |
| 1 | Assignation automatique du manager par trigger | Groupe |
| 2 | Machine à états + **historique** (`historique` / `audit_logs`), commentaire obligatoire au refus, annulation par l'employé, « à compléter » | Claude (commun) + groupe (log) |
| 3 | Circuits par type (manager → RH / comptabilité selon conditions → traitement) ; chaque étape réutilise mails, délais, relances | Claude (prop. 2) ; fournit les « services sollicités » |
| 4 | Statistiques : RPC sécurisée + table de statistiques en Realtime, comptes reliés à Supabase Auth ; graphiques dans Flutter (collègue), page Délais Laravel conservée | Groupe + options A + C |
| 5 | Tâches : « Terminée » à valider par le chef de projet | Claude (prop. 2) |

Décisions restantes : seuils des circuits (500 €, 5 jours), rôles de traitement (RH, comptabilité, IT), qui développe le dashboard Flutter (la collègue ?), comment relier les comptes (`auth_id` sur `users` ou correspondance avec `employees`), schéma de référence pour la jonction.

## Décisions d'Arthur (08/10/2026)

Synthèse retenue (groupe + proposition 2), avec :

- **Seuils** : matériel > 500 € → comptabilité ; congé > 5 jours ouvrés → RH ; note de frais → toujours comptabilité ; formation → toujours RH ; autre → manager seul.
- **Étape « service »** : tout membre actif du service (RH ou comptabilité) reçoit le mail ; le premier qui décide clôt l'étape (les liens des autres deviennent caducs). Pour une étape service, le lien du mail demande de se connecter (on sait ainsi qui a décidé).
- **Traitement après validation** : congé et formation → RH ; note de frais → comptabilité ; matériel → admin ; autre → pas de traitement.
- **Comptes pour Flutter** : reliés par l'adresse e-mail (compte Supabase de la version de la collègue ↔ employé Laravel).

## Spécification retenue

### Statuts et transitions des demandes

| De | Vers | Qui | Mail |
| --- | --- | --- | --- |
| (création) | en attente, étape 1 | employé (manager assigné automatiquement) | nouvelle demande → valideur de l'étape 1 |
| en attente | en attente, étape suivante | valideur de l'étape (si une étape suivante s'applique) | étape suivante → valideurs de la nouvelle étape |
| en attente | validée | valideur de la dernière étape | décision → employé ; à traiter → service de traitement |
| en attente | refusée (motif obligatoire) | valideur de l'étape | décision (avec motif) → employé |
| en attente | à compléter (message obligatoire) | valideur de l'étape | à compléter → employé ; relances suspendues |
| à compléter | en attente (même étape, délais recalculés) | employé | complément reçu → valideurs |
| en attente / à compléter | annulée | employé | annulation → valideurs |
| en attente / à compléter | expirée | cron | expiration (existant) |
| validée | en traitement → terminée | service de traitement | traitement terminé → employé |
| validée / refusée | en attente | RH / admin (correction) | – |

### Tâches

`a_faire` ⇄ `en_cours` → **`a_valider`** (tâche de projet) → `terminee` (chef de projet) ou retour `en_cours` (renvoyée, commentaire obligatoire). Hors projet : → `terminee` directement. Expiration inchangée (`a_faire` / `en_cours` seulement).

### Historique

Chaque création, changement de statut ou d'étape est enregistré par trigger (demandes et tâches) : ancien → nouveau statut, étape, auteur, canal (`appli`, `mail`, `cron`, `systeme`), commentaire.

## Plan de mise en place (étape par étape)

| Étape | Contenu | État |
| --- | --- | --- |
| 1 | Base : `etapes_circuit`, `transitions` (vérifiées par trigger), `historique` (trigger), nouveaux champs (montant, dates de congé, étape, motif), assignation automatique du manager, valideurs par étape, mails déclenchés par les nouvelles transitions, relances / escalades par étape | Écrit (migration `2026_10_08_000004_workflow`, SQL `database/sql/2026_10_08_000004_workflow.sql`) — à appliquer puis tester |
| 2 | Laravel : formulaire (montant, dates), valider / refuser motivé / à compléter / annuler / traiter, étape affichée, historique sur la fiche, validation des tâches par le chef | Écrit : service `app/Services/WorkflowDemande.php` (toutes les actions, appli et lien du mail), route `POST /demandes/{id}/action`, formulaire par type (montant / dates de congé, circuit affiché, manager assigné automatiquement), fiche avec circuit + boutons selon le rôle + historique, filtre « À traiter par moi », lien du mail : valider / refuser / compléter avec commentaire (connexion obligatoire pour une étape de service), tâches de projet « À valider » par le chef (valider / renvoyer avec commentaire), `WorkflowTest` (9 tests). Correctifs SQL `2026_10_08_000005` |
| 3 | Edge Function : nouveaux modèles de mails | Fait : Edge Function `envoyer-mails` **v4** déployée — 9 nouveaux mails (étape suivante, à traiter, à compléter, complément reçu, annulation, traitement terminé, tâche à valider / renvoyée / validée), boutons Valider / Demander un complément / Refuser, mails adaptés aux étapes de service (« Bonjour, » + service, escalade vers la direction), motif du refus, montant et dates de congé dans les mails, annulation automatique des mails devenus inutiles (changement d'étape, demande déjà prise en charge…). Démo sur Supabase : 16 mails envoyés, 1 annulé à juste titre, 0 échec (données `[DÉMO WF]`, nettoyage : `supabase/sql/nettoyer-demo-workflow.sql`) |
| 4 | Statistiques pour Flutter : RPC sécurisée + table de statistiques en Realtime + lien des comptes par e-mail | Écrit et testé (rollback) : `public.stats_workflow(debut, fin)` (RH / direction / admin = entreprise, manager = son équipe, autres refusés), table `stats_quotidiennes` (Realtime, RLS, recalcul à chaque changement + chaque nuit), compte Supabase relié à l'employé par e-mail **confirmé**, aucun chiffre nominatif. Migration `2026_10_08_000006` à appliquer ; mode d'emploi Flutter : `docs/stats-flutter.md` |
| 5 | Tests (SQL + Laravel) et démonstration | Fait : suite SQL rejouable `supabase/tests/tests-workflow.sql` **50 / 50 OK** sur Supabase (38 workflow + 12 statistiques et droits d'accès) ; tests Laravel 37 / 37 (dont `WorkflowTest`, 9) ; démo des mails (étape 3) et démo des statistiques sur 30 jours simulés (ci-dessous) |


## Étape 3 – Démonstration des mails (08/10/2026, mode test : tout arrive dans la boîte de test)

| Scénario | Mails envoyés |
| --- | --- |
| Note de frais #30 (85,40 €) : manager → comptabilité → traitement | nouvelle demande · étape suivante (13 comptables) · décision « validée » · à traiter (comptabilité) · traitement terminé |
| Matériel #31 : complément demandé, complété, puis annulé par l'employé | nouvelle demande · à compléter (avec la demande du manager) · complément reçu · annulation |
| Congé #32 du 21/12 au 01/01 (8 jours ouvrés, Noël et 1er janvier exclus) : manager → RH → refus | nouvelle demande · étape suivante (RH) · décision « refusée » avec motif |
| Tâche #25 (projet 1) : à valider → renvoyée → à valider → validée aussitôt | tâche assignée · tâche à valider · tâche renvoyée (commentaire) · **tâche à valider annulée** (déjà validée) · tâche validée |

Historique de la demande #30 vérifié : création (employé, appli) → étape 2 (manager, appli) → validée (comptable, mail) → en traitement → terminée.


## Étape 5 – Démonstration des statistiques (08/10/2026, dates simulées, tout annulé ensuite)

Équipe fictive (1 manager, 3 employés), 24 demandes réparties du 12/10 au 09/11/2026, décisions 1 à 4 jours après, étape RH / comptabilité selon les circuits. Résultat de `stats_workflow()` pour ce manager (30 derniers jours) :

| Indicateur | Valeur |
| --- | --- |
| Demandes créées | 23 (congé 4, matériel 5, formation 5, note de frais 5, autre 4) |
| Décisions | 19 : 15 validées, 4 refusées |
| Temps moyen création → décision | **2,5 jours ouvrés** (autre 0,8 · formation 2,5 · congé 2,7 · matériel 2,8 · note de frais 4,0) |
| Réponses avant l'échéance | **89,5 %** |
| Services sollicités (temps moyen passé chez eux) | Managers 19 passages, 2,7 j · Comptabilité 4, 2,6 j · RH 4, 2,1 j |
| En cours | 5 en attente, 0 en retard, 11 validées à traiter par les services |
| Courbe `par_jour` | 1 point par jour (créées / décidées), prête pour un graphique Flutter |
