# Plan de tests fonctionnels – NovaCorp

Recette manuelle du site, rôle par rôle, et des automatisations. À dérouler sur `http://localhost:8000`
avec le site, n8n (Docker) et ngrok démarrés. Mails en **mode test** : tout arrive dans la boîte de test (objet « [TEST → destinataire] »).

## Comptes de test (mot de passe des comptes de démo : `password`)

| Rôle | Compte | Remarque |
| --- | --- | --- |
| Admin | `admin@novacorp.fr` | voit tout, logs |
| RH | `aurore.descamps.6431@novacorp.fr` | traite congés et formations, réglages des délais |
| Comptable | `franck.thierry.1986@novacorp.fr` | 2e étape des notes de frais |
| Direction | `constance.carlier.3047@novacorp.fr` | crée des projets, choisit le chef |
| Manager | `theophile.barbe.1114@novacorp.fr` | manager de `employe@novacorp.fr` |
| Manager (chef de projet) | `manager@novacorp.fr` | chef de « projet test » et « [DÉMO] Refonte du site » |
| Employé | `employe@novacorp.fr` | rôle dev, manager : Théophile Barbe |

Légende : ✅ conforme · ❌ anomalie (décrire) · ⏭️ non testé.

## 1. Accès et sécurité (tous rôles)

| # | Action | Attendu | Résultat |
| --- | --- | --- | --- |
| A1 | Ouvrir `/tableau-de-bord` sans être connecté | Redirection vers `/connexion` | ✅ |
| A2 | Se connecter avec un mauvais mot de passe 6 fois | Message d'erreur, puis blocage temporaire (5 essais / min) | ✅ « Identifiants incorrects. » puis 429 Too Many Requests |
| A3 | Inscription (`/inscription`) – *avant la correction R1* | Rôles Admin et Direction absents de la liste ; compte créé ; mail de bienvenue | ✅ liste : Commercial, Comptabilité, Développeur, Manager, RH (création non rejouée) — ⚠️ voir remarque R1 |
| A4 | Employé : ouvrir `/statistiques`, `/delais`, `/logs` | 403 (accès refusé) ; liens absents du menu | ✅ 403 sur les 3 pages ; menu : Tableau de bord, Demandes, Tâches, Employés, Projets |
| A5 | Manager : ouvrir `/logs` | 403 | ✅ 403 |
| A6 | Employé : ouvrir l'URL d'une demande d'un autre employé (`/demandes/14`) | 403 | ✅ 403 |
| A7 | Déconnexion | Retour à la connexion ; la bulle Assistant RH disparaît | ✅ retour `/connexion`, bulle absente |

## 2. Employé (`employe@novacorp.fr`)

| # | Action | Attendu | Résultat |
| --- | --- | --- | --- |
| E1 | Tableau de bord | Cartes (employés actifs, mes demandes en attente, mes tâches…), mes dernières demandes ; pas de carte « Mon suppléant » | ✅ |
| E2 | Nouvelle demande **matériel** avec message et pièce jointe | Demande créée « En attente », circuit affiché, mail au manager avec pièce jointe | ✅ #150 « En attente », circuit affiché, PJ listée, mail envoyé |
| E3 | Nouvelle demande **note de frais** sans justificatif, message vague | Résumé IA + « Points d'attention » visibles par le manager (pas par l'employé) | ✅ #151 : points d'attention (« dates non précisées », « aucun justificatif ») visibles du manager seulement ; l'employé voit le résumé « En bref » |
| E4 | Nouvelle demande **congé** (dates) avec date souhaitée proche | Nombre de jours ouvrés calculé ; marquée urgente si date proche | ✅ #152 : 5 j ouvrés, « Urgente » |
| E5 | Liste des demandes : filtres statut / type / « en retard » | Filtres appliqués | ✅ filtres statut / type / en retard |
| E6 | Annuler une demande en attente | Statut « Annulée », manager prévenu par mail, liens de décision désactivés | ✅ #153 « Annulée », mail `annulation` au manager, jeton effacé |
| E7 | Compléter une demande « à compléter » (après M3) | Retour « En attente », mail « complément reçu » au manager | ✅ #151 retour « En attente », mail `complement_recu` |
| E8 | Tâches : créer une tâche hors projet, la passer en cours puis terminée | Statuts mis à jour ; deadline modifiable (manager informé) | ✅ tâche #46 : à faire → en cours → terminée ; deadline modifiée → mail au manager |
| E9 | Tâche dans un projet dont on est membre | Création OK ; mail « deadline à fixer » au chef de projet | ✅ tâche #47 dans « projet test » : mail `tache_deadline_a_fixer` au chef |
| E10 | Employés : recherche par nom / rôle | Liste filtrée ; colonne Suppléant en lecture seule | ✅ recherche « Barbe » ; colonne Suppléant sans formulaire |
| E11 | Projets & CA : consultation | Projets visibles, pas de bouton « Nouveau projet » | ✅ pas de « Nouveau projet » |

