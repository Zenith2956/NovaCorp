# Statistiques du workflow – mode d'emploi pour l'application Flutter

> Workflow, étape 4 (08/10/2026). SQL : `database/sql/2026_10_08_000006_stats_workflow.sql`.
> Les demandes elles-mêmes restent **invisibles** depuis Flutter (RLS sans règle) : seuls des **chiffres agrégés, sans nom** sont exposés.

## 1. Se connecter

L'application Flutter utilise **Supabase Auth** (clé publique « anon » + e-mail / mot de passe).
Un compte Supabase est relié à l'employé NovaCorp qui a **la même adresse e-mail**, à condition que l'adresse soit **confirmée**.

| Rôle de l'employé | Ce qu'il voit |
| --- | --- |
| RH, direction, admin | Toute l'entreprise |
| Manager | Son équipe (demandes dont il est le manager, tâches de ses projets) |
| Autres (dev, commercial, comptable…) | Rien : « Accès refusé » |

## 2. Statistiques détaillées : fonction `stats_workflow`

```dart
final stats = await supabase.rpc('stats_workflow', params: {
  'p_debut': '2026-09-09', // facultatif (défaut : 30 derniers jours)
  'p_fin': '2026-10-08',   // facultatif (défaut : aujourd'hui) – un an maximum
}) as Map<String, dynamic>;
```

Réponse (JSON) :

| Clé | Contenu | Graphique conseillé |
| --- | --- | --- |
| `perimetre` | `entreprise` ou `equipe` | — |
| `volume.creees`, `volume.par_type`, `volume.par_statut` | Demandes créées sur la période | Camembert par type |
| `par_jour` | `[{jour, creees, decisions}]` pour chaque jour | Courbe / barres |
| `decisions.temps_moyen_jours_ouvres`, `decisions.temps_moyen_par_type` | Délai moyen création → décision (jours ouvrés) | Jauge, barres par type |
| `decisions.dans_les_temps_pct`, `validees`, `refusees` | Taux de réponse avant l'échéance | Jauge |
| `services` | `[{service, libelle, passages, jours_moyens}]` : temps passé chez chaque acteur (managers, RH, comptabilité, employés pour les compléments, service de traitement) | Barres horizontales « services sollicités » |
| `en_cours` | `en_attente`, `en_retard`, `a_completer`, `a_traiter`, `en_traitement` (état actuel) | Cartes chiffrées |
| `taches` | `a_faire`, `en_cours`, `a_valider`, `expirees`, `terminees_periode` | Cartes chiffrées |

Erreurs : code `42501` = compte non relié ou rôle non autorisé ; `22023` = période invalide.

## 3. Temps réel : table `stats_quotidiennes`

Une ligne par jour et par périmètre (`manager_id` vide = entreprise). Elle est recalculée **à chaque changement** de demande ou de tâche, puis chaque nuit à 0 h 10 (UTC).
Chaque utilisateur ne reçoit **que la ligne de son périmètre** (RLS).

Colonnes : `jour`, `manager_id`, `demandes_creees`, `decisions` (du jour), `en_attente`, `en_retard` (état actuel),
`temps_moyen_jours`, `dans_les_temps_pct` (30 derniers jours), `detail` (même JSON que `stats_workflow()` sur 30 jours), `updated_at`.

```dart
// Le widget se redessine dès qu'une demande change dans NovaCorp
supabase
    .from('stats_quotidiennes')
    .stream(primaryKey: ['id'])
    .order('jour')
    .listen((lignes) {
      final aujourdHui = lignes.last;           // ligne du jour
      final detail = aujourdHui['detail'];      // graphiques détaillés
      // historique jour par jour : lignes.map((l) => l['demandes_creees'])
    });
```

## 4. Sécurité (résumé)

- Lecture seule pour les comptes connectés ; rien pour les visiteurs anonymes.
- Aucune donnée nominative (ni nom, ni objet de demande) dans les statistiques.
- Lien de compte uniquement sur une adresse **confirmée** (évite qu'un inconnu crée un compte avec l'adresse d'un RH).
- Les calculs ne bloquent jamais l'appli : en cas d'erreur, la demande est enregistrée et un avertissement est journalisé.

## 5. Tests (08/10/2026, sur Supabase, annulés ensuite)

| Contrôle | Résultat |
| --- | --- |
| Compte RH | périmètre « entreprise », 1 ligne visible (aucune ligne de manager), 0 demande visible, écriture refusée |
| Compte manager | périmètre « équipe », seule sa ligne est visible |
| Compte employé (dev) | « Accès refusé », 0 ligne visible |
| Compte RH à l'adresse non confirmée | « Accès refusé » |
| Visiteur anonyme | fonction et table refusées |
| Période de plus d'un an | « Période invalide » |
| Demande annulée | « en attente » de la ligne du jour : 3 → 2 immédiatement |
| Table publiée en Realtime | oui |
| Temps de recalcul (entreprise + 10 managers) | 79 ms |
