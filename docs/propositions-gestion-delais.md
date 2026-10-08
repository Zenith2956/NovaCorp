# Gestion des délais – propositions (non mises en place)

Demande d'Arthur, 08/10/2026 : nouvelle étape de l'automatisation, la gestion des délais. Propositions de Claude, à comparer avec celle du groupe avant toute mise en place.

## Point de départ

- Cahier des charges initial : « pas de temps limite pour les demandes ».
- Déjà en place : relance après **2 jours ouvrés**, escalade après **5 jours ouvrés**, **identiques pour tous les types**, valeurs écrites en dur dans le cron (`automation.planifier_relances(2, 5)`).
- Jours ouvrés = lundi–vendredi **sans les jours fériés** : le 11/11, le 25/12, le lundi de Pâques… comptent aujourd'hui comme travaillés.
- Déjà mesurable : date d'envoi (`envoyee_at`) et date de décision (`decision_at`) → le délai réel de traitement peut être calculé.
- Aucune notion d'échéance visible dans l'application, ni de date souhaitée par l'employé (ex. congé qui commence dans 3 jours).

## Ce qui est commun aux trois propositions

| Brique | Rôle |
| --- | --- |
| Table `jours_feries` + calcul automatique | Jours fériés français (fixes + Pâques, Ascension, Pentecôte calculés chaque année). `jours_ouvres_depuis()` les exclut. |
| Délais **par type de demande** dans une table `types_demande` | Plus de valeurs en dur : les RH ajustent sans toucher au code. |
| Délai de traitement enregistré | `decision_at - envoyee_at` en jours ouvrés, base des statistiques. |

Délais proposés par défaut (en jours ouvrés, modifiables) :

| Type | Relance | Échéance = escalade |
| --- | --- | --- |
| Congé | 2 | 5 |
| Matériel | 1 | 3 |
| Formation | 4 | 10 |
| Note de frais | 2 | 5 |
| Autre | 2 | 5 |

## Proposition 1 – Délais paramétrables (minimum)

