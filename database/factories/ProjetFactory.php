<?php

namespace Database\Factories;

use App\Models\Projet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Projet>
 */
class ProjetFactory extends Factory
{
    public function definition(): array
    {
        $debut = fake()->dateTimeBetween('-1 year', '+2 months');

        return [
            'nom' => 'Projet '.ucfirst(fake()->unique()->word()),
            'description' => fake()->sentence(12),
            'statut' => fake()->randomElement(['a_venir', 'en_cours', 'en_cours', 'termine']),
            'budget' => fake()->numberBetween(10, 500) * 1000,
            'date_debut' => $debut,
            'date_fin' => fake()->dateTimeBetween($debut, '+1 year'),
        ];
    }
}
