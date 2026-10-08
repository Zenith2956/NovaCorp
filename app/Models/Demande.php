<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Calendrier;
use Illuminate\Support\Facades\DB;
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
        'expiree' => 'Expirée',
    ];

    /** Statuts qu'un manager / RH peut choisir à la main (l'expiration est automatique). */
    public const STATUTS_MANUELS = ['en_attente', 'validee', 'refusee'];

    public const INDICATEURS = [
        'dans_les_temps' => 'Dans les temps',
        'echeance_proche' => 'Échéance proche',
        'en_retard' => 'En retard',
    ];

    protected $fillable = [
        'demandeur_id', 'manager_id', 'type', 'objet', 'message', 'statut', 'envoyee_at',
        'decision_at', 'decision_par', 'jeton_decision',
        'date_souhaitee', 'urgente', 'relance_le', 'echeance_le', 'deadline',
    ];

    protected $hidden = ['jeton_decision'];

    protected function casts(): array
    {
        return [
            'envoyee_at' => 'datetime',
            'decision_at' => 'datetime',
            'date_souhaitee' => 'date',
            'relance_le' => 'date',
            'echeance_le' => 'date',
            'deadline' => 'date',
            'urgente' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Jeton des liens Valider / Refuser envoyés au manager
        static::creating(function (Demande $demande) {
            $demande->jeton_decision ??= (string) Str::uuid();

            // Sous PostgreSQL, le trigger automation.calculer_dates_demande() calcule les dates.
            // Ailleurs (tests SQLite), on applique la même règle en PHP.
            if (DB::connection()->getDriverName() !== 'pgsql') {
                $demande->calculerDates();
            }
        });
    }

    /** Relance, échéance, deadline et urgence (règles : docs/propositions-gestion-delais.md). */
    public function calculerDates(): void
    {
        // Toutes les comparaisons se font sur des dates « AAAA-MM-JJ » (heure de Paris)
        $delais = TypeDemande::delais($this->type);
        $base = Calendrier::date(($this->envoyee_at ?? $this->created_at ?? now())->copy()->timezone('Europe/Paris'));

        $this->relance_le ??= Calendrier::ajouterJoursOuvres($base, $delais['delai_relance']);
        $normale = Calendrier::ajouterJoursOuvres($base, $delais['delai_escalade']);
        $this->echeance_le ??= $normale;

        if ($this->date_souhaitee && $this->date_souhaitee->toDateString() <= $normale->toDateString()) {
            $this->urgente = true;
            $veille = Calendrier::jourOuvrePrecedent($this->date_souhaitee);
            $this->echeance_le = $veille->toDateString() < $base->toDateString() ? $base : $veille;
            if ($this->relance_le->toDateString() > $this->echeance_le->toDateString()) {
                $this->relance_le = $this->echeance_le;
            }
        }

        $this->deadline ??= $this->date_souhaitee
            ?? Calendrier::ajouterJoursOuvres($this->echeance_le, TypeDemande::MARGE_DEADLINE);
    }

    /** dans_les_temps | echeance_proche | en_retard, ou null si la demande n'est plus en attente. */
    public function getIndicateurDelaiAttribute(): ?string
    {
        if ($this->statut !== 'en_attente' || ! $this->echeance_le) {
            return null;
        }
        $aujourdhui = Calendrier::aujourdhui()->toDateString();
        $echeance = $this->echeance_le->toDateString();

        return match (true) {
            $aujourdhui > $echeance => 'en_retard',
            $aujourdhui >= Calendrier::jourOuvrePrecedent($this->echeance_le)->toDateString() => 'echeance_proche',
            default => 'dans_les_temps',
        };
    }

    /** Délai réel de traitement en jours ouvrés (null si pas encore décidée). */
    public function getDelaiTraitementAttribute(): ?int
    {
        if (! $this->decision_at) {
            return null;
        }

        return Calendrier::joursOuvresEntre(
            ($this->envoyee_at ?? $this->created_at)->copy()->timezone('Europe/Paris'),
            $this->decision_at->copy()->timezone('Europe/Paris'),
        );
    }

    public function getTraiteeDansLesTempsAttribute(): ?bool
    {
        return $this->decision_at && $this->echeance_le
            ? $this->decision_at->copy()->timezone('Europe/Paris')->toDateString() <= $this->echeance_le->toDateString()
            : null;
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
