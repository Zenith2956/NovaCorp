# NovaCorp – Résumé des échanges

> Journal du projet, partagé entre deux personnes : les demandes de chacun (auteur indiqué) et les propositions de Claude.
> **À mettre à jour à chaque nouvelle session de travail** (ajouter une entrée dans « Historique » en haut **avec le nom de l'auteur de la demande**, puis compléter les sections concernées).
> Version en ligne : https://claude.ai/code/artifact/aad29b72-0640-4703-99fa-5082f2a1dcaf

Dernière mise à jour : 08/10/2026

## Historique

| Date | Auteur | Demande | Résultat |
| --- | --- | --- | --- |
| 08/10/2026 | Arthur | Délais, étape 5 : crons | Relances / rappels / escalades (demandes + tâches) chaque jour ouvré à 9 h, expiration toutes les heures, jours fériés de l'année suivante chaque 1er décembre. Simulation sur les données actuelles : rien à envoyer aujourd'hui |
| 08/10/2026 | Arthur | Délais, étape 4 : modèles de mails | Edge Function v3 : demandes (+ rappel d'échéance, expiration avec bouton « Refaire la demande », mention URGENT) et 7 mails de tâches ; mails devenus inutiles annulés automatiquement. Les 3 mails de tâches en attente sont partis (2 envoyés, 1 annulé car deadline déjà fixée) |
| 08/10/2026 | Arthur | Ajouter un bouton « Retour » un peu partout | Bouton « ← Retour » sur toutes les pages connectées sauf le tableau de bord : page parente logique pour les fiches et formulaires (évite de revenir sur un formulaire déjà envoyé), sinon page précédente. 1 test |
| 08/10/2026 | Arthur | Trouver plus facilement les membres : tri + barre de recherche | Sélecteur de membres (création de projet et page du projet) : recherche sans accents sur nom / prénom / e-mail / rôle, filtre par rôle, tri nom / prénom / rôle, compteur, « seulement la sélection », ajout de plusieurs membres d'un coup |
| 08/10/2026 | Arthur | Pas de bouton ni de page pour créer un projet | Ajout de « + Nouveau projet » (managers, direction, admin) et « Modifier le projet ». Un manager devient chef de son projet ; la direction / l'admin choisit le chef. Membres choisis à la création. 1 test ajouté. Menu : bouton Déconnexion qui ne passe plus à la ligne |
| 08/10/2026 | Arthur | `php artisan test` : 2 échecs dans `TacheTest` | Erreur dans les tests (pas dans l'appli) : lundi 09/11 + 5 jours ouvrés avec le 11/11 férié = **17/11**, pas 16/11. Tests corrigés |
| 08/10/2026 | Arthur | Délais, étape 3 : projets et tâches dans l'application | Pages Tâches et Projet (membres), création hors projet / par un membre / assignation par le chef, lien « Fixer la deadline », reports, avancement, cartes au tableau de bord, 5 tests. Edge Function v2 : mails de tâches mis en attente jusqu'à l'étape 4 |
| 08/10/2026 | Arthur | Test de l'étape 2 (demande #14, date souhaitée le lendemain acceptée) ; question sur la limite de 5 jours | Comportement voulu : les 5 jours ouvrés minimum ne concernent que les tâches de projet. Décision : la date souhaitée d'une demande reste libre (urgence) |
| 08/10/2026 | Arthur | Délais, étape 2 : les demandes dans l'application | Date souhaitée (urgence), statut « Expirée », indicateur dans les temps / échéance proche / en retard, « Refaire la demande », page `/delais` (stats + réglage des délais par les RH), carte « en retard » au tableau de bord, 6 nouveaux tests |
| 08/10/2026 | Arthur | Remettre le schéma anglais : c'est celui de son collègue | Rien à restaurer : la suppression n'avait jamais été exécutée, le schéma est intact (vérifié). Script de suppression archivé en « NE PAS EXÉCUTER ». Les deux schémas cohabitent sans conflit de noms |
| 08/10/2026 | Arthur | Sécuriser les 6 fonctions inconnues ; ne garder qu'un seul schéma | Fonctions sécurisées (plus aucun accès anonyme ; `my_role` reste accessible aux utilisateurs connectés car utilisée par les règles RLS du schéma anglais). Arthur choisit de garder le schéma Laravel : schéma anglais sauvegardé dans `supabase/archives/schema-anglais-2026-10-08.sql` ; sa suppression (confirmation Supabase bloquée côté Claude) se fait avec `supabase/sql/supprimer-schema-anglais.sql` dans le SQL Editor |
| 08/10/2026 | Arthur | Délais + tâches, étape 1 (base de données) ; migration appliquée et tests Laravel OK | 16 règles testées sur Supabase, toutes conformes. **Alerte** : tables et fonctions inconnues découvertes dans Supabase (voir « Problèmes rencontrés ») |
| 08/10/2026 | Arthur | Règles des tâches : le chef de projet peut assigner ; report de deadline ≥ 1 jour (les 5 jours ouvrés seulement à la création) ; modification par l'employé hors projet → notification à son supérieur | Spécification complétée dans `docs/propositions-gestion-delais.md` |
| 08/10/2026 | Arthur | Valider la synthèse des délais + nouveau module Tâches (projet = groupe ; deadline fixée par le chef de projet, ≥ 5 j ouvrés ; tâche hors projet : deadline libre par l'employé) | Spécification et plan en 6 étapes dans `docs/propositions-gestion-delais.md` (non mis en place) |
| 08/10/2026 | Arthur | Comparer la proposition du groupe (deadline, trigger d'expiration, mail « expirée ») | Comparaison + synthèse dans `docs/propositions-gestion-delais.md` ; point bloquant : un trigger ne réagit pas au temps → cron horaire |
| 08/10/2026 | Arthur | Proposer (sans mettre en place) la gestion des délais | 3 propositions dans `docs/propositions-gestion-delais.md` ; en attente de la proposition du groupe |
| 08/10/2026 | Arthur | Tester et prouver l'automatisation des mails (dates manipulées puis remises en place) | 5 scénarios + panne : tous conformes ; rapport dans `docs/tests-automatisation-mails.md` ; données de démo supprimées par Arthur, état vérifié (demande #5 seule, cron actif) |
| 08/10/2026 | Arthur | Test des liens Valider / Refuser puis étape 4 (crons) | Circuit complet validé (demande #5) ; 3 crons actifs ; SQL Supabase versionné dans `supabase/sql/` |
| 08/10/2026 | Arthur | Étape 3 : secrets Resend créés dans Supabase | Edge Function `envoyer-mails` déployée et testée (1er mail réel, PDF joint) ; Laravel n'envoie plus lui-même (`ENVOI_MAIL_DIRECT=false`) |
| 08/10/2026 | Arthur | Test réel de l'étape 2 (demande #5 avec PDF) | Validé dans Supabase ; heures affichées en heure de Paris (stockage en UTC) |
| 08/10/2026 | Arthur | Envoi d'une pièce jointe : « cURL error 60: SSL certificate » vers Supabase Storage | Ajout du fichier de certificats `cacert.pem` dans `php.ini` (curl.cainfo, openssl.cafile) |
| 08/10/2026 | Arthur | `php artisan test` : échec « GD extension is not installed » | Test corrigé (faux fichier sans GD) ; `extension=gd` activée par Arthur ; 6 tests OK |
| 08/10/2026 | Arthur | Étape 2 de l'automatisation (clé S3 Supabase générée, paquet S3 installé) | Pièces jointes dans Supabase Storage, liens Valider / Refuser, interrupteur `ENVOI_MAIL_DIRECT` |
| 08/10/2026 | Arthur | Mettre en place l'automatisation des mails étape par étape (Resend, X=2, Y=5, escalade RH + N+2, liens Valider/Refuser) | Étape 1 faite : migration `mails_sortants` + triggers + fonction de relance, testée sur Supabase |
| 08/10/2026 | Arthur | Comparer la proposition du groupe (Edge Functions + Cron : création, décision, relance, escalade) avec celles de Claude | Comparaison + synthèse « périmètre du groupe + boîte d'envoi » dans `docs/propositions-automatisation-mails.md` |
| 08/10/2026 | Arthur | Proposer (sans mettre en place) l'automatisation des mails avec Edge Functions + Cron Supabase | 3 propositions dans `docs/propositions-automatisation-mails.md` ; en attente de la proposition d'Arthur pour comparer |
| 08/10/2026 | Arthur | Projet partagé à deux : préciser l'auteur des demandes | Colonne « Auteur » ajoutée ; demandes passées attribuées à Arthur |
| 08/10/2026 | Arthur | Connexion à Supabase + `migrate --seed` | Connecté : 16 tables, 120 employés, 12 projets, 48 lignes de CA ; RLS actif partout |
| 08/10/2026 | Arthur | Passer de MariaDB à PostgreSQL (Supabase) | Code adapté (recherche ILIKE, RLS Supabase, tests), `.env.example` en pgsql |
| 08/10/2026 | Arthur | Annonce : la base passera sur Supabase et le code sur GitHub | Décision notée ; étapes de migration dans « Pistes suivantes » |
| 07/10/2026 | Arthur | Créer un résumé des échanges et l'intégrer au projet | Ce fichier `docs/resume-echanges.md` |
| 07/10/2026 | Arthur | Résoudre les erreurs d'installation (Composer, MySQL) | Application lancée avec MariaDB 12.2 |
| 07/10/2026 | Arthur | Initialiser le projet (PHP, Laravel/Blade, MySQL) | Squelette complet : auth, logs, employés, demandes par mail, projets, CA |

## Demandes initiales (Arthur, 07/10/2026)

Initialiser le projet NovaCorp en PHP avec le framework Laravel (vues Blade) et une base MySQL/MariaDB.

Cahier des charges de départ :

- Entreprise de **120 employés**.
- **Connexion** : interface de connexion, journal (logs) et création de compte.
- **Base de données** : demandes (envoi de mail), employés (rôle RH, dev, manager…, mail, nom, prénom, téléphone), chiffre d'affaires, projets.
- **Envoi et réception de mails**, avec pièces jointes possibles (document, vocal, photo, vidéo…).
- Le **manager valide par retour de mail**, sans traçabilité ni délai.
- Les **relances se font par téléphone** (validation manuelle).
- **Aucun délai** imposé aux demandes.

## Ce qui a été réalisé

Stack : PHP 8.3, Laravel 13, vues Blade, PostgreSQL 17 sur Supabase (projet `NovaCorp`, réf. `dcqnefjzlkwphhytwczr`, région eu-west-1). MariaDB 12.2 a servi au démarrage en local et n'est plus utilisé.

| Module | Contenu |
| --- | --- |
| Authentification | Connexion, création de compte, déconnexion ; 5 essais par minute maximum |
| Logs | Table `connexion_logs` : connexion, déconnexion, échec, inscription + adresse IP ; page `/logs` réservée RH et admin |
| Employés | Nom, prénom, e-mail, téléphone, rôle, manager ; annuaire avec recherche |
| Demandes | Formulaire envoyé par mail au manager, jusqu'à 5 pièces jointes de 50 Mo (document, vocal, photo, vidéo) |
| Validation | Le manager répond au mail ; le statut (en attente, validée, refusée) est changé à la main par le manager, les RH ou l'admin ; aucun délai |
| Projets et CA | Projets, membres, chef de projet, chiffre d'affaires mensuel |
| Données de test | 120 employés (1 admin, 3 direction, 10 managers, 8 RH, 60 dev, 25 commerciaux, 13 comptables), 12 projets, 12 mois de CA |

Tables : `roles`, `users` (employés), `demandes`, `pieces_jointes`, `projets`, `projet_user`, `chiffres_affaires`, `connexion_logs`.

Comptes de démonstration (mot de passe `password`) : admin@novacorp.fr, manager@novacorp.fr, employe@novacorp.fr.

Limite actuelle : l'application envoie les mails mais ne lit pas encore les réponses ; la réponse du manager arrive dans la boîte mail de l'employé.

## Problèmes rencontrés et solutions

| Problème | Cause | Solution |
| --- | --- | --- |
| Objets Supabase non créés par Claude (08/10) : tables `requests`, `attachments`, `employees`, `projects`, `request_types`, `audit_logs`, `role_rules` ; fonctions `handle_new_user`, `log_login`, `my_role`, `resolve_role`, `audit_requests`, `audit_attachments` ; triggers sur `auth.users` | Probablement un autre schéma (en anglais, basé sur Supabase Auth) créé dans le même projet | Sécurisé le 08/10 : `EXECUTE` retiré à `anon` (et à `authenticated` sauf pour `my_role`, nécessaire aux règles RLS). Le schéma anglais a été créé le 08/10 vers 11 h 27 (1 compte Supabase Auth, 0 demande). Décision finale : **les deux schémas sont conservés** ; le schéma anglais appartient au collègue d'Arthur (ne pas le modifier sans son accord). Sauvegarde : `supabase/archives/schema-anglais-2026-10-08.sql` |
| Upload vers Supabase Storage : « cURL error 60: unable to get local issuer certificate » | PHP sous Windows n'a pas de liste d'autorités de certificat pour HTTPS | Télécharger https://curl.se/ca/cacert.pem dans `C:\php\extras\ssl\`, puis dans `php.ini` : `curl.cainfo` et `openssl.cafile` vers ce fichier ; relancer `php artisan serve` |
| `php artisan test` : « GD extension is not installed » | `UploadedFile::fake()->image()` a besoin de l'extension GD | Test passé à `UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg')` ; `extension=gd` activée dans `php.ini` |
| `migrate` : timeout sur `Connection: mysql, Port: 3306` | Dans `.env`, seul `DB_HOST` avait été changé | Passer aussi `DB_CONNECTION=pgsql`, `DB_PORT=5432`, `DB_DATABASE=postgres`, `DB_USERNAME=postgres.<réf>`, `DB_SSLMODE=require` et le mot de passe Supabase |
| `composer install` échoue sur `invalid path 'tests/fixtures/env/nul.env'` | Extension PHP `zip` désactivée : Composer clone les paquets avec git, et Windows refuse le nom de fichier `nul` | Activer `zip`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `curl` dans `C:\php\php.ini`, supprimer `vendor`, relancer `composer install --prefer-dist` |
| `vendor/autoload.php` introuvable | Conséquence du problème précédent : `vendor` incomplet | Réglé par la réinstallation |
| `Access denied for user 'root'@'localhost' (using password: NO)` | `DB_PASSWORD` vide dans `.env` alors que root a un mot de passe | Créer un utilisateur dédié `novacorp` et le mettre dans `.env` |
| `mysql` n'est pas reconnu | Client absent du PATH ; `Get-ChildItem -Recurse` trop lent | `Get-CimInstance Win32_Service` → MariaDB 12.2 dans `C:\Program Files\MariaDB 12.2\bin`, client `mariadb.exe` |

## Procédure d'installation qui fonctionne

1. Dans `C:\php\php.ini`, activer `zip`, `fileinfo`, `mbstring`, `openssl`, `curl`, `gd`, `pdo_pgsql`, `pgsql` ; renseigner `curl.cainfo` et `openssl.cafile` (fichier `cacert.pem`) ; mettre `upload_max_filesize = 50M` et `post_max_size = 260M`.
2. `composer install --prefer-dist`
3. `copy .env.example .env` puis `php artisan key:generate`
4. `& "C:\Program Files\MariaDB 12.2\bin\mariadb.exe" -u root -p`
5. Créer la base et l'utilisateur :

```sql
CREATE DATABASE IF NOT EXISTS novacorp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'novacorp'@'localhost' IDENTIFIED BY 'novacorp';
GRANT ALL PRIVILEGES ON novacorp.* TO 'novacorp'@'localhost';
FLUSH PRIVILEGES;
```

6. Dans `.env` : `DB_USERNAME=novacorp` et `DB_PASSWORD=novacorp`.
7. `php artisan config:clear`, `php artisan migrate --seed`, `php artisan serve` → http://localhost:8000

Mails : par défaut écrits dans `storage/logs/laravel.log` ; pour une vraie boîte de test, Mailpit (`MAIL_MAILER=smtp`, port 1025).

## Passage à PostgreSQL / Supabase (08/10/2026)

Supabase n'héberge que du PostgreSQL : MariaDB est abandonné pour ce projet.

Modifications du code :

| Fichier | Changement | Pourquoi |
| --- | --- | --- |
| `app/Http/Controllers/EmployeController.php` | `whereLike` au lieu de `where(..., 'like', ...)` | Sous PostgreSQL, `LIKE` tient compte des majuscules ; `whereLike` génère `ILIKE` |
| `database/migrations/2026_10_08_000001_enable_rls_supabase.php` | Active le Row Level Security sur toutes les tables | Sans ça, les tables sont lisibles via l'API publique de Supabase ; Laravel garde l'accès |
| `.env.example` | `DB_CONNECTION=pgsql`, hôte du Session pooler, `DB_SSLMODE=require` | Connexion à Supabase |
| `tests/Feature/DemandeTest.php` | Rôles cherchés par nom et non par id | Les id PostgreSQL ne repartent pas de 1 entre deux tests |

État vérifié le 08/10/2026 dans Supabase : 16 tables dans `public`, 9 migrations, 120 employés, 7 rôles, 12 projets, 48 lignes de chiffre d'affaires. RLS actif sur les 16 tables. L'alerte Supabase « RLS Enabled No Policy » (niveau INFO) est voulue : aucune politique = API publique bloquée.

Règle : toute nouvelle table doit recevoir `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` dans sa migration.

Procédure :

1. Dans `C:\php\php.ini`, activer `extension=pdo_pgsql` et `extension=pgsql`.
2. Supabase → **Connect** → onglet **Direct** → Method **Session pooler** → recopier hôte, utilisateur et port dans `.env` (modèle dans `.env.example`).
3. Mettre le mot de passe de la base dans `DB_PASSWORD` (uniquement dans `.env`).
4. `php artisan config:clear` puis `php artisan migrate --seed`.

## Pistes suivantes

Décision du 08/10/2026 (Arthur) : base de données hébergée sur **Supabase** (PostgreSQL) et code sur **GitHub** (https://github.com/Zenith2956/NovaCorp).

- [x] Remplir `.env` et lancer `php artisan migrate --seed` sur Supabase (fait le 08/10/2026).
- [ ] (Option) Stocker les pièces jointes dans Supabase Storage (API compatible S3).
- [x] Automatisation des mails : étapes 1 à 4 en place (08/10/2026).
- [ ] Gestion des délais + module Tâches : mise en place en 6 étapes (voir `docs/propositions-gestion-delais.md`).
- [ ] Passage des mails en production : domaine vérifié chez Resend, `MAIL_EXPEDITEUR`, vider `MAIL_TEST_DESTINATAIRE`, `APP_URL` public.
- [ ] Commiter et pousser le projet sur GitHub.
- [ ] Lancer `php artisan test`.
- [ ] Lire automatiquement les réponses mail des managers (IMAP) pour tracer la validation.
- [ ] Validation en un clic, relances automatiques et délais sur les demandes.
- [ ] Tableau de bord RH et graphiques de chiffre d'affaires.
