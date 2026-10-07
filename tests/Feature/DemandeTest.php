<?php

namespace Tests\Feature;

use App\Mail\DemandeEnvoyee;
use App\Models\ConnexionLog;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemandeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_connexion_et_journalisation(): void
    {
        $user = User::factory()->create(['email' => 'test@novacorp.fr']);

        $this->post('/connexion', ['email' => 'test@novacorp.fr', 'password' => 'mauvais'])
            ->assertSessionHasErrors('email');
        $this->post('/connexion', ['email' => 'test@novacorp.fr', 'password' => 'password'])
            ->assertRedirect('/tableau-de-bord');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('connexion_logs', ['evenement' => 'echec', 'email' => 'test@novacorp.fr']);
        $this->assertDatabaseHas('connexion_logs', ['evenement' => 'connexion', 'user_id' => $user->id]);
    }

    public function test_creation_de_compte(): void
    {
        $this->post('/inscription', [
            'prenom' => 'Jeanne', 'nom' => 'Martin', 'email' => 'jeanne@novacorp.fr',
            'telephone' => '0600000000', 'role_id' => 5,
            'password' => 'motdepasse', 'password_confirmation' => 'motdepasse',
        ])->assertRedirect('/tableau-de-bord');

        $this->assertDatabaseHas('users', ['email' => 'jeanne@novacorp.fr']);
        $this->assertSame(1, ConnexionLog::where('evenement', 'inscription')->count());
    }

    public function test_envoi_demande_avec_pieces_jointes(): void
    {
        Mail::fake();
        Storage::fake('local');

        $manager = User::factory()->create(['role_id' => 3]);
        $employe = User::factory()->create(['role_id' => 5, 'manager_id' => $manager->id]);

        $this->actingAs($employe)->post('/demandes', [
            'type' => 'conge',
            'objet' => 'Congés été',
            'message' => 'Du 1er au 15 août.',
            'manager_id' => $manager->id,
            'pieces_jointes' => [
                UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->image('photo.jpg'),
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('demandes', ['objet' => 'Congés été', 'statut' => 'en_attente']);
        $this->assertDatabaseCount('pieces_jointes', 2);
        Mail::assertSent(DemandeEnvoyee::class, fn ($mail) => $mail->hasTo($manager->email)
            && count($mail->attachments()) === 2);

        // Validation manuelle par le manager
        $demandeId = \App\Models\Demande::first()->id;
        $this->actingAs($manager)->patch("/demandes/{$demandeId}/statut", ['statut' => 'validee'])->assertRedirect();
        $this->assertDatabaseHas('demandes', ['id' => $demandeId, 'statut' => 'validee']);

        // Un autre employé ne peut pas voir la demande
        $autre = User::factory()->create(['role_id' => 5]);
        $this->actingAs($autre)->get("/demandes/{$demandeId}")->assertForbidden();
    }
}
