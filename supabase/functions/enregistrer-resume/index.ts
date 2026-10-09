// Edge Function « enregistrer-resume » – NovaCorp (version 2 : résumé + tri automatique)
// Appelée par n8n (workflow « NovaCorp – résumé IA ») pour enregistrer l'analyse IA d'une demande.
// Simple appel HTTPS : aucun mot de passe de base dans n8n.
// Sécurité : en-tête x-novacorp-secret = secret Vault « n8n_secret » (le même que celui envoyé par Supabase à n8n).
// Corps JSON attendu : { "demande_id": 138, "resume": "<réponse de l'IA>" }
//   « resume » peut être du texte simple (version 1) ou le JSON demandé à l'IA (version 2) :
//   { "resume": "...", "points_attention": ["..."], "urgence_suggeree": false, "type_suggere": "materiel" | null }
import 'jsr:@supabase/functions-js/edge-runtime.d.ts'
import postgres from 'https://deno.land/x/postgresjs@v3.4.5/mod.js'

const sql = postgres((Deno.env.get('NOVACORP_DB_URL') || Deno.env.get('SUPABASE_DB_URL'))!, { max: 1, prepare: false, idle_timeout: 10 })
const TYPES = ['conge', 'materiel', 'formation', 'note_de_frais', 'autre']

Deno.serve(async (req) => {
  if (req.method !== 'POST') return new Response('méthode non autorisée', { status: 405 })

  const [{ secret }] = await sql`select decrypted_secret as secret from vault.decrypted_secrets where name = 'n8n_secret'`
  if (!secret || req.headers.get('x-novacorp-secret') !== secret) {
    return new Response('non autorisé', { status: 401 })
  }

  const corps = await req.json().catch(() => null)
  const demande = Number(corps?.demande_id)
  const brut = typeof corps?.resume === 'string' ? corps.resume : ''
  if (!Number.isInteger(demande) || demande <= 0 || !brut.trim()) {
    return Response.json({ ok: false, erreur: 'demande_id (entier) et resume (texte) obligatoires' }, { status: 400 })
  }

  const { resume, analyse } = decouper(brut)
  if (!resume) return Response.json({ ok: false, erreur: 'résumé vide' }, { status: 400 })

  const [{ ok }] = await sql`select public.enregistrer_resume_ia(${demande}::bigint, ${resume}::text, ${analyse ? sql.json(analyse) : null}::jsonb) as ok`
  return Response.json({ ok, demande_id: demande, analyse: analyse !== null }, { status: ok ? 200 : 404 })
})

/** Réponse de l'IA → résumé + analyse nettoyée (on ne fait jamais confiance au format renvoyé). */
function decouper(brut: string): { resume: string; analyse: Record<string, unknown> | null } {
  const texte = brut.trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '')
  let json: Record<string, unknown> | null = null
  try {
    const debut = texte.indexOf('{'), fin = texte.lastIndexOf('}')
    if (debut >= 0 && fin > debut) json = JSON.parse(texte.slice(debut, fin + 1))
  } catch { json = null }

  if (!json || typeof json.resume !== 'string') return { resume: brut.trim(), analyse: null } // texte simple

  const points = Array.isArray(json.points_attention)
    ? json.points_attention.filter((p): p is string => typeof p === 'string' && p.trim() !== '').map((p) => p.trim().slice(0, 200)).slice(0, 5)
    : []
  const type = typeof json.type_suggere === 'string' && TYPES.includes(json.type_suggere) ? json.type_suggere : null
  return {
    resume: json.resume.trim(),
    analyse: { points_attention: points, urgence_suggeree: json.urgence_suggeree === true, type_suggere: type },
  }
}
