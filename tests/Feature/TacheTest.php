<?php

namespace Tests\Feature;

use App\Models\JourFerie;
use App\Models\Projet;
use App\Models\Role;
use App\Models\Tache;
use App\Models\User;
use App\Support\Calendrier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Projets et tâches (délais, étape 3). Aujourd'hui simulé : lundi 09/11/2026, le 11/11 est férié.
 * Création + 5 jours ouvrés = mardi 17/11 (10, 12, 13, 16, 17 : le 11 est sauté).
 */
class TacheTest extends TestCase
{
    use RefreshDatabase;

    private User $chef;

    private User $membre;

    private User $exterieur;

    private Projet $projet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        JourFerie::create(['jour' => '2026-11-11', 'libelle' => 'Armistice 1918']);
        Calendrier::oublier();
        $this->travelTo(now()->setTimezone('Europe/Paris')->setDate(2026, 11, 9)->setTime(10, 0));

        $manager = Role::where('slug', 'manager')->value('id');
        $dev = Role::where('slug', 'dev')->value('id');
        $this->chef = User::factory()->create(['role_id' => $manager]);
        $this->membre = User::factory()->create(['role_id' => $dev, 'manager_id' => $this->chef->id]);
        $this->exterieur = User::factory()->create(['role_id' => $dev, 'manager_id' => $this->chef->id]);
        $this->projet = Projet::create(['nom' => 'Projet Test', 'statut' => 'en_cours', 'chef_projet_id' => $this->chef->id]);
        $this->projet->membres()->attach($this->membre->id);
    }

    public function test_tache_hors_projet_deadline_libre_mais_pas_dans_le_passe(): void
    {
        $this->actingAs($this->membre)->post('/taches', ['titre' => 'Ranger', 'deadline' => '2026-11-06'])
            ->assertSessionHasErrors('deadline');

        $this->actingAs($this->membre)->post('/taches', ['titre' => 'Ranger', 'deadline' => '2026-11-10'])->assertRedirect();
        $t = Tache::firstWhere('titre', 'Ranger');
        $this->assertNull($t->projet_id);
        $this->assertSame('2026-11-10', $t->deadline->toDateString());
        $this->assertSame('2026-11-10', $t->deadline_initiale->toDateString());

        // L'employé peut la modifier (même plus tôt) ; son manager est prévenu (trigger PostgreSQL)
        $this->actingAs($this->membre)->patch("/taches/{$t->id}/deadline", ['deadline' => '2026-11-09'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'manager'));
        $this->assertSame('2026-11-09', $t->fresh()->deadline->toDateString());
    }

    public function test_un_membre_cree_une_tache_le_chef_fixe_la_deadline_par_le_lien(): void
    {
        $this->actingAs($this->exterieur)->post('/taches', ['titre' => 'X', 'projet_id' => $this->projet->id])->assertForbidden();

        // Un membre ne fixe pas lui-même la deadline
        $this->actingAs($this->membre)->post('/taches', ['titre' => 'Maquette', 'projet_id' => $this->projet->id, 'deadline' => '2026-12-01'])
            ->assertSessionHasErrors('deadline');
        $this->actingAs($this->membre)->post('/taches', ['titre' => 'Maquette', 'projet_id' => $this->projet->id])->assertRedirect();

        $t = Tache::firstWhere('titre', 'Maquette');
        $this->assertNull($t->deadline);
        $this->assertSame($this->membre->id, $t->responsable_id);
        $jeton = $t->jeton_deadline;
        $this->assertNotNull($jeton);

        // Lien du mail, sans connexion
        $this->get("/taches/{$t->id}/fixer-deadline/{$jeton}")->assertOk()->assertSee('17/11/2026');
        $this->post("/taches/{$t->id}/fixer-deadline/{$jeton}", ['deadline' => '2026-11-16'])
            ->assertSessionHasErrors('deadline'); // < création + 5 jours ouvrés
        $this->post("/taches/{$t->id}/fixer-deadline/{$jeton}", ['deadline' => '2026-11-17'])->assertOk()->assertSee('Deadline enregistrée');

        $t->refresh();
        $this->assertSame('2026-11-17', $t->deadline->toDateString());
        $this->assertNull($t->jeton_deadline);
        $this->post("/taches/{$t->id}/fixer-deadline/{$jeton}", ['deadline' => '2026-11-20'])->assertForbidden(); // usage unique
    }

    public function test_report_d_une_deadline_de_projet_au_moins_un_jour(): void
    {
        $t = Tache::create(['titre' => 'API', 'projet_id' => $this->projet->id, 'responsable_id' => $this->membre->id,
            'cree_par' => $this->chef->id, 'statut' => 'a_faire', 'deadline' => '2026-11-16']);

        // Seul le chef de projet peut la changer
        $this->actingAs($this->membre)->patch("/taches/{$t->id}/deadline", ['deadline' => '2026-11-20'])->assertForbidden();

        $this->actingAs($this->chef)->patch("/taches/{$t->id}/deadline", ['deadline' => '2026-11-16'])
            ->assertSessionHasErrors('deadline'); // pas un report
        $this->actingAs($this->chef)->patch("/taches/{$t->id}/deadline", ['deadline' => '2026-11-17'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'repoussée'));

        $t->refresh();
        $this->assertSame('2026-11-17', $t->deadline->toDateString());
        $this->assertSame('2026-11-16', $t->deadline_initiale->toDateString());
        $this->assertSame(1, $t->nb_reports);
    }

    public function test_le_chef_assigne_une_tache_avec_deadline_minimum_5_jours_ouvres(): void
    {
        $this->actingAs($this->chef)->post('/taches', ['titre' => 'Tests', 'projet_id' => $this->projet->id,
            'responsable_id' => $this->membre->id, 'deadline' => '2026-11-16'])->assertSessionHasErrors('deadline');
        $this->actingAs($this->chef)->post('/taches', ['titre' => 'Tests', 'projet_id' => $this->projet->id,
            'responsable_id' => $this->exterieur->id, 'deadline' => '2026-11-17'])->assertSessionHasErrors('responsable_id'); // pas membre
        $this->actingAs($this->chef)->post('/taches', ['titre' => 'Tests', 'projet_id' => $this->projet->id,
            'responsable_id' => $this->membre->id, 'deadline' => '2026-11-17'])->assertRedirect();

        $t = Tache::firstWhere('titre', 'Tests');
        $this->assertSame($this->membre->id, $t->responsable_id);
        $this->assertSame($this->chef->id, $t->deadline_fixee_par);
        $this->actingAs($this->membre)->get('/taches')->assertSee('Tests');
    }

    public function test_avancement_et_membres(): void
    {
        $t = Tache::create(['titre' => 'Doc', 'responsable_id' => $this->membre->id, 'cree_par' => $this->membre->id,
            'statut' => 'a_faire', 'deadline' => '2026-11-20']);
        $this->actingAs($this->membre)->patch("/taches/{$t->id}/statut", ['statut' => 'expiree'])->assertSessionHasErrors('statut');
        $this->actingAs($this->membre)->patch("/taches/{$t->id}/statut", ['statut' => 'terminee']);
        $this->assertNotNull($t->fresh()->termine_at);
        $this->actingAs($this->exterieur)->get("/taches/{$t->id}")->assertForbidden();

        // Gestion des membres : chef oui, membre non
        $this->actingAs($this->membre)->post("/projets/{$this->projet->id}/membres", ['user_id' => $this->exterieur->id])->assertForbidden();
        $this->actingAs($this->chef)->post("/projets/{$this->projet->id}/membres", ['user_id' => $this->exterieur->id])->assertRedirect();
        $this->assertTrue($this->projet->aPourMembre($this->exterieur));
        $this->actingAs($this->chef)->get("/projets/{$this->projet->id}")->assertOk()->assertSee($this->exterieur->nom);
        $this->actingAs($this->chef)->delete("/projets/{$this->projet->id}/membres/{$this->exterieur->id}")->assertRedirect();
        $this->assertFalse($this->projet->aPourMembre($this->exterieur));

        // Plusieurs membres d'un coup (sélecteur avec recherche)
        $autres = User::factory(3)->create(['role_id' => Role::where('slug', 'dev')->value('id')]);
        $this->actingAs($this->chef)->post("/projets/{$this->projet->id}/membres", ['user_ids' => $autres->pluck('id')->all()])
            ->assertSessionHas('success', '3 membres ajoutés au projet.');
        $this->actingAs($this->chef)->post("/projets/{$this->projet->id}/membres", [])->assertSessionHasErrors('user_ids');
        $this->actingAs($this->chef)->get("/projets/{$this->projet->id}")->assertSee('Rechercher : nom, prénom, e-mail');
    }

    public function test_creation_et_modification_de_projet(): void
    {
        // Un développeur ne peut pas créer de projet
        $this->actingAs($this->membre)->get('/projets/nouveau')->assertForbidden();

        // Un manager crée un projet : il en devient le chef
        $this->actingAs($this->chef)->get('/projets')->assertSee('Nouveau projet');
        $this->actingAs($this->chef)->post('/projets', [
            'nom' => 'Refonte intranet', 'statut' => 'a_venir', 'budget' => 15000,
            'date_debut' => '2026-11-16', 'date_fin' => '2026-12-18',
            'membres' => [$this->membre->id, $this->exterieur->id],
        ])->assertRedirect();
        $projet = Projet::firstWhere('nom', 'Refonte intranet');
        $this->assertSame($this->chef->id, $projet->chef_projet_id);
        $this->assertTrue($projet->aPourMembre($this->exterieur));

        // Dates incohérentes refusées
        $this->actingAs($this->chef)->put("/projets/{$projet->id}", ['nom' => 'X', 'statut' => 'en_cours',
            'date_debut' => '2026-12-01', 'date_fin' => '2026-11-01'])->assertSessionHasErrors('date_fin');
        $this->actingAs($this->chef)->put("/projets/{$projet->id}", ['nom' => 'Refonte intranet v2', 'statut' => 'en_cours'])->assertRedirect();
        $this->assertSame('Refonte intranet v2', $projet->fresh()->nom);
        $this->actingAs($this->membre)->get("/projets/{$projet->id}/modifier")->assertForbidden();

        // La direction choisit le chef de projet
        $direction = User::factory()->create(['role_id' => Role::where('slug', 'direction')->value('id')]);
        $this->actingAs($direction)->post('/projets', ['nom' => 'Audit', 'statut' => 'en_cours'])->assertSessionHasErrors('chef_projet_id');
        $this->actingAs($direction)->post('/projets', ['nom' => 'Audit', 'statut' => 'en_cours', 'chef_projet_id' => $this->chef->id])->assertRedirect();
        $this->assertSame($this->chef->id, Projet::firstWhere('nom', 'Audit')->chef_projet_id);
    }
}
