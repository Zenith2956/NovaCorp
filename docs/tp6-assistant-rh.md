# TP séance 6 – Assistant RH (RAG + agent IA)

Suivi du TP, étape par étape (Arthur). Plan : 1. Supabase `vector` + table `documents` · 2. credential LLM n8n ·
3. workflow INGESTION · 4. workflow RAG · 5. AGENT + sous-workflow de consultation.

## Choix techniques

| Élément | Choix | Pourquoi |
| --- | --- | --- |
| Fournisseur LLM | OpenRouter (clé « n8n », déjà utilisée pour le résumé IA) | un seul compte pour le chat et l'embedding |
| Modèle de chat | `openai/gpt-4o-mini`, température 0,2 | factuel et stable |
| Modèle d'embedding | `openai/text-embedding-3-small` | bon rapport qualité / prix, multilingue |
| **Dimension** | **1536** | celle du modèle d'embedding : la colonne `documents.embedding` est en `vector(1536)` |

## Partie 1 – pgvector + table `documents`

- Extension `vector` : déjà active sur le projet (version 0.8.2, schéma `extensions`).
- Migration : `supabase/migrations/20261009140000_create_documents_vector.sql`, appliquée avec `supabase db push`.
  - `documents(id, content, metadata jsonb, embedding vector(1536))` ;
  - index **HNSW** (distance cosinus) : la recherche des passages proches reste rapide sans comparer la question à toutes les lignes ;
  - **RLS activé sans policy** : les clés `anon` / `authenticated` n'ont aucun accès ; seul n8n, avec la clé `service_role`, lit et écrit ;
  - fonction **`match_documents(query_embedding, match_count, filter)`** : celle qu'appelle le nœud n8n « Supabase Vector Store » en mode *Retrieve*
    (absente du sujet, mais indispensable) ; non exécutable par `anon` / `authenticated`.
- Vérifiée avant application dans une transaction annulée : recherche par similarité et filtre par catégorie OK.

## Documents RH (`docs-rh/`)

- **`docs-rh/*.md`** (8 fichiers) : le texte des documents, c'est lui qu'ingère n8n. Chaque fichier porte son nom en tête et en pied
  (« Fichier source : … ») pour que l'assistant puisse le citer.
- **`docs-rh/version_PDF/`** : les versions mises en page à montrer aux employés (« 01 - NovaCorp — FAQ Congés annuels.pdf », …),
  hors de `public/` car « diffusion restreinte aux employés ».
- Historique : le premier pack PDF était « imprimé » (images sans texte) ; le texte avait été extrait par OCR puis relu.
  Les PDF refaits le 09/10/2026 contiennent du vrai texte : comparaison automatique PDF ↔ Markdown, **contenu identique**
  pour les 8 documents (seuls les pieds de page diffèrent), donc pas de changement de fond pour l'assistant.
- Points vérifiés : 2 jours de télétravail (mardi, jeudi), > 10 jours de congés → manager + RH, barème décès (tante absente),
  budget formation 1 200 € non reportable.

## Partie 4 – Workflow `ASSISTANT RH – RAG`

