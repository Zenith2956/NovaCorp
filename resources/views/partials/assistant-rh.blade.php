{{--
    TP séance 6 – Assistant RH (workflow n8n « ASSISTANT RH – RAG ») dans une bulle en bas à droite.
    Widget officiel @n8n/chat (version figée). Affiché seulement pour un utilisateur connecté et si N8N_CHAT_URL est renseigné.
    Le widget parle à Laravel (route assistant-rh), qui relaie vers n8n : l'adresse et les identifiants de n8n ne sont jamais dans la page,
    et l'identité transmise à l'agent (email, nom) est ajoutée côté serveur.
--}}
@auth
@if (config('novacorp.n8n.chat_url'))
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@n8n/chat@1.40.0/dist/style.css">
<style>
    :root {
        --chat--color--primary: #3b5bdb;
        --chat--color--primary-shade-50: #2f4ac0;
        --chat--color--primary--shade-100: #2a42ab;
        --chat--color--secondary: #2b8a3e;
        --chat--color-secondary-shade-50: #237032;
        --chat--color-dark: #1d2433;
        --chat--font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        --chat--window--width: 440px;
        --chat--window--height: 680px;
        --chat--toggle--size: 56px;
    }
    @media (max-width: 480px) {
        :root { --chat--window--width: calc(100vw - 32px); --chat--window--height: 70vh; }
        .assistant-rh-poignee { display: none; }
    }
    /* Poignée de redimensionnement (coin haut-gauche : la fenêtre est ancrée en bas à droite) */
    .chat-window-wrapper .chat-window { position: relative; }
    .assistant-rh-poignee {
        position: absolute; top: 0; left: 0; width: 22px; height: 22px; z-index: 2;
        cursor: nwse-resize; touch-action: none;
        background: linear-gradient(135deg, rgba(255,255,255,.75) 0 2px, transparent 2px 5px, rgba(255,255,255,.75) 5px 7px, transparent 7px);
        border-top-left-radius: var(--chat--window--border-radius, .25rem); opacity: .7;
    }
    .assistant-rh-poignee:hover { opacity: 1; }
</style>
<script type="module">
    import { createChat } from 'https://cdn.jsdelivr.net/npm/@n8n/chat@1.40.0/dist/chat.bundle.es.js';

    createChat({
        webhookUrl: @json(route('assistant-rh')),
        webhookConfig: { method: 'POST', headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' } },
        mode: 'window',
        loadPreviousSession: true,
        showWelcomeScreen: false,
        defaultLanguage: 'en',   // seule langue gérée par le widget : les textes ci-dessous sont en français
        initialMessages: [
            'Bonjour ' + @json(auth()->user()->prenom) + ' 👋',
            "Je suis l'assistant RH de NovaCorp. Posez-moi vos questions sur les congés, le télétravail, les frais, le matériel…",
        ],
        i18n: {
            en: {
                title: 'Assistant RH',
                subtitle: 'Réponses tirées des documents internes NovaCorp',
                footer: '',
                getStarted: 'Nouvelle conversation',
                inputPlaceholder: 'Votre question…',
                closeButtonTooltip: 'Fermer',
            },
        },
    });

    // Fenêtre redimensionnable : glisser la poignée du coin haut-gauche ; double-clic = agrandir / taille normale.
    // La taille est retenue dans ce navigateur (simple confort : si le stockage est indisponible, on garde la taille par défaut).
    const racine = document.documentElement.style;
    const CLE = 'novacorp.assistant-rh.taille';
    const MIN_L = 340, MIN_H = 420, DEFAUT = { l: 440, h: 680 };
    const borner = (l, h) => ({
        l: Math.round(Math.min(Math.max(l, MIN_L), window.innerWidth - 32)),
        h: Math.round(Math.min(Math.max(h, MIN_H), window.innerHeight - 110)),
    });
    const appliquer = ({ l, h }) => {
        racine.setProperty('--chat--window--width', l + 'px');
        racine.setProperty('--chat--window--height', h + 'px');
    };
    const memoriser = (t) => { try { localStorage.setItem(CLE, JSON.stringify(t)); } catch (e) {} };
    try { const t = JSON.parse(localStorage.getItem(CLE) || 'null'); if (t && t.l && t.h) appliquer(borner(t.l, t.h)); } catch (e) {}

    const ajouterPoignee = (fenetre) => {
        if (fenetre.querySelector('.assistant-rh-poignee')) return;
        const poignee = document.createElement('div');
        poignee.className = 'assistant-rh-poignee';
        poignee.title = 'Glisser pour redimensionner · double-clic pour agrandir';
        fenetre.appendChild(poignee);

        poignee.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            poignee.setPointerCapture(e.pointerId);
            const depart = { x: e.clientX, y: e.clientY, l: fenetre.offsetWidth, h: fenetre.offsetHeight };
            const bouger = (ev) => appliquer(borner(depart.l + (depart.x - ev.clientX), depart.h + (depart.y - ev.clientY)));
            const finir = () => {
                poignee.removeEventListener('pointermove', bouger);
                memoriser({ l: fenetre.offsetWidth, h: fenetre.offsetHeight });
            };
            poignee.addEventListener('pointermove', bouger);
            poignee.addEventListener('pointerup', finir, { once: true });
        });
        poignee.addEventListener('dblclick', () => {
            const grand = fenetre.offsetWidth >= window.innerWidth - 64;
            const t = grand ? borner(DEFAUT.l, DEFAUT.h) : borner(window.innerWidth, window.innerHeight);
            appliquer(t);
            memoriser(t);
        });
    };
    // La fenêtre du widget est créée à chaque ouverture : on ajoute la poignée dès qu'elle apparaît
    new MutationObserver(() => document.querySelectorAll('.chat-window-wrapper .chat-window').forEach(ajouterPoignee))
        .observe(document.body, { childList: true, subtree: true });
</script>
@endif
@endauth
