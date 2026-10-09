<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\Parametre;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A2 (choix du suppléant, visibilité du manager titulaire) et A3 (seuil réglable). La délégation elle-même est en SQL (testée dans Supabase). */
class AbsencesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employe;

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $role = fn (string $slug) => Role::where('slug', $slug)->value('id');
        $this->manager = User::factory()->create(['role_id' => $role('manager')]);
        $this->employe = User::factory()->create(['role_id' => $role('dev'), 'manager_id' => $this->manager->id]);
        $this->rh = User::factory()->create(['role_id' => $role('rh')]);
    }

    public function test_le_manager_choisit_son_suppleant(): void
    {
        $this->actingAs($this->manager)->get('/tableau-de-bord')->assertSee('Mon suppléant pendant mes congés');
        $this->actingAs($this->employe)->get('/tableau-de-bord')->assertDontSee('Mon suppléant pendant mes congés');

        $this->actingAs($this->manager)->put('/mon-suppleant', ['suppleant_id' => $this->rh->id])->assertSessionHasNoErrors();
        $this->assertSame($this->rh->id, $this->manager->fresh()->suppleant_id);

        $this->actingAs($this->manager)->put('/mon-suppleant', ['suppleant_id' => $this->manager->id])->assertSessionHasErrors('suppleant_id');
        $this->actingAs($this->manager)->put('/mon-suppleant', ['suppleant_id' => null]);
        $this->assertNull($this->manager->fresh()->suppleant_id);

        // Un employé sans équipe n'a pas de suppléant
        $this->actingAs($this->employe)->put('/mon-suppleant', ['suppleant_id' => $this->rh->id])->assertForbidden();
    }

    public function test_la_rh_choisit_pour_un_manager(): void
    {
        $this->actingAs($this->rh)->get('/employes')->assertSee('Suppléant (congés)');
        $this->actingAs($this->rh)->put("/employes/{$this->manager->id}/suppleant", ['suppleant_id' => $this->employe->id])->assertSessionHasNoErrors();
        $this->assertSame($this->employe->id, $this->manager->fresh()->suppleant_id);
        $this->actingAs($this->manager)->put("/employes/{$this->manager->id}/suppleant", ['suppleant_id' => null])->assertForbidden();
    }

    public function test_le_manager_titulaire_garde_la_vue_sur_la_demande_confiee(): void
    {
        $suppleant = User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]);
        $d = Demande::create(['demandeur_id' => $this->employe->id, 'type' => 'materiel', 'objet' => 'Écran', 'message' => 'x', 'statut' => 'en_attente']);
        $d->forceFill(['manager_id' => $suppleant->id, 'manager_titulaire_id' => $this->manager->id])->save();

        $this->actingAs($this->manager)->get("/demandes/{$d->id}")->assertOk()->assertSee('suppléant(e) de', false);
        $this->actingAs($suppleant)->get("/demandes/{$d->id}")->assertOk()->assertSee('Valider');
    }

    public function test_seuil_absences_reglable_par_la_rh(): void
    {
        $this->actingAs($this->rh)->get('/delais')->assertSee('équipe trop absente', false);
        $this->actingAs($this->rh)->put('/delais/parametres', ['seuil_absences_equipe_pct' => 40])->assertSessionHasNoErrors();
        $this->assertSame('40', Parametre::valeur('seuil_absences_equipe_pct'));
        $this->actingAs($this->rh)->put('/delais/parametres', ['seuil_absences_equipe_pct' => 5])->assertSessionHasErrors();
        $this->actingAs($this->manager)->put('/delais/parametres', ['seuil_absences_equipe_pct' => 60])->assertForbidden();
    }
}
