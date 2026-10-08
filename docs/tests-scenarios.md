# Catalogue des scénarios NovaCorp – tests (08/10/2026)

> Catalogue fourni (GINGA CODES) : S1 est obligatoire (le socle), plus **un** scénario au choix parmi S2–S5.
> Tests sur Supabase avec des données créées pour l'occasion et une date simulée (lundi 09/11/2026, 11/11 férié), tout annulé ensuite.

| Scénario | Attendu par le catalogue | Résultat du test | Verdict |
| --- | --- | --- | --- |
| **S1 – Notification manager** | Demande créée → le manager reçoit un e-mail avec le détail et un lien vers la demande | Manager assigné automatiquement, mail « nouvelle demande » mis en file pour lui et envoyé en ~1 s (webhook). Le mail contient l'objet, le type, le montant ou les dates, le message, les délais, les pièces jointes et les boutons Valider / Demander un complément / Refuser, qui renvoient vers la demande dans l'application | ✅ Conforme |
| **S2 – Approbation à deux niveaux** | Seuil dépassé (ex. congé > 10 jours) → l'e-mail part **aussi** au service RH | Congé de 11 jours ouvrés : étape RH ajoutée. Le mail part aux RH **après** la validation du manager (circuit en 2 étapes), pas en même temps. Seuil actuel : **> 5 jours** (choix du 08/10) | ⚠️ Conforme sur le principe ; seuil et moment d'envoi différents |
| **S3 – Relance automatique** | Chaque matin, les demandes en attente depuis plus de 48 h déclenchent un rappel au manager | Cron jours ouvrés à 9 h (Paris) ; demande du lundi 09/11 → aucune relance le 10/11, relance au manager le 12/11 (2 jours ouvrés, le 11/11 étant férié). Délai par type : 1 j (matériel), 2 j (congé, note de frais, autre), 4 j (formation) ; puis rappel la veille de l'échéance et escalade | ✅ Conforme (en jours ouvrés plutôt qu'en heures) |
| **S4 – Digest hebdo** | Chaque lundi 8 h, récapitulatif au manager (reçues, approuvées, en attente) | Mis en place sous forme de récapitulatif **au jour de réunion du projet** (voir plus bas) | ✅ Adapté |
| **S5 – Onboarding** | Nouvel employé ajouté dans `profiles` → mail de bienvenue + checklist du premier jour | Mis en place sur `users` (notre table des employés) : bienvenue + checklist, manager en copie | ✅ Conforme |

## Mise en place de S4 (version projet) et S5 – 08/10/2026

Décisions d'Arthur : le récapitulatif est lié aux **réunions de projet** ; rythme au choix **chaque semaine, toutes les 2 semaines ou une fois par mois** (1er jour choisi du mois) ; envoi **le jour de la réunion à 8 h** au **chef de projet et aux membres** ; contenu : **tâches du projet, chiffres clés** et, pour le chef seulement, **les demandes de son équipe**. Bienvenue : **à la création du compte**, à l'employé avec **son manager en copie**.

| Élément | Où |
| --- | --- |
| Champs réunion du projet (rythme, jour, date de première réunion pour « toutes les 2 semaines »), affichage « prochain récapitulatif le … » | `projets/form`, `projets/show`, `App\Models\Projet` (`estJourDeReunion`, `prochaineReunion`) |
| Jour de réunion (jours fériés exclus), cron 8 h Paris jours ouvrés, 2 mails par projet (chef / membres), pas de doublon | `automation.est_jour_reunion`, `automation.planifier_recaps`, cron `novacorp-recaps` |
| Mail de bienvenue + checklist du 1er jour (connexion, manager, tâches et projets, demande de matériel, congés, délais de réponse) | trigger `users_bienvenue` → `automation.mail_bienvenue` ; désactivé pendant les seeders (`SET novacorp.sans_mails = 'on'`) |
| Rédaction des 2 nouveaux mails | Edge Function `envoyer-mails` v5 (`mailRecap`, `mailBienvenue`) – à déployer **après** la migration |
| Tests Laravel | `tests/Feature/ProjetReunionTest.php` (règles des jours de réunion, formulaire) |
| Migrations | `2026_10_08_000008` (anti-rafale du webhook), `2026_10_08_000009` (récapitulatif + bienvenue) |

### Tests réels (08/10/2026, mails reçus dans la boîte de test)

| Scénario | Données | Résultat |
| --- | --- | --- |
| **S5 – Bienvenue** | Employée « Camille Démo » créée, manager `manager@novacorp.fr` | Mail « Bienvenue Camille ! Votre checklist du premier jour » envoyé (manager en copie), **délivré**. Le 1er essai est tombé sur l'ancienne version de l'Edge Function en cours de remplacement ; le 2e essai automatique (2 min plus tard) est passé. |
| **S4 – Récapitulatif** | Projet « [DÉMO] Refonte du site » : réunion chaque jeudi (aujourd'hui), 4 membres, 8 tâches (2 terminées cette semaine, 1 à valider, 1 en retard, 3 à venir dont 1 reportée, 1 sans deadline) | 2 mails créés (chef : avec les demandes de son équipe ; membres : sans), envoyés et **délivrés** ; second passage du cron le même jour : 0 mail (pas de doublon) ; période couverte : depuis le 01/10 (1re réunion) |

Edge Function `envoyer-mails` v8 (version 5 du code) déployée ; connexions à la base libérées après 10 s d'inactivité.
Nettoyage de toutes les données de démo : `supabase/sql/nettoyer-demo-stats.sql`.