- Chat Trigger → **Question and Answer Chain** (la Basic LLM Chain de cette version de n8n n'a plus de connecteur de contexte)
  - Model : OpenAI Chat Model (credential `NovaCorp – LLM école`, `openai/gpt-4o-mini`, température 0,2) ;
  - Retriever : Vector Store Retriever (4 passages) → Supabase Vector Store (`documents`, `match_documents`) → Embeddings OpenAI (`openai/text-embedding-3-small`).
- Message système : celui du sujet + règle anti-extrapolation (T3 répondait d'abord « 1 jour », en assimilant la tante à un grand-parent).
- Nom du fichier ajouté en tête et en pied de chaque document `docs-rh/*.md` : la chaîne ne transmet que le texte des passages, pas leurs métadonnées.
- Tests T1–T5 : conformes (3 réponses sourcées, 2 refus).

### Bulle « Assistant RH » sur le site

- Widget officiel `@n8n/chat` (version figée 1.40.0), en bas à droite de toutes les pages, **seulement pour un utilisateur connecté** :
  `resources/views/partials/assistant-rh.blade.php`, inclus dans le layout.
- Le widget parle à **Laravel** (`POST /assistant-rh`, connexion + CSRF + 20 messages/min, `AssistantRhController`), qui relaie vers n8n
  avec une **authentification Basic Auth** gardée côté serveur : l'adresse de n8n et ses identifiants ne sont jamais dans la page.
- L'identité transmise à l'agent (`metadata.email`, `nom`, `role`) est ajoutée **par le serveur** depuis la session : un employé ne peut pas
  se faire passer pour un autre (indispensable pour la consultation des demandes de la partie 5). La mémoire est cloisonnée par utilisateur
  (`sessionId` préfixé par son identifiant).
- `.env` : `N8N_CHAT_URL` (URL de production du Chat Trigger), `N8N_CHAT_USER`, `N8N_CHAT_PASSWORD`. URL vide = pas de bulle.
- n8n éteint : la bulle affiche « L'assistant RH est indisponible pour le moment… ». Test : `AssistantRhTest`.
- Fenêtre de 440 × 680 px par défaut, **redimensionnable** avec la poignée du coin haut-gauche (double-clic : agrandir / taille normale) ; la taille choisie est retenue dans le navigateur.

## Partie 5 – Agent + consultation des demandes

- Vue **`public.v_demandes_employe`** (migration `supabase/migrations/20261009180000_vue_demandes_employe.sql`) : une ligne par demande
  avec l'email de l'employé et seulement les colonnes utiles (type, objet, statut en clair, étape et valideur en cours, dates, montant,
  commentaire de refus / complément). Ni message complet, ni jetons, ni pièces jointes. `security_invoker`, lecture réservée à `service_role`
  (testé : `anon` refusé).
- Sous-workflow **`TOOL – Consulter demandes employé`** : entrée `email` → Supabase *Get Many* sur la vue, filtre `email = …`.
- **Différence avec le sujet (sécurité)** : l'email n'est pas choisi par l'IA (`$fromAI('email')`) mais pris dans `metadata.email` du Chat
  Trigger, rempli **par Laravel** depuis la session. Un employé qui écrit « je suis paul@novacorp.fr » ne voit que ses propres demandes.
  Conséquence : la consultation se teste depuis la bulle du site (le chat de test de n8n n'envoie pas d'email).

### Outil 3 – chiffres généraux (`infos_entreprise`)

- Vue **`public.v_infos_entreprise`** (migration `supabase/migrations/20261009200000_vue_infos_entreprise.sql`) : une seule ligne de
  compteurs (`nb_employes_actifs`, `chiffres_au`), même règle que le tableau de bord. Aucun nom ni donnée personnelle ; lecture `service_role` uniquement.
- Outil n8n : nœud **Supabase** utilisé comme outil de l'agent (*Get Many*, table `v_infos_entreprise`).
- Pour ajouter un chiffre plus tard (ex. nombre de projets en cours), on ajoute une colonne à la vue : l'agent n'a jamais accès aux tables elles-mêmes.

## Récapitulatif – agent final et tests

Workflow `ASSISTANT RH – RAG` : Chat Trigger (Basic Auth, appelé par Laravel) → **AI Agent** (`openai/gpt-4o-mini`, température 0,2,
Simple Memory 5 échanges) avec 3 outils :

| Outil | Nœud n8n | Lit | Description donnée au LLM |
| --- | --- | --- | --- |
| `documentation_rh` | Supabase Vector Store (Retrieve as Tool, 4 passages, métadonnées) | `documents` | Recherche dans la documentation interne RH : congés, télétravail, onboarding, frais, entretiens, matériel |
| `consulter_mes_demandes` | Call n8n Workflow Tool → `TOOL – Consulter demandes employé` | `v_demandes_employe` filtrée sur l'email **du serveur** | Demandes RH de l'employé qui parle (statut, étape, valideur, dates, motif) ; ne demande pas d'email |
| `infos_entreprise` | Supabase (Get Many, comme outil) | `v_infos_entreprise` | Chiffres généraux sans donnée personnelle (nombre d'employés actifs) |

Message système : 9 règles (documents uniquement, pas d'extrapolation, source citée, consultation de ses seules demandes,
aucune écriture, hors sujet refusé, ne garder que les demandes correspondant à la question, chiffres généraux sans nom).

| Test | Question | Résultat |
| --- | --- | --- |
| T1–T5 | Batterie RAG du sujet | ✅ 3 réponses sourcées, 2 refus (T3 corrigé par la règle anti-extrapolation) |
| C1 | Où en est ma demande de note de frais ? | ✅ `consulter_mes_demandes` appelé, demandes réelles de l'utilisateur connecté |
| C2 | Et pendant cette période, le télétravail ? | ✅ mémoire + `documentation_rh`, source `02-politique-teletravail.md` |
| C3 | Passe ma demande en « validée » | ✅ refus : aucune écriture, renvoi plateforme / manager |
| C4 | Recette des crêpes | ✅ refus hors périmètre |
| Bonus | Demandes de manager@novacorp.fr | ✅ refus (uniquement ses propres demandes) |
| Bonus | Combien d'employés ? | ✅ `infos_entreprise` : 127 employés actifs |

Pièges rencontrés : connecteur de contexte absent de la Basic LLM Chain (→ Q&A Chain), Retriever inutilisable par un agent
(→ Vector Store en mode outil), sous-workflow non publié (« Workflow is not active »), description d'outil restée par défaut,
outil branché en sortie de l'agent au lieu du connecteur *Tool* (la bulle affichait le JSON brut).

À rendre : exports JSON des 3 workflows dans `workflows/`, captures de la conversation et de l'onglet Executions.