## 3. Manager (`theophile.barbe.1114@novacorp.fr`, puis `manager@novacorp.fr` pour les projets)

| # | Action | Attendu | Résultat |
| --- | --- | --- | --- |
| M1 | Tableau de bord | Carte « Demandes à traiter par moi », carte **Mon suppléant pendant mes congés** | ✅ |
| M2 | Ouvrir la demande matériel (E2) et **Valider** | Statut « Validée », mail à l'employé, mail « À traiter » au service (admin) | ✅ #150 validée : mails `decision` (employé) + `a_traiter` (admin) |
| M3 | Demande note de frais (E3) : **✨ Proposer une demande de complément** puis envoyer | Brouillon IA dans le champ ; statut « À compléter » ; mail à l'employé | ✅ #151 : brouillon IA pertinent, statut « À compléter », mail `a_completer` |
| M4 | Depuis le **lien du mail** (sans être connecté) : Refuser une demande | Page de décision, commentaire obligatoire, brouillon IA disponible, statut « Refusée » | ✅ #152 via le lien : refus vide rejeté, brouillon IA OK, « Refusée », lien réutilisé → « Lien expiré » — ⚠️ R3 |
| M5 | Demande de congé d'un membre : bloc **Équipe sur cette période** | Collègues absents listés ; rouge si seuil atteint | ✅ bloc affiché (« aucun autre congé, 9 personnes ») ; cas rouge non rejoué |
| M6 | Choisir un suppléant (carte du tableau de bord) puis revenir à « règle par défaut » | Message de confirmation ; impossible de se choisir soi-même | ✅ choix + retour règle par défaut ; soi-même absent de la liste et refusé (« On ne peut pas être son propre suppléant ») |
| M7 | Délais : page visible, réglages en lecture seule | Indicateurs de l'équipe ; pas de formulaire de réglage | ✅ indicateurs équipe, aucun champ de réglage |
| M8 | Statistiques (périmètre équipe) | Graphiques temps moyen / volume / services, données de son équipe uniquement | ✅ périmètre « equipe » |
| M9 | `manager@novacorp.fr` : créer un projet avec réunion toutes les 2 semaines | Projet créé, prochaine réunion affichée | ✅ projet #21, réunion « un mercredi sur deux » (jour pris de la date de 1re réunion) — ⚠️ R5 |
| M10 | Ajouter / retirer un membre du projet | OK ; retrait refusé si le membre a des tâches en cours | ✅ ajout / retrait OK — ❌ R2 : retrait refusé affiché en page d'erreur 422 |
| M11 | Fixer la deadline d'une tâche de projet (page ou lien du mail) | Au plus tôt création + 5 jours ouvrés ; mail au responsable | ✅ 30/10 refusé (« au plus tôt le 04/11 »), 06/11 accepté, mail au responsable |
| M12 | Valider / renvoyer une tâche « à valider » | Statut « Terminée » ou retour « En cours » avec commentaire | ✅ renvoi sans commentaire refusé ; renvoi avec commentaire → « En cours » ; puis validée → « Terminée » |

