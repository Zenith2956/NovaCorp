<?php

return [
    // Disque des pièces jointes : "supabase" (Supabase Storage) ou "local" (tests)
    'disque_pieces_jointes' => env('PIECES_JOINTES_DISK', 'local'),

    // true  = Laravel envoie lui-même le mail au manager (avant l'étape 3)
    // false = envoi par l'Edge Function « envoyer-mails » (boîte d'envoi Supabase)
    'envoi_mail_direct' => (bool) env('ENVOI_MAIL_DIRECT', true),

    // D3 – Brouillon IA (workflow n8n « NovaCorp – brouillon IA ») ; vide = bouton désactivé
    'n8n' => [
        'brouillon_url' => env('N8N_BROUILLON_URL'),   // ex. http://localhost:5678/webhook/novacorp-brouillon
        'secret' => env('N8N_SECRET'),                 // même valeur que le secret Vault « n8n_secret »
    ],
];
