<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\JourFerie;
use App\Models\Projet;
use App\Models\Role;
use App\Models\Tache;
use App\Models\User;
use App\Support\Calendrier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Workflow automatisé (étape 2) : circuits de validation, actions, historique, validation des tâches.
 * Spécification : docs/propositions-workflow.md. Sous SQLite, l'historique est écrit en PHP
 * (sous PostgreSQL, par le trigger automation.historiser()).
 */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employe;

    private User $rh;

    private User $comptable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        JourFerie::create(['jour' => '2026-11-11', 'libelle' => 'Armistice 1918']);
        Calendrier::oublier();
        $this->travelTo(now()->setTimezone('Europe/Paris')->setDate(2026, 11, 9)->setTime(10, 0)); // lundi 09/11/2026

        $this->manager = User::factory()->create(['role_id' => $this->role('manager')]);
        $this->employe = User::factory()->create(['role_id' => $this->role('dev'), 'manager_id' => $this->manager->id]);
        $this->rh = User::factory()->create(['role_id' => $this->role('rh')]);
        $this->comptable = User::factory()->create(['role_id' => $this->role('comptable')]);
    }

    private function role(string $slug): int
    {
        return Role::where('slug', $slug)->value('id');
    }

    private function demande(array $attributs = []): Demande
    {
        return Demande::create($attributs + [
            'demandeur_id' => $this->employe->id, 'type' => 'autre',
            'objet' => 'Demande test', 'message' => 'Merci', 'statut' => 'en_attente',
        ]);
    }

    private function agir(User $user, Demande $demande, string $action, ?string $commentaire = null)
    {
        return $this->actingAs($user)->post("/demandes/{$demande->id}/action", array_filter(['action' => $action, 'commentaire' => $commentaire]));
    }

    public function test_conge_long_passe_par_les_rh_puis_traitement(): void
    {
        Mail::fake();
        // Du lundi 16/11 au mercredi 25/11 : 8 jours ouvrés (> 5) → étape RH
        $this->actingAs($this->employe)->post('/demandes', [
            'type' => 'conge', 'objet' => 'Vacances', 'message' => 'Merci',
            'date_debut' => '2026-11-16', 'date_fin' => '2026-11-25',
        ])->assertRedirect();
        $d = Demande::firstWhere('objet', 'Vacances');
        $this->assertSame($this->manager->id, $d->manager_id);   // assignation automatique
        $this->assertSame(8, $d->nb_jours_ouvres);
        $jeton = $d->jeton_decision;

        // Étape 1 : le manager valide → étape 2 (RH), toujours en attente, nouveau lien
        $this->agir($this->manager, $d, 'valider')->assertSessionHas('success');
        $d->refresh();
        $this->assertSame(['en_attente', 2], [$d->statut, $d->etape]);
        $this->assertNotSame($jeton, $d->jeton_decision);
        $this->assertSame('2026-11-17', $d->echeance_le->toDateString()); // délais RH : 5 j ouvrés (11/11 férié)

        // Le manager ne peut plus décider, la RH oui ; la demande apparaît dans « à traiter » de la RH
        $this->agir($this->manager, $d, 'valider')->assertForbidden();
        $this->actingAs($this->rh)->get('/demandes?a_traiter=1')->assertSee('Vacances');
        $this->actingAs($this->manager)->get('/demandes?a_traiter=1')->assertDontSee('Vacances');

        $this->agir($this->rh, $d, 'valider');
        $d->refresh();
        $this->assertSame('validee', $d->statut);
        $this->assertSame($this->rh->id, $d->decision_par);

        // Traitement par le service RH
        $this->agir($this->employe, $d, 'prendre_en_charge')->assertForbidden();
        $this->agir($this->rh, $d, 'prendre_en_charge');
        $this->agir($this->rh, $d->refresh(), 'terminer');
        $d->refresh();
        $this->assertSame('terminee', $d->statut);
        $this->assertSame($this->rh->id, $d->traite_par);

        // Historique complet : création, étape 2, validée, en traitement, terminée
        $this->assertSame(
            [null, 'en_attente', 'en_attente', 'validee', 'en_traitement'],
            $d->historique->pluck('ancien_statut')->all()
        );
        $this->actingAs($this->employe)->get("/demandes/{$d->id}")->assertOk()
            ->assertSee('Circuit de validation')->assertSee('Ressources humaines')->assertSee('Historique');
    }

    public function test_conge_court_valide_par_le_manager_seul(): void
    {
        $d = $this->demande(['type' => 'conge', 'date_debut' => '2026-11-16', 'date_fin' => '2026-11-18']);
        $this->assertSame(3, $d->nb_jours_ouvres);
        $this->agir($this->manager, $d, 'valider');
        $this->assertSame('validee', $d->fresh()->statut);
    }

    public function test_materiel_selon_le_montant(): void
    {
        $petit = $this->demande(['type' => 'materiel', 'montant' => 300]);
        $this->agir($this->manager, $petit, 'valider');
        $this->assertSame('validee', $petit->fresh()->statut);

        $gros = $this->demande(['type' => 'materiel', 'montant' => 900]);
        $this->agir($this->manager, $gros, 'valider');
        $this->assertSame(2, $gros->fresh()->etape);
        $this->agir($this->rh, $gros->fresh(), 'valider')->assertForbidden();      // pas la comptabilité
        $this->agir($this->comptable, $gros->fresh(), 'valider');
        $this->assertSame('validee', $gros->fresh()->statut);
    }

    public function test_formulaire_par_type(): void
    {
        $this->actingAs($this->employe)->post('/demandes', ['type' => 'materiel', 'objet' => 'Écran', 'message' => 'x'])
            ->assertSessionHasErrors('montant');
        $this->actingAs($this->employe)->post('/demandes', ['type' => 'conge', 'objet' => 'Congé', 'message' => 'x'])
            ->assertSessionHasErrors(['date_debut', 'date_fin']);
        $this->actingAs($this->employe)->post('/demandes', ['type' => 'conge', 'objet' => 'Congé', 'message' => 'x',
            'date_debut' => '2026-11-20', 'date_fin' => '2026-11-18'])->assertSessionHasErrors('date_fin');
        $this->actingAs($this->employe)->get('/demandes/nouvelle')->assertOk()
            ->assertSee($this->manager->nom_complet)->assertSee('Comptabilité');
    }

    public function test_refus_avec_motif_obligatoire(): void
    {
        $d = $this->demande();
        $this->agir($this->manager, $d, 'refuser')->assertSessionHasErrors('commentaire');
        $this->assertSame('en_attente', $d->fresh()->statut);

        $this->agir($this->manager, $d, 'refuser', 'Budget épuisé');
        $d->refresh();
        $this->assertSame(['refusee', 'Budget épuisé'], [$d->statut, $d->commentaire_decision]);
        $this->assertSame('Budget épuisé', $d->historique->last()->commentaire);
        $this->actingAs($this->employe)->get("/demandes/{$d->id}")->assertSee('Motif du refus')->assertSee('Budget épuisé');
    }

    public function test_complement_puis_annulation(): void
    {
        $d = $this->demande();
        $this->agir($this->manager, $d, 'demander_complement', 'Joindre le devis');
        $this->assertSame('a_completer', $d->fresh()->statut);
        $this->assertNull($d->fresh()->jeton_decision);

        $this->agir($this->employe, $d->fresh(), 'completer')->assertSessionHasErrors('commentaire');
        $this->agir($this->employe, $d->fresh(), 'completer', 'Devis de 450 € envoyé par mail');
        $d->refresh();
        $this->assertSame('en_attente', $d->statut);
        $this->assertStringContainsString('Devis de 450 €', $d->message);
        $this->assertNotNull($d->jeton_decision);

        $this->agir($this->manager, $d, 'annuler')->assertForbidden();             // seul le demandeur annule
        $this->agir($this->employe, $d, 'annuler');
        $this->assertSame('annulee', $d->fresh()->statut);
        $this->agir($this->manager, $d->fresh(), 'valider')->assertForbidden();
    }

    public function test_seuls_les_valideurs_decident(): void
    {
        $d = $this->demande();
        $autre = User::factory()->create(['role_id' => $this->role('dev')]);
        $this->agir($autre, $d, 'valider')->assertForbidden();
        $this->agir($this->employe, $d, 'valider')->assertForbidden();
        $this->actingAs($autre)->get("/demandes/{$d->id}")->assertForbidden();
        $this->assertSame('en_attente', $d->fresh()->statut);

        // Correction d'une décision par la RH
        $this->agir($this->manager, $d, 'valider');
        $this->agir($this->manager, $d->fresh(), 'corriger')->assertForbidden();
        $this->agir($this->rh, $d->fresh(), 'corriger');
        $this->assertSame(['en_attente', 1], [$d->fresh()->statut, $d->fresh()->etape]);
    }

    public function test_lien_du_mail_pour_une_etape_de_service(): void
    {
        $d = $this->demande(['type' => 'note_de_frais', 'montant' => 80]);
        $this->agir($this->manager, $d, 'valider');
        $d->refresh();
        $this->assertSame(2, $d->etape);
        $this->app['auth']->forgetGuards();

        // Étape Comptabilité : connexion obligatoire pour savoir qui décide
        $url = "/decision/{$d->id}/{$d->jeton_decision}";
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->rh)->get($url)->assertForbidden();
        $this->actingAs($this->comptable)->get($url)->assertOk()->assertSee('Comptabilité');
        $this->actingAs($this->comptable)->post($url, ['choix' => 'validee'])->assertSee('Décision enregistrée');
        $d->refresh();
        $this->assertSame(['validee', $this->comptable->id], [$d->statut, $d->decision_par]);
        $this->assertSame('mail', $d->historique->last()->canal);
    }

    public function test_tache_de_projet_validee_par_le_chef(): void
    {
        $projet = Projet::create(['nom' => 'Intranet', 'statut' => 'en_cours', 'chef_projet_id' => $this->manager->id]);
        $t = Tache::create(['titre' => 'Maquettes', 'projet_id' => $projet->id, 'responsable_id' => $this->employe->id,
            'cree_par' => $this->manager->id, 'statut' => 'en_cours', 'deadline' => '2026-11-27']);

        // Le responsable termine → « à valider »
        $this->actingAs($this->employe)->patch("/taches/{$t->id}/statut", ['statut' => 'terminee'])->assertSessionHas('success');
        $this->assertSame('a_valider', $t->fresh()->statut);
        $this->assertNull($t->fresh()->termine_at);
        $this->actingAs($this->employe)->post("/taches/{$t->id}/validation", ['decision' => 'valider'])->assertForbidden();

        // Le chef renvoie (commentaire obligatoire)
        $this->actingAs($this->manager)->post("/taches/{$t->id}/validation", ['decision' => 'renvoyer'])
            ->assertSessionHasErrors('commentaire_validation');
        $this->actingAs($this->manager)->post("/taches/{$t->id}/validation", ['decision' => 'renvoyer', 'commentaire_validation' => 'Ajouter la version mobile']);
        $this->assertSame('en_cours', $t->fresh()->statut);
        $this->actingAs($this->employe)->get("/taches/{$t->id}")->assertSee('Ajouter la version mobile');

        // Nouvelle remise, puis validation
        $this->actingAs($this->employe)->patch("/taches/{$t->id}/statut", ['statut' => 'terminee']);
        $this->actingAs($this->manager)->post("/taches/{$t->id}/validation", ['decision' => 'valider']);
        $t->refresh();
        $this->assertSame('terminee', $t->statut);
        $this->assertNotNull($t->termine_at);
        $this->assertSame(['en_cours', 'a_valider', 'en_cours', 'a_valider', 'terminee'], $t->historique->pluck('nouveau_statut')->all());
        $this->assertSame('Ajouter la version mobile', $t->historique[2]->commentaire);
    }
}
