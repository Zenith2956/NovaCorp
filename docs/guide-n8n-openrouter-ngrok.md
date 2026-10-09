# Guide – relier n8n, OpenRouter et ngrok (et ensuite NovaCorp)

> Demande d'Arthur (09/10/2026) : travailler avec n8n, OpenRouter et ngrok, étape par étape, en expliquant.
> **Règle** : aucune clé ni mot de passe dans le chat ou dans Git ; on les colle uniquement dans n8n / ngrok / Supabase.

## 0. Qui fait quoi ?

| Outil | Rôle | Image |
| --- | --- | --- |
| **n8n** (`http://localhost:5678`) | Atelier d'automatisation : on enchaîne des « nœuds » (recevoir un appel, appeler une IA, écrire en base, envoyer un mail…) | le chef d'orchestre |
| **OpenRouter** | Une seule clé pour utiliser des centaines de modèles d'IA (GPT, Claude, Mistral, Llama…) ; facturation à l'usage | le cerveau |
| **ngrok** | Donne une adresse Internet publique (`https://xxx.ngrok-free.app`) à n8n qui tourne sur ton PC, pour que Supabase puisse l'appeler | le tunnel |

```
NovaCorp (Supabase : trigger / cron)  ──HTTPS──▶  ngrok  ──▶  n8n sur ton PC  ──▶  OpenRouter (IA)
                                                                   │
                                                                   └──▶ réécrit le résultat dans Supabase / envoie un mail
```

Sans ngrok, Supabase (dans le cloud) ne peut pas joindre `localhost` sur ton PC.

## 1. ngrok : rendre n8n joignable depuis Internet

1. **Installer** (PowerShell) : `winget install ngrok.ngrok` (ou télécharger sur ngrok.com/download), puis fermer / rouvrir le terminal et vérifier : `ngrok version`.
2. **Relier ngrok à ton compte** : sur dashboard.ngrok.com → *Getting Started* → **Your Authtoken** → copier la commande `ngrok config add-authtoken …` et la lancer dans PowerShell. (Ne pas la coller dans le chat.)
3. **Réserver ton adresse fixe gratuite** : *Network → Domains* → un domaine `xxx.ngrok-free.app` est offert (le créer s'il n'y en a pas). Avec une adresse fixe, l'URL ne change pas à chaque démarrage.
4. **Ouvrir le tunnel** (laisser cette fenêtre ouverte) :
   `ngrok http 5678 --url=https://<ton-domaine>.ngrok-free.app`
