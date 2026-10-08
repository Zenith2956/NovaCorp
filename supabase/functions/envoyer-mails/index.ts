// Edge Function « envoyer-mails » – NovaCorp
// Envoie les mails en attente de la table public.mails_sortants via l'API Resend.
// Appelée chaque minute par pg_cron (étape 4) ; peut aussi être appelée à la main.
//
// Secrets (Edge Functions > Secrets) : RESEND_API_KEY, APP_URL,
//   MAIL_TEST_DESTINATAIRE (si renseigné : TOUS les mails partent vers cette adresse),
//   MAIL_EXPEDITEUR (facultatif, défaut « NovaCorp <onboarding@resend.dev> »).
// Sécurité : l'appel doit porter l'en-tête x-cron-secret = secret Vault « cron_secret ».
import 'jsr:@supabase/functions-js/edge-runtime.d.ts'
import postgres from 'https://deno.land/x/postgresjs@v3.4.5/mod.js'
import { createClient } from 'jsr:@supabase/supabase-js@2'
import { encodeBase64 } from 'jsr:@std/encoding@1/base64'

const sql = postgres(Deno.env.get('SUPABASE_DB_URL')!, { max: 3, prepare: false })
const storage = createClient(
  Deno.env.get('SUPABASE_URL')!,
  Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!,
).storage.from('pieces-jointes')

const RESEND_API_KEY = Deno.env.get('RESEND_API_KEY') ?? ''
const APP_URL = (Deno.env.get('APP_URL') ?? 'http://localhost:8000').replace(/\/$/, '')
const MODE_TEST = (Deno.env.get('MAIL_TEST_DESTINATAIRE') ?? '').trim()
const EXPEDITEUR = Deno.env.get('MAIL_EXPEDITEUR') ?? 'NovaCorp <onboarding@resend.dev>'

const LOT = 20                          // mails traités par appel
const MAX_TENTATIVES = 5
const MAX_PJ_OCTETS = 15 * 1024 * 1024  // au-delà : liens de téléchargement seulement
const TYPES_DEMANDE: Record<string, string> = {
  conge: 'Congé', materiel: 'Matériel', formation: 'Formation', note_de_frais: 'Note de frais', autre: 'Autre',
}

type MailSortant = { id: number; demande_id: number; type: string; destinataires: string[]; copies: string[] | null; tentatives: number }

Deno.serve(async (req) => {
  // --- Autorisation : secret partagé stocké dans Vault
  const [{ secret }] = await sql`select decrypted_secret as secret from vault.decrypted_secrets where name = 'cron_secret'`
  if (!secret || req.headers.get('x-cron-secret') !== secret) {
    return new Response('non autorisé', { status: 401 })
  }

  // --- Remet en file les mails bloqués « en_cours » (fonction interrompue)
  await sql`update public.mails_sortants set statut = 'a_envoyer', updated_at = now()
            where statut = 'en_cours' and updated_at < now() - interval '10 minutes'`

  // --- Réserve un lot (SKIP LOCKED : deux appels simultanés ne prennent pas les mêmes)
  const lot: MailSortant[] = await sql`
    update public.mails_sortants m
       set statut = 'en_cours', tentatives = m.tentatives + 1, updated_at = now()
     where m.id in (
       select id from public.mails_sortants
        where statut = 'a_envoyer' and (prochain_essai_at is null or prochain_essai_at <= now())
        order by id limit ${LOT}
        for update skip locked)
    returning m.id, m.demande_id, m.type, m.destinataires, m.copies, m.tentatives`

  const bilan = { envoyes: 0, echecs: 0, annules: 0 }

  for (const mail of lot) {
    try {
      const resultat = await traiter(mail)
      bilan[resultat]++
    } catch (e) {
      bilan.echecs++
      const erreur = e instanceof Error ? e.message : String(e)
      const definitif = mail.tentatives >= MAX_TENTATIVES
      await sql`update public.mails_sortants
                   set statut = ${definitif ? 'echec' : 'a_envoyer'},
                       derniere_erreur = ${erreur.slice(0, 2000)},
                       prochain_essai_at = now() + make_interval(mins => ${2 ** mail.tentatives}),
                       updated_at = now()
                 where id = ${mail.id}`
    }
    await new Promise((r) => setTimeout(r, 600)) // limite Resend : 2 envois / seconde
  }

  console.log('envoyer-mails', { lot: lot.length, ...bilan })
  return Response.json({ lot: lot.length, ...bilan })
})

