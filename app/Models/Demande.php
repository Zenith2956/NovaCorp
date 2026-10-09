<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'a_completer' => 'À compléter',
        'annulee' => 'Annulée',
        'expiree' => 'Expirée',
        'en_traitement' => 'En traitement',
        'terminee' => 'Terminée',
    ];

    /** Types qui demandent un montant / des dates de congé (formulaire et circuits). */
    public const TYPES_AVEC_MONTANT = ['materiel', 'note_de_frais'];

    public const TYPES_AVEC_DATES = ['conge'];

    public const CANAUX = ['appli' => 'Application', 'mail' => 'Lien du mail', 'cron' => 'Automatique', 'systeme' => 'Système'];

    public const INDICATEURS = [
        'dans_les_temps' => 'Dans les temps',
        'echeance_proche' => 'Échéance proche',
        'en_retard' => 'En retard',
    ];

    protected $fillable = [
        'demandeur_id', 'manager_id', 'type', 'objet', 'message', 'statut', 'envoyee_at',
        'decision_at', 'decision_par', 'jeton_decision',
        'date_souhaitee', 'urgente', 'relance_le', 'echeance_le', 'deadline',
        'montant', 'date_debut', 'date_fin', 'nb_jours_ouvres', 'etape', 'commentaire_decision',
        'traite_par', 'traite_at', 'derniere_action_par', 'derniere_action_canal',
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
            'date_debut' => 'date',
            'date_fin' => 'date',
            'montant' => 'decimal:2',
            'traite_at' => 'datetime',
            'resume_ia_at' => 'datetime',
            'analyse_ia' => 'array',
            'etape' => 'integer',
            'nb_jours_ouvres' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Jeton des liens Valider / Refuser envoyés au manager
        static::creating(function (Demande $demande) {
            $demande->jeton_decision ??= (string) Str::uuid();

            // Sous PostgreSQL, le trigger automation.calculer_dates_demande() assigne le manager et calcule les dates.
            // Ailleurs (tests SQLite), on applique les mêmes règles en PHP.
            if (DB::connection()->getDriverName() !== 'pgsql') {
                $demande->etape ??= 1;
                $demande->manager_id ??= User::whereKey($demande->demandeur_id)->value('manager_id')
                    ?? User::whereHas('role', fn ($q) => $q->where('slug', 'direction'))->where('actif', true)->orderBy('id')->value('id');
                if ($demande->date_debut && $demande->date_fin) {
                    $demande->nb_jours_ouvres = Calendrier::joursOuvresEntre($demande->date_debut->copy()->subDay(), $demande->date_fin);
                }
                $demande->calculerDates();
            }
        });

        // Historique : rempli par le trigger automation.historiser() sous PostgreSQL, ici pour les autres bases
        static::created(fn (Demande $demande) => Historique::consigner($demande, 'demande', true));
        static::updated(fn (Demande $demande) => Historique::consigner($demande, 'demande'));
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

    /** Types que le service de l'utilisateur valide (étape d'un circuit) ou traite. */
    public static function typesDuService(User $user): array
    {
        $slug = $user->role?->slug;
        if (! $slug) {
            return [];
        }

        return EtapeCircuit::where('valideur', $slug)->pluck('type_code')
            ->merge(TypeDemande::where('role_traitement', $slug)->pluck('code'))
            ->unique()->values()->all();
    }

    /** Demandes visibles : les siennes, celles reçues comme manager, celles des types de son service ; tout pour RH / admin / direction. */
    public function scopeVisiblePar(Builder $q, User $user): Builder
    {
        if ($user->hasRole('rh', 'admin', 'direction')) {
            return $q;
        }

        return $q->where(fn ($q) => $q->where('demandeur_id', $user->id)
            ->orWhere('manager_id', $user->id)
            ->orWhere('manager_titulaire_id', $user->id)
            ->orWhereIn('type', self::typesDuService($user)));
    }

    /** Demandes qui attendent une action de l'utilisateur (décision à son étape, ou traitement par son service). */
    public function scopeATraiterPar(Builder $q, User $user): Builder
    {
        $slug = $user->role?->slug ?? '';
        $etape = fn ($valideur) => fn ($s) => $s->selectRaw('1')->from('etapes_circuit')
            ->whereColumn('etapes_circuit.type_code', 'demandes.type')
            ->whereColumn('etapes_circuit.ordre', 'demandes.etape')
            ->where('etapes_circuit.valideur', $valideur[0], $valideur[1]);

        return $q->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('statut', 'en_attente')->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('manager_id', $user->id)->whereNotExists($etape(['<>', 'manager'])))
                ->orWhere(fn ($q) => $q->where('demandeur_id', '<>', $user->id)->whereExists($etape(['=', $slug])))))
            ->orWhere(fn ($q) => $q->whereIn('statut', ['validee', 'en_traitement'])
                ->whereIn('type', TypeDemande::where('role_traitement', $slug)->pluck('code'))));
    }

    public function historique(): HasMany
    {
        return $this->hasMany(Historique::class, 'objet_id')->where('objet', 'demande')->orderBy('id');
    }

    public function traitePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'traite_par');
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

    /** A2 – Manager d'origine quand la demande a été confiée à son suppléant pendant son absence. */
    public function managerTitulaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_titulaire_id');
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
