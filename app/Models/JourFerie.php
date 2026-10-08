<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JourFerie extends Model
{
    protected $table = 'jours_feries';

    protected $primaryKey = 'jour';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['jour', 'libelle'];

    protected function casts(): array
    {
        return ['jour' => 'date'];
    }
}
