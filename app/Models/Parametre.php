<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Réglages modifiables par la RH sans toucher au code (table « parametres »). */
class Parametre extends Model
{
    protected $primaryKey = 'cle';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;

    protected $fillable = ['cle', 'valeur', 'libelle'];

    public static function valeur(string $cle, ?string $defaut = null): ?string
    {
        return static::whereKey($cle)->value('valeur') ?? $defaut;
    }
}
