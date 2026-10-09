<?php

namespace App\Models;

use App\Support\Calendrier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Projet extends Model
{
    use HasFactory;

    protected $fillable = ['nom', 'description', 'statut', 'chef_projet_id', 'budget', 'date_debut', 'date_fin',
        'reunion_frequence', 'reunion_jour', 'reunion_depuis'];

    /** Rythme des réunions : le récapitulatif part à 8 h le jour de la réunion (S4, docs/tests-scenarios.md). */
    public const FREQUENCES = [
        'hebdomadaire' => 'Chaque semaine',
        'bimensuelle' => 'Toutes les 2 semaines',
        'mensuelle' => 'Une fois par mois (le 1er jour choisi du mois)',
    ];

    public const JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi'];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'budget' => 'decimal:2',
            'reunion_jour' => 'integer',
            'reunion_depuis' => 'date',
            'dernier_recap_le' => 'date',
        ];
    }

    /** Même règle que automation.est_jour_reunion() (jour férié : pas de réunion). */
    public function estJourDeReunion(CarbonInterface $jour): bool
    {
        if (! $this->reunion_frequence || ! $this->reunion_jour || $jour->dayOfWeekIso !== $this->reunion_jour
            || ! Calendrier::estOuvre($jour)) {
            return false;
        }

        return match ($this->reunion_frequence) {
            'hebdomadaire' => true,
            'bimensuelle' => $this->reunion_depuis
                && $jour->toDateString() >= $this->reunion_depuis->toDateString()
                && (int) round(CarbonImmutable::parse($this->reunion_depuis->toDateString())
                    ->diffInDays(CarbonImmutable::parse($jour->toDateString()))) % 14 === 0,
            'mensuelle' => $jour->day <= 7,
            default => false,
        };
    }

    /** Prochaine réunion (aujourd'hui compris), donc prochain récapitulatif. */
    public function prochaineReunion(): ?CarbonImmutable
    {
        if (! $this->reunion_frequence) {
            return null;
        }
        $jour = Calendrier::aujourdhui();
        for ($i = 0; $i < 120; $i++, $jour = $jour->addDay()) {
            if ($this->estJourDeReunion($jour)) {
                return $jour;
            }
        }

        return null;
    }

    public function getDescriptionReunionAttribute(): ?string
    {
        $jour = self::JOURS[$this->reunion_jour] ?? null;

        return match ($this->reunion_frequence) {
            'hebdomadaire' => "Chaque {$jour}",
            'bimensuelle' => "Un {$jour} sur deux (à partir du {$this->reunion_depuis?->format('d/m/Y')})",
            'mensuelle' => "Le 1er {$jour} du mois",
            default => null,
        };
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

    public function taches(): HasMany
    {
        return $this->hasMany(Tache::class);
    }

    public function aPourMembre(User $user): bool
    {
        return $user->id === $this->chef_projet_id || $this->membres()->whereKey($user->id)->exists();
    }

    /** Le chef de projet (et la direction / l'admin) gère les membres. */
    public function gerablePar(User $user): bool
    {
        return $user->id === $this->chef_projet_id || $user->hasRole('direction', 'admin');
    }
}
