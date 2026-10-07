<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PieceJointe extends Model
{
    protected $table = 'pieces_jointes';

    protected $fillable = ['demande_id', 'nom_original', 'chemin', 'mime_type', 'categorie', 'taille'];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class);
    }

    /** Déduit la catégorie (document, audio, image, video) à partir du type MIME. */
    public static function categorieDepuisMime(?string $mime): string
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => 'image',
            str_starts_with((string) $mime, 'audio/') => 'audio',
            str_starts_with((string) $mime, 'video/') => 'video',
            $mime !== null => 'document',
            default => 'autre',
        };
    }
}
