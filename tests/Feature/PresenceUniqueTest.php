<?php

namespace Tests\Feature;

use App\Exports\PaiementEquipesExport;
use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** A worker is present in one division a day — the double pay this used to allow is refused. */
class PresenceUniqueTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private Enterprise $a;

    private Enterprise $b;

    private Quinzaine $qa;

    private Quinzaine $qb;

    private User $manager;

    private Employee $worker;

    private Operation $op;

    private Bloc $bloc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        foreach (['a' => 'PRESTATAIRE', 'b' => 'NON DECLARE'] as $prop => $name) {
            $this->{$prop} = Enterprise::create(['farm_id' => $this->farm->id, 'name' => $name, 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
            $this->{'q'.$prop} = Quinzaine::create(['enterprise_id' => $this->{$prop}->id, 'label' => 'Q', 'start_date' => '2026-11-01', 'end_date' => '2026-11-15', 'is_closed' => false]);
        }
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->op = Operation::create(['farm_id' => $this->farm->id, 'name' => 'Irrigation', 'abbreviation' => 'Irr']);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);

        // Pointed in A on the 2nd, then moved to B.
        $this->worker = Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $this->a->id, 'matricule' => '001', 'full_name' => 'GHANEM MINA', 'base_rate' => 97.44, 'is_active' => true]);
        $this->cell($this->qa, '2026-11-02')->assertSessionHasNoErrors();
        $this->worker->update(['enterprise_id' => $this->b->id]);
    }

    private function cell(Quinzaine $q, string $date, array $data = [])
    {
        return $this->actingAs($this->manager)->post(route('pointage.cell'), $data + [
            'employee_id' => $this->worker->id, 'quinzaine_id' => $q->id, 'date' => $date,
            'operation_id' => $this->op->id, 'bloc_id' => $this->bloc->id,
        ]);
    }

    private function daysInB(): int
    {
        return PointageRecord::where('quinzaine_id', $this->qb->id)->count();
    }

    public function test_a_cell_present_in_another_division_that_day_is_refused(): void
    {
        $this->cell($this->qb, '2026-11-02')->assertSessionHasErrors(['date' => 'GHANEM MINA est déjà pointé(e) présent(e) le 02/11 dans PRESTATAIRE : un ouvrier ne travaille que dans une division par jour.']);
        $this->cell($this->qb, '2026-11-02', ['operation_id' => null, 'bloc_id' => null, 'present' => true])->assertSessionHasErrors('date');
        $this->assertSame(0, $this->daysInB());

        // Another day is fine.
        $this->cell($this->qb, '2026-11-03')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->daysInB());
    }

    public function test_an_unworked_holiday_is_not_a_presence_and_stays_allowed(): void
    {
        $this->cell($this->qb, '2026-11-02', ['operation_id' => null, 'bloc_id' => null, 'is_jf' => true])->assertSessionHasNoErrors();
        $this->assertFalse(PointageRecord::where('quinzaine_id', $this->qb->id)->sole()->present);
    }

    public function test_pasting_on_several_days_is_all_or_nothing(): void
    {
        $this->actingAs($this->manager)->post(route('pointage.cell.bulk'), [
            'employee_id' => $this->worker->id, 'quinzaine_id' => $this->qb->id, 'dates' => ['2026-11-01', '2026-11-02', '2026-11-03'],
            'operation_id' => $this->op->id, 'bloc_id' => $this->bloc->id,
        ])->assertSessionHasErrors('date');

        $this->assertSame(0, $this->daysInB());
    }

    public function test_tous_presents_skips_and_names_who_is_present_elsewhere(): void
    {
        $other = Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $this->b->id, 'matricule' => '002', 'full_name' => 'OTHER', 'base_rate' => 97.44, 'is_active' => true]);

        $this->actingAs($this->manager)->post(route('pointage.presence'), [
            'quinzaine_id' => $this->qb->id, 'date' => '2026-11-02', 'employee_ids' => [$this->worker->id, $other->id],
        ])->assertSessionHas('success', '1 ouvrier(s) marqué(s) présent(s).')
            ->assertSessionHasErrors(['date' => 'Déjà présent(s) ce jour-là dans une autre division, non marqué(s) ici : GHANEM MINA.']);

        $this->assertSame([$other->id], PointageRecord::where('quinzaine_id', $this->qb->id)->pluck('employee_id')->all());
    }

    public function test_the_dashboards_count_a_period_once_for_all_its_divisions(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10));
        $this->actingAs($this->manager)->get(route('pointage.index'))->assertInertia(fn ($p) => $p
            ->where('totals.open_quinzaines', 1)->where('totals.overdue_quinzaines', 0)->where('totals.pointes_periode', 1));

        // Past its end date, the one period (two divisions) is one to close.
        $this->travelTo(now()->setDate(2026, 11, 20));
        $this->actingAs($this->manager)->get(route('dashboard'))->assertInertia(fn ($p) => $p
            ->where('stats.open_quinzaines', 1)->where('stats.overdue_quinzaines', 1));
    }

    public function test_the_team_payment_export_holds_only_that_equipe(): void
    {
        $one = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'ONE']);
        $two = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'TWO']);
        $this->worker->update(['equipe_id' => $one->id]);
        $mate = Employee::create(['farm_id' => $this->farm->id, 'enterprise_id' => $this->a->id, 'matricule' => '003', 'full_name' => 'MATE', 'base_rate' => 97.44, 'is_active' => true, 'equipe_id' => $two->id]);
        PointageRecord::create(['employee_id' => $mate->id, 'quinzaine_id' => $this->qa->id, 'date' => '2026-11-03', 'present' => true, 'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => 100]);

        $path = tempnam(sys_get_temp_dir(), 'paie').'.xlsx';
        file_put_contents($path, Excel::raw(new PaiementEquipesExport($this->qa->load('enterprise'), null, $one->id), ExcelType::XLSX));
        $this->assertSame(['RÉSUMÉ', 'ONE'], IOFactory::load($path)->getSheetNames());

        $this->actingAs($this->manager)->get(route('pointage.paiementEquipes', ['quinzaine' => $this->qa->id, 'equipe' => $two->id]))
            ->assertOk()->assertDownload('Paiement_TWO_Q.xlsx');
        $foreign = Equipe::create(['farm_id' => Farm::create(['name' => 'Other'])->id, 'name' => 'X']);
        $this->actingAs($this->manager)->get(route('pointage.paiementEquipes', ['quinzaine' => $this->qa->id, 'equipe' => $foreign->id]))->assertForbidden();
    }
}
