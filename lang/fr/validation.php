<?php

// Traductions minimales des messages de validation utilisés dans l'application.
// Pour la traduction complète : composer require laravel-lang/common --dev
return [
    'required' => 'Le champ :attribute est obligatoire.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'exists' => 'La valeur sélectionnée pour :attribute est invalide.',
    'in' => 'La valeur sélectionnée pour :attribute est invalide.',
    'confirmed' => 'La confirmation du :attribute ne correspond pas.',
    'string' => 'Le champ :attribute doit être un texte.',
    'array' => 'Le champ :attribute doit être une liste.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'extensions' => 'Le fichier :attribute doit avoir l\'une des extensions suivantes : :values.',
    'date' => 'Le champ :attribute doit être une date valide.',
    'after_or_equal' => 'Le champ :attribute doit être une date égale ou postérieure au :date.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'gte' => [
        'numeric' => 'Le champ :attribute doit être supérieur ou égal à :value.',
    ],
    'max' => [
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
        'file' => 'Le fichier :attribute ne doit pas dépasser :max Ko.',
        'array' => 'Vous ne pouvez pas joindre plus de :max fichiers.',
    ],
    'min' => [
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'password' => [
        'min' => 'Le mot de passe doit contenir au moins :min caractères.',
    ],
    'attributes' => [
        'email' => 'e-mail',
        'password' => 'mot de passe',
        'prenom' => 'prénom',
        'nom' => 'nom',
        'telephone' => 'téléphone',
        'role_id' => 'rôle',
        'manager_id' => 'manager',
        'objet' => 'objet',
        'message' => 'message',
        'pieces_jointes' => 'pièces jointes',
        'pieces_jointes.*' => 'pièce jointe',
        'date_souhaitee' => 'date souhaitée',
        'types.*.delai_relance' => 'délai de relance',
        'types.*.delai_escalade' => 'délai d\'escalade',
    ],
];
