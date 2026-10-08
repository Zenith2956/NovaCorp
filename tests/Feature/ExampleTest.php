<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_racine_redirige_vers_la_connexion(): void
    {
        $this->get('/')->assertRedirect('/tableau-de-bord');
        $this->get('/tableau-de-bord')->assertRedirect('/connexion');
    }

    public function test_bouton_retour(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $user = \App\Models\User::factory()->create(['role_id' => \App\Models\Role::where('slug', 'dev')->value('id')]);

        $this->actingAs($user)->get('/tableau-de-bord')->assertDontSee('← Retour');
        $this->actingAs($user)->get('/demandes')->assertSee('← Retour', false);
        $this->actingAs($user)->get('/demandes/nouvelle')->assertSee('href="'.route('demandes.index').'"', false);
    }
}
