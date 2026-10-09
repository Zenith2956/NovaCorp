<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequeteHttp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** TP séance 6 – bulle « Assistant RH » : widget n8n relayé par Laravel. */
class AssistantRhTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->user = User::factory()->create(['role_id' => Role::where('slug', 'dev')->value('id')]);
        config(['novacorp.n8n.chat_url' => 'http://n8n.test/webhook/abc/chat', 'novacorp.n8n.chat_user' => 'novacorp', 'novacorp.n8n.chat_password' => 'secret-test']);
    }

    public function test_bulle_pour_les_utilisateurs_connectes_sans_exposer_n8n(): void
    {
        $page = $this->actingAs($this->user)->get('/tableau-de-bord')->assertOk()
            ->assertSee('@n8n/chat@1.40.0', false)
            ->assertSee('assistant-rh', false);
        $page->assertDontSee('n8n.test', false)->assertDontSee('secret-test', false);

        config(['novacorp.n8n.chat_url' => null]);
        $this->actingAs($this->user)->get('/tableau-de-bord')->assertDontSee('@n8n/chat', false);
    }

    public function test_pas_de_bulle_ni_d_acces_sans_connexion(): void
    {
        $this->get('/connexion')->assertOk()->assertDontSee('@n8n/chat', false);
        $this->postJson('/assistant-rh', ['chatInput' => 'Bonjour'])->assertUnauthorized();
    }

    public function test_relais_avec_identite_du_serveur(): void
    {
        Http::fake(['n8n.test/*' => Http::response(['output' => '2 jours, mardi et jeudi. Source : 02-politique-teletravail.md'])]);

        $this->actingAs($this->user)->postJson('/assistant-rh', [
            'action' => 'sendMessage', 'sessionId' => 'abc-123', 'chatInput' => 'Combien de jours de télétravail ?',
            'metadata' => ['email' => 'quelquun.dautre@novacorp.fr'],   // ignoré : l'identité vient de la session
        ])->assertOk()->assertJson(['output' => '2 jours, mardi et jeudi. Source : 02-politique-teletravail.md']);

        Http::assertSent(fn (RequeteHttp $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('novacorp:secret-test'))
            && $r['metadata']['email'] === $this->user->email
            && $r['sessionId'] === 'u'.$this->user->id.'-abc-123'
            && $r['chatInput'] === 'Combien de jours de télétravail ?');
    }

    public function test_message_clair_si_n8n_est_eteint(): void
    {
        Http::fake(['n8n.test/*' => Http::response('Not found', 404)]);

        $this->actingAs($this->user)->postJson('/assistant-rh', ['chatInput' => 'Bonjour'])
            ->assertOk()->assertJsonFragment(['output' => "L'assistant RH est indisponible pour le moment. Réessayez plus tard ou contactez le service RH (rh@novacorp.fr)."]);
    }
}