5. **Vérifier** : ouvrir `https://<ton-domaine>.ngrok-free.app` dans le navigateur → page d'avertissement ngrok (« Visit Site ») puis l'écran de n8n. ✅ Fait le 09/10 (éditeur n8n affiché via l'adresse ngrok).

## Configuration d'Arthur (09/10/2026)

- n8n installé avec le script `get-n8n.sh` (Git Bash) → **Docker Compose** : fichiers `~/n8n/compose.yml` et `~/n8n/.env`, données dans le volume Docker `n8n-data`.
  Arrêter : `docker compose -f ./n8n/compose.yml down` · démarrer : `docker compose -f ./n8n/compose.yml up -d` (depuis `C:/Users/arthu`).
- Domaine ngrok fixe : `https://triage-bunkhouse-animating.ngrok-free.dev` (les nouveaux domaines gratuits finissent en `.ngrok-free.dev`).
- ⚠️ Le tunnel doit viser le port **5678** (n8n) : `ngrok http 5678 --url=https://triage-bunkhouse-animating.ngrok-free.dev`. Un tunnel vers le port 80 ne mène à rien.
- Adresse publique de n8n : ligne `WEBHOOK_URL=https://triage-bunkhouse-animating.ngrok-free.dev/` dans `~/n8n/.env`, puis redémarrage (down / up -d).

## 2. n8n : lui donner son adresse publique

n8n doit connaître l'adresse ngrok, sinon il affiche des URL de webhook en `localhost` (inutilisables depuis Supabase).
Arrêter n8n puis le relancer avec la variable `WEBHOOK_URL` :

| n8n lancé avec… | Commande (PowerShell) |
| --- | --- |
| `npx n8n` ou `n8n start` | `$env:WEBHOOK_URL="https://<ton-domaine>.ngrok-free.app/"; npx n8n` |
| Docker | `docker run -it --rm -p 5678:5678 -e WEBHOOK_URL=https://<ton-domaine>.ngrok-free.app/ -v n8n_data:/home/node/.n8n docker.n8n.io/n8nio/n8n` |
| Docker Compose | ajouter `- WEBHOOK_URL=https://<ton-domaine>.ngrok-free.app/` dans `environment:` puis `docker compose up -d` |

## 3. OpenRouter dans n8n

1. Sur openrouter.ai → *API Keys* : la clé **n8n** existe déjà. **Lui mettre une limite** (menu ⋮ → *Edit* → *Credit limit*, ex. 5 $) : elle est « unlimited » aujourd'hui.
2. Dans n8n → *Overview* → **Create → Credential** → chercher **OpenRouter** → coller la clé (`sk-or-v1-…`) → *Save* (le test doit être vert).

## 4. Premier workflow de test (webhook → IA → réponse)

1. n8n → **Build a workflow**.
2. Nœud **Webhook** : méthode `POST`, chemin `novacorp-test`, *Respond* : « Using 'Respond to Webhook' Node ».
3. Nœud **Basic LLM Chain** : *Prompt* = « Define below » → `Résume en une phrase : {{ $json.body.texte }}` ; sous-nœud **OpenRouter Chat Model** : credential de l'étape 3, modèle `openai/gpt-4o-mini` (peu cher) ou un modèle `:free`.
4. Nœud **Respond to Webhook** : *Respond With* = JSON → `{ "resume": "{{ $json.text }}" }`.
5. Cliquer **Test workflow** (n8n attend un appel), puis dans PowerShell :
   ```powershell
   Invoke-RestMethod -Method Post -Uri "https://<ton-domaine>.ngrok-free.app/webhook-test/novacorp-test" `
     -ContentType "application/json" -Body '{"texte":"Je souhaite poser 3 jours de congé fin octobre pour un déménagement."}'
   ```
   → la réponse contient le résumé de l'IA. 🎉 La chaîne ngrok → n8n → OpenRouter fonctionne.
6. Publier le workflow (bouton **Publish** en haut à droite dans la version actuelle de n8n ; « Active » dans les anciennes) : l'URL de production devient `…/webhook/novacorp-test` (sans `-test`).

### ✅ Réalisé le 09/10/2026 (version simplifiée, 2 blocs)

- **Webhook** : POST, chemin `novacorp-test`, *Respond* = « When Last Node Finishes », *Response Data* = « First Entry JSON » (pas besoin de bloc « Respond to Webhook »).
- **Basic LLM Chain** (icône maillons, PAS les blocs Anthropic / OpenAI / « Analyze… ») : prompt « Résume en une phrase, en français : {{ $json.body.texte }} », avec le sous-nœud **OpenRouter Chat Model** (`openai/gpt-4o-mini`) branché sur « Model ».
- Test PowerShell → réponse `text : Je demande trois jours de congé du 26 au 28 octobre pour mon déménagement, car mon travail en cours…` : la chaîne **ngrok → n8n → OpenRouter** fonctionne.
- Pièges rencontrés : méthode restée en GET (→ 404 « webhook not registered ») ; en mode test, n8n n'écoute qu'**un appel** après chaque clic sur « Execute workflow ».

## 5. Résumé IA des nouvelles demandes (idée D2) – ✅ opérationnel (testé le 09/10/2026, demande #139)

**Circuit** : nouvelle demande → trigger `demandes_resume_ia` (migration 000010) → `pg_net` POST vers `…ngrok-free.dev/webhook/novacorp-resume` (en-tête `x-novacorp-secret`) → n8n → OpenRouter (`openai/gpt-4o-mini`) → n8n appelle l'Edge Function `enregistrer-resume` (→ `public.enregistrer_resume_ia(id, texte)`) → résumé enregistré (`demandes.resume_ia`) + mail « nouvelle demande » du manager envoyé aussitôt avec un encadré **En bref**.

- Le mail attend le résumé **30 s au plus** ; si n8n / ngrok / le PC sont éteints, il part sans résumé. NovaCorp ne dépend pas de n8n.
- n8n renvoie le résumé à l'Edge Function **`enregistrer-resume`** (protégée par le secret partagé), qui ne fait **que** appeler `enregistrer_resume_ia`.
- Désactivé tant que les secrets Vault `n8n_resume_url` et `n8n_secret` n'existent pas ; les demandes `[DÉMO]` sont ignorées.
- ⚠️ Confidentialité : l'objet et le message de la demande sont envoyés à OpenRouter / OpenAI. Ne pas l'activer en production sans accord.

### A. Base de données
1. `php artisan migrate` (000010).
2. SQL Editor : `ALTER ROLE n8n_novacorp WITH PASSWORD '<mot de passe choisi par toi>';` (jamais dans le chat ni dans Git).
3. SQL Editor :
   ```sql
   select vault.create_secret('https://triage-bunkhouse-animating.ngrok-free.dev/webhook/novacorp-resume', 'n8n_resume_url');
   select vault.create_secret('<longue chaîne aléatoire>', 'n8n_secret');
   ```

### B. Workflow n8n « NovaCorp – résumé IA »
1. **Webhook** : POST, path `novacorp-resume`, Authentication = *Header Auth* (Name `x-novacorp-secret`, Value = le même secret), Respond = *Immediately*.
2. **Basic LLM Chain** (+ sous-nœud *OpenRouter Chat Model*, option **Maximum Number of Tokens = 300** – sinon OpenRouter refuse : « Payment required »), prompt :
   `Tu aides un manager. Résume cette demande en 1 ou 2 phrases en français, factuelles, sans formule de politesse. Type : {{ $json.body.type }}. Objet : {{ $json.body.objet }}. Message : {{ $json.body.message }}. Montant : {{ $json.body.montant }}. Du {{ $json.body.date_debut }} au {{ $json.body.date_fin }} ({{ $json.body.nb_jours_ouvres }} j ouvrés). Date souhaitée : {{ $json.body.date_souhaitee }}. Urgente : {{ $json.body.urgente }}.`
3. **HTTP Request** (remplace le bloc Postgres, abandonné : connexion du conteneur n8n au pooler Supabase refusée – certificat puis « Host not found ») :
   - Method `POST`, URL `https://dcqnefjzlkwphhytwczr.supabase.co/functions/v1/enregistrer-resume`
   - Authentication : *Generic Credential Type* → *Header Auth* → la **même** credential que le Webhook (`x-novacorp-secret`)
   - Send Body : JSON, *Using Fields Below* : `demande_id` = `{{ $('Webhook').item.json.body.demande_id }}`, `resume` = `{{ $json.text }}`
   - L'Edge Function `enregistrer-resume` vérifie le secret (Vault `n8n_secret`) puis appelle `public.enregistrer_resume_ia`. Aucun mot de passe de base dans n8n (le rôle `n8n_novacorp` n'est plus utilisé).