async function traiter(mail: MailSortant): Promise<'envoyes' | 'annules'> {
  const [d] = await sql`
    select d.id, d.type, d.objet, d.message, d.statut, d.jeton_decision, d.created_at, d.envoyee_at,
           e.prenom as e_prenom, e.nom as e_nom, e.email as e_email, e.telephone as e_tel,
           m.prenom as m_prenom, m.nom as m_nom, m.email as m_email, m.telephone as m_tel
      from public.demandes d
      join public.users e on e.id = d.demandeur_id
      left join public.users m on m.id = d.manager_id
     where d.id = ${mail.demande_id}`
  if (!d) throw new Error(`demande ${mail.demande_id} introuvable`)

  // Relance / escalade devenues inutiles : la demande a été traitée entre-temps
  if ((mail.type === 'relance' || mail.type === 'escalade') && d.statut !== 'en_attente') {
    await sql`update public.mails_sortants set statut = 'annule', derniere_erreur = 'demande déjà traitée', updated_at = now() where id = ${mail.id}`
    return 'annules'
  }

  const pjs = await sql`select nom_original, chemin, mime_type, taille from public.pieces_jointes where demande_id = ${d.id} order by id`
  const { sujet, html, avecPj } = construire(mail.type, d, pjs.length)

  // Pièces jointes : fichier joint si le total est raisonnable, sinon lien signé (7 jours)
  const attachments: { filename: string; content: string }[] = []
  const liens: string[] = []
  if (avecPj && pjs.length) {
    const total = pjs.reduce((s: number, p: { taille: number }) => s + Number(p.taille), 0)
    for (const p of pjs) {
      if (total <= MAX_PJ_OCTETS) {
        const { data, error } = await storage.download(p.chemin)
        if (error) throw new Error(`pièce jointe ${p.chemin} : ${error.message}`)
        attachments.push({ filename: p.nom_original, content: encodeBase64(new Uint8Array(await data.arrayBuffer())) })
      } else {
        const { data, error } = await storage.createSignedUrl(p.chemin, 7 * 24 * 3600, { download: p.nom_original })
        if (error) throw new Error(`lien ${p.chemin} : ${error.message}`)
        liens.push(`<li><a href="${data.signedUrl}">${esc(p.nom_original)}</a></li>`)
      }
    }
  }
  const corps = liens.length
    ? html + `<p><strong>Pièces jointes (liens valables 7 jours) :</strong></p><ul>${liens.join('')}</ul>`
    : html

  // Mode test : tout part vers une seule adresse, le vrai destinataire est dans l'objet
  const to = MODE_TEST ? [MODE_TEST] : mail.destinataires
  const cc = MODE_TEST ? [] : (mail.copies ?? [])
  const objet = MODE_TEST ? `[TEST → ${mail.destinataires.join(', ')}${mail.copies?.length ? ' / cc ' + mail.copies.join(', ') : ''}] ${sujet}` : sujet

  const reponse = await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${RESEND_API_KEY}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': `novacorp-mail-${mail.id}`, // pas de doublon si on réessaie
    },
    body: JSON.stringify({
      from: EXPEDITEUR, to, cc: cc.length ? cc : undefined,
      reply_to: mail.type === 'nouvelle_demande' || mail.type === 'relance' ? d.e_email : undefined,
      subject: objet, html: corps,
      attachments: attachments.length ? attachments : undefined,
    }),
  })
  const json = await reponse.json().catch(() => ({}))
  if (!reponse.ok) throw new Error(`Resend ${reponse.status} : ${JSON.stringify(json)}`)

  await sql`update public.mails_sortants
               set statut = 'envoye', envoye_at = now(), fournisseur_id = ${json.id ?? null},
                   derniere_erreur = null, updated_at = now()
             where id = ${mail.id}`
  if (mail.type === 'nouvelle_demande') {
    await sql`update public.demandes set envoyee_at = coalesce(envoyee_at, now()) where id = ${d.id}`
  }
  return 'envoyes'
}

