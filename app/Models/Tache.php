<?php

namespace App\Models;

use App\Support\Calendrier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tâche : dans un projet (deadline fixée par le chef de projet, ≥ création + 5 jours ouvrés,
 * reports d'au moins 1 jour) ou hors projet (deadline libre choisie par l'employé ;
 * chaque modification prévient son manager). Règles : docs/propositions-gestion-delais.md
 *
 * Sous PostgreSQL, les règles sont aussi appliquées par le trigger automation.controler_tache()
 * et les mails par automation.mail_tache() ; ici on valide en amont pour afficher des messages clairs.
 */
class Tache extends Model
{
    public const STATUTS = [
        'a_faire' => 'À faire',
        'en_cours' => 'En cours',
        'terminee' => 'Terminée',
        'expiree' => 'Expirée',
    ];

    /** Statuts que le responsable peut choisir (l'expiration est automatique). */
    public const STATUTS_MANUELS = ['a_faire', 'en_cours', 'terminee'];

    /** Jours ouvrés minimum entre la création et la première deadline d'une tâche de projet. */
    public const DELAI_MINIMUM_PROJET = 5;

    protected $fillable = [
        'titre', 'description', 'projet_id', 'responsable_id', 'cree_par', 'statut', 'deadline',
        'deadline_initiale', 'nb_reports', 'deadline_fixee_par', 'deadline_fixee_at', 'jeton_deadline', 'termine_at',
    ];

    protected $hidden = ['jeton_deadline'];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'deadline_initiale' => 'date',
            'deadline_fixee_at' => 'datetime',
            'termine_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Même tenue des champs que le trigger PostgreSQL, pour les autres bases (tests SQLite)
        static::saving(function (Tache $t) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                return;
            }
            if (! $t->exists) {
                $t->deadline_initiale = $t->deadline;
                if ($t->projet_id && ! $t->deadline) {
                    $t->jeton_deadline ??= (string) Str::uuid();
                } else {
                    $t->deadline_fixee_at ??= now();
                }
            } elseif ($t->isDirty('deadline')) {
                $ancienne = $t->getOriginal('deadline');
                if (! $ancienne) {
                    $t->deadline_initiale = $t->deadline;
                    $t->deadline_fixee_at = now();
                    $t->jeton_deadline = null;
                } else {
                    if ($t->deadline->toDateString() > CarbonImmutable::parse($ancienne)->toDateString()) {
                        $t->nb_reports++;
                    }
                    if ($t->statut === 'expiree' && $t->getOriginal('statut') === 'expiree') {
                        $t->statut = 'a_faire';
                    }
                }
            }
            if ($t->statut === 'terminee' && $t->getOriginal('statut') !== 'terminee') {
                $t->termine_at = now();
            }
        });
    }

    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function getStatutLibelleAttribute(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function chefProjetId(): ?int
    {
        return $this->projet?->chef_projet_id;
    }

    /** Date la plus tôt autorisée pour la prochaine deadline (null = aucune contrainte autre que « pas dans le passé »). */
    public function deadlineMinimum(): CarbonImmutable
    {
        $aujourdhui = Calendrier::aujourdhui();

        if (! $this->projet_id) {
            return $aujourdhui;                                           // hors projet : libre
        }
        if (! $this->deadline) {                                          // première deadline : création + 5 j ouvrés
            $creation = $this->created_at ? Calendrier::date($this->created_at->copy()->timezone('Europe/Paris')) : $aujourdhui;

            return Calendrier::ajouterJoursOuvres($creation, self::DELAI_MINIMUM_PROJET);
        }
        $report = Calendrier::date($this->deadline)->addDay();            // report : au moins 1 jour

        return $report->lt($aujourdhui) ? $aujourdhui : $report;
    }

    /** Indicateur : null si terminée/expirée ou sans deadline. */
    public function getIndicateurDelaiAttribute(): ?string
    {
        if (! in_array($this->statut, ['a_faire', 'en_cours'], true) || ! $this->deadline) {
            return null;
        }
        $aujourdhui = Calendrier::aujourdhui()->toDateString();
        $deadline = $this->deadline->toDateString();

        return match (true) {
            $aujourdhui > $deadline => 'en_retard',
            $aujourdhui >= Calendrier::jourOuvrePrecedent($this->deadline)->toDateString() => 'echeance_proche',
            default => 'dans_les_temps',
        };
    }

    /** Qui peut voir la tâche. */
    public function visiblePar(User $user): bool
    {
        return $user->hasRole('rh', 'direction', 'admin')
            || $user->id === $this->responsable_id
            || $user->id === $this->cree_par
            || ($this->projet && ($user->id === $this->projet->chef_projet_id
                || $this->projet->membres()->whereKey($user->id)->exists()));
    }

    /** Qui peut changer la deadline : chef de projet (ou admin) pour un projet, le responsable hors projet. */
    public function deadlineModifiablePar(User $user): bool
    {
        return $this->projet_id
            ? $user->id === $this->chefProjetId() || $user->hasRole('admin')
            : $user->id === $this->responsable_id;
    }
}
