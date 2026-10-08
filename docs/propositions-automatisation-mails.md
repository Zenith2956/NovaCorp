# Automatisation des mails – propositions (non mises en place)

Demande d'Arthur, 08/10/2026 : envoyer les mails automatiquement avec les **Edge Functions** et le **Cron** de Supabase. Propositions de Claude, à comparer avec la proposition existante d'Arthur avant toute mise en place.

## État actuel du projet Supabase (vérifié le 08/10/2026)

- Aucune Edge Function déployée.
- `pg_cron`, `pg_net` et `pgmq` disponibles mais **pas encore activés** ; `supabase_vault` activé.
- Aujourd'hui, Laravel envoie le mail **immédiatement** à la création de la demande, avec les pièces jointes stockées **sur le serveur Laravel** (`storage/app/private`).

## Ce qui est commun aux trois propositions

| Brique | Rôle |
| --- | --- |
| Fournisseur d'envoi par API HTTP (Resend ou Brevo) | Les Edge Functions bloquent les ports SMTP classiques (25 et 587) : on passe par une API HTTP. Brevo est français, Resend est celui des exemples Supabase. Nécessite de vérifier le domaine d'envoi (ex. `novacorp.fr`). |
| Pièces jointes dans **Supabase Storage** | Une Edge Function ne peut pas lire les fichiers stockés sur le serveur Laravel. Les vidéos lourdes : envoyer un **lien de téléchargement signé** plutôt qu'une pièce jointe (les fournisseurs limitent la taille totale d'un mail). |
| `pg_cron` + `pg_net` | `pg_cron` lance une tâche à heure fixe ; `pg_net` appelle l'Edge Function en HTTP depuis la base. |
| Secrets dans **Vault** et dans les secrets des Edge Functions | URL du projet et clés jamais en clair dans le SQL ni dans git. |
| Colonnes de suivi dans `demandes` | `statut_envoi` (a_envoyer / envoye / echec), `tentatives`, `derniere_erreur`, `envoyee_at`. Apporte la traçabilité qui manque aujourd'hui. |

## Proposition 1 – « Boîte d'envoi » + Cron (recommandée)

1. Laravel n'envoie plus le mail : il enregistre la demande avec `statut_envoi = a_envoyer`.
2. `pg_cron` appelle toutes les minutes l'Edge Function `envoyer-demandes`.
3. La fonction lit les demandes `a_envoyer` (max ~20 par passage), envoie via l'API, passe à `envoye` + `envoyee_at`, ou incrémente `tentatives` et note l'erreur.
4. Après 5 échecs : `statut_envoi = echec`, visible dans l'appli pour renvoi manuel.

Délai d'envoi : jusqu'à 1 minute. Pièces : 1 Edge Function, 1 tâche cron, 4 colonnes.

## Proposition 2 – Déclencheur instantané + Cron de rattrapage

1. Un trigger `AFTER INSERT` sur `demandes` appelle l'Edge Function via `pg_net` dès la création.
2. Une tâche cron toutes les 15 minutes renvoie ce qui est resté `a_envoyer` ou en erreur.

Délai d'envoi : quelques secondes. Plus de pièces (trigger + fonction + cron) et deux chemins d'envoi à tester.

## Proposition 3 – File d'attente Supabase Queues (`pgmq`) + Cron

1. Un trigger place chaque demande dans une file `pgmq`.
2. Le cron lit la file par lots et appelle l'Edge Function ; un message non traité réapparaît automatiquement (nouvel essai).

Le plus robuste (modèle de la doc Supabase), mais surdimensionné pour 120 employés.

## Complément commun : relances automatiques

Remplace la relance par téléphone. Une 2ᵉ tâche cron, chaque jour ouvré à 9 h, appelle `relancer-demandes` :

- demande `en_attente` depuis plus de **N jours** → mail de relance au manager ;
- après **M relances** → copie aux RH ;
- colonnes `nb_relances`, `derniere_relance_at`.

⚠ Cela introduit un délai, alors que le cahier des charges initial dit « aucun délai ». À trancher : valeur de N et M, ou pas de relance automatique.

## Comparaison

| Critère | 1. Boîte d'envoi + Cron | 2. Trigger + Cron | 3. pgmq + Cron |
| --- | --- | --- | --- |
| Délai d'envoi | ≤ 1 min | Quelques secondes | ≤ 1 min (réglable) |
| Complexité | Faible | Moyenne | Élevée |
| Nouvel essai si échec | Oui (compteur) | Oui (cron de rattrapage) | Oui (natif) |
| Traçabilité | Colonnes dans `demandes` | Colonnes dans `demandes` | File + colonnes |
| Adapté à 120 employés | Oui | Oui | Surdimensionné |

