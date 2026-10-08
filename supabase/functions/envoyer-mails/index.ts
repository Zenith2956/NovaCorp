// Edge Function « envoyer-mails » – NovaCorp (version 3 : demandes + tâches + délais)
// Envoie les mails en attente de la table public.mails_sortants via l'API Resend.
// Appelée chaque minute par pg_cron ; peut aussi être appelée à la main : select automation.appeler_envoyer_mails();
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

type MailSortant = {
  id: number; demande_id: number | null; tache_id: number | null; type: string
  destinataires: string[]; copies: string[] | null; donnees: Record<string, unknown> | null; tentatives: number
}
type Contenu = { sujet: string; html: string; replyTo?: string; attachments?: { filename: string; content: string }[] }
class Annule extends Error {}

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
    returning m.id, m.demande_id, m.tache_id, m.type, m.destinataires, m.copies, m.donnees, m.tentatives`

  const bilan = { envoyes: 0, echecs: 0, annules: 0 }

  for (const mail of lot) {
    try {
      const contenu = mail.tache_id ? await mailTache(mail) : await mailDemande(mail)
      await envoyer(mail, contenu)
      bilan.envoyes++
    } catch (e) {
      if (e instanceof Annule) {
        await sql`update public.mails_sortants set statut = 'annule', derniere_erreur = ${e.message}, updated_at = now() where id = ${mail.id}`
        bilan.annules++
        continue
      }
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

// =====================================================================
// Envoi (commun)
// =====================================================================
async function envoyer(mail: MailSortant, c: Contenu) {
  // Mode test : tout part vers une seule adresse, le vrai destinataire est dans l'objet
  const to = MODE_TEST ? [MODE_TEST] : mail.destinataires
  const cc = MODE_TEST ? [] : (mail.copies ?? [])
  const objet = MODE_TEST
    ? `[TEST → ${mail.destinataires.join(', ')}${mail.copies?.length ? ' / cc ' + mail.copies.join(', ') : ''}] ${c.sujet}`
    : c.sujet

  const reponse = await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${RESEND_API_KEY}`,
      'Content-Type': 'application/json',
      'Idempotency-Key': `novacorp-mail-${mail.id}`, // pas de doublon si on réessaie
    },
    body: JSON.stringify({
      from: EXPEDITEUR, to, cc: cc.length ? cc : undefined, reply_to: c.replyTo,
      subject: objet, html: c.html,
      attachments: c.attachments?.length ? c.attachments : undefined,
    }),
  })
  const json = await reponse.json().catch(() => ({}))
  if (!reponse.ok) throw new Error(`Resend ${reponse.status} : ${JSON.stringify(json)}`)

  await sql`update public.mails_sortants
               set statut = 'envoye', envoye_at = now(), fournisseur_id = ${json.id ?? null},
                   derniere_erreur = null, updated_at = now()
             where id = ${mail.id}`
  if (mail.type === 'nouvelle_demande' && mail.demande_id) {
    await sql`update public.demandes set envoyee_at = coalesce(envoyee_at, now()) where id = ${mail.demande_id}`
  }
}

