<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequeteHttp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** D3 – Brouillon IA du motif de refus / de la demande de complément (n8n simulé). */
class BrouillonIaTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employe;

    private Demande $demande;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config(['novacorp.n8n.brouillon_url' => 'http://n8n.test/webhook/novacorp-brouillon', 'novacorp.n8n.secret' => 'secret-test']);
        $this->manager = User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]);
        $this->employe = User::factory()->create(['role_id' => Role::where('slug', 'dev')->value('id'), 'manager_id' => $this->manager->id]);
        $this->demande = Demande::create(['demandeur_id' => $this->employe->id, 'type' => 'note_de_frais', 'objet' => 'Repas client',
            'message' => 'Merci de me rembourser', 'statut' => 'en_attente', 'montant' => 57]);
    }

    private function url(): string
    {
        return "/demandes/{$this->demande->id}/brouillon-ia";
    }

    public function test_le_valideur_recoit_un_brouillon(): void
    {
        Http::fake(['n8n.test/*' => Http::response(['text' => 'Merci de joindre le justificatif du repas.'])]);

        $this->actingAs($this->manager)->postJson($this->url(), ['intention' => 'demander_complement', 'notes' => 'ticket'])
            ->assertOk()->assertJson(['texte' => 'Merci de joindre le justificatif du repas.']);

        Http::assertSent(fn (RequeteHttp $r) => $r->hasHeader('x-novacorp-secret', 'secret-test')
            && $r['intention'] === 'demander_complement' && $r['notes_valideur'] === 'ticket' && $r['objet'] === 'Repas client');
        // Rien n'est décidé : la demande reste en attente
        $this->assertSame('en_attente', $this->demande->fresh()->statut);
    }

    public function test_bouton_visible_pour_le_valideur_seulement(): void
    {
        $this->actingAs($this->manager)->get("/demandes/{$this->demande->id}")->assertSee('Proposer un motif de refus');
        $this->actingAs($this->employe)->get("/demandes/{$this->demande->id}")->assertDontSee('Proposer un motif de refus');
    }

    public function test_refuse_au_demandeur_et_sans_configuration(): void
    {
        Http::fake();
        $this->actingAs($this->employe)->postJson($this->url(), ['intention' => 'refuser'])->assertForbidden();
        $this->actingAs($this->manager)->postJson($this->url(), ['intention' => 'valider'])->assertUnprocessable();

        config(['novacorp.n8n.brouillon_url' => null]);
        $this->actingAs($this->manager)->postJson($this->url(), ['intention' => 'refuser'])->assertStatus(503);
        $this->actingAs($this->manager)->get("/demandes/{$this->demande->id}")->assertDontSee('Proposer un motif de refus');
        Http::assertNothingSent();
    }

    public function test_n8n_eteint_message_clair(): void
    {
        Http::fake(['n8n.test/*' => Http::response('Not found', 404)]);

        $this->actingAs($this->manager)->postJson($this->url(), ['intention' => 'refuser'])
            ->assertStatus(503)->assertJsonFragment(['erreur' => "L'assistant IA est indisponible pour le moment (n8n éteint ?). Rédigez le commentaire vous-même."]);
    }

    public function test_depuis_le_lien_du_mail_sans_connexion(): void
    {
        Http::fake(['n8n.test/*' => Http::response(['text' => 'Pourriez-vous joindre le ticket ?'])]);
        $jeton = $this->demande->fresh()->jeton_decision;
        $this->assertNotNull($jeton);

        $this->get("/decision/{$this->demande->id}/{$jeton}?choix=a_completer")->assertOk()->assertSee('Proposer une demande de complément');
        $this->postJson("/decision/{$this->demande->id}/{$jeton}/brouillon-ia", ['intention' => 'demander_complement'])
            ->assertOk()->assertJson(['texte' => 'Pourriez-vous joindre le ticket ?']);
        $this->postJson("/decision/{$this->demande->id}/mauvais-jeton/brouillon-ia", ['intention' => 'refuser'])->assertForbidden();
    }
}