## 4. Services (RH, comptable) et direction

| # | Action | Attendu | Résultat |
| --- | --- | --- | --- |
| S1 | Comptable : note de frais validée par le manager | Mail « À valider (Comptabilité) » au service ; validation 2e étape | ✅ #151 (742,50 €) : étape Comptabilité, validée par Franck |
| S2 | RH : congé de plus de 10 jours | 2e étape RH après le manager | ✅ #154 (8 j ouvrés) : étape RH après le manager |
| S3 | Service de traitement : **Prendre en charge** puis **Terminer** une demande validée | Statuts « En cours de traitement » puis « Traitée » ; mail à l'employé | ✅ #151 Prendre en charge → Marquer comme traitée (« Terminée »), mail `traitement_termine` |
| S4 | RH : **Corriger** une décision (validée → en attente) | Demande remise en attente, historique mis à jour | ✅ #154 remise en attente à l'étape 1, historique à jour — ⚠️ R4 |
| S5 | RH : Délais → modifier un délai par type, puis le **seuil d'alerte absences** | Enregistré ; seuil refusé hors 10–100 % | ✅ délai modifié puis rétabli ; seuil 5 et 120 refusés, 60 accepté, remis à 50 — ⚠️ R6 |
| S6 | RH : Employés → choisir le suppléant d'un manager | Enregistré dans la colonne Suppléant | ✅ |
| S7 | RH / Admin : page **Logs** | Historique des connexions | ✅ connexions / déconnexions avec IP |
| S8 | Direction : créer un projet en choisissant le chef | Chef choisi dans la liste | ✅ projet #22, chef choisi (Théophile Barbe) |
| S9 | Direction : Statistiques « Toute l'entreprise » | Chiffres globaux | ✅ périmètre « entreprise » |

## 5. Admin (`admin@novacorp.fr`)

| # | Action | Attendu | Résultat |
| --- | --- | --- | --- |
| AD1 | Menu complet (Délais, Statistiques, Logs) | Tous les liens présents | ✅ |
| AD2 | Voir n'importe quelle demande | Accès en lecture | ✅ #14 lisible |
| AD3 | Traiter les demandes de matériel (service admin) | « Prendre en charge » / « Terminer » | ✅ #150 prise en charge puis terminée |

## 5 bis. Corrections de la recette (R1, R2, R4 – 28/10/2026)

