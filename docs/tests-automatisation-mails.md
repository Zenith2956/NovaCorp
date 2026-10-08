# Tests de l'automatisation des mails – 08/10/2026

Demande d'Arthur : prouver le fonctionnement, en manipulant les dates si besoin, puis tout remettre en place.
Tests réalisés par Claude directement sur Supabase (projet `dcqnefjzlkwphhytwczr`), mode test actif : tous les mails sont arrivés dans la boîte de `MAIL_TEST_DESTINATAIRE`, l'objet indiquant le vrai destinataire.

## Méthode

1. Cron d'envoi mis en pause pour contrôler le moment de l'envoi.
2. Création de 5 demandes `[DÉMO]` (employé : employe@novacorp.fr, manager : Théophile Barbe), dont 3 **antidatées**.
3. Simulation du cron de 9 h : `automation.planifier_relances(2, 5)` lancé **deux fois**.
4. Appel de l'Edge Function `envoyer-mails`, comme le cron.
5. Panne simulée (mail de type inconnu) pour tester les nouveaux essais.
6. Remise en état : suppression des demandes `[DÉMO]` et de leurs mails, cron réactivé.

## Scénarios et résultats

Nous étions le jeudi 08/10/2026 ; les jours ouvrés sont comptés du lundi au vendredi.

| Demande | Situation simulée | Jours ouvrés | Attendu | Résultat |
| --- | --- | --- | --- | --- |
| A (#6) | Envoyée lundi 05/10, sans réponse | 3 | Mail au manager + relance | ✅ 2 mails envoyés |
| B (#7) | Envoyée mardi 29/09, sans réponse | 7 | Mail + relance + escalade (RH + N+2, manager en copie) | ✅ 3 mails envoyés ; escalade : 9 destinataires (8 RH + N+2), copie au manager |
| C (#8) | Créée aujourd'hui | 0 | Mail au manager seulement | ✅ 1 mail, aucune relance |
| D (#9) | Refusée par le manager | 0 | Mail au manager + mail « refusée » à l'employé | ✅ 2 mails envoyés |
| E (#10) | Relance planifiée puis demande validée avant l'envoi | 3 | Relance **annulée** + mail « validée » à l'employé | ✅ relance annulée (« demande déjà traitée »), décision envoyée |

| Contrôle | Résultat |
| --- | --- |
| Cron de 9 h lancé deux fois de suite | 1er passage : 4 mails planifiés (3 relances + 1 escalade) ; 2e passage : **0** (pas de doublon) |
| Appel de l'Edge Function | Lot de 12 : **10 envoyés**, 1 annulé, 1 échec, réponse en ~20 s |
| Panne (type de mail inconnu) | 1er échec : remis en file avec nouvel essai 2 min plus tard ; 5e échec : statut **`echec`** définitif avec l'erreur enregistrée |
| Appel sans le secret (test du 08/10, étape 3) | Refusé (HTTP 401) |

## Remise en état

- Cron `novacorp-envoyer-mails` réactivé (aucun mail en attente).
- Demandes `[DÉMO]` #6 à #10 et leurs mails supprimés par Arthur (SQL Editor) ; vérifié : il ne reste que la demande #5 et ses 2 mails.
- Demande #5 et ses 2 mails inchangés.
- Les mails reçus pendant la démo restent dans la boîte de test (preuve) ; leurs liens Valider / Refuser ne fonctionnent plus (demandes supprimées).

## Rejouer ces tests

Le script complet n'est pas versionné (il crée et supprime des données). Principe : mettre en pause le cron (`cron.alter_job(..., active := false)`), insérer des demandes avec `created_at` / `envoyee_at` antidatés et un `jeton_decision = gen_random_uuid()`, lancer `select automation.planifier_relances(2, 5);` puis `select automation.appeler_envoyer_mails();`, observer `public.mails_sortants`, puis supprimer les demandes de test et réactiver le cron.
