<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public const ROLES = [
        'admin' => 'Administrateur',
        'direction' => 'Direction',
        'manager' => 'Manager',
        'rh' => 'Ressources humaines',
        'dev' => 'Développeur',
        'commercial' => 'Commercial',
        'comptable' => 'Comptabilité',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $slug => $libelle) {
            Role::updateOrCreate(['slug' => $slug], ['libelle' => $libelle]);
        }
    }
}