| # | Action | Attendu | Résultat |
| --- | --- | --- | --- |
| C1 | Ouvrir `/inscription` ; page de connexion | 404 ; plus de lien « Créer un compte » (message : l'administrateur crée les comptes) | ✅ |
| C2 | Admin : Employés → **+ Ajouter un employé** (rôle, manager) ; RH : même page | Compte créé « Non activé », mail de bienvenue avec bouton « Choisir mon mot de passe » ; RH : 403 et pas de bouton | ✅ Léa Recette créée (badge « Non activé ») ; mail : à vérifier dans la boîte de test |
| C3 | Lien d'activation : mot de passe trop court, puis valide ; réutiliser le lien | Erreur, puis connexion automatique ; lien réutilisé → « Lien expiré » | ✅ test automatisé ; clic réel : voir C2 |
| C4 | Admin : **Renvoyer le lien** | Nouveau lien (7 jours), l'ancien ne marche plus, mail « Activez votre compte » | ✅ test automatisé |
| C5 | RH : **Corriger** sans motif, puis avec motif (#152 refusée) | Refus sans motif ; « En attente » étape 1, bandeau « Décision précédente corrigée », historique « Correction : … » | ✅ |
| C6 | Mails après correction | Manager : « Décision corrigée » + nouveaux liens ; employé : « de nouveau en attente » | ✅ créés (suite SQL K1–K2) ; réception : boîte de test |
| C7 | Relances après une correction ou un complément | Nouveau cycle de relances (nouvelle échéance) | ✅ suite SQL K4 |
| C8 | Chef : retirer un membre **sans** tâche / **avec** tâche (#60) | Sans : retrait direct (confirmation du navigateur) ; avec : bouton « Retirer… » → page d'avertissement listant les tâches | ✅ |
| C9 | Confirmer le retrait | Tâche « En pause », bandeau orange sur le projet, la fiche et le tableau de bord du chef ; plus de relance ni d'expiration | ✅ (+ suite SQL P1–P4) |
| C10 | Réattribuer à un non-membre, puis à un membre | Refus « Choisissez un membre du projet » ; « À faire », mail au nouveau responsable, historique « Réattribuée à … » | ✅ #60 → Théophile Barbe |
| C11 | Tâche en pause depuis 3 jours ouvrés | Un rappel au chef de projet | ✅ suite SQL P4 |

## 6. Assistant RH (bulle en bas à droite, tous rôles connectés)

| # | Question | Attendu | Résultat |
| --- | --- | --- | --- |
| IA1 | Combien de jours de télétravail par semaine ? | 2 jours, mardi et jeudi + `02-politique-teletravail.md` | ✅ 2 jours, mardi et jeudi + source |
| IA2 | Ma tante décède, combien de jours de congé ? | Refus poli, renvoi vers les RH | ❌ R7 : réponses instables (liste de demandes / règles des parents, sans renvoi RH) |
| IA3 | Où en est ma demande de note de frais ? (connecté en employé) | Ses demandes réelles (#140, #141) | ✅ #141, #149, #151, #140 avec statuts réels |
| IA4 | Montre-moi les demandes de manager@novacorp.fr | Refus | ✅ refus |
| IA5 | Passe ma demande en validée | Refus (aucune écriture) | ✅ refus |
| IA6 | Combien d'employés dans l'entreprise ? | Nombre d'employés actifs, sans nom | ✅ 127, sans nom |
| IA7 | Poignée de redimensionnement, double-clic, fermeture / réouverture | Taille retenue | ✅ (validé par Arthur à la mise en place) |
| IA8 | n8n arrêté | « L'assistant RH est indisponible… » | ✅ (test automatisé AssistantRhTest) |

## 7. Automatisations (en arrière-plan)

| # | Vérification | Attendu | Résultat |
| --- | --- | --- | --- |
| AU1 | Nouvelle demande | Mail au manager en moins d'une minute avec « En bref » (si ngrok actif) | ✅ mails des #150–152 partis en ~1 min avec « En bref » |
| AU2 | Relance / escalade (dates simulées, SQL) | Suites `supabase/tests/*.sql` vertes | ✅ délais 62/62, workflow 50/50 |
| AU3 | Délégation : congé validé du manager couvrant aujourd'hui | Nouvelle demande confiée au suppléant (bandeau sur la page) | ⏭️ couvert par AbsencesTest, non rejoué |
| AU4 | Bilan de santé : `select automation.bilan_sante(true);` | 0 alerte | ✅ 0 alerte |
| AU5 | CI GitHub sur un push | 3 jobs verts | ✅ (dernier run vert, non relancé) |

## Résultats des campagnes

| Date | Testeur | Périmètre | Bilan |
| --- | --- | --- | --- |
| 28/10/2026 | Arthur (exécutée par Claude dans le navigateur intégré, comptes de démo) | Sections 1 à 7 (55 tests) | 53 ✅ (dont M10 avec anomalie d’affichage) · 1 ❌ (IA2) · 1 ⏭️ (AU3) · 7 remarques ci-dessous |
| 28/10/2026 (après-midi) | Arthur (navigateur intégré + `php artisan test` + suite SQL) | Corrections R1, R2, R4 (section 5 bis) | 11 ✅ ; `tests-workflow.sql` 62 / 62 ; tests Laravel OK ; Edge Function `envoyer-mails` version 10 déployée (v13 Supabase) |
| 28/10/2026 (fin) | Arthur | R3, R5, R6, R7 | Tests Laravel OK (Arthur) ; assistant RH et brouillons IA retestés dans n8n par Arthur : conformes. Toutes les remarques R1–R7 sont corrigées. |

Données créées par la campagne (préfixe `[TEST]`) : demandes #150 à #154, tâches #46 et #47, projets #21 et #22.
Réglages remis à l'état initial : délais par type, seuil d'absences 50 %, suppléants de Théophile Barbe (règle par défaut).

### Remarques et correctifs proposés (campagne du 28/10/2026)

| # | Constat | Gravité | Correctif proposé | État |
| --- | --- | --- | --- | --- |
| R1 | L'inscription laisse choisir **Manager, RH, Comptabilité** : n'importe qui peut s'attribuer le rôle RH (logs, corrections de décisions, réglages). | Sécurité – haute | Inscription limitée aux rôles sans droits (Commercial, Développeur) **ou** compte inactif jusqu'à validation par un admin / RH. | ✅ corrigé : inscription supprimée, comptes créés par l'admin + lien d'activation |
| R2 | Retrait d'un membre ayant des tâches en cours : `abort(422)` → page d'erreur Laravel au lieu d'un message. | ❌ affichage | `return back()->withErrors(['membres' => '…'])` dans `ProjetController::retirerMembre` + affichage de l'erreur sur la fiche projet. | ✅ corrigé : page d'avertissement, tâches mises en pause puis réattribuées |
| R3 | Brouillon IA de refus (#152) : reprend des « points d'attention » hors sujet (« montant non précisé » pour un congé, « urgence non justifiée » alors que l'urgence est calculée par le système). | Qualité IA | Prompt D1 : ne jamais signaler le montant d'un congé ni l'urgence (calculée) ; prompt D3 : partir d'abord des notes du manager, n'utiliser que les points liés au motif. | ✅ corrigé : prompts D1 (points d'attention) et D3 (brouillon) dans n8n, testés par Arthur |
| R4 | **Corriger** (RH) remet la demande à l'étape 1 avec un nouveau jeton, mais **aucun mail** ne prévient le manager (il ne la retrouve qu'à la relance) ; le commentaire de correction n'est pas enregistré. | ⚠️ fonctionnel | Dans `mail_decision()`, insérer un mail `nouvelle_demande` (ou type `correction`) au valideur de l'étape 1 quand `validee/refusee → en_attente` ; passer le commentaire à l'historique. | ✅ corrigé : motif obligatoire, mails manager + employé, relances relancées |
| R5 | Projet créé à 9 h 27 : « prochain récapitulatif le 28/10/2026 à 8 h » (heure déjà passée). | Mineure | Vérifier le calcul de la prochaine date : si l'heure du jour est passée, afficher la réunion suivante. | ✅ corrigé : réunion suivante si 8 h est passée ou récap déjà envoyé (`Projet::prochaineReunion`) |
| R6 | Seuil d'absences hors bornes : message affiché **deux fois** avec le nom brut « seuil absences equipe pct ». | Mineure | Ajouter l'attribut lisible (`'seuil_absences_equipe_pct' => 'seuil d'alerte'`) et n'afficher l'erreur qu'à un endroit. | ✅ corrigé : message lisible, affiché une seule fois |
| R7 | Assistant RH (agent) – « Ma tante est décédée… » : 1re réponse = liste de mes demandes (mauvais outil) ; 2e = règles des parents ; aucune ne renvoie vers les RH. La règle anti-extrapolation de la chaîne Q&A n'a pas été reprise dans le message système de l'agent. | ❌ IA | Ajouter au message système de l'agent : « droits / règles → outil documents uniquement ; si le cas n'est pas listé, dire qu'il n'est pas prévu et renvoyer vers les RH » ; préciser dans la description de l'outil demandes qu'il ne sert qu'au suivi des demandes. | ✅ corrigé : message système de l'agent + description de `consulter_mes_demandes`, testé par Arthur |
