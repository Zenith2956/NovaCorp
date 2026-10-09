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

Le pack reçu (`public/DocumentsRH/*.pdf`) ne contient que des PDF « imprimés » (images, sans texte sélectionnable).
Le texte a été extrait par OCR (tesseract, français), relu et remis en forme dans **`docs-rh/*.md`** (8 fichiers, mêmes noms) :
ce sont eux qu'ingère n8n ; les PDF restent les versions « de façade » à montrer aux employés.
Points vérifiés sur les PDF : 2 jours de télétravail (mardi, jeudi), > 10 jours de congés → manager + RH, barème décès (tante absente),
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
