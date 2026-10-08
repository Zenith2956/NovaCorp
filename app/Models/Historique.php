<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** Journal des changements de statut / d'étape (rempli par trigger PostgreSQL). */
class Historique extends Model
{
    protected $table = 'historique';

    public const UPDATED_AT = null;

    protected $casts = ['created_at' => 'datetime'];

    protected $fillable = ['objet', 'objet_id', 'ancien_statut', 'nouveau_statut', 'etape', 'auteur_id', 'canal', 'commentaire'];

    /** Équivalent PHP du trigger automation.historiser() pour les bases autres que PostgreSQL (tests). */
    public static function consigner(Model $m, string $objet, bool $creation = false): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return;
        }
        $nouveau = $creation;
        if (! $nouveau && ! $m->wasChanged('statut') && ! ($objet === 'demande' && $m->wasChanged('etape'))) {
            return;
        }
        $ancien = $nouveau ? null : $m->getOriginal('statut');
        $commentaire = $objet === 'demande'
            ? (in_array($m->statut, ['refusee', 'a_completer'], true) ? $m->commentaire_decision : null)
            : ($ancien === 'a_valider' && $m->statut === 'en_cours' ? $m->commentaire_validation : null);

        static::create([
            'objet' => $objet, 'objet_id' => $m->id, 'ancien_statut' => $ancien, 'nouveau_statut' => $m->statut,
            'etape' => $objet === 'demande' ? $m->etape : null, 'auteur_id' => $m->derniere_action_par,
            'canal' => $m->derniere_action_canal ?? 'systeme', 'commentaire' => $commentaire,
        ]);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
