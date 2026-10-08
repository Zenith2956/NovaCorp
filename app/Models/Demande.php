<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Demande extends Model
{
    public const TYPES = [
        'conge' => 'Congé',
        'materiel' => 'Matériel',
        'formation' => 'Formation',
        'note_de_frais' => 'Note de frais',
        'autre' => 'Autre',
    ];

    public const STATUTS = [
        'en_attente' => 'En attente',
        'validee' => 'Validée',
        'refusee' => 'Refusée',
    ];

    protected $fillable = [
        'demandeur_id', 'manager_id', 'type', 'objet', 'message', 'statut', 'envoyee_at',
        'decision_at', 'decision_par', 'jeton_decision',
    ];

    protected $hidden = ['jeton_decision'];

    protected function casts(): array
    {
        return [
            'envoyee_at' => 'datetime',
            'decision_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Jeton des liens Valider / Refuser envoyés au manager
        static::creating(fn (Demande $demande) => $demande->jeton_decision ??= (string) Str::uuid());
    }

    public function mailsSortants(): HasMany
    {
        return $this->hasMany(MailSortant::class);
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function piecesJointes(): HasMany
    {
        return $this->hasMany(PieceJointe::class);
    }

    public function getTypeLibelleAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getStatutLibelleAttribute(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }
}
