# Tests de la gestion des délais

## Suite de tests automatiques (bilan du 08/10/2026)

Demande d'Arthur : « faire tous les tests nécessaires pour résumer la gestion des délais ».

| Niveau | Fichier | Ce qui est testé | Résultat |
| --- | --- | --- | --- |
| Base de données (PostgreSQL / Supabase) | `supabase/tests/tests-gestion-delais.sql` | 62 vérifications : calendrier, calcul des dates, chronologie relance → rappel → escalade → expiration, règles des tâches, relances / rappels / escalade / expiration des tâches | **62 / 62** (exécuté sur Supabase, rien n'est conservé) |
| Application (Laravel) | `tests/Feature/DelaiTest.php` | dates, urgence, indicateurs, date passée refusée, « Refaire la demande », page Délais, réglage par les RH | 6 tests (à lancer : `php artisan test`) |
| Application (Laravel) | `tests/Feature/TacheTest.php` | tâches hors projet / de projet, lien « Fixer la deadline », report ≥ 1 jour, assignation, avancement, membres, création de projet | 6 tests |
| Cohérence PHP ↔ SQL | `tests/Feature/CoherenceDelaisTest.php` | mêmes cas et mêmes résultats que la suite SQL (C7–C13, D1–D10) + statistiques de la page Délais | 9 tests (nouveau) |
| Mails (Edge Function) | démonstration ci-dessous | 13 modèles de mails, annulations automatiques | 14 envoyés, 1 annulé, 0 échec |

### Règles couvertes

| Règle | SQL | Laravel | Démo |
| --- | --- | --- | --- |
| Jours ouvrés : week-ends et jours fériés exclus (Pâques, Ascension, Pentecôte calculées) | C1–C14 | Cohérence, DelaiTest | – |
| Délais par type (congé 2/5, matériel 1/3, formation 4/10, note de frais 2/5, autre 2/5), défaut 2/5 | D1–D6, D10 | Cohérence | – |
| Deadline = échéance + 5 j ouvrés | D3 | Cohérence | D |
| Date souhaitée libre : proche → urgente, échéance la veille ouvrée ; lointaine → deadline repoussée | D7–D9 | Cohérence, DelaiTest | A |
| Relance → rappel la veille → escalade (RH + N+2, manager en copie), sans doublon | R1–R7 | – | B, C |
| Expiration le lendemain de la deadline, liens désactivés, mail employé + manager + RH | R8–R11 | DelaiTest (affichage, Refaire) | D |
| Demande traitée : plus de relance ni d'expiration | R12 | – | – |
| Tâche hors projet : deadline libre (pas dans le passé), chaque modification prévient le manager | T1–T6 | TacheTest | T3, T4 |
| Tâche de projet : responsable membre ; créée par un membre → le chef fixe la deadline (≥ création + 5 j ouvrés) via un lien à usage unique | T7–T11 | TacheTest | T1 |
| Report d'une deadline de projet : au moins 1 jour, jamais avancée, jamais retirée ; nombre de reports et deadline initiale conservés | T12–T15 | TacheTest | T5 |
| Assignation par le chef (≥ 5 j ouvrés), mail au responsable | T16–T17 | TacheTest | T5 |
| Deadline à fixer : relance du chef (2 j), escalade à son supérieur (5 j) | S1–S6 | – | T2 |
| Rappel la veille, expiration, tâche terminée jamais expirée, report après expiration → « à faire » | S3, S5, S7, S8, T18 | TacheTest | T3, T6 |
| Mail devenu inutile annulé (relance d'une demande traitée, rappel d'une tâche close…) | – | – | T6 |
| Statistiques : délai moyen, % dans les temps, en retard | – | Cohérence, DelaiTest | – |

Pour rejouer la suite SQL : copier `supabase/tests/tests-gestion-delais.sql` dans Supabase > SQL Editor, puis **Run** ; le résultat s'affiche comme une erreur « TESTS GESTION DES DÉLAIS : x / y OK » (c'est ce qui annule tout).