// =====================================================================
// Demandes
// =====================================================================
async function mailDemande(mail: MailSortant): Promise<Contenu> {
  const [d] = await sql`
    select d.id, d.type, d.objet, d.message, d.statut, d.jeton_decision, d.created_at, d.envoyee_at,
           d.urgente, d.date_souhaitee, d.echeance_le, d.deadline,
           e.prenom as e_prenom, e.nom as e_nom, e.email as e_email, e.telephone as e_tel,
           m.prenom as m_prenom, m.nom as m_nom, m.email as m_email, m.telephone as m_tel
      from public.demandes d
      join public.users e on e.id = d.demandeur_id
      left join public.users m on m.id = d.manager_id
     where d.id = ${mail.demande_id}`
  if (!d) throw new Error(`demande ${mail.demande_id} introuvable`)

  // Relance / rappel / escalade devenus inutiles : la demande a été traitée entre-temps
  if (['relance', 'rappel_echeance', 'escalade'].includes(mail.type) && d.statut !== 'en_attente') {
    throw new Annule('demande déjà traitée')
  }

  const typeLib = TYPES_DEMANDE[d.type] ?? d.type
  const employe = `${esc(d.e_prenom)} ${esc(d.e_nom)}`
  const manager = `${esc(d.m_prenom ?? '')} ${esc(d.m_nom ?? '')}`
  const urgent = d.urgente ? 'URGENT – ' : ''
  const lien = (choix: string) => `${APP_URL}/decision/${d.id}/${d.jeton_decision}?choix=${choix}`
  const boutons = d.jeton_decision
    ? `<p style="margin:24px 0">${bouton(lien('validee'), 'Valider', '#2b8a3e')}&nbsp;&nbsp;${bouton(lien('refusee'), 'Refuser', '#c92a2a')}</p>
       <p style="color:#667085;font-size:13px">Vous pouvez aussi répondre directement à ce mail.</p>`
    : ''
  const delais = `<p style="font-size:14px"><strong>Réponse attendue avant le :</strong> ${dateFr(d.echeance_le)}
       ${d.date_souhaitee ? `· <strong>date souhaitée :</strong> ${dateFr(d.date_souhaitee)}` : ''}
       · sans réponse, la demande expire après le ${dateFr(d.deadline)}.</p>`
  const resume = `
    <p><strong>Objet :</strong> ${esc(d.objet)}<br><strong>Type :</strong> ${typeLib}</p>
    <div style="padding:12px;background:#f4f6fb;border-left:4px solid #3b5bdb;white-space:pre-line">${esc(d.message)}</div>`
  const pied = `<p style="color:#667085;font-size:12px;margin-top:24px">Demande #${d.id} – NovaCorp · ${employe} · ${esc(d.e_email)}${d.e_tel ? ' · ' + esc(d.e_tel) : ''}</p>`
  const envoyeeLe = dateFr(d.envoyee_at ?? d.created_at)

  switch (mail.type) {
    case 'nouvelle_demande': {
      const pjs = await sql`select nom_original, chemin, taille from public.pieces_jointes where demande_id = ${d.id} order by id`
      const { attachments, liens } = await piecesJointes(pjs)
      return {
        sujet: `[NovaCorp] ${urgent}Demande #${d.id} – ${typeLib} : ${d.objet}`,
        replyTo: d.e_email,
        attachments,
        html: page(`<p>Bonjour ${esc(d.m_prenom ?? '')},</p>
          <p><strong>${employe}</strong> vous adresse une demande${d.urgente ? ' <strong style="color:#c2255c">urgente</strong>' : ''}.</p>
          ${resume}${delais}${pjs.length ? `<p>${pjs.length} pièce(s) jointe(s).</p>` : ''}${liens}${boutons}${pied}`),
      }
    }
    case 'decision': {
      const ok = d.statut === 'validee'
      return {
        sujet: `[NovaCorp] Votre demande #${d.id} a été ${ok ? 'validée' : 'refusée'}`,
        html: page(`<p>Bonjour ${esc(d.e_prenom)},</p>
          <p>Votre demande <strong>« ${esc(d.objet)} »</strong> a été
          <strong style="color:${ok ? '#2b8a3e' : '#c92a2a'}">${ok ? 'validée' : 'refusée'}</strong> par ${manager}.</p>
          <p>${lienTexte(`${APP_URL}/demandes/${d.id}`, 'Voir la demande dans NovaCorp')}</p>${pied}`),
      }
    }
    case 'relance':
      return {
        sujet: `[NovaCorp] ${urgent}Relance – demande #${d.id} en attente : ${d.objet}`,
        replyTo: d.e_email,
        html: page(`<p>Bonjour ${esc(d.m_prenom ?? '')},</p>
          <p>La demande de <strong>${employe}</strong> envoyée le ${envoyeeLe} attend toujours votre réponse.</p>
          ${resume}${delais}${boutons}${pied}`),
      }
    case 'rappel_echeance':
      return {
        sujet: `[NovaCorp] ${urgent}Échéance demain – demande #${d.id} : ${d.objet}`,
        replyTo: d.e_email,
        html: page(`<p>Bonjour ${esc(d.m_prenom ?? '')},</p>
          <p>La demande de <strong>${employe}</strong> arrive à échéance le <strong>${dateFr(d.echeance_le)}</strong>.
          Sans réponse, elle sera transmise aux RH et à votre responsable.</p>${resume}${boutons}${pied}`),
      }
    case 'escalade':
      return {
        sujet: `[NovaCorp] Escalade – demande #${d.id} sans réponse (échéance du ${dateFr(d.echeance_le)} dépassée)`,
        html: page(`<p>Bonjour,</p>
          <p>La demande de <strong>${employe}</strong> adressée à <strong>${manager}</strong>${d.m_tel ? ' (' + esc(d.m_tel) + ')' : ''}
          le ${envoyeeLe} n'a pas reçu de réponse malgré une relance. L'échéance était le ${dateFr(d.echeance_le)} ;
          la demande expirera après le ${dateFr(d.deadline)}.</p>${resume}
          <p>${lienTexte(`${APP_URL}/demandes/${d.id}`, 'Traiter la demande dans NovaCorp')}</p>${pied}`),
      }
    case 'expiration':
      return {
        sujet: `[NovaCorp] Votre demande #${d.id} a expiré : ${d.objet}`,
        html: page(`<p>Bonjour ${esc(d.e_prenom)},</p>
          <p>Votre demande <strong>« ${esc(d.objet)} »</strong>, adressée à ${manager} le ${envoyeeLe},
          n'a pas reçu de réponse avant le ${dateFr(d.deadline)} : elle a <strong>expiré</strong>.</p>
          <p>${bouton(`${APP_URL}/demandes/nouvelle?refaire=${d.id}`, 'Refaire la demande', '#3b5bdb')}</p>
          <p style="color:#667085;font-size:13px">Les RH et votre manager sont en copie de ce message.</p>${pied}`),
      }
    default:
      throw new Error(`type de mail inconnu : ${mail.type}`)
  }
}