4. **Publish**.

## 6. Tri automatique des demandes (idée D1) – ✅ opérationnel (testé le 09/10/2026, demande #141)

Même workflow, même appel IA : l'IA renvoie un **JSON** (résumé + points d'attention) au lieu d'une phrase.
Migration 000011 : colonne `demandes.analyse_ia` (jsonb), `enregistrer_resume_ia(id, resume, analyse)`, déclencheur exécuté **en fin de transaction** pour connaître les pièces jointes, rôle `n8n_novacorp` supprimé.
L'Edge Function `enregistrer-resume` (v2) découpe le JSON (et accepte encore un texte simple). Les points d'attention sont des **suggestions** : rien n'est modifié ni bloqué ; ils sont montrés au valideur (mail + page), pas au demandeur.

Dans n8n, seul le prompt du **Basic LLM Chain** change (et *Maximum Number of Tokens* → 400) :

```
Tu aides un manager à traiter une demande interne. Réponds UNIQUEMENT avec un objet JSON valide, sans texte autour, au format :
{"resume": "...", "points_attention": ["..."], "urgence_suggeree": false, "type_suggere": null}
- resume : 1 ou 2 phrases factuelles en français, sans formule de politesse.
- points_attention : 0 à 3 points courts sur ce qui manque ou pose question (ex. note de frais sans justificatif, montant absent, dates incohérentes, motif flou). Liste vide si tout est clair.
- urgence_suggeree : true seulement si le message montre une vraie urgence (échéance très proche, travail bloqué).
- type_suggere : un code parmi conge, materiel, formation, note_de_frais, autre si le type choisi semble faux, sinon null.

Demande :
Type choisi : {{ $json.body.type }} ({{ $json.body.type_code }})
Objet : {{ $json.body.objet }}
Message : {{ $json.body.message }}
Montant : {{ $json.body.montant }} €
Dates : du {{ $json.body.date_debut }} au {{ $json.body.date_fin }} ({{ $json.body.nb_jours_ouvres }} j ouvrés)
Date souhaitée : {{ $json.body.date_souhaitee }}
Marquée urgente : {{ $json.body.urgente }}
Pièces jointes : {{ JSON.stringify($json.body.pieces_jointes) }}
Date du jour : {{ $now.toFormat('yyyy-MM-dd') }}
```

