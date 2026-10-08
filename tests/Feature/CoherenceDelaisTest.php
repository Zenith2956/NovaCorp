<?php

namespace Tests\Feature;

use App\Models\Demande;
use App\Models\JourFerie;
use App\Models\Role;
use App\Models\User;
use App\Support\Calendrier;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cohérence PHP / PostgreSQL : mêmes cas et mêmes résultats attendus que la suite SQL
 * supabase/tests/tests-gestion-delais.sql (codes C… et D…). Si les deux passent,
 * l'application (PHP) et la base (triggers) calculent les délais de la même façon.
 * Aujourd'hui simulé : lundi 09/11/2026, le 11/11 est férié.
 */
class CoherenceDelaisTest extends TestCase
{
    use RefreshDatabase;

    private User $employe;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        JourFerie::create(['jour' => '2026-11-11', 'libelle' => 'Armistice 1918']);
        Calendrier::oublier();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00', 'Europe/Paris'));
        $this->manager = User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]);
        $this->employe = User::factory()->create(['role_id' => Role::where('slug', 'dev')->value('id'), 'manager_id' => $this->manager->id]);
    }

    private function d(string $jour): CarbonImmutable
    {
        return CarbonImmutable::parse($jour);
    }

    public function test_calendrier_identique_au_sql(): void
    {
        $this->assertFalse(Calendrier::estOuvre($this->d('2026-11-14')), 'C7 samedi');
        $this->assertFalse(Calendrier::estOuvre($this->d('2026-11-11')), 'C8 11/11');
        $this->assertSame('2026-11-12', Calendrier::ajouterJoursOuvres($this->d('2026-11-09'), 2)->toDateString(), 'C9');
        $this->assertSame('2026-11-16', Calendrier::ajouterJoursOuvres($this->d('2026-11-13'), 1)->toDateString(), 'C10');
        $this->assertSame('2026-11-09', Calendrier::ajouterJoursOuvres($this->d('2026-11-09'), 0)->toDateString(), 'C11');
        $this->assertSame('2026-11-10', Calendrier::jourOuvrePrecedent($this->d('2026-11-12'))->toDateString(), 'C12');
        $this->assertSame('2026-11-13', Calendrier::jourOuvrePrecedent($this->d('2026-11-16'))->toDateString(), 'C13');
        $this->assertSame(2, Calendrier::joursOuvresEntre($this->d('2026-11-09'), $this->d('2026-11-12')), 'jours ouvrés écoulés');
    }

    /** Mêmes cas que D1 à D10 dans la suite SQL. */
    public static function cas(): array
    {
        return [
            'D1-D4 congé' => ['conge', null, '2026-11-12', '2026-11-17', '2026-11-24', false],
            'D5 matériel' => ['materiel', null, '2026-11-10', '2026-11-13', '2026-11-20', false],
            'D6 formation' => ['formation', null, '2026-11-16', '2026-11-24', '2026-12-01', false],
            'D7 date souhaitée proche' => ['conge', '2026-11-12', '2026-11-10', '2026-11-10', '2026-11-12', true],
            'D8 date souhaitée lointaine' => ['conge', '2026-11-30', '2026-11-12', '2026-11-17', '2026-11-30', false],
            'D9 date souhaitée aujourd\'hui' => ['conge', '2026-11-09', '2026-11-09', '2026-11-09', '2026-11-09', true],
            'D10 type inconnu' => ['type_inconnu', null, '2026-11-12', '2026-11-17', '2026-11-24', false],
        ];
    }

    #[DataProvider('cas')]
    public function test_dates_des_demandes_identiques_au_sql(string $type, ?string $souhaitee, string $relance, string $echeance, string $deadline, bool $urgente): void
    {
        $d = Demande::create([
            'demandeur_id' => $this->employe->id, 'manager_id' => $this->manager->id, 'type' => $type,
            'objet' => 'x', 'message' => 'x', 'statut' => 'en_attente', 'date_souhaitee' => $souhaitee,
        ]);

        $this->assertSame($relance, $d->relance_le->toDateString(), 'relance');
        $this->assertSame($echeance, $d->echeance_le->toDateString(), 'échéance');
        $this->assertSame($deadline, $d->deadline->toDateString(), 'deadline');
        $this->assertSame($urgente, (bool) $d->urgente, 'urgente');
    }

    public function test_statistiques_de_la_page_delais(): void
    {
        // Deux demandes « matériel » du 09/11 (échéance 13/11) : l'une décidée le 12/11 (2 j ouvrés, à temps),
        // l'autre le 16/11 (4 j ouvrés, en retard) -> délai moyen 3 j, 50 % dans les temps.
        foreach (['2026-11-12 15:00', '2026-11-16 15:00'] as $decision) {
            $d = Demande::create(['demandeur_id' => $this->employe->id, 'manager_id' => $this->manager->id,
                'type' => 'materiel', 'objet' => 'x', 'message' => 'x', 'statut' => 'en_attente', 'envoyee_at' => now()]);
            $d->update(['statut' => 'validee', 'decision_at' => CarbonImmutable::parse($decision, 'Europe/Paris')]);
        }
        // Une troisième, toujours en attente : en retard le 16/11
        Demande::create(['demandeur_id' => $this->employe->id, 'manager_id' => $this->manager->id,
            'type' => 'materiel', 'objet' => 'En souffrance', 'message' => 'x', 'statut' => 'en_attente', 'envoyee_at' => now()]);

        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
        $this->actingAs($this->manager)->get('/delais')
            ->assertOk()
            ->assertViewHas('global', fn ($g) => $g['traitees'] === 2 && (float) $g['delai_moyen'] === 3.0
                && $g['pct_dans_les_temps'] === 50 && $g['en_retard'] === 1 && $g['en_attente'] === 1)
            ->assertSee('En souffrance');
    }
}
