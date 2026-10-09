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
        --chat--window--width: 380px;
        --chat--window--height: 560px;
        --chat--toggle--size: 56px;
    }
    @media (max-width: 480px) {
        :root { --chat--window--width: calc(100vw - 32px); --chat--window--height: 70vh; }
    }
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
</script>
@endif
@endauth
