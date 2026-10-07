<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChiffreAffaire extends Model
{
    protected $table = 'chiffres_affaires';

    protected $fillable = ['periode', 'montant', 'projet_id', 'commentaire'];

    protected function casts(): array
    {
        return [
            'periode' => 'date',
            'montant' => 'decimal:2',
        ];
    }

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class);
    }
}
