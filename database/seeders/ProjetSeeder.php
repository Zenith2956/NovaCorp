<?php

namespace Database\Seeders;

use App\Models\ChiffreAffaire;
use App\Models\Projet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjetSeeder extends Seeder
{
    public function run(): void
    {
        $managerRole = Role::where('slug', 'manager')->value('id');
        $managers = User::where('role_id', $managerRole)->pluck('id');
        $employes = User::whereNotIn('role_id', Role::whereIn('slug', ['admin', 'direction'])->pluck('id'))->pluck('id');

        $projets = Projet::factory(12)->create()->each(function (Projet $projet) use ($managers, $employes) {
            $projet->update(['chef_projet_id' => $managers->random()]);
            $projet->membres()->attach($employes->random(rand(4, 12))->all());
        });

        // Chiffre d'affaires mensuel sur les 12 derniers mois, réparti par projet
        for ($i = 11; $i >= 0; $i--) {
            $periode = now()->startOfMonth()->subMonths($i)->toDateString();
            foreach ($projets->random(4) as $projet) {
                ChiffreAffaire::create([
                    'periode' => $periode,
                    'montant' => rand(15_000, 120_000),
                    'projet_id' => $projet->id,
                ]);
            }
        }
    }
}