---

# Démonstration de la gestion des délais – 08/10/2026

Demande d'Arthur : étape 6, démonstration complète avec dates simulées puis remise en état.
Réalisée par Claude sur Supabase (projet `dcqnefjzlkwphhytwczr`) ; mode test actif : les mails sont arrivés dans la boîte de `MAIL_TEST_DESTINATAIRE`, l'objet indiquant le vrai destinataire.

## Méthode

1. Cron d'envoi mis en pause pendant la préparation.
2. Création d'éléments `[DÉMO]` (employé : employe@novacorp.fr ; manager : Théophile Barbe ; projet « Projet Consequatur », chef : manager@novacorp.fr), certains **antidatés** ; leurs mails « d'origine » sont annulés (ils seraient partis à l'époque).
3. Passage simulé des crons : expiration, puis passage de 9 h (`planifier_rappels`) **deux fois** (contrôle des doublons). Pour la tâche T6, expiration simulée « demain matin ».
4. Nouvelles saisies du jour : demande urgente (A) et tâche créée par un membre (T1).
5. Envoi par l'Edge Function, cron d'envoi réactivé.

Nous étions le jeudi 08/10/2026.

## Demandes

| Élément | Situation | Dates calculées | Mails | Résultat |
| --- | --- | --- | --- | --- |
| A | Matériel, date souhaitée 09/10 | urgente ; échéance 08/10 ; deadline 09/10 | Nouvelle demande « URGENT » au manager | ✅ envoyé |
| B | Congé envoyé le 02/10 | relance 06/10 ; échéance 09/10 ; deadline 16/10 | Relance + rappel « échéance demain » | ✅ 2 envoyés |
| C | Matériel envoyé le 01/10 | relance 02/10 ; échéance 06/10 ; deadline 13/10 | Relance + escalade (8 RH + N+2, manager en copie) | ✅ 2 envoyés |
| D | Autre envoyé le 21/09 | échéance 28/09 ; deadline 05/10 | Passée en « Expirée » ; mail à l'employé (manager + 8 RH en copie) avec bouton « Refaire la demande » | ✅ envoyé |

## Tâches

| Élément | Situation | Mails | Résultat |
| --- | --- | --- | --- |
| T1 | Créée aujourd'hui par un membre du projet | « Deadline à fixer » au chef de projet (au plus tôt le 15/10) | ✅ envoyé |
| T2 | Créée le 30/09 par un membre, deadline jamais fixée | Relance du chef (2 j ouvrés) + escalade à son supérieur (5 j ouvrés), chef en copie | ✅ 2 envoyés |
| T3 | Hors projet, deadline demain | Rappel la veille au responsable | ✅ envoyé |
| T4 | Hors projet, deadline repoussée du 20/10 au 23/10 | Information au manager de l'employé (ancienne → nouvelle date) | ✅ envoyé |
| T5 | Assignée par le chef (deadline 15/10), puis repoussée au 16/10 | « Nouvelle tâche assignée » + « Deadline repoussée » au responsable | ✅ 2 envoyés |
| T6 | Hors projet, deadline aujourd'hui, non terminée | Passée en « Expirée » le lendemain ; mail d'expiration. Son rappel du jour, devenu inutile, est **annulé** automatiquement | ✅ 1 envoyé, 1 annulé |

## Contrôles

| Contrôle | Résultat |
| --- | --- |
| Second passage du cron de 9 h | 0 nouveau mail (pas de doublon) ✅ |
| Envoi | Lot de 15 : **14 envoyés, 1 annulé, 0 échec** ✅ |
| Cron d'envoi | Réactivé, plus aucun mail en attente ✅ |

## Remise en état

Les données `[DÉMO]` (demandes 15 à 18, tâches 8 à 13) se suppriment avec `supabase/sql/nettoyer-demo-delais.sql`, à exécuter dans le SQL Editor de Supabase **avant le vendredi 09/10 à 9 h** (la suppression demande une confirmation que Claude ne peut pas donner).
