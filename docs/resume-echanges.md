# NovaCorp – Résumé des échanges

> Journal du projet : mes demandes et les propositions de Claude.
> **À mettre à jour à chaque nouvelle session de travail** (ajouter une entrée dans « Historique » en haut, puis compléter les sections concernées).
> Version en ligne : https://claude.ai/code/artifact/aad29b72-0640-4703-99fa-5082f2a1dcaf

Dernière mise à jour : 07/10/2026

## Historique

| Date | Demande | Résultat |
| --- | --- | --- |
| 07/10/2026 | Créer un résumé des échanges et l'intégrer au projet | Ce fichier `docs/resume-echanges.md` |
| 07/10/2026 | Résoudre les erreurs d'installation (Composer, MySQL) | Application lancée avec MariaDB 12.2 |
| 07/10/2026 | Initialiser le projet (PHP, Laravel/Blade, MySQL) | Squelette complet : auth, logs, employés, demandes par mail, projets, CA |

## Mes demandes

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

Stack : PHP 8.3, Laravel 13, vues Blade, MariaDB 12.2.

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
| `composer install` échoue sur `invalid path 'tests/fixtures/env/nul.env'` | Extension PHP `zip` désactivée : Composer clone les paquets avec git, et Windows refuse le nom de fichier `nul` | Activer `zip`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `curl` dans `C:\php\php.ini`, supprimer `vendor`, relancer `composer install --prefer-dist` |
| `vendor/autoload.php` introuvable | Conséquence du problème précédent : `vendor` incomplet | Réglé par la réinstallation |
| `Access denied for user 'root'@'localhost' (using password: NO)` | `DB_PASSWORD` vide dans `.env` alors que root a un mot de passe | Créer un utilisateur dédié `novacorp` et le mettre dans `.env` |
| `mysql` n'est pas reconnu | Client absent du PATH ; `Get-ChildItem -Recurse` trop lent | `Get-CimInstance Win32_Service` → MariaDB 12.2 dans `C:\Program Files\MariaDB 12.2\bin`, client `mariadb.exe` |

## Procédure d'installation qui fonctionne

1. Dans `C:\php\php.ini`, activer `zip`, `fileinfo`, `mbstring`, `openssl`, `pdo_mysql`, `curl` ; mettre `upload_max_filesize = 50M` et `post_max_size = 260M`.
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

## Pistes suivantes

- [ ] Commiter et pousser le projet sur GitHub.
- [ ] Lancer `php artisan test`.
- [ ] Lire automatiquement les réponses mail des managers (IMAP) pour tracer la validation.
- [ ] Validation en un clic, relances automatiques et délais sur les demandes.
- [ ] Tableau de bord RH et graphiques de chiffre d'affaires.
