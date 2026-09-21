<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Quinzaine;
use App\Models\TransportCompany;
use App\Models\TransportVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The pointage grid can list a transport's riders even when they belong to different divisions. */
class PointageTransportGridTest extends TestCase
{
    use RefreshDatabase;

    private function setUpFarm(): array
    {
        $farm = Farm::create(['name' => 'Farm']);
        $mk = fn ($name) => Enterprise::create([
            'farm_id' => $farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $entA = $mk('A');
        $entB = $mk('B');
        $qa = Quinzaine::create(['enterprise_id' => $entA->id, 'label' => 'Q', 'start_date' => '2026-09-16', 'end_date' => '2026-09-30', 'is_closed' => false]);
        $qb = Quinzaine::create(['enterprise_id' => $entB->id, 'label' => 'Q', 'start_date' => '2026-09-16', 'end_date' => '2026-09-30', 'is_closed' => true]);
        $company = TransportCompany::create(['farm_id' => $farm->id, 'name' => 'Co']);
        $van = TransportVehicle::create(['farm_id' => $farm->id, 'transport_company_id' => $company->id, 'code' => 'T1']);
        $emp = fn ($ent, $name, $vehicle) => Employee::create([
            'farm_id' => $farm->id, 'enterprise_id' => $ent->id, 'matricule' => $name, 'full_name' => $name,
            'type' => 'persea', 'base_rate' => 97.44, 'is_active' => true, 'transport_vehicle_id' => $vehicle?->id,
        ]);
        $a = $emp($entA, 'Rider A', $van);
        $b = $emp($entB, 'Rider B', $van);
        $c = $emp($entA, 'Walker A', null);

        return [User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]), $qa, $qb, $van, $a, $b, $c];
    }

    public function test_transport_mode_lists_riders_across_divisions_with_their_own_quinzaine(): void
    {
        [$manager, $qa, $qb, $van, $a, $b] = $this->setUpFarm();

        $this->actingAs($manager)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'transport', 'transport' => $van->id]))
            ->assertInertia(fn ($p) => $p
                ->where('mode', 'transport')
                ->has('employees', 2)
                ->where('employees.0.quinzaine_id', $qa->id)
                ->where('employees.1.quinzaine_id', $qb->id)
                ->where('employees.1.quinzaine_closed', true));
    }

    public function test_default_mode_stays_the_divisions_own_employees(): void
    {
        [$manager, $qa] = $this->setUpFarm();

        $this->actingAs($manager)->get(route('pointage.grid', $qa->id))
            ->assertInertia(fn ($p) => $p->where('mode', 'division')->has('employees', 2)->has('transports', 1));
    }

    public function test_transport_mode_without_a_transport_is_empty(): void
    {
        [$manager, $qa] = $this->setUpFarm();

        $this->actingAs($manager)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'transport']))
            ->assertInertia(fn ($p) => $p->where('mode', 'transport')->has('employees', 0));
    }

    public function test_enterprise_scoped_user_only_sees_own_division_riders(): void
    {
        [, $qa, , $van, $a] = $this->setUpFarm();
        $scoped = User::factory()->create(['role' => 'data_entry', 'farm_id' => $qa->enterprise->farm_id, 'enterprise_id' => $qa->enterprise_id]);

        $this->actingAs($scoped)->get(route('pointage.grid', ['quinzaine' => $qa->id, 'mode' => 'transport', 'transport' => $van->id]))
            ->assertInertia(fn ($p) => $p->has('employees', 1)->where('employees.0.id', $a->id));
    }
}
