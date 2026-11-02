<?php

namespace Tests\Feature;

use App\Exports\PointageExport;
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
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** A worker of another site, managed from the worker form; what a closed period keeps of them. */
class SiteSepareFormTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;

    private User $pointeur;

    private Enterprise $interim;

    private Enterprise $direct;

    private Quinzaine $seconde;

    private Employee $ouvrier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00');

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->pointeur = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'acces_pointage' => 'saisie']);
        $this->interim = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'INTERIM', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => true]);
        $this->direct = Enterprise::create(['farm_id' => $this->farm->id, 'name' => 'DIRECT', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44, 'invoiced_to_client' => false]);
        $this->seconde = Quinzaine::create(['enterprise_id' => $this->interim->id, 'label' => 'Q', 'start_date' => '2026-10-16', 'end_date' => '2026-10-31', 'is_closed' => false]);
        $this->ouvrier = $this->worker('001');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function worker(string $m, array $extra = []): Employee
    {
        return Employee::create($extra + ['farm_id' => $this->farm->id, 'enterprise_id' => $this->interim->id, 'matricule' => $m, 'full_name' => "W{$m}", 'base_rate' => 97.44, 'complement' => 0, 'is_active' => true]);
    }

    private function jour(Employee $e, Quinzaine $q, string $date): void
    {
        $op = Operation::firstOrCreate(['farm_id' => $this->farm->id, 'name' => 'Irrigation'], ['abbreviation' => 'Irr']);
        $bloc = Bloc::firstOrCreate(['farm_id' => $this->farm->id, 'name' => 'B1']);
        PointageRecord::create(['employee_id' => $e->id, 'quinzaine_id' => $q->id, 'date' => $date, 'present' => true, 'operation_id' => $op->id, 'bloc_id' => $bloc->id, 'hours' => 0, 'rate' => 97.44, 'brut' => 97.44, 'net' => 90.87]);
    }

    /** What the form sends for $e, with $changes. */
    private function enregistrer(Employee $e, array $changes)
    {
        return $this->actingAs($this->pointeur)->put(route('employees.update', $e), $changes + [
            'full_name' => $e->full_name, 'base_rate' => $e->base_rate, 'complement' => $e->complement, 'enterprise_id' => $e->enterprise_id,
            'salaire_mensuel' => $e->salaire_mensuel, 'site_separe' => $e->site_separe, 'salaire_brut_mensuel' => $e->salaire_brut_mensuel,
        ]);
    }

    private function site(): array
    {
        return ['site_separe' => ' assilah ', 'salaire_mensuel' => 3500, 'salaire_brut_mensuel' => 3422.72];
    }

    private function grille(Quinzaine $q): array
    {
        $props = [];
        $this->actingAs($this->pointeur)->get(route('pointage.grid', $q))->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return [collect($props['employees'])->pluck('id')->all(), $props['autresSites']];
    }

    /** The fiche's LA SOMME and whether it has the site's table. */
    private function fiche(Quinzaine $q): array
    {
        $path = tempnam(sys_get_temp_dir(), 'site');
        file_put_contents($path, Excel::raw(new PointageExport($this->interim->fresh(), $q->start_date, $q->end_date, $q->fresh()), ExcelType::XLSX));
        $ws = IOFactory::load($path)->getActiveSheet();
        unlink($path);
        $table = false;
        foreach ($ws->getRowIterator() as $row) {
            $table = $table || $ws->getCell('A'.$row->getRowIndex())->getValue() === 'ASSILAH';
        }

        return [round((float) $ws->getCell('C3')->getCalculatedValue(), 2), $table];
    }

    public function test_the_form_makes_a_worker_one_of_another_site_and_back(): void
    {
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'T', 'chef_employee_id' => $this->ouvrier->id, 'is_active' => true]);
        $this->ouvrier->update(['equipe_id' => $equipe->id]);

        $this->enregistrer($this->ouvrier, $this->site())->assertSessionHasNoErrors();

        $w = $this->ouvrier->fresh();
        $this->assertSame('ASSILAH', $w->site_separe);
        $this->assertEquals(3422.72, $w->salaire_brut_mensuel);
        $this->assertNull($w->equipe_id);
        $this->assertNull($equipe->fresh()->chef_employee_id);
        // Paid in the open second quinzaine, apart from the Grid's rows, on the fiche's own table.
        $this->assertEquals(3500, PointageRecord::where('employee_id', $w->id)->where('nature', PointageRecord::NATURE_SALAIRE)->sole()->net);
        [$lignes, $sites] = $this->grille($this->seconde);
        $this->assertNotContains($w->id, $lignes);
        $this->assertSame('ASSILAH', $sites[0]['site']);
        $this->assertTrue($this->fiche($this->seconde)[1]);

        // Back to a worker of this farm: the site and its gross go, they're a Grid row again.
        $this->enregistrer($w, ['site_separe' => '', 'salaire_mensuel' => '', 'salaire_brut_mensuel' => 3422.72])->assertSessionHasNoErrors();
        $w = $w->fresh();
        $this->assertNull($w->site_separe);
        $this->assertNull($w->salaire_brut_mensuel);
        $this->assertNull($w->salaire_mensuel);
        $this->assertSame(0, PointageRecord::where('employee_id', $w->id)->count());
        [$lignes, $sites] = $this->grille($this->seconde);
        $this->assertContains($w->id, $lignes);
        $this->assertSame([], $sites);
    }

    public function test_saving_with_the_division_as_the_forms_string_keeps_the_salary(): void
    {
        $this->enregistrer($this->ouvrier, $this->site() + ['enterprise_id' => (string) $this->interim->id])->assertSessionHasNoErrors();

        $this->assertEquals(3500, PointageRecord::where('employee_id', $this->ouvrier->id)->where('nature', PointageRecord::NATURE_SALAIRE)->sole()->net);
    }

    public function test_a_site_needs_its_salary_and_gross(): void
    {
        $this->enregistrer($this->ouvrier, ['site_separe' => 'ASSILAH', 'salaire_mensuel' => '', 'salaire_brut_mensuel' => ''])
            ->assertSessionHasErrors(['salaire_mensuel', 'salaire_brut_mensuel']);
        $this->assertNull($this->ouvrier->fresh()->site_separe);
    }

    public function test_a_site_is_refused_outside_an_invoiced_division(): void
    {
        $this->enregistrer($this->ouvrier, $this->site() + ['enterprise_id' => $this->direct->id])->assertSessionHasErrors('site_separe');
        $this->assertNull($this->ouvrier->fresh()->site_separe);

        // Moving one of them there is refused the same way.
        $yahya = $this->worker('002', ['site_separe' => 'ASSILAH', 'salaire_mensuel' => 3500, 'salaire_brut_mensuel' => 3422.72]);
        $this->enregistrer($yahya, ['enterprise_id' => $this->direct->id])->assertSessionHasErrors('site_separe');
        $this->assertSame($this->interim->id, $yahya->fresh()->enterprise_id);
    }

    public function test_a_worker_with_days_in_an_open_period_cant_become_one(): void
    {
        $this->jour($this->ouvrier, $this->seconde, '2026-10-20');

        $this->enregistrer($this->ouvrier, $this->site())->assertSessionHasErrors('site_separe');
        $this->assertNull($this->ouvrier->fresh()->site_separe);

        // Days of a closed period are no obstacle.
        $this->seconde->update(['is_closed' => true]);
        $this->enregistrer($this->ouvrier, $this->site())->assertSessionHasNoErrors();
        $this->assertSame('ASSILAH', $this->ouvrier->fresh()->site_separe);
    }

    public function test_a_new_worker_can_be_one_from_the_start(): void
    {
        $this->actingAs($this->pointeur)->post(route('employees.store'), ['full_name' => 'NOUVEAU SITE', 'base_rate' => 97.44, 'enterprise_id' => $this->interim->id] + $this->site())
            ->assertSessionHasNoErrors();

        $w = Employee::where('full_name', 'NOUVEAU SITE')->sole();
        $this->assertSame('ASSILAH', $w->site_separe);
        $this->assertEquals(3500, PointageRecord::where('employee_id', $w->id)->where('nature', PointageRecord::NATURE_SALAIRE)->sole()->net);

        // Without a site, the form's hidden salary doesn't make a salaried worker.
        $this->actingAs($this->pointeur)->post(route('employees.store'), ['full_name' => 'ORDINAIRE', 'base_rate' => 97.44, 'enterprise_id' => $this->interim->id, 'site_separe' => '', 'salaire_mensuel' => 3500, 'salaire_brut_mensuel' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull(Employee::where('full_name', 'ORDINAIRE')->sole()->salaire_mensuel);
    }

    public function test_they_stay_out_of_the_equipes(): void
    {
        $yahya = $this->worker('002', ['site_separe' => 'ASSILAH', 'salaire_mensuel' => 3500, 'salaire_brut_mensuel' => 3422.72]);
        $equipe = Equipe::create(['farm_id' => $this->farm->id, 'name' => 'T', 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farm->id, 'acces_pointage' => 'saisie']);

        $this->actingAs($manager)->get(route('equipes.index'))->assertInertia(fn (Assert $page) => $page
            ->where('employees', fn ($list) => collect($list)->pluck('id')->doesntContain($yahya->id)));

        $this->actingAs($manager)->put(route('equipes.members', $equipe), ['employee_ids' => [$yahya->id]])->assertSessionHasErrors('employee_ids.0');
        $this->assertNull($yahya->fresh()->equipe_id);
    }

    public function test_a_closed_period_keeps_them_as_they_stood(): void
    {
        $this->jour($this->ouvrier, $this->seconde, '2026-10-20');
        $yahya = $this->worker('002');
        $this->enregistrer($yahya, $this->site())->assertSessionHasNoErrors();
        $this->actingAs($this->pointeur)->post(route('settings.quinzaine.close', $this->seconde))->assertSessionHasNoErrors();
        $yahya = $yahya->fresh();

        [$somme, $table] = $this->fiche($this->seconde);
        $this->assertTrue($table);
        $ttcAvant = $this->grille($this->seconde)[1][0]['ttc'];

        // A new salary and gross, in the farm's data only: the closed fiche doesn't follow.
        Employee::whereKey($yahya->id)->update(['salaire_mensuel' => 5000, 'salaire_brut_mensuel' => 4800]);
        $this->assertSame([$somme, true], $this->fiche($this->seconde));
        [$lignes, $sites] = $this->grille($this->seconde);
        $this->assertEquals(3500, $sites[0]['salaire']);
        $this->assertEqualsWithDelta($ttcAvant, $sites[0]['ttc'], 0.01);
        $this->assertNotContains($yahya->id, $lignes);

        // Nor when they stop being one of another site.
        Employee::whereKey($yahya->id)->update(['site_separe' => null, 'salaire_brut_mensuel' => null]);
        $this->assertSame([$somme, true], $this->fiche($this->seconde));
        $this->assertSame('ASSILAH', $this->grille($this->seconde)[1][0]['site']);

        // And a worker who becomes one afterwards keeps their row in that closed period.
        Employee::whereKey($this->ouvrier->id)->update(['site_separe' => 'AUTRE', 'salaire_mensuel' => 3000, 'salaire_brut_mensuel' => 2900]);
        $this->assertSame([$somme, true], $this->fiche($this->seconde));
        $this->assertContains($this->ouvrier->id, $this->grille($this->seconde)[0]);
    }
}
