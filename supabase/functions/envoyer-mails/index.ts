// Edge Function « envoyer-mails » – NovaCorp (version 4 : demandes + tâches + délais + workflow)
// Envoie les mails en attente de la table public.mails_sortants via l'API Resend.
// Appelée chaque minute par pg_cron ; peut aussi être appelée à la main : select automation.appeler_envoyer_mails();
//
// Secrets (Edge Functions > Secrets) : RESEND_API_KEY, APP_URL,
//   MAIL_TEST_DESTINATAIRE (si renseigné : TOUS les mails partent vers cette adresse),
//   MAIL_EXPEDITEUR (facultatif, défaut « NovaCorp <onboarding@resend.dev> »),
//   NOVACORP_DB_URL (facultatif, voir plus bas).
// Sécurité : l'appel doit porter l'en-tête x-cron-secret = secret Vault « cron_secret ».
import 'jsr:@supabase/functions-js/edge-runtime.d.ts'
import postgres from 'https://deno.land/x/postgresjs@v3.4.5/mod.js'
import { createClient } from 'jsr:@supabase/supabase-js@2'
import { encodeBase64 } from 'jsr:@std/encoding@1/base64'

// NOVACORP_DB_URL (facultatif) : chaîne de connexion à jour si SUPABASE_DB_URL garde un ancien mot de passe
const sql = postgres((Deno.env.get('NOVACORP_DB_URL') || Deno.env.get('SUPABASE_DB_URL'))!, { max: 3, prepare: false })
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
    select d.id, d.type, d.objet, d.message, d.statut, d.etape, d.jeton_decision, d.created_at, d.envoyee_at,
           d.urgente, d.date_souhaitee, d.echeance_le, d.deadline, d.commentaire_decision,
           d.montant, d.date_debut, d.date_fin, d.nb_jours_ouvres,
           e.prenom as e_prenom, e.nom as e_nom, e.email as e_email, e.telephone as e_tel,
           m.prenom as m_prenom, m.nom as m_nom, m.email as m_email, m.telephone as m_tel,
           dp.prenom as dp_prenom, dp.nom as dp_nom,
           tp.prenom as tp_prenom, tp.nom as tp_nom,
           ec.libelle as etape_libelle, coalesce(ec.valideur, 'manager') as valideur,
           rv.libelle as valideur_service,
           td.role_traitement, rt.libelle as service_traitement,
           (select count(*) from public.etapes_circuit x where x.type_code = d.type) as nb_etapes
      from public.demandes d
      join public.users e on e.id = d.demandeur_id
      left join public.users m on m.id = d.manager_id
      left join public.users dp on dp.id = d.decision_par
      left join public.users tp on tp.id = d.traite_par
      left join public.etapes_circuit ec on ec.type_code = d.type and ec.ordre = d.etape
      left join public.roles rv on rv.slug = ec.valideur
      left join public.types_demande td on td.code = d.type
      left join public.roles rt on rt.slug = td.role_traitement
     where d.id = ${mail.demande_id}`
  if (!d) throw new Error(`demande ${mail.demande_id} introuvable`)

  const don = mail.donnees ?? {}
  const etapeDuMail = don.etape !== undefined && don.etape !== null ? Number(don.etape) : null

  // Mails devenus inutiles entre leur création et leur envoi
  const pourLeValideur = ['relance', 'rappel_echeance', 'escalade', 'etape_suivante', 'complement_recu']
  if (pourLeValideur.includes(mail.type)) {
    if (d.statut !== 'en_attente') throw new Annule('demande déjà traitée')
    if (etapeDuMail !== null && etapeDuMail !== Number(d.etape)) throw new Annule("la demande a changé d'étape")
  }
  if (mail.type === 'a_completer' && d.statut !== 'a_completer') throw new Annule('demande déjà complétée ou close')
  if (mail.type === 'a_traiter' && d.statut !== 'validee') throw new Annule('demande déjà prise en charge ou corrigée')

  const typeLib = TYPES_DEMANDE[d.type] ?? d.type
  const employe = `${esc(d.e_prenom)} ${esc(d.e_nom)}`
  const manager = `${esc(d.m_prenom ?? '')} ${esc(d.m_nom ?? '')}`
  const decideur = d.dp_prenom ? `${esc(d.dp_prenom)} ${esc(d.dp_nom)}` : manager
  const parService = d.valideur !== 'manager'
  const service = esc(d.valideur_service ?? d.etape_libelle ?? '')
  const bonjourValideur = parService ? `<p>Bonjour,</p>` : `<p>Bonjour ${esc(d.m_prenom ?? '')},</p>`
  const etapeTexte = Number(d.nb_etapes) > 1 && d.etape_libelle
    ? `<p style="font-size:14px"><strong>Étape ${d.etape} du circuit :</strong> ${esc(d.etape_libelle)}${Number(d.etape) > 1 ? ' (déjà validée par le manager)' : ''}</p>`
    : ''
  const urgent = d.urgente ? 'URGENT – ' : ''
  const lien = (choix: string) => `${APP_URL}/decision/${d.id}/${d.jeton_decision}?choix=${choix}`
  const boutons = d.jeton_decision
    ? `<p style="margin:24px 0">${bouton(lien('validee'), 'Valider', '#2b8a3e')}&nbsp;&nbsp;${bouton(lien('a_completer'), 'Demander un complément', '#e67700')}&nbsp;&nbsp;${bouton(lien('refusee'), 'Refuser', '#c92a2a')}</p>
       <p style="color:#667085;font-size:13px">${parService
         ? `Ce mail est envoyé à tout le service ${service} : la première décision clôt l'étape. Connexion à NovaCorp demandée pour savoir qui décide.`
         : 'Vous pouvez aussi répondre directement à ce mail.'} Un commentaire est obligatoire pour refuser ou demander un complément.</p>`
    : ''
  const delais = `<p style="font-size:14px"><strong>Réponse attendue avant le :</strong> ${dateFr(d.echeance_le)}
       ${d.date_souhaitee ? `· <strong>date souhaitée :</strong> ${dateFr(d.date_souhaitee)}` : ''}
       · sans réponse, la demande expire après le ${dateFr(d.deadline)}.</p>`
  const details = [
    d.montant !== null ? `<strong>Montant :</strong> ${euros(d.montant)}` : '',
    d.date_debut ? `<strong>Congé :</strong> du ${dateFr(d.date_debut)} au ${dateFr(d.date_fin)} (${d.nb_jours_ouvres} jour(s) ouvré(s))` : '',
  ].filter(Boolean).join('<br>')
  const resume = `
    <p><strong>Objet :</strong> ${esc(d.objet)}<br><strong>Type :</strong> ${typeLib}${details ? '<br>' + details : ''}</p>
    <div style="padding:12px;background:#f4f6fb;border-left:4px solid #3b5bdb;white-space:pre-line">${esc(d.message)}</div>`
  const commentaire = (titre: string, texte: unknown, couleur = '#c92a2a') => texte
    ? `<p><strong>${titre} :</strong></p><div style="padding:12px;background:#fff8f0;border-left:4px solid ${couleur};white-space:pre-line">${esc(String(texte))}</div>`
    : ''
  const lienFiche = (texte: string) => `<p>${lienTexte(`${APP_URL}/demandes/${d.id}`, texte)}</p>`
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
          ${resume}${etapeTexte}${delais}${pjs.length ? `<p>${pjs.length} pièce(s) jointe(s).</p>` : ''}${liens}${boutons}${pied}`),
      }
    }
    case 'etape_suivante': {
      const pjs = await sql`select nom_original, chemin, taille from public.pieces_jointes where demande_id = ${d.id} order by id`
      const { attachments, liens } = await piecesJointes(pjs)
      return {
        sujet: `[NovaCorp] ${urgent}À valider (${d.etape_libelle ?? 'étape ' + d.etape}) – demande #${d.id} : ${d.objet}`,
        replyTo: d.e_email,
        attachments,
        html: page(`${bonjourValideur}
          <p>La demande de <strong>${employe}</strong> a été validée par son manager, ${manager}.
          Elle attend maintenant la validation ${parService ? `du service <strong>${service}</strong>` : 'de votre part'}.</p>
          ${resume}${etapeTexte}${delais}${pjs.length ? `<p>${pjs.length} pièce(s) jointe(s).</p>` : ''}${liens}${boutons}${pied}`),
      }
    }
    case 'decision': {
      const ok = d.statut === 'validee' || d.statut === 'en_traitement' || d.statut === 'terminee'
      if (d.statut === 'en_attente') throw new Annule('décision corrigée (demande remise en attente)')
      return {
        sujet: `[NovaCorp] Votre demande #${d.id} a été ${ok ? 'validée' : 'refusée'}`,
        html: page(`<p>Bonjour ${esc(d.e_prenom)},</p>
          <p>Votre demande <strong>« ${esc(d.objet)} »</strong> a été
          <strong style="color:${ok ? '#2b8a3e' : '#c92a2a'}">${ok ? 'validée' : 'refusée'}</strong> par ${decideur}.</p>
          ${ok ? '' : commentaire('Motif du refus', don.commentaire ?? d.commentaire_decision)}
          ${ok && d.service_traitement ? `<p>Elle est transmise au service <strong>${esc(d.service_traitement)}</strong> pour traitement ; vous serez prévenu quand ce sera fait.</p>` : ''}
          ${lienFiche('Voir la demande dans NovaCorp')}${pied}`),
      }
    }
    case 'a_traiter':
      return {
        sujet: `[NovaCorp] À traiter – demande #${d.id} validée : ${typeLib}, ${d.objet}`,
        replyTo: d.e_email,
        html: page(`<p>Bonjour,</p>
          <p>La demande de <strong>${employe}</strong> a été validée par ${decideur}.
          Elle est à traiter par le service <strong>${esc(d.service_traitement ?? '')}</strong>.</p>
          ${resume}
          <p style="margin:24px 0">${bouton(`${APP_URL}/demandes/${d.id}`, 'Prendre en charge dans NovaCorp', '#3b5bdb')}</p>
          <p style="color:#667085;font-size:13px">Ce mail est envoyé à tout le service : la première personne qui prend la demande en charge s'en occupe.</p>${pied}`),
      }
    case 'a_completer':
      return {
        sujet: `[NovaCorp] Votre demande #${d.id} est à compléter : ${d.objet}`,
        html: page(`<p>Bonjour ${esc(d.e_prenom)},</p>
          <p>${decideurAction(d)} demande un complément avant de pouvoir se prononcer sur votre demande <strong>« ${esc(d.objet)} »</strong>.</p>
          ${commentaire('Ce qui est demandé', don.commentaire ?? d.commentaire_decision, '#e67700')}
          <p style="margin:24px 0">${bouton(`${APP_URL}/demandes/${d.id}`, 'Compléter la demande', '#e67700')}</p>
          <p style="color:#667085;font-size:13px">Sans complément avant le ${dateFr(d.deadline)}, la demande expire.</p>${pied}`),
      }
    case 'complement_recu':
      return {
        sujet: `[NovaCorp] ${urgent}Complément reçu – demande #${d.id} : ${d.objet}`,
        replyTo: d.e_email,
        html: page(`${bonjourValideur}
          <p><strong>${employe}</strong> a complété sa demande comme demandé. Elle attend de nouveau votre décision.</p>
          ${resume}${etapeTexte}${delais}${boutons}${pied}`),
      }
    case 'annulation':
      return {
        sujet: `[NovaCorp] Demande #${d.id} annulée par ${d.e_prenom} ${d.e_nom} : ${d.objet}`,
        html: page(`${bonjourValideur}
          <p><strong>${employe}</strong> a annulé sa demande <strong>« ${esc(d.objet)} »</strong> (${typeLib}).
          Vous n'avez plus rien à faire : les liens des mails précédents ne fonctionnent plus.</p>${pied}`),
      }
    case 'traitement_termine':
      return {
        sujet: `[NovaCorp] Votre demande #${d.id} a été traitée : ${d.objet}`,
        html: page(`<p>Bonjour ${esc(d.e_prenom)},</p>
          <p>Votre demande <strong>« ${esc(d.objet)} »</strong> (${typeLib}) a été traitée par
          ${d.tp_prenom ? `<strong>${esc(d.tp_prenom)} ${esc(d.tp_nom)}</strong>` : 'le service'}${d.service_traitement ? ` (${esc(d.service_traitement)})` : ''}.
          Le dossier est clos.</p>${lienFiche('Voir la demande dans NovaCorp')}${pied}`),
      }
    case 'relance':
      return {
        sujet: `[NovaCorp] ${urgent}Relance – demande #${d.id} en attente : ${d.objet}`,
        replyTo: d.e_email,
        html: page(`${bonjourValideur}
          <p>La demande de <strong>${employe}</strong> envoyée le ${envoyeeLe} attend toujours ${parService ? `la décision du service <strong>${service}</strong>` : 'votre réponse'}.</p>
          ${resume}${etapeTexte}${delais}${boutons}${pied}`),
      }
    case 'rappel_echeance':
      return {
        sujet: `[NovaCorp] ${urgent}Échéance demain – demande #${d.id} : ${d.objet}`,
        replyTo: d.e_email,
        html: page(`${bonjourValideur}
          <p>La demande de <strong>${employe}</strong> arrive à échéance le <strong>${dateFr(d.echeance_le)}</strong>.
          Sans réponse, elle sera transmise ${parService ? 'à la direction' : 'aux RH et à votre responsable'}.</p>${resume}${etapeTexte}${boutons}${pied}`),
      }
    case 'escalade':
      return {
        sujet: `[NovaCorp] Escalade – demande #${d.id} sans réponse (échéance du ${dateFr(d.echeance_le)} dépassée)`,
        html: page(`<p>Bonjour,</p>
          <p>La demande de <strong>${employe}</strong> attend depuis le ${envoyeeLe}
          ${parService ? `la validation du service <strong>${service}</strong> (étape ${d.etape})`
            : `la réponse de <strong>${manager}</strong>${d.m_tel ? ' (' + esc(d.m_tel) + ')' : ''}`},
          malgré une relance. L'échéance était le ${dateFr(d.echeance_le)} ;
          la demande expirera après le ${dateFr(d.deadline)}.</p>${resume}
          ${lienFiche('Voir la demande dans NovaCorp')}${pied}`),
      }
    case 'expiration':
      return {
        sujet: `[NovaCorp] Votre demande #${d.id} a expiré : ${d.objet}`,
        html: page(`<p>Bonjour ${esc(d.e_prenom)},</p>
          <p>Votre demande <strong>« ${esc(d.objet)} »</strong>, envoyée le ${envoyeeLe},
          n'a pas ${d.etape > 1 ? `été validée à l'étape « ${esc(d.etape_libelle ?? '')} »` : `reçu de réponse de ${manager}`} avant le ${dateFr(d.deadline)} : elle a <strong>expiré</strong>.</p>
          <p>${bouton(`${APP_URL}/demandes/nouvelle?refaire=${d.id}`, 'Refaire la demande', '#3b5bdb')}</p>
          <p style="color:#667085;font-size:13px">Les RH et votre manager sont en copie de ce message.</p>${pied}`),
      }
    default:
      throw new Error(`type de mail inconnu : ${mail.type}`)
  }
}

/** Qui a demandé le complément : le service de l'étape, ou le manager. */
function decideurAction(d: Record<string, any>): string {
  return d.valideur !== 'manager'
    ? `Le service <strong>${esc(d.valideur_service ?? d.etape_libelle ?? '')}</strong>`
    : `<strong>${esc(d.m_prenom ?? '')} ${esc(d.m_nom ?? '')}</strong>`
}

// =====================================================================
// Tâches
// =====================================================================
async function mailTache(mail: MailSortant): Promise<Contenu> {
  const [t] = await sql`
    select t.id, t.titre, t.description, t.statut, t.commentaire_validation, t.deadline, t.deadline_initiale, t.nb_reports, t.jeton_deadline,
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
  if (mail.type === 'tache_a_valider' && t.statut !== 'a_valider') throw new Annule('tâche déjà validée ou renvoyée')

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
    case 'tache_a_valider':
      return {
        sujet: `[NovaCorp] Tâche à valider – ${t.titre} (${t.projet})`,
        html: page(`<p>Bonjour ${esc(t.c_prenom ?? '')},</p>
          <p><strong>${responsable}</strong> a terminé sa tâche${t.deadline ? ` (deadline du ${dateFr(t.deadline)})` : ''}.
          En tant que chef de projet, validez-la ou renvoyez-la avec un commentaire.</p>
          ${resume}<p style="margin:24px 0">${bouton(`${APP_URL}/taches/${t.id}`, 'Valider ou renvoyer dans NovaCorp', '#6741d9')}</p>${pied}`),
      }
    case 'tache_renvoyee':
      return {
        sujet: `[NovaCorp] Tâche renvoyée par le chef de projet – ${t.titre}`,
        html: page(`<p>Bonjour ${esc(t.r_prenom)},</p>
          <p>${chef} n'a pas validé votre tâche : elle repasse <strong>en cours</strong>.</p>
          <p><strong>Ce qu'il reste à faire :</strong></p>
          <div style="padding:12px;background:#fff8f0;border-left:4px solid #e67700;white-space:pre-line">${esc(String(don.commentaire ?? t.commentaire_validation ?? ''))}</div>
          ${resume}<p>${lienTache}</p>${pied}`),
      }
    case 'tache_validee':
      return {
        sujet: `[NovaCorp] Tâche validée – ${t.titre}`,
        html: page(`<p>Bonjour ${esc(t.r_prenom)},</p>
          <p>${chef} a validé votre tâche <strong>« ${esc(t.titre)} »</strong> (projet ${esc(t.projet ?? '')}) : elle est <strong style="color:#2b8a3e">terminée</strong>. Merci !</p>
          <p>${lienTache}</p>${pied}`),
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
function euros(n: unknown): string {
  return Number(n).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}
function esc(s: string) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!))
}