// =====================================================================
// Tâches
// =====================================================================
async function mailTache(mail: MailSortant): Promise<Contenu> {
  const [t] = await sql`
    select t.id, t.titre, t.description, t.statut, t.deadline, t.deadline_initiale, t.nb_reports, t.jeton_deadline,
           t.projet_id, t.created_at, p.nom as projet,
           automation.ajouter_jours_ouvres(automation.date_paris(t.created_at), 5) as minimum,
           r.prenom as r_prenom, r.nom as r_nom, r.email as r_email,
           c.prenom as c_prenom, c.nom as c_nom, c.email as c_email
      from public.taches t
      join public.users r on r.id = t.responsable_id
      left join public.projets p on p.id = t.projet_id
      left join public.users c on c.id = p.chef_projet_id
     where t.id = ${mail.tache_id}`
  if (!t) throw new Error(`tâche ${mail.tache_id} introuvable`)

  const actif = ['a_faire', 'en_cours'].includes(t.statut)
  const deadlineFixee = t.deadline !== null
  const don = mail.donnees ?? {}

  // Mails devenus inutiles entre leur création et leur envoi
  if (['tache_deadline_a_fixer', 'tache_relance_deadline', 'tache_escalade_deadline'].includes(mail.type) && (deadlineFixee || !actif)) {
    throw new Annule('deadline déjà fixée ou tâche close')
  }
  if (mail.type === 'tache_rappel' && (!actif || isoDate(t.deadline) !== String(don.deadline))) {
    throw new Annule('tâche close ou deadline modifiée')
  }
  if (mail.type === 'tache_expiration' && t.statut !== 'expiree') {
    throw new Annule('tâche réactivée (deadline repoussée)')
  }

  const responsable = `${esc(t.r_prenom)} ${esc(t.r_nom)}`
  const chef = `${esc(t.c_prenom ?? '')} ${esc(t.c_nom ?? '')}`
  const ou = t.projet_id ? `projet <strong>${esc(t.projet)}</strong>` : 'hors projet'
  const lienTache = lienTexte(`${APP_URL}/taches/${t.id}`, 'Voir la tâche dans NovaCorp')
  const lienFixer = t.jeton_deadline
    ? bouton(`${APP_URL}/taches/${t.id}/fixer-deadline/${t.jeton_deadline}`, 'Fixer la deadline', '#3b5bdb')
    : lienTache
  const resume = `<p><strong>Tâche :</strong> ${esc(t.titre)} · ${ou} · responsable : ${responsable}</p>
    ${t.description ? `<div style="padding:12px;background:#f4f6fb;border-left:4px solid #3b5bdb;white-space:pre-line">${esc(t.description)}</div>` : ''}`
  const pied = `<p style="color:#667085;font-size:12px;margin-top:24px">Tâche #${t.id} – NovaCorp</p>`

  switch (mail.type) {
    case 'tache_deadline_a_fixer':
      return {
        sujet: `[NovaCorp] Deadline à fixer – ${t.titre} (${t.projet})`,
        html: page(`<p>Bonjour ${esc(t.c_prenom ?? '')},</p>
          <p><strong>${responsable}</strong> a créé une tâche dans votre projet. En tant que chef de projet,
          fixez sa deadline : <strong>au plus tôt le ${dateFr(t.minimum)}</strong> (création + 5 jours ouvrés).</p>
          ${resume}<p style="margin:24px 0">${lienFixer}</p>${pied}`),
      }
    case 'tache_relance_deadline':
      return {
        sujet: `[NovaCorp] Relance – deadline toujours à fixer : ${t.titre} (${t.projet})`,
        html: page(`<p>Bonjour ${esc(t.c_prenom ?? '')},</p>
          <p>La tâche de <strong>${responsable}</strong> créée le ${dateFr(t.created_at)} n'a toujours pas de deadline.
          Au plus tôt le ${dateFr(t.minimum)}.</p>${resume}<p style="margin:24px 0">${lienFixer}</p>${pied}`),
      }
    case 'tache_escalade_deadline':
      return {
        sujet: `[NovaCorp] Escalade – tâche sans deadline depuis 5 jours ouvrés : ${t.titre} (${t.projet})`,
        html: page(`<p>Bonjour,</p>
          <p>La tâche de <strong>${responsable}</strong> (projet ${esc(t.projet)}) attend depuis le ${dateFr(t.created_at)}
          que son chef de projet, <strong>${chef}</strong>, fixe une deadline.</p>${resume}<p>${lienTache}</p>${pied}`),
      }
    case 'tache_deadline_fixee': {
      const ancienne = don.ancienne ? String(don.ancienne) : null
      const titre = don.assignation ? 'Nouvelle tâche assignée' : ancienne ? 'Deadline repoussée' : 'Deadline fixée'
      return {
        sujet: `[NovaCorp] ${titre} – ${t.titre} : ${dateFr(String(don.deadline ?? isoDate(t.deadline)))}`,
        html: page(`<p>Bonjour ${esc(t.r_prenom)},</p>
          <p>${don.assignation ? `${chef} vous a assigné une tâche.` : ancienne
            ? `${chef} a repoussé la deadline de votre tâche (elle était fixée au ${dateFr(ancienne)}).`
            : `${chef} a fixé la deadline de votre tâche.`}</p>
          <p style="font-size:16px"><strong>Deadline : ${dateFr(String(don.deadline ?? isoDate(t.deadline)))}</strong></p>
          ${resume}<p>${lienTache}</p>${pied}`),
      }
    }
    case 'tache_deadline_modifiee':
      return {
        sujet: `[NovaCorp] Information – ${t.r_prenom} ${t.r_nom} a modifié la deadline d'une tâche`,
        html: page(`<p>Bonjour,</p>
          <p>Pour information, <strong>${responsable}</strong> a modifié la deadline de sa tâche hors projet :
          <strong>${dateFr(String(don.ancienne))} → ${dateFr(String(don.nouvelle))}</strong>
          ${t.nb_reports > 1 ? `(${t.nb_reports} reports, deadline initiale le ${dateFr(t.deadline_initiale)})` : ''}.</p>
          ${resume}<p>${lienTache}</p>${pied}`),
      }
    case 'tache_rappel':
      return {
        sujet: `[NovaCorp] Rappel – deadline le ${dateFr(t.deadline)} : ${t.titre}`,
        html: page(`<p>Bonjour ${esc(t.r_prenom)},</p>
          <p>Votre tâche doit être terminée le <strong>${dateFr(t.deadline)}</strong>.
          Pensez à la passer en « Terminée » dans NovaCorp${t.projet_id ? ', ou à demander un report à votre chef de projet' : ''}.</p>
          ${resume}<p>${lienTache}</p>${pied}`),
      }
    case 'tache_expiration':
      return {
        sujet: `[NovaCorp] Tâche expirée – ${t.titre} (deadline du ${dateFr(t.deadline)})`,
        html: page(`<p>Bonjour ${esc(t.r_prenom)},</p>
          <p>La tâche n'a pas été terminée avant sa deadline du <strong>${dateFr(t.deadline)}</strong> : elle est passée
          au statut <strong>expirée</strong>. ${t.projet_id ? 'Votre chef de projet est en copie et peut repousser la deadline.'
            : 'Vous pouvez repousser la deadline dans NovaCorp (votre manager en sera informé).'}</p>
          ${resume}<p>${lienTache}</p>${pied}`),
      }
    default:
      throw new Error(`type de mail inconnu : ${mail.type}`)
  }
}

