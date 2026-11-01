<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\TransportCompany;
use App\Models\User;
use App\Rules\RibMarocain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A RIB entered in the app is 24 digits and divisible by 97; a typing mistake is refused. */
class RibMarocainTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $division;

    private User $pointeur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->farm = Farm::create(['name' => 'Farm']);
        $this->division = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id]);
    }

    /** A fictitious valid RIB: its first 22 digits, then the key that makes it divisible by 97. */
    private function rib(string $debut = '0000000000000000123456'): string
    {
        $reste = 0;
        foreach (str_split($debut.'00') as $chiffre) {
            $reste = ($reste * 10 + (int) $chiffre) % 97;
        }

        return $debut.str_pad((string) (97 - $reste), 2, '0', STR_PAD_LEFT);
    }

    private function ajouter(?string $rib, string $nom = 'ALAOUI FATIMA')
    {
        return $this->actingAs($this->pointeur)->post(route('employees.store'), [
            'full_name' => $nom, 'base_rate' => 97.44, 'enterprise_id' => $this->division->id, 'rib' => $rib,
        ]);
    }

    public function test_the_rule(): void
    {
        $bon = $this->rib();
        $this->assertNull(RibMarocain::erreur($bon));
        $this->assertNull(RibMarocain::erreur(chunk_split($bon, 4, ' ')));
        $this->assertStringContainsString('24 chiffres (23', RibMarocain::erreur(substr($bon, 1)));
        $this->assertStringContainsString('que des chiffres', RibMarocain::erreur('12A'));
        // Any single wrong digit is caught, wherever it is.
        foreach (range(0, 23) as $i) {
            $faux = $bon;
            $faux[$i] = (string) (((int) $bon[$i] + 1) % 10);
            $this->assertStringContainsString('la clé', RibMarocain::erreur($faux), "chiffre {$i}");
        }
    }

    public function test_a_new_worker_needs_a_valid_rib_or_none(): void
    {
        $bon = $this->rib();

        $this->ajouter(substr($bon, 0, 20))->assertSessionHasErrors('rib');
        $this->ajouter(substr($bon, 0, 23).(((int) $bon[23] + 1) % 10))->assertSessionHasErrors('rib');
        $this->assertSame(0, Employee::count());

        $this->ajouter(chunk_split($bon, 4, ' '))->assertSessionHasNoErrors();
        $this->assertSame($bon, Employee::sole()->rib); // saved without its spaces

        $this->ajouter('', 'BENNANI OMAR')->assertSessionHasNoErrors(); // no RIB: paid in cash
        $this->assertNull(Employee::where('rib', null)->sole()->rib);
    }

    public function test_only_a_changed_rib_is_checked_on_a_worker(): void
    {
        $ancien = '123456789012345678901234'; // saved before the rule, wrong key
        $worker = Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $this->division->id, 'matricule' => '001', 'full_name' => 'ANCIEN', 'base_rate' => 97.44, 'is_active' => true, 'rib' => $ancien]);
        $modifier = fn ($rib) => $this->actingAs($this->pointeur)->put(route('employees.update', $worker), [
            'full_name' => 'ANCIEN RENOMME', 'base_rate' => 97.44, 'enterprise_id' => $this->division->id, 'rib' => $rib,
        ]);

        $modifier($ancien)->assertSessionHasNoErrors();
        $this->assertSame('ANCIEN RENOMME', $worker->fresh()->full_name);

        $modifier('123456789012345678901235')->assertSessionHasErrors('rib');
        $this->assertSame($ancien, $worker->fresh()->rib);

        $modifier($this->rib())->assertSessionHasNoErrors();
        $this->assertSame($this->rib(), $worker->fresh()->rib);
    }

    public function test_a_transport_company_follows_the_same_rule(): void
    {
        $this->actingAs($this->pointeur)->post(route('transport.companies.store'), ['name' => 'Bus', 'rib' => '123'])->assertSessionHasErrors('rib');
        $this->actingAs($this->pointeur)->post(route('transport.companies.store'), ['name' => 'Bus', 'rib' => $this->rib()])->assertSessionHasNoErrors();

        // Its RIB from before the rule doesn't stop a rename or the on/off switch.
        $ancienne = TransportCompany::create(['farm_id' => $this->farm->id, 'name' => 'Ancienne', 'rib' => '1234567890123456789012345', 'is_active' => true]);
        $this->actingAs($this->pointeur)->put(route('transport.companies.update', $ancienne), ['name' => 'Renommee', 'rib' => $ancienne->rib])->assertSessionHasNoErrors();
        $this->actingAs($this->pointeur)->put(route('transport.companies.update', $ancienne), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertSame('1234567890123456789012345', $ancienne->fresh()->rib);
        $this->assertFalse((bool) $ancienne->fresh()->is_active);
    }
}