// ---------- Contenu des mails ----------
// deno-lint-ignore no-explicit-any
function construire(type: string, d: any, nbPj: number) {
  const typeLib = TYPES_DEMANDE[d.type] ?? d.type
  const employe = `${esc(d.e_prenom)} ${esc(d.e_nom)}`
  const lien = (choix: string) => `${APP_URL}/decision/${d.id}/${d.jeton_decision}?choix=${choix}`
  const boutons = d.jeton_decision
    ? `<p style="margin:24px 0">
         ${bouton(lien('validee'), 'Valider', '#2b8a3e')}&nbsp;&nbsp;${bouton(lien('refusee'), 'Refuser', '#c92a2a')}
       </p><p style="color:#667085;font-size:13px">Vous pouvez aussi répondre directement à ce mail.</p>`
    : ''
  const resume = `
    <p><strong>Objet :</strong> ${esc(d.objet)}<br><strong>Type :</strong> ${typeLib}</p>
    <div style="padding:12px;background:#f4f6fb;border-left:4px solid #3b5bdb;white-space:pre-line">${esc(d.message)}</div>`
  const pied = `<p style="color:#667085;font-size:12px;margin-top:24px">Demande #${d.id} – NovaCorp · ${employe} · ${esc(d.e_email)}${d.e_tel ? ' · ' + esc(d.e_tel) : ''}</p>`

  switch (type) {
    case 'nouvelle_demande':
      return {
        sujet: `[NovaCorp] Demande #${d.id} – ${typeLib} : ${d.objet}`,
        html: page(`<p>Bonjour ${esc(d.m_prenom ?? '')},</p>
          <p><strong>${employe}</strong> vous adresse une demande.</p>${resume}
          ${nbPj ? `<p>${nbPj} pièce(s) jointe(s).</p>` : ''}${boutons}${pied}`),
        avecPj: true,
      }
    case 'decision': {
      const ok = d.statut === 'validee'
      return {
        sujet: `[NovaCorp] Votre demande #${d.id} a été ${ok ? 'validée' : 'refusée'}`,
        html: page(`<p>Bonjour ${esc(d.e_prenom)},</p>
          <p>Votre demande <strong>« ${esc(d.objet)} »</strong> a été
          <strong style="color:${ok ? '#2b8a3e' : '#c92a2a'}">${ok ? 'validée' : 'refusée'}</strong>
          par ${esc(d.m_prenom ?? '')} ${esc(d.m_nom ?? '')}.</p>
          <p><a href="${APP_URL}/demandes/${d.id}">Voir la demande dans NovaCorp</a></p>${pied}`),
        avecPj: false,
      }
    }
    case 'relance':
      return {
        sujet: `[NovaCorp] Relance – demande #${d.id} en attente : ${d.objet}`,
        html: page(`<p>Bonjour ${esc(d.m_prenom ?? '')},</p>
          <p>La demande de <strong>${employe}</strong> envoyée le ${dateFr(d.envoyee_at ?? d.created_at)}
          attend toujours votre réponse.</p>${resume}${boutons}${pied}`),
        avecPj: false,
      }
    case 'escalade':
      return {
        sujet: `[NovaCorp] Escalade – demande #${d.id} sans réponse depuis 5 jours ouvrés`,
        html: page(`<p>Bonjour,</p>
          <p>La demande de <strong>${employe}</strong> adressée à
          <strong>${esc(d.m_prenom ?? '')} ${esc(d.m_nom ?? '')}</strong>${d.m_tel ? ' (' + esc(d.m_tel) + ')' : ''}
          le ${dateFr(d.envoyee_at ?? d.created_at)} n'a pas reçu de réponse malgré une relance.</p>${resume}
          <p><a href="${APP_URL}/demandes/${d.id}">Traiter la demande dans NovaCorp</a></p>${pied}`),
        avecPj: false,
      }
    default:
      throw new Error(`type de mail inconnu : ${type}`)
  }
}

function page(contenu: string) {
  return `<!DOCTYPE html><html lang="fr"><body style="font-family:Arial,sans-serif;color:#1d2433;line-height:1.5">${contenu}</body></html>`
}
function bouton(href: string, texte: string, couleur: string) {
  return `<a href="${href}" style="display:inline-block;padding:10px 20px;background:${couleur};color:#fff;text-decoration:none;border-radius:6px;font-weight:bold">${texte}</a>`
}
function dateFr(d: Date | string) {
  // colonnes « timestamp » stockées en UTC
  const date = typeof d === 'string' ? new Date(d.replace(' ', 'T') + 'Z') : d
  return date.toLocaleDateString('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', year: 'numeric' })
}
function esc(s: string) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}
