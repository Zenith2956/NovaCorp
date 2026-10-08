<?php

namespace Tests\Feature;

use App\Models\JourFerie;
use App\Models\Projet;
use App\Models\Role;
use App\Models\User;
use App\Support\Calendrier;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** S4 : jour de réunion d'un projet (récapitulatif envoyé ce jour-là à 8 h, voir automation.est_jour_reunion). */
class ProjetReunionTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        JourFerie::create(['jour' => '2026-11-11', 'libelle' => 'Armistice 1918']);
        Calendrier::oublier();
        $this->travelTo(now()->setTimezone('Europe/Paris')->setDate(2026, 11, 9)->setTime(10, 0)); // lundi 09/11/2026
        $this->chef = User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]);
    }

    private function projet(array $reunion): Projet
    {
        return Projet::create(['nom' => 'P', 'statut' => 'en_cours', 'chef_projet_id' => $this->chef->id] + $reunion);
    }

    private function jour(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Europe/Paris');
    }

    public function test_regles_des_jours_de_reunion(): void
    {
        $hebdo = $this->projet(['reunion_frequence' => 'hebdomadaire', 'reunion_jour' => 3]);   // mercredi
        $this->assertFalse($hebdo->estJourDeReunion($this->jour('2026-11-11')));               // férié : pas de réunion
        $this->assertTrue($hebdo->estJourDeReunion($this->jour('2026-11-18')));
        $this->assertFalse($hebdo->estJourDeReunion($this->jour('2026-11-17')));
        $this->assertSame('2026-11-18', $hebdo->prochaineReunion()->toDateString());
        $this->assertSame('Chaque mercredi', $hebdo->description_reunion);

        $deux = $this->projet(['reunion_frequence' => 'bimensuelle', 'reunion_jour' => 1, 'reunion_depuis' => '2026-11-02']);
        $this->assertTrue($deux->estJourDeReunion($this->jour('2026-11-16')));
        $this->assertFalse($deux->estJourDeReunion($this->jour('2026-11-09')));
        $this->assertFalse($deux->estJourDeReunion($this->jour('2026-10-19')));                // avant la première réunion
        $this->assertSame('2026-11-16', $deux->prochaineReunion()->toDateString());

        $mois = $this->projet(['reunion_frequence' => 'mensuelle', 'reunion_jour' => 2]);       // 1er mardi du mois
        $this->assertTrue($mois->estJourDeReunion($this->jour('2026-12-01')));
        $this->assertFalse($mois->estJourDeReunion($this->jour('2026-12-08')));
        $this->assertSame('2026-12-01', $mois->prochaineReunion()->toDateString());

        $this->assertNull($this->projet([])->prochaineReunion());
    }

    public function test_le_chef_choisit_la_reunion(): void
    {
        $projet = $this->projet([]);
        $base = ['nom' => 'P', 'statut' => 'en_cours'];

        $this->actingAs($this->chef)->put("/projets/{$projet->id}", $base + ['reunion_frequence' => 'bimensuelle'])
            ->assertSessionHasErrors('reunion_depuis');
        $this->actingAs($this->chef)->put("/projets/{$projet->id}", $base + ['reunion_frequence' => 'bimensuelle', 'reunion_depuis' => '2026-11-14'])
            ->assertSessionHasErrors('reunion_depuis');                                         // samedi refusé

        $this->actingAs($this->chef)->put("/projets/{$projet->id}", $base + ['reunion_frequence' => 'bimensuelle', 'reunion_depuis' => '2026-11-19'])
            ->assertRedirect();
        $projet->refresh();
        $this->assertSame([4, '2026-11-19'], [$projet->reunion_jour, $projet->reunion_depuis->toDateString()]);  // jeudi déduit
        $this->actingAs($this->chef)->get("/projets/{$projet->id}")->assertSee('Un jeudi sur deux')->assertSee('19/11/2026 à 8 h');

        $this->actingAs($this->chef)->put("/projets/{$projet->id}", $base + ['reunion_frequence' => '', 'reunion_jour' => 2]);
        $this->assertNull($projet->fresh()->reunion_jour);
    }
}
