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
        'jeton_activation', 'jeton_activation_expire_at',
    ];

    protected $hidden = ['password', 'remember_token', 'jeton_activation'];

    /** R1 – durée de validité du lien d'activation envoyé au nouvel employé. */
    public const VALIDITE_ACTIVATION_JOURS = 7;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
            'jeton_activation_expire_at' => 'datetime',
        ];
    }

    /** R1 – compte créé par l'admin dont le mot de passe n'a pas encore été choisi. */
    public function getEnAttenteActivationAttribute(): bool
    {
        return $this->jeton_activation !== null;
    }

    /** R1 – nouveau lien d'activation (les liens précédents ne marchent plus). */
    public function nouveauJetonActivation(): string
    {
        $this->forceFill([
            'jeton_activation' => $jeton = (string) \Illuminate\Support\Str::uuid(),
            'jeton_activation_expire_at' => now()->addDays(self::VALIDITE_ACTIVATION_JOURS),
        ])->save();

        return $jeton;
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
