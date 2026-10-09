<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\JourFerie;
use App\Models\Role;
use App\Models\User;
use App\Support\Calendrier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Page « Statistiques » du back-office (mêmes chiffres que automation.calculer_stats côté Supabase). */
class StatistiqueTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $autreManager;

    private User $employe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        JourFerie::create(['jour' => '2026-11-11', 'libelle' => 'Armistice 1918']);
        Calendrier::oublier();
        $this->travelTo(now()->setTimezone('Europe/Paris')->setDate(2026, 11, 9)->setTime(10, 0)); // lundi 09/11/2026

        $this->manager = User::factory()->create(['role_id' => $this->role('manager')]);
        $this->autreManager = User::factory()->create(['role_id' => $this->role('manager')]);
        $this->employe = User::factory()->create(['role_id' => $this->role('dev'), 'manager_id' => $this->manager->id]);
    }

    private function role(string $slug): int
    {
        return Role::where('slug', $slug)->value('id');
    }

    public function test_acces_selon_le_role(): void
    {
        $this->actingAs($this->employe)->get('/statistiques')->assertForbidden();
        $this->actingAs($this->manager)->get('/statistiques')->assertOk()->assertSee('Votre équipe')->assertDontSee('Toute l\'entreprise</option>', false);
        $rh = User::factory()->create(['role_id' => $this->role('rh')]);
        $this->actingAs($rh)->get('/statistiques')->assertOk()->assertSee($this->manager->nom_complet);
        $this->actingAs($rh)->getJson('/statistiques/donnees?jours=12')->assertStatus(422);
    }

    public function test_chiffres_temps_moyen_volume_et_perimetre(): void
    {
        // Envoyée le lundi 02/11, validée le lundi 09/11 : 5 jours ouvrés, après l'échéance du 06/11
        $d = Demande::create(['demandeur_id' => $this->employe->id, 'type' => 'materiel', 'objet' => 'Écran', 'message' => 'x',
            'statut' => 'en_attente', 'montant' => 200, 'envoyee_at' => '2026-11-02 09:00', 'created_at' => '2026-11-02 09:00']);
        $d->update(['echeance_le' => '2026-11-06']);
        $d->update(['statut' => 'validee', 'decision_at' => '2026-11-09 09:00', 'decision_par' => $this->manager->id]);
        Demande::create(['demandeur_id' => $this->employe->id, 'type' => 'conge', 'objet' => 'Congé', 'message' => 'x', 'statut' => 'en_attente']);

        $s = $this->actingAs($this->manager)->getJson('/statistiques/donnees?jours=30')->assertOk()->json();
        $this->assertSame('equipe', $s['perimetre']);
        $this->assertSame(2, $s['volume']['creees']);
        $this->assertSame(['materiel' => 1, 'conge' => 1], $s['volume']['par_type']);
        $this->assertSame(1, $s['decisions']['total']);
        $this->assertEquals(5, $s['decisions']['temps_moyen_jours_ouvres']);
        $this->assertEquals(0, $s['decisions']['dans_les_temps_pct']);
        $this->assertSame(1, $s['en_cours']['en_attente']);
        $this->assertSame(1, $s['en_cours']['a_traiter']);           // matériel validé → service admin
        $this->assertCount(30, $s['par_jour']);
        $this->assertSame('Managers', $s['services'][0]['libelle']);

        // Un autre manager ne voit rien de cette équipe
        $autre = $this->actingAs($this->autreManager)->getJson('/statistiques/donnees?jours=30')->json();
        $this->assertSame(0, $autre['volume']['creees']);

        // La RH voit l'entreprise, ou l'équipe d'un manager choisi
        $rh = User::factory()->create(['role_id' => $this->role('rh')]);
        $this->assertSame('entreprise', $this->actingAs($rh)->getJson('/statistiques/donnees')->json('perimetre'));
        $this->assertSame(0, $this->actingAs($rh)->getJson('/statistiques/donnees?manager='.$this->autreManager->id)->json('volume.creees'));
    }
}
