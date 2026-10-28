<?php

namespace Tests\Feature;

use App\Models\MailSortant;
use App\Models\Projet;
use App\Models\Role;
use App\Models\Tache;
use App\Models\User;
use App\Support\Calendrier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Corrections de la recette du 28/10/2026 (docs/plan-de-tests.md) :
 * R1 comptes créés par l'admin + lien d'activation ; R2 retrait d'un membre → tâches en pause puis réattribuées.
 * (R4 « Corriger » : WorkflowTest ; les mails sont créés par les triggers PostgreSQL, testés dans Supabase.)
 */
class RecetteCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['role_id' => $this->role('admin')]);
    }

    private function role(string $slug): int
    {
        return Role::where('slug', $slug)->value('id');
    }

    // ---------------------------------------------------------------- R1

    public function test_plus_d_inscription_libre(): void
    {
        $this->get('/inscription')->assertNotFound();
        $this->post('/inscription', ['email' => 'pirate@exemple.fr'])->assertNotFound();
        $this->get('/connexion')->assertOk()->assertDontSee('Créer un compte')->assertSee('administrateur');
    }

    public function test_seul_l_admin_ajoute_un_employe(): void
    {
        $rh = User::factory()->create(['role_id' => $this->role('rh')]);
        $this->actingAs($rh)->get('/employes/nouveau')->assertForbidden();
        $this->actingAs($rh)->post('/employes', [])->assertForbidden();
        $this->actingAs($rh)->get('/employes')->assertDontSee('Ajouter un employé');
        $this->actingAs($this->admin)->get('/employes')->assertSee('Ajouter un employé');
        $this->actingAs($this->admin)->get('/employes/nouveau')->assertOk()->assertSee('Direction');  // l'admin peut tout attribuer
    }

    public function test_creation_par_l_admin_puis_activation(): void
    {
        $manager = User::factory()->create(['role_id' => $this->role('manager')]);
        $this->actingAs($this->admin)->post('/employes', [
            'prenom' => 'Jeanne', 'nom' => 'Martin', 'email' => 'jeanne@novacorp.fr', 'telephone' => '0600000000',
            'role_id' => $this->role('dev'), 'manager_id' => $manager->id,
        ])->assertRedirect()->assertSessionHas('success');

        $jeanne = User::where('email', 'jeanne@novacorp.fr')->firstOrFail();
        $this->assertTrue($jeanne->en_attente_activation);
        $this->assertTrue($jeanne->jeton_activation_expire_at->isFuture());
        $this->actingAs($this->admin)->get('/employes?q=jeanne')->assertSee('Non activé')->assertSee('Renvoyer le lien');
        $this->app['auth']->forgetGuards();
        $this->post('/deconnexion');

        // Pas de connexion possible avant d'avoir choisi son mot de passe
        $this->post('/connexion', ['email' => 'jeanne@novacorp.fr', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $jeton = $jeanne->jeton_activation;
        $this->get("/activation/{$jeton}")->assertOk()->assertSee('Bienvenue Jeanne');
        $this->post("/activation/{$jeton}", ['password' => 'court', 'password_confirmation' => 'court'])->assertSessionHasErrors('password');
        $this->post("/activation/{$jeton}", ['password' => 'MotDePasse2026', 'password_confirmation' => 'MotDePasse2026'])
            ->assertRedirect('/tableau-de-bord');
        $this->assertAuthenticatedAs($jeanne->fresh());
        $this->assertFalse($jeanne->fresh()->en_attente_activation);
        $this->assertDatabaseHas('connexion_logs', ['evenement' => 'activation', 'user_id' => $jeanne->id]);

        // Lien à usage unique
        $this->post('/deconnexion');
        $this->get("/activation/{$jeton}")->assertOk()->assertSee('Lien expiré');
        $this->post("/activation/{$jeton}", ['password' => 'Autre2026x', 'password_confirmation' => 'Autre2026x'])->assertForbidden();
    }

    public function test_lien_expire_et_renvoi_par_l_admin(): void
    {
        $nouveau = User::factory()->create(['role_id' => $this->role('dev')]);
        $ancien = $nouveau->nouveauJetonActivation();
        $nouveau->forceFill(['jeton_activation_expire_at' => now()->subDay()])->save();
        $this->get("/activation/{$ancien}")->assertSee('Lien expiré');

        $this->actingAs($this->admin)->post("/employes/{$nouveau->id}/activation")->assertSessionHas('success');
        $nouveau->refresh();
        $this->assertNotSame($ancien, $nouveau->jeton_activation);
        $this->assertTrue($nouveau->jeton_activation_expire_at->isFuture());
        $this->assertDatabaseHas('mails_sortants', ['user_id' => $nouveau->id, 'type' => 'activation']);

        // Compte déjà activé : pas de renvoi
        $actif = User::factory()->create(['role_id' => $this->role('dev')]);
        $this->actingAs($this->admin)->post("/employes/{$actif->id}/activation")->assertStatus(422);
    }

    // ---------------------------------------------------------------- R2

    public function test_retrait_d_un_membre_met_ses_taches_en_pause_puis_reattribution(): void
    {
        Calendrier::oublier();
        $this->travelTo(now()->setTimezone('Europe/Paris')->setDate(2026, 11, 9)->setTime(10, 0));
        $chef = User::factory()->create(['role_id' => $this->role('manager')]);
        [$marc, $lea, $sans] = User::factory(3)->create(['role_id' => $this->role('dev')])->all();
        $projet = Projet::create(['nom' => 'Projet R2', 'statut' => 'en_cours', 'chef_projet_id' => $chef->id]);
        $projet->membres()->attach([$marc->id, $lea->id, $sans->id]);
        $t1 = Tache::create(['titre' => 'Écrire les tests', 'projet_id' => $projet->id, 'responsable_id' => $marc->id,
            'cree_par' => $chef->id, 'statut' => 'en_cours', 'deadline' => '2026-11-20']);
        $t2 = Tache::create(['titre' => 'Déjà finie', 'projet_id' => $projet->id, 'responsable_id' => $marc->id,
            'cree_par' => $chef->id, 'statut' => 'terminee', 'deadline' => '2026-11-20']);

        // Membre sans tâche : retrait direct
        $this->actingAs($chef)->delete("/projets/{$projet->id}/membres/{$sans->id}")->assertRedirect("/projets/{$projet->id}");
        $this->assertFalse($projet->aPourMembre($sans));

        // Membre avec une tâche en cours : avertissement d'abord, rien n'est changé
        $this->actingAs($chef)->get("/projets/{$projet->id}")->assertSee('Retirer…');
        $this->actingAs($chef)->delete("/projets/{$projet->id}/membres/{$marc->id}")
            ->assertRedirect("/projets/{$projet->id}/membres/{$marc->id}/retirer");
        $this->assertTrue($projet->aPourMembre($marc));
        $this->actingAs($chef)->get("/projets/{$projet->id}/membres/{$marc->id}/retirer")
            ->assertOk()->assertSee('Écrire les tests')->assertDontSee('Déjà finie')->assertSee('en pause');
        $this->actingAs($lea)->get("/projets/{$projet->id}/membres/{$marc->id}/retirer")->assertForbidden();

        // Confirmation : tâche en pause, membre retiré
        $this->actingAs($chef)->delete("/projets/{$projet->id}/membres/{$marc->id}", ['confirmer' => 1])->assertSessionHas('success');
        $this->assertFalse($projet->aPourMembre($marc));
        $t1->refresh();
        $this->assertSame('en_pause', $t1->statut);
        $this->assertSame($marc->id, $t1->pause_ancien_responsable_id);
        $this->assertNotNull($t1->pause_le);
        $this->assertSame('terminee', $t2->fresh()->statut);
        $this->assertDatabaseHas('historique', ['objet' => 'tache', 'objet_id' => $t1->id, 'nouveau_statut' => 'en_pause',
            'commentaire' => "{$marc->nom_complet} retiré(e) du projet"]);

        // Mise en avant : projet, tableau de bord du chef, fiche de la tâche
        $this->actingAs($chef)->get("/projets/{$projet->id}")->assertSee('tâche en pause à réattribuer')->assertSee('Réattribuer');
        $this->actingAs($chef)->get('/tableau-de-bord')->assertSee('en pause à réattribuer')->assertSee('Écrire les tests');
        $this->actingAs($lea)->get('/tableau-de-bord')->assertDontSee('en pause à réattribuer');

        // Plus d'action possible sur la tâche en pause hors réattribution
        $this->actingAs($marc)->patch("/taches/{$t1->id}/statut", ['statut' => 'terminee'])->assertForbidden();
        $this->actingAs($lea)->post("/taches/{$t1->id}/reattribuer", ['responsable_id' => $lea->id])->assertForbidden();

        // Réattribution : uniquement à un membre du projet
        $this->actingAs($chef)->post("/taches/{$t1->id}/reattribuer", ['responsable_id' => $marc->id])->assertSessionHasErrors('responsable_id');
        $this->actingAs($chef)->post("/taches/{$t1->id}/reattribuer", ['responsable_id' => $lea->id])->assertSessionHas('success');
        $t1->refresh();
        $this->assertSame(['a_faire', $lea->id, null], [$t1->statut, $t1->responsable_id, $t1->pause_le]);
        $this->assertDatabaseHas('historique', ['objet' => 'tache', 'objet_id' => $t1->id, 'ancien_statut' => 'en_pause',
            'commentaire' => "Réattribuée à {$lea->nom_complet}"]);
    }

    public function test_reattribution_avec_deadline_depassee(): void
    {
        Calendrier::oublier();
        $this->travelTo(now()->setTimezone('Europe/Paris')->setDate(2026, 11, 9)->setTime(10, 0));
        $chef = User::factory()->create(['role_id' => $this->role('manager')]);
        [$marc, $lea] = User::factory(2)->create(['role_id' => $this->role('dev')])->all();
        $projet = Projet::create(['nom' => 'Projet R2b', 'statut' => 'en_cours', 'chef_projet_id' => $chef->id]);
        $projet->membres()->attach([$marc->id, $lea->id]);
        $t = Tache::create(['titre' => 'En retard', 'projet_id' => $projet->id, 'responsable_id' => $marc->id,
            'cree_par' => $chef->id, 'statut' => 'expiree', 'deadline' => '2026-11-02']);

        $this->actingAs($chef)->delete("/projets/{$projet->id}/membres/{$marc->id}", ['confirmer' => 1]);
        $this->assertSame('en_pause', $t->fresh()->statut);

        $this->actingAs($chef)->post("/taches/{$t->id}/reattribuer", ['responsable_id' => $lea->id])->assertSessionHasErrors('deadline');
        $this->actingAs($chef)->post("/taches/{$t->id}/reattribuer", ['responsable_id' => $lea->id, 'deadline' => '2026-11-06'])
            ->assertSessionHasErrors('deadline');   // dans le passé
        $this->actingAs($chef)->post("/taches/{$t->id}/reattribuer", ['responsable_id' => $lea->id, 'deadline' => '2026-11-16'])
            ->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame(['a_faire', $lea->id, '2026-11-16'], [$t->statut, $t->responsable_id, $t->deadline->toDateString()]);
    }

    // ---------------------------------------------------------------- R5 / R6

    public function test_prochain_recapitulatif_jamais_dans_le_passe(): void
    {
        Calendrier::oublier();
        $chef = User::factory()->create(['role_id' => $this->role('manager')]);
        $projet = Projet::create(['nom' => 'P', 'statut' => 'en_cours', 'chef_projet_id' => $chef->id,
            'reunion_frequence' => 'hebdomadaire', 'reunion_jour' => 1]);                       // chaque lundi

        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 07:30', 'Europe/Paris'));   // lundi, avant 8 h
        $this->assertSame('2026-11-09', $projet->prochaineReunion()->toDateString());
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 09:27', 'Europe/Paris'));   // lundi, après 8 h
        $this->assertSame('2026-11-16', $projet->prochaineReunion()->toDateString());
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-09 07:59', 'Europe/Paris'));   // récap déjà parti (cron en avance)
        $projet->forceFill(['dernier_recap_le' => '2026-11-09'])->save();
        $this->assertSame('2026-11-16', $projet->fresh()->prochaineReunion()->toDateString());
    }

    public function test_message_du_seuil_d_absences_lisible_et_affiche_une_fois(): void
    {
        $rh = User::factory()->create(['role_id' => $this->role('rh')]);
        $reponse = $this->actingAs($rh)->from('/delais')->followingRedirects()
            ->put('/delais/parametres', ['seuil_absences_equipe_pct' => 5]);
        $reponse->assertSee('Le seuil d&#039;alerte doit être compris entre 10 et 100 %.', false);
        $this->assertSame(1, substr_count($reponse->getContent(), 'compris entre 10 et 100'));
        $reponse->assertDontSee('seuil absences equipe pct');
    }
}
