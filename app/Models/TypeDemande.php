<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Délais (en jours ouvrés) par type de demande, réglables par les RH. */
class TypeDemande extends Model
{
    protected $table = 'types_demande';

    protected $fillable = ['code', 'libelle', 'delai_relance', 'delai_escalade'];

    /** Délais par défaut si le type n'est pas en base. */
    public const DEFAUT = ['delai_relance' => 2, 'delai_escalade' => 5];

    /** Jours ouvrés ajoutés à l'échéance pour obtenir la deadline (expiration). */
    public const MARGE_DEADLINE = 5;

    public static function delais(string $code): array
    {
        $type = static::where('code', $code)->first();

        return $type ? $type->only(['delai_relance', 'delai_escalade']) : self::DEFAUT;
    }
}