1. Table `types_demande` (code, libellé, délai de relance, délai d'escalade).
2. Table `jours_feries`, remplie automatiquement pour l'année en cours et la suivante.
3. `planifier_relances()` lit les délais du type au lieu de 2 / 5.

Rien ne change à l'écran. Peu de travail, mais les délais restent invisibles pour les utilisateurs.

## Proposition 2 – Échéances calculées et visibles (recommandée)

Tout ce qui précède, plus :

1. À la création, un trigger calcule et **stocke** sur la demande : `relance_prevue_at`, `echeance_at` (= escalade). Le cron ne fait plus de calcul : il prend les demandes dont la date est passée → plus simple et vérifiable.
2. **Date souhaitée** (facultative) saisie par l'employé : si elle tombe avant l'échéance normale, l'échéance est avancée (veille ouvrée de la date souhaitée) et la demande est marquée **urgente** (objet du mail préfixé « URGENT »).
3. **Indicateur de délai** dans l'application : `Dans les temps` / `Échéance proche` (≤ 1 jour ouvré) / `En retard`, sur la liste des demandes, la fiche et le tableau de bord du manager.
4. **Rappel la veille de l'échéance** au manager (nouveau type de mail `rappel_echeance`), en plus de la relance.
5. Page **Délais** (RH / direction) : délai moyen de traitement par type et par manager, % de demandes traitées dans les temps, demandes en retard.

## Proposition 3 – Gestion complète

Tout ce qui précède, plus :

1. **Absences et suppléants** : un manager déclare ses absences et un suppléant ; pendant l'absence, demandes, relances et liens Valider / Refuser vont au suppléant. Sinon, un manager en congé déclenche des escalades injustes.
2. **Expiration** : une demande dont la date souhaitée est passée sans décision passe au statut `expiree`, l'employé et les RH sont prévenus.
3. **Rapport mensuel automatique** par mail à la direction et aux RH (cron le 1er du mois), avec les indicateurs de la page Délais.

Auto-validation à l'échéance : **déconseillée** (une demande validée sans décision humaine pose un problème de responsabilité), mais possible par type si le groupe le souhaite.

## Comparaison

| Critère | 1. Paramétrable | 2. Échéances visibles | 3. Complète |
| --- | --- | --- | --- |
| Délais différents par type | Oui | Oui | Oui |
| Jours fériés exclus | Oui | Oui | Oui |
| Échéance visible dans l'appli | Non | **Oui** | Oui |
| Urgence selon la date souhaitée | Non | **Oui** | Oui |
| Statistiques de délais | Non | **Oui** | Oui + rapport mensuel |
| Manager absent | Non géré | Non géré | **Suppléant** |
| Travail | Faible | Moyen | Élevé |

## Décisions à prendre

1. Proposition retenue (1, 2, 3 ou celle du groupe).
2. Délais par type : valider ou ajuster le tableau par défaut.
3. Date souhaitée par l'employé : oui / non ; obligatoire pour les congés ?
4. Rappel la veille de l'échéance : oui / non.
5. Qui voit la page Délais : RH, direction, managers (seulement leur équipe) ?
6. (Prop. 3) Suppléants et expiration : à faire maintenant ou plus tard.

## Proposition du groupe (transmise par Arthur, 08/10/2026)

- Champ `deadline` dans `requests`
- Trigger : si `NOW() > deadline` → `status = expired`
- Edge Function : mail automatique « Votre demande a expiré »

## Comparaison groupe / Claude

La proposition du groupe correspond à la partie « expiration » de la proposition 3 de Claude. L'idée est bonne (une demande ne reste plus en attente indéfiniment), mais il y a un point technique bloquant et plusieurs manques.

### Point bloquant : un trigger ne se déclenche pas avec le temps

Un trigger PostgreSQL s'exécute uniquement quand une ligne est **insérée, modifiée ou supprimée**. Quand l'heure dépasse `deadline`, il ne se passe rien dans la table, donc aucun trigger ne part : la demande ne passerait jamais en `expired`.

Solution : un **cron** (comme pour les relances) qui, chaque heure, fait
`update demandes set statut = 'expiree' where statut = 'en_attente' and deadline < now()`.
Cette modification, elle, déclenche un trigger qui met le mail « expirée » dans la boîte d'envoi : le principe du groupe est conservé, seul le déclencheur change.

### Autres points

| Point | Groupe | Avis |
| --- | --- | --- |
| Noms | `requests`, `status`, `expired` | Adapter à l'existant : `demandes`, `statut`, `expiree` |
| Qui fixe la deadline ? | Non précisé | Proposé : l'employé (date souhaitée), sinon une valeur par défaut selon le type |
| Lien avec relance / escalade | Non précisé | Sans ordre clair, une demande peut expirer avant d'avoir été escaladée. Proposé : relance → escalade → expiration, dans cet ordre |
| Qui est prévenu à l'expiration ? | L'employé | Ajouter le manager et les RH en copie : une expiration est un échec du processus, pas seulement une information |
| Edge Function | Nouvelle fonction | Inutile : `envoyer-mails` sait déjà envoyer ; on ajoute seulement un type de mail `expiration` |
| Liens Valider / Refuser | Non abordé | À invalider à l'expiration (sinon le manager pourrait valider une demande expirée) |
| Jours fériés, délais par type | Non abordés | Repris des propositions de Claude |
| Affichage | Non abordé | Nouveau statut « Expirée » à ajouter dans l'appli (liste, fiche, filtre, couleur) |

## Synthèse proposée : proposition 2 de Claude + expiration du groupe

Chaque demande a deux dates, calculées à la création (jours ouvrés, fériés exclus) :

| Date | Calcul | Ce qui se passe |
| --- | --- | --- |
| Relance | Délai de relance du type | Mail de relance au manager |
| Échéance de réponse | Délai d'escalade du type, avancée si la date souhaitée est proche | Rappel la veille, escalade RH + N+2 à l'échéance |
| **Deadline** (groupe) | Date souhaitée par l'employé, sinon échéance + 5 jours ouvrés | La demande passe en `expiree` ; mail à l'employé, manager et RH en copie ; liens Valider / Refuser désactivés |

Mécanique (même modèle que les mails) :

- Cron **horaire** `automation.expirer_demandes()` : passe en `expiree` les demandes en attente dont la deadline est dépassée.
- Trigger existant sur le changement de statut étendu à `expiree` → mail de type `expiration` dans la boîte d'envoi.
- `envoyer-mails` : nouveau modèle de mail « Votre demande a expiré ».
- Application : champ « Date souhaitée » dans le formulaire, statut et indicateur de délai affichés, page Délais.

Décisions restantes : délais par type, date souhaitée obligatoire ou non (congés), valeur par défaut de la deadline (échéance + 5 j ?), qui voit la page Délais, une demande expirée peut-elle être relancée par l'employé (nouvelle demande pré-remplie) ?

## Décisions d'Arthur (08/10/2026)

- Synthèse retenue (proposition 2 de Claude + expiration du groupe), avec les valeurs par défaut proposées : délais par type du tableau ci-dessus, date souhaitée facultative, deadline par défaut = échéance + 5 jours ouvrés, page Délais visible par RH / direction (tout) et managers (leur équipe), demande expirée → bouton « Refaire la demande » pré-rempli.
- **Nouveau : module Tâches, distinct des demandes.**
  - Un **projet** est un groupe : un chef de projet et des membres assignés.
  - Les membres créent leurs tâches dans le projet ; le **chef de projet fixe la deadline à la réception**, au minimum **+5 jours ouvrés**.
  - On peut créer une **tâche hors projet** : l'employé fixe lui-même sa deadline, **librement** (toute date future).

## Spécification retenue

### A. Demandes (circuit de validation)

| Date | Calcul | Effet |
| --- | --- | --- |
| Relance | Délai de relance du type (jours ouvrés, fériés exclus) | Mail de relance au manager |
| Échéance | Délai d'escalade du type, avancée à la veille ouvrée de la date souhaitée si elle est plus proche (demande « urgente ») | Rappel la veille, escalade RH + N+2 |
| Deadline | Date souhaitée, sinon échéance + 5 jours ouvrés | Statut `expiree`, mail à l'employé (manager + RH en copie), liens Valider / Refuser désactivés |

### B. Projets et tâches

| Élément | Règle |
| --- | --- |
| Projet | Groupe : `chef_projet_id` + membres (`projet_user`, déjà en base). Le chef de projet gère les membres. |
| Tâche | Titre, description, responsable, projet (facultatif), statut `a_faire` / `en_cours` / `terminee` / `expiree`, deadline. |
| Tâche de projet | Créée par un membre → mail au chef de projet « Fixer la deadline » (lien à usage unique) → deadline ≥ aujourd'hui + 5 jours ouvrés → mail au responsable « Deadline fixée au … ». Sans réponse : relance au chef de projet après 2 jours ouvrés, escalade à son N+1 après 5. |
| Tâche hors projet | Deadline choisie par l'employé à la création, n'importe quelle date future. |
| Rappel | La veille de la deadline, au responsable (chef de projet en copie si projet). |
| Expiration | Cron horaire : tâche non terminée dont la deadline est passée → `expiree`, mail au responsable (chef de projet en copie). |
| Visibilité | Responsable, chef de projet et membres du projet ; RH / direction / admin : tout. |

Règles complémentaires validées par Arthur (08/10/2026) :

| # | Règle |
| --- | --- |
| 1 | Le chef de projet peut **assigner une tâche** à un membre du projet ; il fixe alors la deadline directement (≥ création + 5 jours ouvrés). |
| 2 | Le minimum de **+5 jours ouvrés** ne s'applique qu'à la **première** deadline (création). Pour **repousser** une deadline, il suffit qu'elle soit au moins **1 jour** plus tard que la précédente. |
| 3 | Sur une tâche **hors projet**, l'employé peut modifier sa deadline ; chaque modification envoie une **notification à son supérieur** (son manager), avec l'ancienne et la nouvelle date. |

### Impacts techniques

- Nouvelles tables : `jours_feries`, `types_demande`, `taches` (RLS activé).
- `demandes` : `date_souhaitee`, `relance_prevue_at`, `echeance_at`, `deadline`.
- `mails_sortants` : `demande_id` devient facultatif, ajout de `tache_id` (l'un des deux obligatoire) ; nouveaux types de mails : `rappel_echeance`, `expiration`, `tache_deadline_a_fixer`, `tache_deadline_fixee`, `tache_relance_deadline`, `tache_escalade_deadline`, `tache_rappel`, `tache_expiration`, `tache_deadline_modifiee` (notification au supérieur).
- `taches` : historique des changements de deadline (`deadline_initiale`, `nb_reports`) pour la traçabilité.
- `envoyer-mails` : nouveaux modèles, redéploiement.
- Crons : expiration horaire (demandes + tâches), rappels de la veille, relances / escalades basées sur les dates stockées.

## Plan de mise en place (étape par étape)

| Étape | Contenu |
| --- | --- |
| 1 | Base : jours fériés, délais par type, dates sur les demandes, table `taches`, `mails_sortants` élargie, fonctions SQL |
| 2 | Demandes dans Laravel : date souhaitée, statut Expirée, indicateur de délai, « Refaire la demande », page Délais |
| 3 | Projets et tâches dans Laravel : membres, création de tâches, page « Fixer la deadline » |
| 4 | Edge Function : nouveaux modèles de mails |
| 5 | Crons : expiration horaire, rappels, relances / escalades |
| 6 | Tests de démonstration avec dates simulées, puis remise en état |
