# Propositions – automatisations suivantes (09/10/2026)

> Demande d'Arthur : d'autres idées pour automatiser le projet (suggestions, rien n'est mis en place).
> Effort : ● petit (réutilise l'existant) · ●● moyen · ●●● gros.

## A. Compléter les circuits existants

| # | Idée | Ce que ça automatise | Effort |
| --- | --- | --- | --- |
| A1 ✅ | **Relance des « à traiter » et « à valider »** | Une demande validée non prise en charge par RH / compta / admin après 2 j ouvrés, ou une tâche « à valider » oubliée par le chef, déclenche une relance puis une escalade (aujourd'hui, seules les demandes en attente et les deadlines sont relancées). | ● |
| A2 ✅ | **Délégation pendant les absences** | Pendant un congé validé d'un manager, ses nouvelles demandes et relances partent automatiquement à un suppléant (son N+1 ou une personne choisie) ; le demandeur est prévenu. Évite les demandes bloquées pendant les vacances. | ●● |
| A3 ✅ | **Alerte « équipe trop absente »** | À la demande de congé, le mail au manager indique combien de membres de l'équipe sont déjà absents sur ces dates (ex. « 3 / 5 absents le 24/12 »). | ● |
| A4 | **Départ d'un employé (offboarding)** | Le symétrique de S5 : un compte désactivé → ses tâches en cours et ses demandes à valider sont listées et réassignées, le manager et l'admin reçoivent une checklist (matériel à récupérer, accès à couper). | ●● |
| A5 | **Fin de projet** | Date de fin proche ou dépassée → alerte au chef ; toutes les tâches terminées → proposition de clôturer le projet ; projet clos → récapitulatif final. | ● |

## B. Outils du quotidien

| # | Idée | Ce que ça automatise | Effort |
| --- | --- | --- | --- |
| B1 | **Calendrier des absences (iCal)** | Une Edge Function publie les congés validés de l'équipe sous forme de calendrier auquel on s'abonne dans Outlook / Google Agenda : mis à jour tout seul. | ●● |
| B2 | **Notifications dans l'appli** | Cloche de notifications (Laravel) et notifications push (Flutter) en plus des mails, alimentées par la même boîte d'envoi ; préférences par personne (tout par mail, résumé quotidien…). | ●● |
| B3 | **Rebonds de mails** | Quand Resend signale une adresse invalide (déjà reçu par le webhook), le compte est marqué « e-mail à corriger » et l'admin est prévenu. | ● |

## C. Fiabilité et sécurité (le projet tourne seul : il faut savoir quand il casse)

| # | Idée | Ce que ça automatise | Effort |
| --- | --- | --- | --- |
| C1 ✅ | **Bilan de santé quotidien** | Chaque matin, un mail à l'admin seulement s'il y a un problème : mails en échec, cron en erreur, appels d'Edge Function perdus, alertes de sécurité Supabase. | ● |
| C2 ✅ | **Intégration continue GitHub** | À chaque push / PR : `php artisan test`, `flutter test`, suites SQL ; **détection de secrets** (aurait bloqué le mot de passe dans `.env.example`) ; au merge sur `main`, déploiement automatique des Edge Functions et des migrations. | ●● |
| C3 | **Sauvegarde hebdomadaire** | Export de la base (GitHub Actions) chaque semaine, gardé 3 mois (le plan Supabase gratuit n'a pas de restauration à la minute près). | ● |
| C4 | **Purge et RGPD** | Mails envoyés effacés après 90 jours, demandes anonymisées après N années, fichiers de pièces jointes orphelins supprimés. | ● |

## D. IA (le catalogue prévoit une séance « IA de tri / rédaction »)

| # | Idée | Ce que ça automatise | Effort |
| --- | --- | --- | --- |
| D1 ✅ | **Tri automatique des demandes** | À la création, l'IA propose le type, détecte l'urgence et les pièces manquantes (« note de frais sans justificatif ») avant l'envoi. | ●● |
| D2 ✅ (mail de demande) | **Résumé pour le manager** | Une phrase de résumé en tête du mail de demande et du récapitulatif de réunion (« 3 tâches en retard, toutes chez Hugo »). | ●● |
| D3 ✅ | **Aide à la rédaction** | Brouillon de motif de refus ou de demande de complément, que le valideur relit avant d'envoyer. | ● |

## Ordre conseillé

1. **C1 + A1** : petits, et ils évitent qu'un problème ou une demande passent inaperçus.
2. **C2** : protège le dépôt (tests + secrets) avant que le projet grossisse.
3. **A2 / A3** : les plus visibles pour les employés et managers.
4. **D1–D3** pour la séance IA.
