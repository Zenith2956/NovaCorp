<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\JourFerie;
use App\Models\Role;
use App\Models\TypeDemande;
use App\Models\User;
use App\Support\Calendrier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gestion des délais (étape 2). Sous SQLite, les dates sont calculées en PHP
 * avec les mêmes règles que le trigger PostgreSQL.
 */
class DelaiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        JourFerie::create(['jour' => '2026-11-11', 'libelle' => 'Armistice 1918']);
        Calendrier::oublier();
        $this->travelTo(now()->setTimezone('Europe/Paris')->setDate(2026, 11, 9)->setTime(10, 0)); // lundi 09/11/2026

        $this->manager = User::factory()->create(['role_id' => $this->role('manager')]);
        $this->employe = User::factory()->create(['role_id' => $this->role('dev'), 'manager_id' => $this->manager->id]);
    }

    private function role(string $slug): int
    {
        return Role::where('slug', $slug)->value('id');
    }

    private function demande(array $attributs = []): Demande
    {
        return Demande::create([
            'demandeur_id' => $this->employe->id, 'manager_id' => $this->manager->id,
            'type' => 'materiel', 'objet' => 'Écran', 'message' => 'Merci', 'statut' => 'en_attente',
            ...$attributs,
        ]);
    }

    public function test_dates_calculees_selon_le_type_et_les_jours_feries(): void
    {
        // Matériel : relance 1 j, échéance 3 j ; le 11/11 est férié
        $d = $this->demande();
        $this->assertSame('2026-11-10', $d->relance_le->toDateString());
        $this->assertSame('2026-11-13', $d->echeance_le->toDateString());
        $this->assertSame('2026-11-20', $d->deadline->toDateString()); // échéance + 5 j ouvrés
        $this->assertFalse((bool) $d->urgente);
    }

    public function test_date_souhaitee_proche_rend_la_demande_urgente(): void
    {
        // Congé : échéance normale le 17/11 ; date souhaitée le 12/11 -> urgente, échéance la veille ouvrée (10/11)
        $d = $this->demande(['type' => 'conge', 'date_souhaitee' => '2026-11-12']);
        $this->assertTrue($d->urgente);
        $this->assertSame('2026-11-10', $d->echeance_le->toDateString());
        $this->assertSame('2026-11-10', $d->relance_le->toDateString());
        $this->assertSame('2026-11-12', $d->deadline->toDateString());
    }

    public function test_indicateur_de_delai(): void
    {
        $d = $this->demande(); // échéance 13/11
        $this->assertSame('dans_les_temps', $d->indicateur_delai);

        $this->travelTo(now()->setDate(2026, 11, 12));
        $this->assertSame('echeance_proche', $d->fresh()->indicateur_delai);

        $this->travelTo(now()->setDate(2026, 11, 16));
        $this->assertSame('en_retard', $d->fresh()->indicateur_delai);

        $this->actingAs($this->manager)->get('/demandes?retard=1')->assertOk()->assertSee('En retard');
    }

    public function test_formulaire_refuse_une_date_souhaitee_passee(): void
    {
        $this->actingAs($this->employe)->post('/demandes', [
            'type' => 'conge', 'objet' => 'x', 'message' => 'x', 'manager_id' => $this->manager->id,
            'date_souhaitee' => '2026-11-01',
        ])->assertSessionHasErrors('date_souhaitee');
    }

    public function test_refaire_une_demande_expiree(): void
    {
        $d = $this->demande(['objet' => 'Clavier ergonomique']);
        $d->update(['statut' => 'expiree', 'jeton_decision' => null]);

        $this->actingAs($this->employe)->get("/demandes/{$d->id}")->assertSee('Refaire la demande');
        $this->actingAs($this->employe)->get("/demandes/nouvelle?refaire={$d->id}")
            ->assertOk()->assertSee('Refaire la demande #'.$d->id)->assertSee('Clavier ergonomique');

        // Le statut « Expirée » ne peut pas être choisi à la main
        $this->actingAs($this->manager)->patch("/demandes/{$this->demande()->id}/statut", ['statut' => 'expiree'])
            ->assertSessionHasErrors('statut');
    }

    public function test_page_delais_et_reglage_par_les_rh(): void
    {
        $this->demande();
        $this->actingAs($this->manager)->get('/delais')->assertOk()->assertSee('Délais de traitement');
        $this->actingAs($this->employe)->get('/delais')->assertForbidden();

        $rh = User::factory()->create(['role_id' => $this->role('rh')]);
        $type = TypeDemande::where('code', 'materiel')->first();
        $this->actingAs($rh)->put('/delais/types', [
            'types' => [$type->id => ['delai_relance' => 2, 'delai_escalade' => 4]],
        ])->assertRedirect();
        $this->assertSame(4, $type->fresh()->delai_escalade);

        // Le manager ne peut pas modifier les délais
        $this->actingAs($this->manager)->put('/delais/types', [
            'types' => [$type->id => ['delai_relance' => 1, 'delai_escalade' => 1]],
        ])->assertForbidden();
    }
}