// =====================================================================
// Outils
// =====================================================================
async function piecesJointes(pjs: { nom_original: string; chemin: string; taille: number }[]) {
  // Fichier joint si le total est raisonnable, sinon lien signé (7 jours)
  const attachments: { filename: string; content: string }[] = []
  const liste: string[] = []
  const total = pjs.reduce((s, p) => s + Number(p.taille), 0)
  for (const p of pjs) {
    if (total <= MAX_PJ_OCTETS) {
      const { data, error } = await storage.download(p.chemin)
      if (error) throw new Error(`pièce jointe ${p.chemin} : ${error.message}`)
      attachments.push({ filename: p.nom_original, content: encodeBase64(new Uint8Array(await data.arrayBuffer())) })
    } else {
      const { data, error } = await storage.createSignedUrl(p.chemin, 7 * 24 * 3600, { download: p.nom_original })
      if (error) throw new Error(`lien ${p.chemin} : ${error.message}`)
      liste.push(`<li><a href="${data.signedUrl}">${esc(p.nom_original)}</a></li>`)
    }
  }
  const liens = liste.length ? `<p><strong>Pièces jointes (liens valables 7 jours) :</strong></p><ul>${liste.join('')}</ul>` : ''
  return { attachments, liens }
}

function page(contenu: string) {
  return `<!DOCTYPE html><html lang="fr"><body style="font-family:Arial,sans-serif;color:#1d2433;line-height:1.5">${contenu}</body></html>`
}
function bouton(href: string, texte: string, couleur: string) {
  return `<a href="${href}" style="display:inline-block;padding:10px 20px;background:${couleur};color:#fff;text-decoration:none;border-radius:6px;font-weight:bold">${texte}</a>`
}
function lienTexte(href: string, texte: string) {
  return `<a href="${href}">${texte}</a>`
}
/** Date (ou horodatage UTC « AAAA-MM-JJ HH:MM:SS ») → JJ/MM/AAAA, heure de Paris. */
function dateFr(d: Date | string | null): string {
  if (!d) return '—'
  let date: Date
  if (d instanceof Date) date = d
  else if (/^\d{4}-\d{2}-\d{2}$/.test(d)) date = new Date(`${d}T12:00:00Z`) // date seule : midi UTC, pas de décalage
  else date = new Date(d.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(d) ? '' : 'Z'))
  return date.toLocaleDateString('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', year: 'numeric' })
}
/** Colonne « date » → AAAA-MM-JJ (pour comparer avec les données JSON). */
function isoDate(d: Date | string | null): string {
  if (!d) return ''
  if (d instanceof Date) return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
  return String(d).slice(0, 10)
}
function esc(s: string) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}
