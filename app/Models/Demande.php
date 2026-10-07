<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $fillable = ['demandeur_id', 'manager_id', 'type', 'objet', 'message', 'statut', 'envoyee_at'];

    protected function casts(): array
    {
        return ['envoyee_at' => 'datetime'];
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
