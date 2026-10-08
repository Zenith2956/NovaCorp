# Consignes pour Claude

- À chaque session de travail sur ce projet, **mettre à jour `docs/resume-echanges.md`** :
  ajouter une ligne en haut du tableau « Historique » (date, **auteur**, demande, résultat), puis compléter
  les sections concernées (réalisé, problèmes/solutions, pistes) et la date de dernière mise à jour.
- Langue : français. Stack : PHP 8.3, Laravel 13, Blade, PostgreSQL 17 sur Supabase (projet réf. `dcqnefjzlkwphhytwczr`), poste Windows.
- Toute nouvelle table : activer le Row Level Security dans sa migration (`ALTER TABLE ... ENABLE ROW LEVEL SECURITY`).
- Le projet est partagé entre deux personnes : **toujours indiquer qui a fait la demande** (colonne « Auteur »).
  Arthur = arthur.crozon. Si l'auteur n'est pas connu, demander son prénom avant d'écrire l'entrée.
- Mails : jamais d'envoi direct depuis Laravel. On ajoute une ligne dans `mails_sortants` (trigger ou SQL) ;
  l'Edge Function `supabase/functions/envoyer-mails` l'envoie (cron chaque minute). Voir `docs/propositions-automatisation-mails.md`.
  Après modification de la fonction : la redéployer sur Supabase.
- Supabase contient aussi le **schéma « anglais » du collègue d'Arthur** (`requests`, `employees`, `projects`, `attachments`,
  `request_types`, `audit_logs`, `role_rules`, fonctions `my_role`…, triggers sur `auth.users`). L'appli Laravel ne l'utilise pas.
  **Ne pas le modifier ni le supprimer sans l'accord de son auteur.** Sauvegarde : `supabase/archives/schema-anglais-2026-10-08.sql`.