## Décisions à prendre

1. Proposition retenue (1, 2, 3 ou celle d'Arthur).
2. Fournisseur d'envoi (Resend ou Brevo) et domaine d'envoi.
3. Relances automatiques : oui/non, N jours, M relances.
4. Pièces jointes : toutes en Supabase Storage ; vidéos en lien plutôt qu'en pièce jointe ?
5. La validation reste-t-elle « par retour de mail », ou ajoute-t-on des liens Valider / Refuser dans le mail ?

## Proposition du groupe (transmise par Arthur, 08/10/2026)

Edge Functions :
- Envoi de mail au manager lors de la création d'une demande
- Envoi de mail à l'employé lors de la validation / du refus
- Envoi de relances automatiques
- Escalade automatique si pas de réponse

Cron Supabase :
- Vérification quotidienne des demandes en attente
- Relance automatique après X jours
- Escalade après Y jours

## Comparaison groupe / Claude

Les deux sont complémentaires : la proposition du groupe dit **quoi** envoyer, celles de Claude disent **comment** l'envoyer de façon fiable.

| Point | Groupe | Claude | Avis |
| --- | --- | --- | --- |
| Mail au manager à la création | Oui | Oui | Identique |
| Mail à l'employé après validation / refus | **Oui** | Non | Bon ajout du groupe, à garder |
| Relance après X jours | Oui | Oui (N jours) | Identique |
| Escalade après Y jours | Oui, destinataire non précisé | Copie RH après M relances | Préciser : RH, ou N+2 (manager du manager, déjà en base) |
| Ce qui déclenche l'envoi à la création | Non précisé | Cron chaque minute (ou trigger) | À préciser côté groupe |
| Nouvel essai si l'envoi échoue | Non prévu | Oui (compteur, état `echec`) | À ajouter |
| Traçabilité des envois | Non prévue | Colonnes de suivi | À ajouter |
| Fournisseur d'envoi, pièces jointes, secrets | Non abordés | API Resend/Brevo, Storage, Vault | À ajouter |

### Point bloquant pour « mail à l'employé lors de la validation »

Aujourd'hui le manager valide **en répondant au mail** : l'application ne le sait pas, donc rien ne peut déclencher ce mail. Deux solutions :

1. Garder la mise à jour manuelle du statut dans l'appli : le mail part quand quelqu'un change le statut.
2. Ajouter des liens **Valider / Refuser** (signés, à usage unique) dans le mail du manager : la décision est enregistrée et le mail à l'employé part automatiquement. Recommandé, et c'est ce qui rend l'escalade fiable.

Dans les deux cas, la relance et l'escalade s'arrêtent dès que le statut n'est plus `en_attente`.

## Synthèse proposée : périmètre du groupe + « boîte d'envoi »

Une seule table d'envoi `mails` (type, destinataire, demande, état, tentatives, erreur, envoyé le) alimentée de trois façons, et une seule Edge Function qui l'envoie :

| Événement | Ce qui l'enregistre dans `mails` | Type |
| --- | --- | --- |
| Création d'une demande | Trigger `AFTER INSERT` sur `demandes` | `nouvelle_demande` → manager |
| Statut passé à validée / refusée | Trigger `AFTER UPDATE OF statut` | `decision` → employé |
| Demande en attente depuis X jours ouvrés | Cron quotidien 9 h (SQL seul, sans Edge Function) | `relance` → manager |
| Demande en attente depuis Y jours ouvrés | Même cron quotidien | `escalade` → RH ou N+2, manager en copie |

- **Cron 1** (chaque minute) : appelle l'Edge Function `envoyer-mails`, qui envoie les mails `a_envoyer` par l'API et met à jour leur état.
- **Cron 2** (jours ouvrés, 9 h) : simple requête SQL qui ajoute les relances et escalades dans `mails`, une seule fois par palier (pas de doublon).

Avantages : chaque mail est tracé (historique consultable par demande), réessayé en cas d'échec, et ajouter un nouveau type de mail ne demande ni nouvelle fonction ni nouveau cron.

La table `mails` devra avoir le RLS activé comme les autres.

Décisions restantes : valeurs de X et Y, destinataire de l'escalade, liens Valider / Refuser ou non, fournisseur d'envoi.

## Mise en place (décidée le 08/10/2026 par Arthur)

Choix : synthèse « périmètre du groupe + boîte d'envoi », fournisseur **Resend**, relance après **2 jours ouvrés**, escalade après **5 jours ouvrés** vers **RH + N+2** (manager en copie), **liens Valider / Refuser** signés dans le mail du manager.

| Étape | Contenu | État |
| --- | --- | --- |
| 1. Base de données | Migration `2026_10_08_000002_create_mails_sortants_table` : table `mails_sortants` (RLS), colonnes `jeton_decision`, `decision_at`, `decision_par` sur `demandes`, schéma privé `automation` avec triggers création/décision et `automation.planifier_relances(2, 5)` | **Fait et appliqué** sur Supabase (vérifié : 2 triggers, RLS actif) |
| 2. Laravel | Bucket privé `pieces-jointes` (50 Mo/fichier) ; disque `supabase` (API S3) ; liens `/decision/{id}/{jeton}?choix=validee|refusee` (page de confirmation en GET, enregistrement en POST, jeton à usage unique) ; statut manuel qui consomme aussi le jeton ; interrupteur `ENVOI_MAIL_DIRECT` (true jusqu'à l'étape 3) | **Fait et testé** le 08/10 (demande #5 : fichier dans le bucket, mail `nouvelle_demande` en attente, jeton présent) |
| 3. Edge Function | `supabase/functions/envoyer-mails/index.ts` déployée (version 1). Lots de 20, réservation `FOR UPDATE SKIP LOCKED`, 5 essais avec attente croissante, clé d'idempotence Resend, PJ jointes jusqu'à 15 Mo sinon liens signés 7 jours, relance/escalade annulées si la demande est déjà traitée. Sécurité : en-tête `x-cron-secret` comparé au secret Vault `cron_secret`. Appel manuel : `select automation.appeler_envoyer_mails();` | **Fait et testé** le 08/10 : mail de la demande #5 envoyé (PDF joint) ; appel sans secret → 401. `ENVOI_MAIL_DIRECT=false` |
| 4. Crons | `novacorp-envoyer-mails` (chaque minute), `novacorp-relances` (jours ouvrés, 9 h Paris : planifié 7 h et 8 h UTC avec filtre sur l'heure de Paris), `novacorp-purge-historique-cron` (dimanche). SQL versionné dans `supabase/sql/automatisation-mails.sql` | **Fait et testé** le 08/10 : clic « Valider » sur le mail de la demande #5 → mail « décision » envoyé ; le cron tourne chaque minute sans erreur |

Note : jusqu'à l'étape 3, Laravel envoie encore le mail lui-même ; les lignes créées entre-temps dans `mails_sortants` seront annulées avant d'activer l'envoi automatique, pour éviter les doublons.

### Secrets de l'étape 3

| Où | Nom | Contenu |
| --- | --- | --- |
| Edge Functions > Secrets | `RESEND_API_KEY` | Clé Resend (saisie par Arthur) |
| Edge Functions > Secrets | `MAIL_TEST_DESTINATAIRE` | Adresse qui reçoit TOUS les mails tant qu'on est en test (vider pour la production) |
| Edge Functions > Secrets | `APP_URL` | `http://localhost:8000` : les liens Valider / Refuser ne marchent que si `php artisan serve` tourne sur ce poste |
| Vault | `project_url`, `cron_secret` | Créés par migration ; `cron_secret` généré aléatoirement, jamais affiché |

Pour passer en production : vérifier un domaine dans Resend, définir `MAIL_EXPEDITEUR` (ex. `NovaCorp <no-reply@domaine>`), vider `MAIL_TEST_DESTINATAIRE`, mettre l'URL publique de l'appli dans `APP_URL`.

## Bilan (08/10/2026)

Les 4 étapes sont en place et testées de bout en bout sur la demande #5 : création → mail au manager (PDF joint) → clic « Valider » dans le mail → mail de décision à l'employé, envoyé automatiquement par le cron. Les relances et escalades partiront chaque jour ouvré à 9 h.

Toujours en **mode test** : tous les mails arrivent à l'adresse de `MAIL_TEST_DESTINATAIRE`.

Commandes utiles : voir la fin de `supabase/sql/automatisation-mails.sql` (envoyer tout de suite, suivi, pause, historique).