Le bloc **HTTP Request** ne change pas (`resume` = `{{ $json.text }}`).

## 7. Brouillon de réponse pour le valideur (idée D3) – ✅ opérationnel (testé le 09/10/2026)

Sur la page d'une demande **et sur la page ouverte par le lien du mail** (`/decision/…`, sans connexion pour le manager), le valideur qui peut refuser / demander un complément voit **« ✨ Proposer un motif de refus »** et **« ✨ Proposer une demande de complément »**. Laravel appelle n8n **directement** (même PC, pas besoin de ngrok) et place le brouillon dans le champ *Commentaire* : rien n'est envoyé sans que le valideur clique lui-même. Ce qu'il a déjà écrit sert d'indication à l'IA. Si n8n est éteint : message « assistant indisponible », le reste fonctionne.

Code : `App\Services\BrouillonIa`, `BrouillonIaController` (route `POST /demandes/{id}/brouillon-ia`, 10 appels/min ; `DecisionController::brouillonIa`, route `POST /decision/{id}/{jeton}/brouillon-ia`), vue partagée `demandes/_brouillon_ia`, test `BrouillonIaTest`.

**`.env` de Laravel** (jamais commité) puis `php artisan config:clear` :
```
N8N_BROUILLON_URL=http://localhost:5678/webhook/novacorp-brouillon
N8N_SECRET=<même valeur que le secret Vault n8n_secret>
```

**Nouveau workflow n8n « NovaCorp – brouillon IA »** :
1. **Webhook** : POST, path `novacorp-brouillon`, Authentication *Header Auth* (la même credential `x-novacorp-secret`), Respond **When Last Node Finishes**, Response Data **First Entry JSON**.
2. **Basic LLM Chain** + *OpenRouter Chat Model* (Maximum Number of Tokens 300), prompt :
```
Tu rédiges, au nom de {{ $json.body.valideur }}, le commentaire d'une {{ $json.body.intention_libelle }} pour une demande interne de {{ $json.body.demandeur_prenom }}.
Écris 2 à 4 phrases en français, polies et précises, en vouvoyant, sans formule d'appel ni signature.
Refus : explique clairement le motif. Demande de complément : liste précisément ce qui manque.
Appuie-toi d'abord sur les notes du valideur, puis sur les points d'attention. N'invente aucun fait.
Notes du valideur : {{ $json.body.notes_valideur }}
Points d'attention : {{ JSON.stringify($json.body.points_attention) }}
Demande : type {{ $json.body.type }}, objet « {{ $json.body.objet }} », montant {{ $json.body.montant }} €, du {{ $json.body.date_debut }} au {{ $json.body.date_fin }}, date souhaitée {{ $json.body.date_souhaitee }}.
Message : {{ $json.body.message }}
Pièces jointes : {{ JSON.stringify($json.body.pieces_jointes) }}
Réponds uniquement avec le texte du commentaire.
```
3. **Publish**.

## Sécurité

- Tant que ngrok tourne, n8n est **public** : garder le compte propriétaire n8n avec un mot de passe solide ; protéger chaque webhook par un en-tête secret.
- Clé OpenRouter : limite de crédit, et la régénérer si elle fuit.
- ngrok gratuit : quand ton PC ou la fenêtre ngrok est fermé, les appels de Supabase échouent (prévoir que NovaCorp continue de fonctionner sans n8n).
