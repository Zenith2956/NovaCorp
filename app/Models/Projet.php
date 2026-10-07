<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Projet extends Model
{
    use HasFactory;

    protected $fillable = ['nom', 'description', 'statut', 'chef_projet_id', 'budget', 'date_debut', 'date_fin'];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'budget' => 'decimal:2',
        ];
    }

    public function chefProjet(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chef_projet_id');
    }

    public function membres(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function chiffresAffaires(): HasMany
    {
        return $this->hasMany(ChiffreAffaire::class);
    }
}
