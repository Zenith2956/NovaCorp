<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crée les 120 employés de NovaCorp.
 * Mot de passe de tous les comptes de démo : "password".
 */
class EmployeSeeder extends Seeder
{
    /** Répartition des 120 employés par rôle. */
    private const REPARTITION = [
        'admin' => 1,
        'direction' => 3,
        'manager' => 10,
        'rh' => 8,
        'dev' => 60,
        'commercial' => 25,
        'comptable' => 13,
    ];

    public function run(): void
    {
        $roles = Role::pluck('id', 'slug');

        // Compte administrateur fixe pour se connecter facilement
        User::factory()->create([
            'prenom' => 'Admin',
            'nom' => 'NovaCorp',
            'email' => 'admin@novacorp.fr',
            'role_id' => $roles['admin'],
        ]);

        $direction = User::factory(self::REPARTITION['direction'])->create(['role_id' => $roles['direction']]);

        // Les managers rapportent à la direction
        $managers = User::factory(self::REPARTITION['manager'])
            ->create(['role_id' => $roles['manager']])
            ->each(fn (User $m) => $m->update(['manager_id' => $direction->random()->id]));

        // Compte manager fixe pour tester la réception des demandes
        $managers->first()->update(['email' => 'manager@novacorp.fr']);

        foreach (['rh', 'dev', 'commercial', 'comptable'] as $slug) {
            User::factory(self::REPARTITION[$slug])->create([
                'role_id' => $roles[$slug],
            ])->each(fn (User $u) => $u->update(['manager_id' => $managers->random()->id]));
        }

        // Compte employé fixe
        User::where('role_id', $roles['dev'])->first()->update(['email' => 'employe@novacorp.fr']);
    }
}
