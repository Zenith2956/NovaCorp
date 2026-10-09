<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Employé de NovaCorp.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'nom', 'prenom', 'email', 'telephone', 'role_id', 'manager_id', 'suppleant_id', 'actif', 'password',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
        ];
    }

    public function getNomCompletAttribute(): string
    {
        return "{$this->prenom} {$this->nom}";
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** A2 – Remplaçant choisi pendant les congés (à défaut : le N+1, puis la direction). */
    public function suppleant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suppleant_id');
    }

    public function equipe(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(Demande::class, 'demandeur_id');
    }

    public function demandesRecues(): HasMany
    {
        return $this->hasMany(Demande::class, 'manager_id');
    }

    public function projets(): BelongsToMany
    {
        return $this->belongsToMany(Projet::class);
    }

    public function taches(): HasMany
    {
        return $this->hasMany(Tache::class, 'responsable_id');
    }

    public function projetsDiriges(): HasMany
    {
        return $this->hasMany(Projet::class, 'chef_projet_id');
    }

    public function hasRole(string ...$slugs): bool
    {
        return in_array($this->role?->slug, $slugs, true);
    }
}
