<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Résumé (D2) et tri automatique (D1) par IA : affichage sur la page de la demande. */
class AnalyseIaTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_pour_tous_points_d_attention_pour_le_valideur_seulement(): void
    {
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]);
        $employe = User::factory()->create(['role_id' => Role::where('slug', 'dev')->value('id'), 'manager_id' => $manager->id]);
        $d = Demande::create(['demandeur_id' => $employe->id, 'type' => 'materiel', 'objet' => 'Repas client', 'message' => 'Restaurant 86 €', 'statut' => 'en_attente', 'montant' => 86]);

        // Sans analyse : aucun encadré
        $this->actingAs($manager)->get("/demandes/{$d->id}")->assertOk()->assertDontSee('En bref');

        DB::table('demandes')->where('id', $d->id)->update([
            'resume_ia' => 'Repas avec un client pour 86 €.',
            'analyse_ia' => json_encode(['points_attention' => ['Aucun justificatif joint.'], 'urgence_suggeree' => true, 'type_suggere' => 'note_de_frais']),
            'resume_ia_at' => now(),
        ]);

        $this->actingAs($manager)->get("/demandes/{$d->id}")->assertOk()
            ->assertSee('Repas avec un client pour 86 €.')
            ->assertSee("Points d'attention", false)
            ->assertSee('Aucun justificatif joint.')
            ->assertSee('« Note de frais »')
            ->assertSee('Semble urgente');

        $this->actingAs($employe)->get("/demandes/{$d->id}")->assertOk()
            ->assertSee('Repas avec un client pour 86 €.')
            ->assertDontSee('Aucun justificatif joint.')
            ->assertDontSee('Semble urgente');
    }
}
