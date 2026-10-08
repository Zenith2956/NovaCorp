<?php

return [
    // Disque des pièces jointes : "supabase" (Supabase Storage) ou "local" (tests)
    'disque_pieces_jointes' => env('PIECES_JOINTES_DISK', 'local'),

    // true  = Laravel envoie lui-même le mail au manager (avant l'étape 3)
    // false = envoi par l'Edge Function « envoyer-mails » (boîte d'envoi Supabase)
    'envoi_mail_direct' => (bool) env('ENVOI_MAIL_DIRECT', true),
];
