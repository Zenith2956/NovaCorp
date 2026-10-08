// Edge Function « webhook-resend » – NovaCorp
// Reçoit les événements de Resend (envoyé, délivré, retardé, rebond, plainte, échec) et met à jour
// le statut de livraison du mail dans public.mails_sortants. Spécification : docs/architecture-technique.md
//
// Configuration (Resend > Webhooks > Add endpoint) :
//   URL : https://<projet>.supabase.co/functions/v1/webhook-resend
//   Événements : email.sent, email.delivered, email.delivery_delayed, email.bounced, email.complained, email.failed
// Secret (Edge Functions > Secrets) : RESEND_WEBHOOK_SECRET = « Signing secret » affiché par Resend (whsec_…).
// Sécurité : signature Svix vérifiée (HMAC-SHA256), horodatage à ± 5 minutes, événements rejoués ignorés.
import 'jsr:@supabase/functions-js/edge-runtime.d.ts'
import postgres from 'https://deno.land/x/postgresjs@v3.4.5/mod.js'
import { decodeBase64, encodeBase64 } from 'jsr:@std/encoding@1/base64'

const sql = postgres((Deno.env.get('NOVACORP_DB_URL') || Deno.env.get('SUPABASE_DB_URL'))!, { max: 2, prepare: false })
const SECRET = (Deno.env.get('RESEND_WEBHOOK_SECRET') ?? '').trim()
const TOLERANCE_S = 5 * 60

const LIVRAISON: Record<string, string> = {
  'email.sent': 'envoye',
  'email.delivery_delayed': 'retarde',
  'email.delivered': 'delivre',
  'email.failed': 'echec',
  'email.bounced': 'rebond',
  'email.complained': 'plainte',
}

Deno.serve(async (req) => {
  if (req.method !== 'POST') return new Response('méthode non autorisée', { status: 405 })

  const corps = await req.text()
  const id = req.headers.get('svix-id') ?? ''
  const horodatage = req.headers.get('svix-timestamp') ?? ''
  const signatures = req.headers.get('svix-signature') ?? ''

  if (!SECRET) return new Response('secret non configuré', { status: 500 })
  if (!id || !horodatage || !signatures) return new Response('signature absente', { status: 401 })
  if (Math.abs(Date.now() / 1000 - Number(horodatage)) > TOLERANCE_S) return new Response('horodatage invalide', { status: 401 })
  if (!(await signatureValide(id, horodatage, corps, signatures))) return new Response('signature invalide', { status: 401 })

  let evenement: { type?: string; created_at?: string; data?: Record<string, any> }
  try {
    evenement = JSON.parse(corps)
  } catch {
    return new Response('JSON invalide', { status: 400 })
  }
  const type = String(evenement.type ?? '')
  const fournisseurId = evenement.data?.email_id ? String(evenement.data.email_id) : null

  const [mail] = fournisseurId
    ? await sql`select id from public.mails_sortants where fournisseur_id = ${fournisseurId} limit 1`
    : []

  // Journal ; un même événement renvoyé par Resend (même svix-id) n'est traité qu'une fois
  const inseres = await sql`
    insert into public.mails_evenements (svix_id, type, fournisseur_id, mail_id, donnees, recu_at)
    values (${id}, ${type}, ${fournisseurId}, ${mail?.id ?? null}, ${sql.json(evenement.data ?? {})}, now())
    on conflict (svix_id) do nothing
    returning id`
  if (inseres.length === 0) return Response.json({ deja_recu: true })

  const statut = LIVRAISON[type]
  if (statut && mail) {
    const detail = evenement.data?.bounce?.message ?? evenement.data?.failed?.reason ?? null
    await sql`
      update public.mails_sortants
         set livraison = ${statut},
             livraison_at = ${evenement.created_at ?? new Date().toISOString()}::timestamptz,
             livraison_detail = ${detail},
             updated_at = now()
       where id = ${mail.id}
         and automation.rang_livraison(livraison) <= automation.rang_livraison(${statut})`
  }

  return Response.json({ recu: type, mail: mail?.id ?? null, statut: statut ?? null })
})

/** Signature Svix : base64(HMAC-SHA256(secret, "id.horodatage.corps")), en-tête « v1,<sig> v1,<sig2> … ». */
async function signatureValide(id: string, horodatage: string, corps: string, entete: string): Promise<boolean> {
  const cle = await crypto.subtle.importKey(
    'raw', decodeBase64(SECRET.replace(/^whsec_/, '')), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'],
  )
  const attendue = encodeBase64(new Uint8Array(
    await crypto.subtle.sign('HMAC', cle, new TextEncoder().encode(`${id}.${horodatage}.${corps}`)),
  ))
  return entete.split(' ').some((s) => {
    const [version, valeur] = s.split(',')
    return version === 'v1' && egaliteConstante(valeur ?? '', attendue)
  })
}

function egaliteConstante(a: string, b: string): boolean {
  if (a.length !== b.length) return false
  let difference = 0
  for (let i = 0; i < a.length; i++) difference |= a.charCodeAt(i) ^ b.charCodeAt(i)
  return difference === 0
}
