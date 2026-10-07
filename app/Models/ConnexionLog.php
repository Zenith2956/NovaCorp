<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnexionLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email', 'evenement', 'ip_address', 'user_agent'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Enregistre un évènement d'authentification. */
    public static function enregistrer(string $evenement, ?User $user = null, ?string $email = null): self
    {
        return static::create([
            'user_id' => $user?->id,
            'email' => $email ?? $user?->email,
            'evenement' => $evenement,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 1000),
        ]);
    }
}
