# NovaCorp – dashboard Flutter

Dashboard du workflow (temps moyen, volume, services sollicités), **en temps réel** via Supabase.
Il lit uniquement des chiffres agrégés : fonction `stats_workflow()` et table `stats_quotidiennes` (voir `docs/stats-flutter.md`).

## Première installation (une fois)

```bash
cd flutter_app
flutter create . --project-name novacorp_dashboard --platforms web,windows,android   # génère les dossiers de plateforme, garde lib/
flutter pub get
```

## Lancer

```bash
flutter run -d chrome --dart-define=SUPABASE_URL=https://dcqnefjzlkwphhytwczr.supabase.co --dart-define=SUPABASE_ANON_KEY=<clé publique>
```

La clé publique (« anon » / « publishable ») se trouve dans Supabase > Project Settings > API Keys. Elle ne donne accès qu'à ce que les règles RLS autorisent.

## Compte de connexion

Supabase > Authentication > Users > **Add user** : e-mail d'un employé NovaCorp ayant le rôle RH, direction, admin ou manager
(ex. `admin@novacorp.fr`), un mot de passe, et cocher **Auto Confirm User**. Le compte est relié à l'employé par l'e-mail.
