-- =====================================================================
-- TP séance 6 – Partie 1 : base de connaissances RH pour le RAG (n8n)
-- Table public.documents : un passage (« chunk ») des documents RH par ligne,
-- avec son embedding (vecteur qui représente le sens du texte).
--
-- Modèle d'embedding retenu : openai/text-embedding-3-small via OpenRouter → 1536 dimensions.
-- ⚠ La dimension de la colonne doit être EXACTEMENT celle du modèle : un autre modèle
--   (ex. 768 ou 1024 dimensions) ferait échouer l'ingestion (« dimensions do not match »).
-- =====================================================================

-- 1. pgvector : déjà activé sur le projet (schéma « extensions ») ; sans effet s'il l'est déjà
create extension if not exists vector with schema extensions;

-- 2. La table des passages
create table if not exists public.documents (
    id        bigint generated always as identity primary key,  -- identifiant auto
    content   text not null,                                     -- le texte du passage
    metadata  jsonb not null default '{}'::jsonb,                -- {"source": "01-faq-conges.md", "category": "Congés"}
    embedding extensions.vector(1536)                            -- l'« empreinte de sens » du passage
);
comment on table public.documents is 'RAG RH (TP séance 6) : passages des documents docs-rh/*.md et leurs embeddings, alimentés par n8n';

-- 3. Index HNSW : retrouve vite les passages les plus proches d'une question
--    (sans index, PostgreSQL comparerait la question à TOUS les passages).
--    vector_cosine_ops = distance cosinus, celle utilisée par les modèles d'embedding OpenAI.
create index if not exists documents_embedding_hnsw
    on public.documents using hnsw (embedding extensions.vector_cosine_ops);

-- Filtrage par catégorie / source dans les métadonnées
create index if not exists documents_metadata_gin on public.documents using gin (metadata);

-- 4. RLS activé SANS policy : personne ne lit ni n'écrit avec les clés anon / authenticated.
--    Seul n8n, avec la clé service_role (qui contourne le RLS), y accède.
alter table public.documents enable row level security;

-- 5. Fonction de recherche appelée par le nœud n8n « Supabase Vector Store » (mode Retrieve).
--    Nom et paramètres imposés par n8n / LangChain : match_documents(query_embedding, match_count, filter).
create or replace function public.match_documents(
    query_embedding extensions.vector(1536),
    match_count int default null,
    filter jsonb default '{}'
)
returns table (id bigint, content text, metadata jsonb, similarity float)
language plpgsql stable
set search_path = public, extensions
as $$
#variable_conflict use_column
begin
    return query
    select d.id, d.content, d.metadata,
           1 - (d.embedding <=> query_embedding) as similarity   -- 1 = même sens, 0 = sans rapport
      from public.documents d
     where d.metadata @> filter
     order by d.embedding <=> query_embedding
     limit match_count;
end;
$$;

-- Les documents internes ne sont pas lisibles via l'API publique : seule la clé service_role (n8n) peut chercher
revoke all on function public.match_documents(extensions.vector, int, jsonb) from public, anon, authenticated;
grant execute on function public.match_documents(extensions.vector, int, jsonb) to service_role;
