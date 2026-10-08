<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Étape d'un circuit de validation (type de demande, ordre, valideur, condition, délais). */
class EtapeCircuit extends Model
{
    protected $table = 'etapes_circuit';

    protected $fillable = ['type_code', 'ordre', 'libelle', 'valideur', 'condition_champ', 'condition_seuil', 'delai_relance', 'delai_escalade'];

    protected function casts(): array
    {
        return ['condition_seuil' => 'decimal:2', 'ordre' => 'integer', 'delai_relance' => 'integer', 'delai_escalade' => 'integer'];
    }

    /** L'étape s'applique-t-elle à cette demande ? (champ > seuil, ou pas de condition) */
    public function sApplique(Demande $demande): bool
    {
        if (! $this->condition_champ) {
            return true;
        }

        return (float) ($demande->{$this->condition_champ} ?? 0) > (float) $this->condition_seuil;
    }

    public function getDescriptionConditionAttribute(): ?string
    {
        return match ($this->condition_champ) {
            'montant' => 'si montant > '.number_format((float) $this->condition_seuil, 0, ',', ' ').' €',
            'nb_jours_ouvres' => 'si plus de '.(int) $this->condition_seuil.' jours ouvrés',
            default => null,
        };
    }
}
