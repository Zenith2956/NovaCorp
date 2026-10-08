# NovaCorp

Application interne de NovaCorp (≈120 employés) – **PHP 8.3 / Laravel 13 / vues Blade / PostgreSQL (Supabase)**.

Journal du projet (demandes, décisions, problèmes rencontrés) : [docs/resume-echanges.md](docs/resume-echanges.md)

## Fonctionnalités (initialisation)

| Module | Contenu |
|---|---|
| Authentification | Connexion, création de compte, déconnexion, limitation à 5 essais/min |
| Logs | Table `connexion_logs` (connexion, déconnexion, échec, inscription + IP) – page `/logs` réservée RH/admin |
| Employés | Nom, prénom, e-mail, téléphone, rôle (admin, direction, manager, RH, dev, commercial, comptable), manager |
| Demandes | Formulaire → **envoi par mail au manager** avec pièces jointes (documents, vocaux, photos, vidéos) |
| Projets & CA | Projets, membres, chef de projet, chiffre d'affaires mensuel |

### Processus de validation (état actuel, volontairement non outillé)

- Le manager **valide en répondant au mail** (le « Répondre à » pointe vers l'employé) → pas de traçabilité ni de délai.
- Les **relances se font par téléphone** (le n° du demandeur et du manager est affiché).
- Le statut (en attente / validée / refusée) est **mis à jour manuellement** dans l'appli par le manager, les RH ou l'admin.
- Aucune date limite n'est associée aux demandes.

## Installation (Windows – Laragon, XAMPP ou WAMP)

Prérequis : PHP ≥ 8.3 (extensions `pdo_pgsql`, `pgsql`, `zip`, `fileinfo`, `mbstring`, `openssl`), Composer, un projet Supabase.

```bash
composer install
copy .env.example .env          # (cp sous Linux/Mac)
php artisan key:generate
```

Dans `.env`, renseigner la connexion Supabase (Connect → Direct → Session pooler) et `DB_PASSWORD`, puis :

```bash
php artisan migrate --seed      # crée les tables + 120 employés + projets + CA
php artisan serve               # http://localhost:8000
```

### Comptes de démonstration (mot de passe : `password`)

| E-mail | Rôle |
|---|---|
| admin@novacorp.fr | Administrateur |
| manager@novacorp.fr | Manager |
| employe@novacorp.fr | Développeur |

### Mails

Par défaut `MAIL_MAILER=log` : les mails (avec pièces jointes) sont écrits dans `storage/logs/laravel.log`.
Pour les voir comme dans une vraie boîte : installer [Mailpit](https://mailpit.axllent.org/) puis dans `.env` :

```
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
```

### Pièces jointes volumineuses (vidéos)

La limite applicative est de 50 Mo par fichier (5 fichiers max). Il faut aussi augmenter dans `php.ini` :
`upload_max_filesize = 50M` et `post_max_size = 260M`. Attention : beaucoup de serveurs SMTP refusent les mails > 25 Mo.

## Structure

```
app/Http/Controllers/        Auth/ (Login, Register), Demande, Dashboard, Employe, Projet, ConnexionLog
app/Http/Middleware/EnsureRole.php   middleware role:rh,admin
app/Mail/DemandeEnvoyee.php  mail envoyé au manager
app/Models/                  User (employé), Role, Demande, PieceJointe, Projet, ChiffreAffaire, ConnexionLog
app/Subscribers/             journalisation des évènements d'authentification
database/migrations/         schéma de la base
database/seeders/            rôles, 120 employés, projets, CA
resources/views/             vues Blade
public/css/app.css           style (pas de build front nécessaire)
```

## Schéma de la base

```
roles(id, slug, libelle)
users(id, nom, prenom, email, telephone, role_id→roles, manager_id→users, actif, password)
demandes(id, demandeur_id→users, manager_id→users, type, objet, message, statut, envoyee_at)
pieces_jointes(id, demande_id→demandes, nom_original, chemin, mime_type, categorie, taille)
projets(id, nom, description, statut, chef_projet_id→users, budget, date_debut, date_fin)
projet_user(projet_id→projets, user_id→users)
chiffres_affaires(id, periode, montant, projet_id→projets, commentaire)
connexion_logs(id, user_id→users, email, evenement, ip_address, user_agent, created_at)
```

## Pistes suivantes

- Réception automatique des réponses mail (IMAP) pour tracer la validation.
- Validation en un clic + relances automatiques + délais.
- Tableau de bord RH et graphiques de CA.
