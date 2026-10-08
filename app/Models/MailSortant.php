<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mail en attente / envoyé par l'Edge Function « envoyer-mails ».
 * Les lignes sont créées par des triggers PostgreSQL et le cron de relance.
 */
class MailSortant extends Model
{
    protected $table = 'mails_sortants';

    public const TYPES = [
        'nouvelle_demande' => 'Nouvelle demande',
        'decision' => 'Décision',
        'relance' => 'Relance',
        'escalade' => 'Escalade',
    ];

    protected $fillable = [
        'demande_id', 'type', 'destinataires', 'copies', 'statut',
        'tentatives', 'prochain_essai_at', 'derniere_erreur', 'fournisseur_id', 'envoye_at',
    ];

    protected function casts(): array
    {
        return [
            'destinataires' => 'array',
            'copies' => 'array',
            'prochain_essai_at' => 'datetime',
            'envoye_at' => 'datetime',
        ];
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class);
    }
}
