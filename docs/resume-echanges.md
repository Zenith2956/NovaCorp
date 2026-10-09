# NovaCorp – Résumé des échanges

> Journal du projet, partagé entre deux personnes : les demandes de chacun (auteur indiqué) et les propositions de Claude.
> **À mettre à jour à chaque nouvelle session de travail** (ajouter une entrée dans « Historique » en haut **avec le nom de l'auteur de la demande**, puis compléter les sections concernées).
> Version en ligne : https://claude.ai/code/artifact/aad29b72-0640-4703-99fa-5082f2a1dcaf

Dernière mise à jour : 08/10/2026

## Historique

| Date | Auteur | Demande | Résultat |
| --- | --- | --- | --- |
| 09/10/2026 | Arthur | `flutter create` + `pub get` faits : 214 → 2 problèmes ; `flutter upgrade` refusé (« local changes ») | `test/widget_test.dart` (généré par Flutter, testait un compteur `MyApp` inexistant) remplacé par un test de l'écran de connexion. Reste une simple information : `anonKey` déprécié dans supabase_flutter 2.18 (fonctionne toujours). Mise à jour de Flutter non nécessaire (3.44 suffit) ; `--force` effacerait des modifications locales du SDK |
| 09/10/2026 | Arthur | Après redémarrage, 214 « problèmes » dans VS Code (ex. `Target of URI doesn't exist: package:flutter/material.dart`) | Pas des bugs de l'appli : les dépendances du projet Flutter (`flutter_app/`) n'ont jamais été téléchargées (pas de `.dart_tool`), donc l'analyseur Dart ne connaît ni Flutter ni Supabase et tout le reste découle de là. Solution : installer Flutter puis `flutter create .` + `flutter pub get` dans `flutter_app/` (ou exclure le dossier de l'analyse en attendant) |
| 08/10/2026 | Arthur | Migration 000009 et tests OK : déployer et tester S4 / S5 | Edge Function déployée (v8). S5 : bienvenue de « Camille Démo » délivrée (manager en copie). S4 : projet « [DÉMO] Refonte du site » (réunion le jeudi) → récap chef + récap membres délivrés, pas de doublon. Données de démo conservées ; nettoyage : `supabase/sql/nettoyer-demo-stats.sql` |
| 08/10/2026 | Arthur | Créer un récapitulatif lié aux réunions de projet (S4) + un mail de bienvenue (S5) | Choix : réunion chaque semaine / toutes les 2 semaines / 1 fois par mois ; récap à 8 h le jour de la réunion au chef + membres ; tâches, chiffres clés, demandes de l'équipe (chef) ; bienvenue à la création du compte, manager en copie. Écrit : migration 000009, cron `novacorp-recaps`, trigger `users_bienvenue`, formulaire projet, 2 modèles de mails, 2 tests. En attente : `php artisan migrate`, puis déploiement et tests |
| 08/10/2026 | Arthur | Tester les scénarios du catalogue (S1 à S5) | `docs/tests-scenarios.md` : S1 ✅, S3 ✅ (jours ouvrés), S2 ⚠️ (seuil 5 j au lieu de 10, RH prévenue après le manager et non en même temps), S4 (digest hebdo) et S5 (onboarding) ❌ absents |
| 08/10/2026 | Arthur | Page Statistiques vide (connecté en `manager@novacorp.fr`) | Normal : un manager ne voit que son équipe, et les données de démo étaient rattachées à un « Démo Manager » sans connexion. Équipe et 48 demandes de démo rattachées à `manager@novacorp.fr` (Démo Manager désactivé), statistiques recalculées sur 30 jours ; script de génération mis à jour |
| 08/10/2026 | Arthur | Générer des données pour vérifier les statistiques, et les laisser visibles | 48 demandes de démo (équipe « Démo », 09/09 → 08/10, aucun mail envoyé) : temps moyen 2,8 j ouvrés, 86,5 % dans les temps, 5 services sollicités. Incident : rafale de 48 appels du webhook → base saturée quelques secondes ; corrigé (migration 000008 : 1 appel max toutes les 2 s, testé). Nettoyage plus tard : `supabase/sql/nettoyer-demo-stats.sql` |
| 08/10/2026 | Arthur | Tests OK, nettoyage webhook fait ; « je ne trouve plus les statistiques » (dashboard Flutter, Realtime, graphiques) | Les statistiques existaient seulement dans Supabase, sans écran. Ajouts : page **Statistiques** dans Laravel (6 indicateurs, 4 graphiques, période, entreprise / équipe, rafraîchie chaque minute, 2 tests) et projet **Flutter** `flutter_app/` (connexion Supabase Auth, mêmes graphiques, temps réel via `stats_quotidiennes`) |
| 08/10/2026 | Arthur | Migration 000007 appliquée et webhook créé dans Resend | Test de bout en bout : demande #86 → mail envoyé en 1 s par le webhook base de données → Resend renvoie « délivré » → statut enregistré (ordre des événements respecté) ; appels sans signature ou mal signés refusés (401). Case « Webhooks » cochée. Nettoyage : `supabase/sql/nettoyer-demo-webhook.sql` |
| 08/10/2026 | Arthur | Décisions architecture : Laravel = back-office web, Flutter sur notre schéma, commencer par les webhooks | Webhook base de données (mail ajouté → envoi immédiat, cron en secours) testé ; migration 000007 (suivi de livraison + journal `mails_evenements`) ; Edge Function `webhook-resend` déployée (signature Svix vérifiée). À faire : `php artisan migrate`, créer le webhook dans Resend et mettre son secret dans Supabase |
| 08/10/2026 | Arthur | Branche `develop` : vérifier / mettre en place l'architecture technique (Flutter, Supabase Auth/DB/Storage/Edge Functions/Cron/Realtime, triggers, webhooks) | Vérification dans `docs/architecture-technique.md` : DB, Storage, Edge Functions, Cron, triggers en place ; Auth et Realtime partiels ; **Flutter et webhooks absents**. 7 propositions (comptes Auth, RLS métier, workflow en RPC, Realtime métier, webhooks, liens des mails, projet Flutter), en attente de décision |
| 08/10/2026 | Arthur | Pour le moment, travail uniquement sur le PC d'Arthur | Les points liés à Ashley (historique Supabase CLI, son `.env`, ses migrations) sont mis en attente ; on continue sur la branche locale `Merge` |
| 08/10/2026 | Arthur | Retirer la « photo » du schéma (décision avec Ashley) ; mot de passe de la base changé | `supabase/migrations/20261008124735_remote_schema.sql` supprimé (Laravel reste maître de nos tables) ; `.env.example` remis avec `DB_PASSWORD=` vide. Le changement de mot de passe avait cassé l'envoi des mails (l'Edge Function gardait l'ancien : erreurs 500 de 15 h 40 à 15 h 44) → Edge Function redéployée (v6), envois rétablis ; secret facultatif `NOVACORP_DB_URL` ajouté pour ce cas. Reste : retirer la version 20261008124735 de l'historique Supabase CLI, mettre à jour les `.env` |
| 08/10/2026 | Arthur | Analyser le merge des deux versions (branche `Merge`, PR #4 depuis `Ashley-dev`) | Aucun conflit, aucun fichier de code modifié. Apports d'Ashley : projet Supabase CLI (`supabase/config.toml`), 3 migrations Supabase (copie complète du schéma de la base au 08/10 12 h 47 UTC, triggers `auth.users`, bucket `attachments` + règles) déjà marquées appliquées sur Supabase. **Alerte sécurité** : le mot de passe de la base a été écrit dans `.env.example` (commit 67b5836) → à changer dans Supabase et à retirer du fichier. Risque à venir : deux systèmes de migrations (Laravel et Supabase CLI) pour la même base |
| 08/10/2026 | Arthur | Refaire un test de tout ce qui a été fait depuis le matin | Suite délais : 61 / 62, le seul écart (R12) est voulu : depuis le workflow, une demande validée prévient aussi le service qui traite — attendu du test mis à jour. Suite workflow + statistiques : **50 / 50**. Crons : 0 échec sur 24 h ; Edge Function v4 : répond en 200, file vide (nettoyage des démos fait). Points relevés : ~1 appel sur 4 du cron vers l'Edge Function n'aboutit pas (délai réseau pg_net, le mail part la minute suivante, rien de perdu) ; 2 nouvelles fonctions du schéma de la collègue (`expire_overdue_requests`, `requests_before_write`) appelables sans connexion — à voir avec elle ; protection des mots de passe divulgués désactivée dans Supabase Auth. À relancer : `php artisan test` |
| 08/10/2026 | Arthur | `php artisan migrate` (statistiques) fait ; fin du workflow, étape 5 | Statistiques installées et vérifiées (table, Realtime, trigger, cron de nuit). Suite SQL complète **50 / 50 OK** (correctif : droits sur la table de résultats pour les tests d'accès). Démo des statistiques sur 30 jours simulés : 2,5 jours ouvrés de délai moyen, 89,5 % de réponses avant l'échéance, temps passé par service (voir `docs/propositions-workflow.md`). Workflow terminé (5 / 5 étapes) |
| 08/10/2026 | Arthur | Workflow, étape 5 : tests | Suite SQL rejouable `supabase/tests/tests-workflow.sql` (50 contrôles, rien n'est enregistré) : 38 / 38 OK sur Supabase (création, transitions interdites, circuits, mails, relances / escalades par étape, expiration, validation des tâches). Les 12 contrôles des statistiques tournent dès que la migration 000006 est appliquée |
| 08/10/2026 | Arthur | Workflow, étape 4 : statistiques pour le dashboard Flutter | Fonction RPC `stats_workflow()` (volume, par jour, temps moyen, % dans les temps, services sollicités, en cours, tâches) et table `stats_quotidiennes` en Realtime ; accès : RH / direction / admin = entreprise, manager = son équipe, autres refusés ; compte Supabase relié par e-mail confirmé ; rien de nominatif. 16 contrôles de sécurité et de calcul OK sur Supabase (annulés ensuite). Mode d'emploi pour la collègue : `docs/stats-flutter.md`. À lancer : `php artisan migrate` |
| 08/10/2026 | Arthur | Workflow, étape 3 (mails) — tests Laravel OK (37/37) | Edge Function v4 déployée : 9 nouveaux mails (étape suivante, à traiter, à compléter, complément reçu, annulation, traitement terminé, tâche à valider / renvoyée / validée), bouton « Demander un complément », mails adaptés aux étapes RH / comptabilité, motif du refus. Démo sur Supabase : 16 mails envoyés, 1 annulé à juste titre, 0 échec. Nettoyage des données de démo : `supabase/sql/nettoyer-demo-workflow.sql` |
| 08/10/2026 | Arthur | `php artisan migrate` (correctifs 000005) OK ; `php artisan test` : 1 échec dans `DemandeTest` | Erreur dans le test (pas dans l'appli) : `historique()` est déjà trié par id croissant, `latest('id')` ajoutait un 2e tri ignoré → le test lisait la création (canal « systeme ») au lieu de la décision. Test corrigé |
| 08/10/2026 | Arthur | Workflow, étape 2 (Laravel) | Service `WorkflowDemande` : valider (passage automatique à l'étape suivante selon montant / jours de congé), refuser (motif obligatoire), demander un complément, compléter, annuler, prendre en charge / terminer (RH, compta, admin), correction par RH / admin. Formulaire selon le type (montant, dates de congé, circuit affiché, plus de choix du manager : assignation automatique). Fiche : circuit, boutons selon le rôle, historique. Filtre et carte « À traiter par moi ». Lien du mail avec commentaire et « Demander un complément » (connexion obligatoire pour une étape de service). Tâches de projet : « Terminée » → « À valider » par le chef (valider / renvoyer avec commentaire). 9 nouveaux tests (`WorkflowTest`) + tests existants adaptés. À lancer : `php artisan migrate` (correctifs 000005) puis `php artisan test` |
| 08/10/2026 | Arthur | Workflow, étape 1 (base de données) | Migration écrite : circuits (9 étapes), machine à états (25 transitions vérifiées par trigger), historique automatique, montant / dates de congé / étape / motif, assignation automatique du manager, mails des nouvelles transitions, relances et escalades par étape. En attente de `php artisan migrate` |
| 08/10/2026 | Arthur | Valider la synthèse du workflow (seuils 500 € / 5 jours, tout membre du service valide, traitement RH / compta / admin, comptes reliés par e-mail) | Spécification et plan en 5 étapes dans `docs/propositions-workflow.md` ; étape 1 lancée |
| 08/10/2026 | Arthur | Comparer la proposition du groupe pour le workflow (trigger + assignation, validation + log, relances, dashboard Flutter Realtime) | Points 1-3 déjà presque en place (manquent : assignation auto, historique) ; point 4 bloqué tant que Flutter n'a pas d'accès (RLS sans règle, comptes non reliés à Supabase Auth) → options RPC / vues / table de stats en Realtime. Synthèse dans `docs/propositions-workflow.md` |
| 08/10/2026 | Arthur | Proposer (sans mettre en place) l'automatisation du workflow, avant la jonction avec la version de sa collègue | 3 propositions dans `docs/propositions-workflow.md` (statuts + historique / circuits par type / moteur complet) + point sur la jonction des deux schémas ; en attente de la proposition du groupe |
| 08/10/2026 | Arthur | Faire tous les tests nécessaires pour résumer la gestion des délais | Suite SQL rejouable `supabase/tests/tests-gestion-delais.sql` : **62 / 62 OK** sur Supabase ; nouveaux tests Laravel de cohérence PHP ↔ SQL + statistiques (`CoherenceDelaisTest`) ; tableau règles ↔ tests dans `docs/tests-gestion-delais.md` |
| 08/10/2026 | Arthur | Délais, étape 6 : démonstration complète | 10 scénarios (urgente, rappel d'échéance, escalade, expiration, deadline à fixer, relance/escalade du chef, rappel, report hors projet, report projet, tâche expirée) : 14 mails envoyés, 1 annulé à juste titre, 0 échec. Rapport `docs/tests-gestion-delais.md`. Nettoyage à faire par Arthur avant le 09/10 9 h |
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
- [x] Gestion des délais + module Tâches : 6 étapes en place et démontrées (08/10/2026).
- [ ] Workflow : choisir entre les propositions de `docs/propositions-workflow.md`.
- [ ] Jonction avec la version de la collègue : choisir le schéma de référence.
- [ ] Passage des mails en production : domaine vérifié chez Resend, `MAIL_EXPEDITEUR`, vider `MAIL_TEST_DESTINATAIRE`, `APP_URL` public.
- [ ] Commiter et pousser le projet sur GitHub.
- [ ] Lancer `php artisan test`.
- [ ] Lire automatiquement les réponses mail des managers (IMAP) pour tracer la validation.
- [ ] Validation en un clic, relances automatiques et délais sur les demandes.
- [ ] Tableau de bord RH et graphiques de chiffre d'affaires.
