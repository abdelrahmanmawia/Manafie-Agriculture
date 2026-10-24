<?php

namespace App\Http\Controllers;

use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\Employee;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Parcelle;
use App\Services\JourFerieService;
use App\Services\PayrollService;
use App\Services\RepartitionService;
use App\Services\TransportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\PointageExport;
use App\Exports\DivisionsPointageExport; // Add this import
use App\Exports\PaiementEquipesExport;

class PointageController extends Controller
{
    protected $payrollService;

    protected $transportService;

    public function __construct(PayrollService $payrollService, TransportService $transportService)
    {
        $this->payrollService = $payrollService;
        $this->transportService = $transportService;
    }

    public function export(Request $request, $quinzaineId)
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request, $quinzaine);

        $cleanLabel = str_replace([' ', '/', '\\'], '_', $quinzaine->label ?: 'Pointage');
        $fileName = $cleanLabel . '.xlsx';

        return Excel::download(new PointageExport($quinzaine->enterprise, $quinzaine->start_date, $quinzaine->end_date, $quinzaine), $fileName);
    }

    // New method for exporting all divisions
    public function exportAllDivisions(Request $request, Quinzaine $quinzaine)
    {
        // Unlike export() (one division's own quinzaine), this pulls payroll data for every
        // division on the farm at once — assertQuinzaineInScope() below only confirms the
        // requester can see the ONE quinzaine passed in, which let a division-locked data_entry
        // export every other division's payroll by passing their own quinzaine id. Anyone who
        // sees the whole farm is fine (assertQuinzaineInScope() bounds them to it); Pointage
        // access itself is enforced by the route group.
        abort_if($request->user()->enterprise_id, 403);

        $quinzaine->loadMissing('enterprise');
        $this->assertQuinzaineInScope($request, $quinzaine);

        $cleanLabel = str_replace([' ', '/', '\\'], '_', $quinzaine->label ?: 'Pointage');
        $fileName = 'All_Divisions_' . $cleanLabel . '.xlsx';

        return Excel::download(new DivisionsPointageExport($quinzaine), $fileName);
    }

    /**
     * "Paiement par équipe" for the quinzaine's dates: every division's paid workers grouped by
     * équipe (PaiementEquipesExport). A division-locked user gets only their own division's.
     */
    public function exportPaiementEquipes(Request $request, Quinzaine $quinzaine)
    {
        $quinzaine->loadMissing('enterprise');
        $this->assertQuinzaineInScope($request, $quinzaine);

        $cleanLabel = str_replace([' ', '/', '\\'], '_', $quinzaine->label ?: 'Pointage');

        return Excel::download(new PaiementEquipesExport($quinzaine, $request->user()->enterprise_id), 'Paiement_par_equipe_'.$cleanLabel.'.xlsx');
    }

    /**
     * /pointage — the zone's landing page: division management (create a division, see its
     * workers/periods, jump into its quinzaine list) plus a farm-wide totals row. This is the
     * one place that owns the "Divisions de la Ferme" grid — it used to be duplicated onto the
     * Accueil/FarmDashboard page as well, which now only links here instead of repeating it.
     * The actual quinzaine list/management lives at quinzaines() below.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();

        $withCounts = [
            'employees',
            'quinzaines',
            'quinzaines as open_quinzaines_count' => fn ($q) => $q->where('is_closed', false),
        ];

        // The farm's divisions — or, for a user locked to one division, just that one (still
        // rendered as a one-card grid, for consistency); only farm-wide users get the farm
        // header with its create-division action.
        $farmId = $this->scopedFarmId($request);
        $enterprises = \App\Models\Enterprise::where('farm_id', $farmId)
            ->when($user->enterprise_id, fn ($q, $enterpriseId) => $q->where('id', $enterpriseId))
            ->withCount($withCounts)
            ->get();
        if ($user->enterprise_id) {
            $farmId = null;
        }

        $enterpriseIds = $enterprises->pluck('id');

        return Inertia::render('Pointage/Index', [
            'enterprises' => $enterprises,
            'farm' => $farmId ? Farm::find($farmId, ['id', 'name']) : null,
            'totals' => [
                'employees' => $enterprises->sum('employees_count'),
                'open_quinzaines' => $enterprises->sum('open_quinzaines_count'),
                // Real (not fabricated) trend signals — new hires added to the system in the
                // last 30 days, and open quinzaines whose end_date has already passed.
                'new_employees_30d' => \App\Models\Employee::whereIn('enterprise_id', $enterpriseIds)
                    ->where('created_at', '>=', now()->subDays(30))->count(),
                'overdue_quinzaines' => \App\Models\Quinzaine::whereIn('enterprise_id', $enterpriseIds)
                    ->where('is_closed', false)->where('end_date', '<', now()->toDateString())->count(),
            ],
        ]);
    }

    public function quinzaines(Request $request)
    {
        // Farm first, then the division: the user's own if they're locked to one, else the
        // ?enterprise_id= filter (validated to be in the farm). That filter used to be ignored
        // for a farm_manager, whose page shows it all the same.
        $farmId = $this->scopedFarmId($request);
        $enterpriseId = $this->scopedEnterpriseId($request, $farmId);

        $quinzaines = Quinzaine::with('enterprise')
            ->whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))
            ->when($enterpriseId, fn ($q) => $q->where('enterprise_id', $enterpriseId))
            ->orderByDesc('start_date')
            ->get();

        // Each division's progress in the period: who and how much is pointed, and how many
        // present days still wait for their operation ("à préciser" — closing refuses them).
        $stats = PointageRecord::whereIn('quinzaine_id', $quinzaines->pluck('id'))
            ->selectRaw('quinzaine_id, COUNT(DISTINCT employee_id) as ouvriers, SUM(net) as net')
            ->selectRaw('SUM(CASE WHEN present = ? AND nature = ? THEN 1 ELSE 0 END) as jours', [true, PointageRecord::NATURE_JOUR])
            ->selectRaw('SUM(CASE WHEN present = ? AND nature = ? AND operation_id IS NULL THEN 1 ELSE 0 END) as a_preciser', [true, PointageRecord::NATURE_JOUR])
            ->groupBy('quinzaine_id')
            ->get()->keyBy('quinzaine_id');
        $quinzaines->each(fn ($q) => $q->setAttribute('stats', [
            'ouvriers' => (int) ($stats[$q->id]->ouvriers ?? 0),
            'jours' => (int) ($stats[$q->id]->jours ?? 0),
            'net' => round((float) ($stats[$q->id]->net ?? 0), 2),
            'a_preciser' => (int) ($stats[$q->id]->a_preciser ?? 0),
        ]));

        return Inertia::render('Pointage/Quinzaines', [
            'quinzaines' => $quinzaines,
            'enterprises' => $this->selectableEnterprises($request),
        ]);
    }

    public function grid(Request $request, $quinzaineId)
    {
        $user = $request->user();
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request, $quinzaine);

        $enterpriseId = $quinzaine->enterprise_id;
        $farmId = $quinzaine->enterprise->farm_id;

        // Two ways to pick who appears in the grid:
        //  - division (default): the quinzaine's own enterprise, as always;
        //  - equipe: one chef d'équipe's team — or "none", the workers in no équipe — whatever
        //    their division. This is the farm's first pointage step (the chefs' sheets); each
        //    person is pointed against THEIR OWN division's quinzaine for the same dates, so
        //    payroll/closing stay per division (see the per-employee quinzaine_id below).
        $mode = $request->query('mode') === 'equipe' ? 'equipe' : 'division';
        $equipeId = $request->query('equipe');
        $equipes = Equipe::with('chef:id,full_name')
            ->where('farm_id', $farmId)->where('is_active', true)
            ->withCount(['employees' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')->get(['id', 'name', 'chef_employee_id']);

        if ($mode === 'equipe') {
            // No (or an unknown) équipe chosen yet → an empty grid that asks the user to pick one.
            $equipe = $equipeId === 'none' ? null : $equipes->firstWhere('id', (int) $equipeId);
            $employees = ($equipeId === 'none' || $equipe)
                ? Employee::with('enterprise')->where('farm_id', $farmId)->where('is_active', true)
                    // "Sans équipe" also lists the members of a deactivated équipe, so nobody drops out.
                    ->when($equipe, fn ($q) => $q->where('equipe_id', $equipe->id), fn ($q) => $q->where(fn ($q) => $q->whereNull('equipe_id')
                        ->orWhereIn('equipe_id', Equipe::where('farm_id', $farmId)->where('is_active', false)->select('id'))))
                    // An enterprise-scoped user never sees other divisions' people, same as elsewhere.
                    ->when($user->enterprise_id, fn ($q) => $q->where('enterprise_id', $user->enterprise_id))
                    ->orderBy('full_name')->get()
                    // The chef's own line first, like on the paper sheet.
                    ->sortByDesc(fn ($e) => $equipe && $e->id === $equipe->chef_employee_id)->values()
                : new \Illuminate\Database\Eloquent\Collection();
            $sameDates = Quinzaine::whereIn('enterprise_id', $employees->pluck('enterprise_id')->unique())
                ->whereDate('start_date', $quinzaine->start_date)->whereDate('end_date', $quinzaine->end_date)
                ->get()->keyBy('enterprise_id');
        } else {
            $mode = 'division';
            // Plus anyone with days in this quinzaine who has since left the division (or been
            // deactivated): their days stay here, shown read-only (writes require the division).
            $employees = Employee::where(fn ($q) => $q->where('enterprise_id', $enterpriseId)->where('is_active', true))
                ->orWhereIn('id', PointageRecord::where('quinzaine_id', $quinzaine->id)->select('employee_id'))
                ->get();
            $sameDates = collect([$enterpriseId => $quinzaine]);
        }
        $employees->each(function ($e) use ($sameDates) {
            $q = $sameDates->get($e->enterprise_id);
            $e->setAttribute('quinzaine_id', $q?->id);
            $e->setAttribute('quinzaine_closed', $q ? (bool) $q->is_closed : true);
        });
        $quinzaineIds = $sameDates->pluck('id')->all();
        $operations = Operation::where('farm_id', $quinzaine->enterprise->farm_id)->get();
        $blocs = Bloc::where('farm_id', $quinzaine->enterprise->farm_id)->get();

        // Generate the 15-16 days period
        $period = CarbonPeriod::create($quinzaine->start_date, $quinzaine->end_date);
        $days = [];
        foreach ($period as $date) {
            $days[] = $date->format('Y-m-d');
        }

        // Fetch existing records for this quinzaine, filtering to only those within the date range
        // A fixed monthly salary is a record too (SalaireFixeService) but not a day: it's left out
        // of the day cells and passed apart, for the TOTAL NET column.
        $salairesFixes = PointageRecord::whereIn('quinzaine_id', $quinzaineIds)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->where('nature', PointageRecord::NATURE_SALAIRE)
            ->pluck('net', 'employee_id');

        $records = PointageRecord::whereIn('quinzaine_id', $quinzaineIds)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->where('nature', PointageRecord::NATURE_JOUR)
            ->whereDate('date', '>=', $quinzaine->start_date)
            ->whereDate('date', '<=', $quinzaine->end_date)
            ->get()
            ->groupBy(['employee_id', function ($item) {
                return $item->date->format('Y-m-d');
            }]);

        return Inertia::render('Pointage/Grid', [
            'quinzaine' => $quinzaine,
            'employees' => $employees,
            'operations' => $operations,
            'blocs' => $blocs,
            'days' => $days,
            'existingRecords' => $records,
            'mode' => $mode,
            'equipeId' => $mode === 'equipe' && $equipeId ? ($equipeId === 'none' ? 'none' : (int) $equipeId) : null,
            'equipes' => $equipes,
            'salairesFixes' => $salairesFixes,
        ]);
    }

    public function updateCell(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'quinzaine_id' => 'required|exists:quinzaines,id',
            'operation_id' => 'nullable|exists:operations,id',
            'bloc_id' => 'nullable|exists:blocs,id',
            'date' => 'required|date',
            'hours' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|numeric|min:0',
            'is_jf' => 'nullable|boolean',
            // Present, operation still to be entered ("à préciser") — the équipe step.
            'present' => 'nullable|boolean',
        ]);

        $validated += ['operation_id' => null, 'bloc_id' => null];

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        $this->assertQuinzaineInScope($request, $quinzaine);

        // employee_id was only checked with exists:employees,id — an employee from a
        // completely different enterprise/farm could otherwise be written into this
        // quinzaine (mismatched data, and a cross-tenant write via an ID the caller
        // isn't actually scoped to).
        $employee = Employee::findOrFail($validated['employee_id']);
        abort_unless($employee->enterprise_id === $quinzaine->enterprise_id, 403);

        // Same reasoning as employee_id above: exists:operations,id / exists:blocs,id only
        // proves the ID exists SOMEWHERE, not that it belongs to this farm — an operation/bloc
        // ID from a different farm would otherwise be writable into this quinzaine's records.
        $farmId = $quinzaine->enterprise->farm_id;
        $operationRecord = null;
        if ($validated['operation_id']) {
            $operationRecord = Operation::findOrFail($validated['operation_id']);
            abort_unless($operationRecord->farm_id === $farmId, 403);
        }
        if ($validated['bloc_id']) {
            $blocRecord = Bloc::findOrFail($validated['bloc_id']);
            abort_unless($blocRecord->farm_id === $farmId, 403);
        }

        if (! $this->dateInQuinzaine($validated['date'], $quinzaine)) {
            return redirect()->back()->withErrors(['date' => 'Cette date est en dehors de la période de la quinzaine.']);
        }

        return $this->writeWhileOpen($quinzaine, fn () => $this->writeCell($validated, $quinzaine, $employee, $operationRecord));
    }

    /**
     * "Tous présents" of the équipe Grid: marks the given workers present that day, operation
     * still to be entered (RepartitionService::markPresent()). Each lands in their own division's
     * quinzaine for the same dates; a division-locked user only reaches their own division.
     */
    public function markPresence(Request $request, RepartitionService $repartition)
    {
        $validated = $request->validate([
            'quinzaine_id' => 'required|exists:quinzaines,id',
            'date' => 'required|date',
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        $this->assertQuinzaineInScope($request, $quinzaine);
        if (! $this->dateInQuinzaine($validated['date'], $quinzaine)) {
            return redirect()->back()->withErrors(['date' => 'Cette date est en dehors de la période de la quinzaine.']);
        }

        $employees = Employee::whereIn('id', $validated['employee_ids'])
            ->where('farm_id', $quinzaine->enterprise->farm_id)
            ->where('is_active', true)
            ->when($request->user()->enterprise_id, fn ($q) => $q->where('enterprise_id', $request->user()->enterprise_id))
            ->get();

        $added = $repartition->markPresent($quinzaine, Carbon::parse($validated['date'])->format('Y-m-d'), $employees);

        return redirect()->back()->with('success', "{$added} ouvrier(s) marqué(s) présent(s).");
    }

    /**
     * Enters a division's operations for one day by headcount ("B1 Irrigation 4") instead of
     * worker by worker; RepartitionService::repartir() assigns the day's "à préciser" workers.
     */
    public function repartir(Request $request, RepartitionService $repartition)
    {
        $validated = $request->validate([
            'quinzaine_id' => 'required|exists:quinzaines,id',
            'date' => 'required|date',
            'lignes' => 'required|array|min:1',
            'lignes.*.bloc_id' => 'required|exists:blocs,id',
            'lignes.*.operation_id' => 'required|exists:operations,id',
            'lignes.*.effectif' => 'required|integer|min:0',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        $this->assertQuinzaineInScope($request, $quinzaine);
        if (! $this->dateInQuinzaine($validated['date'], $quinzaine)) {
            return redirect()->back()->withErrors(['date' => 'Cette date est en dehors de la période de la quinzaine.']);
        }

        $farmId = $quinzaine->enterprise->farm_id;
        $blocs = Bloc::where('farm_id', $farmId)->whereIn('id', array_column($validated['lignes'], 'bloc_id'))->get()->keyBy('id');
        $operations = Operation::where('farm_id', $farmId)->whereIn('id', array_column($validated['lignes'], 'operation_id'))->get()->keyBy('id');

        $lignes = [];
        foreach ($validated['lignes'] as $ligne) {
            $bloc = $blocs->get($ligne['bloc_id']);
            $operation = $operations->get($ligne['operation_id']);
            abort_unless($bloc && $operation, 403);
            if ($operation->unit_rate) {
                return redirect()->back()->withErrors(['repartition' => "{$operation->name} est payée à la quantité : saisissez-la ouvrier par ouvrier."]);
            }
            $lignes[] = $ligne + ['label' => "{$bloc->name} · {$operation->name}"];
        }

        return $this->writeWhileOpen($quinzaine, function () use ($repartition, $quinzaine, $validated, $lignes) {
            [$assigned, $left] = $repartition->repartir($quinzaine, Carbon::parse($validated['date'])->format('Y-m-d'), $lignes);

            return redirect()->back()->with('success', "{$assigned} ouvrier(s) affecté(s)".($left ? ", {$left} encore à préciser." : '.'));
        });
    }

    /**
     * Records dated outside their quinzaine are invisible in the Grid/summary (both filter to
     * start..end) but still counted by generateSnapshot() and payroll history — pay nobody can
     * see or correct. Only a date inside the period may be written.
     */
    private function dateInQuinzaine(string $date, Quinzaine $quinzaine): bool
    {
        $day = Carbon::parse($date)->startOfDay();

        return $day->betweenIncluded($quinzaine->start_date->copy()->startOfDay(), $quinzaine->end_date->copy()->startOfDay());
    }

    /**
     * Runs a pointage write with the quinzaine row share-locked and is_closed re-read under that
     * lock. EnterpriseController::closeQuinzaine() takes the same row FOR UPDATE, so a close waits
     * for in-flight cell writes and any write arriving after it sees the period closed — before,
     * a save landing mid-close could slip into a "frozen" period after its snapshot was taken.
     * Shared (not exclusive) so concurrent cell writes on the same quinzaine don't serialize.
     */
    private function writeWhileOpen(Quinzaine $quinzaine, \Closure $write)
    {
        return DB::transaction(function () use ($quinzaine, $write) {
            if (Quinzaine::whereKey($quinzaine->id)->sharedLock()->value('is_closed')) {
                return redirect()->back()->withErrors(['date' => 'Cette période est clôturée et ne peut plus être modifiée.']);
            }

            return $write();
        });
    }

    private function writeCell(array $validated, Quinzaine $quinzaine, Employee $employee, ?Operation $operationRecord)
    {
        $hasOperation = $validated['operation_id'] && $validated['bloc_id'];
        $present = $hasOperation || ! empty($validated['present']);

        // Not present, and not a paid holiday either -> plain absence, no pay: delete the
        // record. If is_jf IS set though, this is a public holiday the employee did NOT work —
        // still owed one day's pay, just never the worked+JF double (see PayrollService::
        // calculate()'s $worked param) — so it's saved as a record instead of deleted.
        if (! $present && empty($validated['is_jf'])) {
            PointageRecord::where('employee_id', $validated['employee_id'])
                ->where('quinzaine_id', $validated['quinzaine_id'])
                ->where('nature', PointageRecord::NATURE_JOUR)
                ->whereDate('date', $validated['date'])
                ->delete();
            return redirect()->back();
        }

        $identity = [
            'employee_id' => $validated['employee_id'],
            'quinzaine_id' => $validated['quinzaine_id'],
            'date' => Carbon::parse($validated['date'])->format('Y-m-d'),
        ];

        // No operation: either present with the operation still "à préciser" (paid as a worked
        // day — the operation doesn't change a day's pay — H.S./J.F. included), or the unworked
        // paid holiday (one NET/J, never anything else).
        if (! $hasOperation) {
            $hs = $present ? ($validated['hours'] ?? 0) : 0;
            $isJf = $present ? (bool) ($validated['is_jf'] ?? false) : true;
            $calc = $this->payrollService->calculate(
                $quinzaine->enterprise->contract_type,
                $quinzaine->enterprise->default_brut_rate,
                $hs,
                $employee->complement,
                $isJf,
                $quinzaine->enterprise->invoiced_to_client,
                $present
            );

            $this->upsertPointageRecord($identity, [
                'present' => $present,
                'operation_id' => null,
                'bloc_id' => null,
                'hours' => $hs,
                'quantity' => null,
                'is_jf' => $isJf,
                'rate' => $quinzaine->enterprise->default_brut_rate,
                'brut' => $calc['brut'],
                'net' => $calc['total_net'],
            ]);

            return redirect()->back();
        }

        $operation = $operationRecord;
        $quantity = $validated['quantity'] ?? null;

        if ($operation->unit_rate && $quantity > 0) {
            // Piece-rate: pay = quantity * the operation's own rate, independent of the
            // enterprise's rate/employee's complement — no HS/JF concept for piece-rate work.
            $this->upsertPointageRecord($identity, [
                'present' => true,
                'operation_id' => $validated['operation_id'],
                'bloc_id' => $validated['bloc_id'],
                'hours' => 0,
                'quantity' => $quantity,
                'is_jf' => false,
                'rate' => $operation->unit_rate,
                'brut' => $operation->unit_rate,
                'net' => $quantity * $operation->unit_rate,
            ]);

            return redirect()->back();
        }

        $hs = $validated['hours'] ?? 0;
        $isJf = $validated['is_jf'] ?? false;

        $calc = $this->payrollService->calculate(
            $quinzaine->enterprise->contract_type,
            $quinzaine->enterprise->default_brut_rate,
            $hs,
            $employee->complement,
            $isJf,
            $quinzaine->enterprise->invoiced_to_client
        );

        $this->upsertPointageRecord($identity, [
            'present' => true,
            'operation_id' => $validated['operation_id'],
            'bloc_id' => $validated['bloc_id'],
            'hours' => $hs,
            'quantity' => null,
            'is_jf' => $isJf,
            'rate' => $quinzaine->enterprise->default_brut_rate,
            'brut' => $calc['brut'],
            'net' => $calc['total_net'],
        ]);

        return back();
    }

    /**
     * Single point of write for one employee/quinzaine/date cell — replaces the old
     * delete-then-create pattern, which was two separate non-atomic statements. A double
     * submission of the same save (e.g. a double-click on "Enregistrer") could have its two
     * requests interleave between those statements and each end up inserting its own row for
     * the same day instead of the second cleanly replacing the first — confirmed as the exact
     * cause of a real duplicate-record bug (two 2QZ Août 2026 employees each had an extra
     * `pointage_records` row for the same date, double-counting that day's net). Look-up-then-
     * write closes almost all of that window; the unique index on
     * (employee_id, quinzaine_id, date) is the real backstop — it turns the rare remaining race
     * into a caught constraint violation instead of a silent duplicate, so the catch below just
     * retries as a plain update.
     *
     * The look-up uses whereDate(), not updateOrCreate()'s plain `date = 'Y-m-d'`: Eloquent's
     * date cast stores 'Y-m-d 00:00:00', which a string compare (SQLite) never matches — the
     * create then hit the unique index and the fallback update matched nothing, silently
     * dropping the edit. Same reasoning as PointageScanController::sync()'s own look-up.
     */
    private function upsertPointageRecord(array $identity, array $values): void
    {
        // Every write through here is a manual Grid entry: clearing scan_uuid marks the record
        // as hand-entered, which PointageScanController::sync() then never overwrites (a scan
        // would otherwise reset the H.S./JF typed in here).
        $values['scan_uuid'] = null;

        $existing = fn () => PointageRecord::where('employee_id', $identity['employee_id'])
            ->where('quinzaine_id', $identity['quinzaine_id'])
            ->where('nature', PointageRecord::NATURE_JOUR)
            ->whereDate('date', $identity['date']);

        try {
            DB::transaction(function () use ($existing, $identity, $values) {
                $record = $existing()->first();
                // The day keeps the vehicle it was first entered with (TransportService): moving
                // the worker to another équipe later doesn't move this day to the new vehicle.
                if (! $record || $record->transport_vehicle_id === null) {
                    $values['transport_vehicle_id'] = $this->transportService->riderVehicleId($identity['employee_id']);
                }
                $record ? $record->update($values) : PointageRecord::create($identity + $values);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $existing()->update($values);
        }
    }

    /**
     * "Coller sur les jours restants" — applies one already-entered day's Operation+Bloc+H.S. to
     * several other dates for the same employee in a single request, for the common case of an
     * ouvrier doing the same job (and clocking the same overtime) every day of the quinzaine.
     * Deliberately narrower than updateCell(): no piece-rate (a copied quantity would be
     * meaningless — each day's quantity is real work, not something to duplicate), no JF — that
     * still goes through the single-cell modal so it's entered deliberately, not accidentally
     * propagated. Also supports clearing days when operation_id and bloc_id are null.
     */
    public function updateCellBulk(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'quinzaine_id' => 'required|exists:quinzaines,id',
            'operation_id' => 'nullable|exists:operations,id',
            'bloc_id' => 'nullable|exists:blocs,id',
            'dates' => 'required|array|min:1',
            'dates.*' => 'date',
            'hours' => 'nullable|numeric|min:0',
            'is_jf' => 'nullable|boolean',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        $this->assertQuinzaineInScope($request, $quinzaine);

        $employee = Employee::findOrFail($validated['employee_id']);
        abort_unless($employee->enterprise_id === $quinzaine->enterprise_id, 403);

        // Same reasoning as updateCell(): exists:operations,id / exists:blocs,id only proves
        // the ID exists somewhere, not that it belongs to this farm.
        $farmId = $quinzaine->enterprise->farm_id;
        if ($validated['operation_id']) {
            abort_unless(Operation::where('id', $validated['operation_id'])->where('farm_id', $farmId)->exists(), 403);
        }
        if ($validated['bloc_id']) {
            abort_unless(Bloc::where('id', $validated['bloc_id'])->where('farm_id', $farmId)->exists(), 403);
        }

        foreach ($validated['dates'] as $date) {
            if (! $this->dateInQuinzaine($date, $quinzaine)) {
                return redirect()->back()->withErrors(['date' => 'Cette date est en dehors de la période de la quinzaine.']);
            }
        }

        return $this->writeWhileOpen($quinzaine, fn () => $this->writeCellBulk($validated, $quinzaine, $employee));
    }

    private function writeCellBulk(array $validated, Quinzaine $quinzaine, Employee $employee)
    {
        // If operation_id is null, this is a clear operation - delete all records for the dates
        if (!$validated['operation_id'] || !$validated['bloc_id']) {
            foreach ($validated['dates'] as $date) {
                PointageRecord::where('employee_id', $employee->id)
                    ->where('quinzaine_id', $quinzaine->id)
                    ->where('nature', PointageRecord::NATURE_JOUR)
                    ->whereDate('date', Carbon::parse($date)->format('Y-m-d'))
                    ->delete();
            }
            return back();
        }

        $operation = Operation::find($validated['operation_id']);
        abort_if($operation->unit_rate, 422, 'Impossible de coller une opération à la quantité sur plusieurs jours — chaque jour a sa propre quantité.');

        $hs = $validated['hours'] ?? 0;

        $calc = $this->payrollService->calculate(
            $quinzaine->enterprise->contract_type,
            $quinzaine->enterprise->default_brut_rate,
            $hs,
            $employee->complement,
            false,
            $quinzaine->enterprise->invoiced_to_client
        );

        foreach ($validated['dates'] as $date) {
            $this->upsertPointageRecord(
                [
                    'employee_id' => $employee->id,
                    'quinzaine_id' => $quinzaine->id,
                    'date' => Carbon::parse($date)->format('Y-m-d'),
                ],
                [
                    'present' => true,
                    'operation_id' => $validated['operation_id'],
                    'bloc_id' => $validated['bloc_id'],
                    'hours' => $hs,
                    'quantity' => null,
                    'is_jf' => false,
                    'rate' => $quinzaine->enterprise->default_brut_rate,
                    'brut' => $calc['brut'],
                    'net' => $calc['total_net'],
                ]
            );
        }

        return redirect()->back();
    }

    /**
     * "Jour férié" on a Grid day header: marks the holiday for every open division of the farm
     * covering that date (only the user's own division if they're locked to one). Who is owed
     * what is JourFerieService's business; the result per division is flashed back for the
     * Grid's summary modal.
     */
    public function markJourFerie(Request $request, JourFerieService $jourFerie)
    {
        return $this->applyJourFerie($request, fn ($farmId, $enterpriseId, $date) => $jourFerie->mark($farmId, $enterpriseId, $date), 'mark');
    }

    public function unmarkJourFerie(Request $request, JourFerieService $jourFerie)
    {
        return $this->applyJourFerie($request, fn ($farmId, $enterpriseId, $date) => $jourFerie->unmark($farmId, $enterpriseId, $date), 'unmark');
    }

    private function applyJourFerie(Request $request, \Closure $apply, string $action)
    {
        $validated = $request->validate([
            'quinzaine_id' => 'required|exists:quinzaines,id',
            'date' => 'required|date',
        ]);

        $quinzaine = Quinzaine::with('enterprise')->findOrFail($validated['quinzaine_id']);
        $this->assertQuinzaineInScope($request, $quinzaine);

        if ($quinzaine->is_closed) {
            return redirect()->back()->withErrors(['date' => 'Cette période est clôturée et ne peut plus être modifiée.']);
        }
        if (! $this->dateInQuinzaine($validated['date'], $quinzaine)) {
            return redirect()->back()->withErrors(['date' => 'Cette date est en dehors de la période de la quinzaine.']);
        }

        $date = Carbon::parse($validated['date']);
        $divisions = $apply($quinzaine->enterprise->farm_id, $request->user()->enterprise_id, $date);

        return redirect()->back()->with('jourFerie', [
            'action' => $action,
            'date' => $date->toDateString(),
            'divisions' => $divisions,
        ]);
    }

    public function summary(Request $request, $quinzaineId)
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request, $quinzaine);

        // Inner-joining operations/blocs would silently drop paid-holiday records that have no
        // operation/bloc (see PointageController::updateCell()) from dailyTotals — left join so
        // their net still counts toward the total, they just can't be placed in the matrix below.
        $records = PointageRecord::where('quinzaine_id', $quinzaineId)
            ->leftJoin('operations', 'pointage_records.operation_id', '=', 'operations.id')
            ->leftJoin('blocs', 'pointage_records.bloc_id', '=', 'blocs.id')
            ->join('employees', 'pointage_records.employee_id', '=', 'employees.id')
            ->select(
                'pointage_records.*',
                'operations.name as op_name',
                'blocs.name as bloc_name',
                'employees.complement'
            )
            ->get();

        $allBlocs = Bloc::where('farm_id', $quinzaine->enterprise->farm_id)->pluck('name')->toArray();
        $allOps = Operation::where('farm_id', $quinzaine->enterprise->farm_id)->pluck('name')->toArray();

        // Generate date range
        $period = \Carbon\CarbonPeriod::create($quinzaine->start_date, $quinzaine->end_date);
        $days = [];
        foreach ($period as $date) { $days[] = $date->format('Y-m-d'); }

        $blocMatrices = [];
        foreach ($allBlocs as $bloc) {
            $blocMatrices[$bloc] = [];
            foreach ($allOps as $op) {
                $blocMatrices[$bloc][$op] = [];
                foreach ($days as $day) {
                    $blocMatrices[$bloc][$op][$day] = 0;
                }
            }
        }

        $dailyTotals = array_fill_keys($days, 0);

        foreach ($records as $record) {
            // Skip records that fall outside the current date range (e.g., after editing quinzaine dates)
            $dateKey = $record->date->format('Y-m-d');
            if (!in_array($dateKey, $days)) {
                continue;
            }

            // Read the record's own stored net directly rather than recomputing via
            // calculate() — piece-rate records (quantity set) were computed from the
            // Operation's unit_rate, not the enterprise's rate/employee's complement, so
            // recomputing here would silently show the wrong total for them.
            // A record with no operation/bloc (unworked paid holiday) has nowhere to sit in the
            // matrix, but its net must still count toward the day's total.
            if ($record->bloc_name !== null && $record->op_name !== null) {
                $blocMatrices[$record->bloc_name][$record->op_name][$dateKey] += $record->net;
            }
            $dailyTotals[$dateKey] += $record->net;
        }

        return response()->json([
            'bloc_matrices' => $blocMatrices,
            'blocs' => $allBlocs,
            'operations' => $allOps,
            'days' => $days,
            'daily_totals' => $dailyTotals
        ]);
    }
}
