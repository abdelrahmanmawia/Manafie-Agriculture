<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Harvest;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\TransportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EnterpriseController extends Controller
{
    protected $payrollService;
    protected $transportService;

    public function __construct(PayrollService $payrollService, TransportService $transportService)
    {
        $this->payrollService = $payrollService;
        $this->transportService = $transportService;
    }

    /**
     * Day-to-day Pointage-domain operations within an EXISTING division (open/close/edit pay
     * periods, add/remove its operations/blocs) — that division's own farm_manager (or a
     * super_admin working within that farm), or a data_entry granted Pointage access
     * (canAccessPointage()) — never a stock-only data_entry, never another farm's manager, and
     * never a sibling division for a user locked to one (Controller::assertEnterpriseInScope();
     * this used to check the farm only, so such a user could create or close another
     * division's quinzaines).
     */
    private function assertEnterpriseManagerAccess(Request $request, Enterprise $enterprise): void
    {
        abort_unless($request->user()->canAccessPointage(), 403);
        $this->assertEnterpriseInScope($request, $enterprise);
    }

    /**
     * Operations and blocs are farm-wide (every division points against them), so deleting one
     * is checked against the farm, not a division.
     */
    private function assertFarmPointageAccess(Request $request, int $farmId): void
    {
        abort_unless($request->user()->canAccessPointage(), 403);
        $this->assertFarmScope($request, $farmId);
    }

    /**
     * Creating/renaming/deleting a division itself — including its contract_type, which
     * branches PayrollService's whole formula — is a structural decision, not routine pointage
     * work. Stays farm_manager/super_admin-only regardless of a data_entry's domain flags.
     */
    private function assertEnterpriseStructuralAccess(Request $request, int $farmId): void
    {
        abort_if($request->user()->role === 'data_entry', 403);
        $this->assertFarmScope($request, $farmId);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $farmId = session('active_farm_id');

        // A data_entry granted exactly one domain (magasinier or pointeur) has no real use for
        // the Hub — send them straight into the zone they actually work in. One with both (or
        // neither) flag still lands on the normal Hub below.
        if ($user->role === 'data_entry' && $user->farm_id) {
            if ($user->canAccessPointage() && ! $user->canAccessStock()) {
                return redirect()->route('pointage.index');
            }
            if ($user->canAccessStock() && ! $user->canAccessPointage()) {
                return redirect()->route('stock.dashboard');
            }
        }

        if ($user->role === 'super_admin') {
            if ($farmId) {
                $farm = Farm::findOrFail($farmId);

                return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                    'farm' => $farm,
                    'stats' => $this->farmStats($farm),
                    'isSuperAdmin' => true
                ]));
            }

            return Inertia::render('Admin/SuperDashboard', [
                'farms' => \App\Models\Farm::withCount('enterprises')->get(),
            ]);
        }

        if ($user->role === 'farm_manager') {
            if (!$user->farm_id) {
                return Inertia::render('Admin/FarmDashboard', [
                    'farm' => null,
                    'stats' => ['employees_count' => 0, 'open_quinzaines' => 0, 'pointes_periode' => 0, 'overdue_quinzaines' => 0],
                    'error' => 'Aucune ferme ne vous est assignée.'
                ]);
            }
            $farm = \App\Models\Farm::findOrFail($user->farm_id);
            return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                'farm' => $farm,
                'stats' => $this->farmStats($farm),
            ]));
        }

        // Data Entry or other roles
        if ($user->role === 'data_entry' && !$user->enterprise_id && $user->farm_id) {
            $farm = \App\Models\Farm::findOrFail($user->farm_id);
            return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                'farm' => $farm,
                'stats' => $this->farmStats($farm),
            ]));
        }

        if (!$user->enterprise_id) {
            return Inertia::render('Admin/Dashboard', [
                'enterprise' => null,
                'stats' => [
                    'employees_count' => 0,
                    'open_quinzaines' => 0,
                    'pointes_periode' => 0,
                    'overdue_quinzaines' => 0,
                ],
                'error' => 'Aucune division ne vous est assignée.'
            ]);
        }

        $enterprise = Enterprise::findOrFail($user->enterprise_id);

        return Inertia::render('Admin/Dashboard', [
            'enterprise' => $enterprise,
            'stats' => ['employees_count' => Employee::where('enterprise_id', $enterprise->id)->count()]
                + $this->periodStats(collect([$enterprise->id])),
        ]);
    }

    /**
     * employees_count and open_quinzaines are the headline numbers; overdue_quinzaines (open
     * periods past their end date) and pointes_periode (workers present in the open periods) are
     * the signals StatCard shows next to them — see Controller::periodStats().
     */
    private function farmStats(Farm $farm): array
    {
        $scopeToFarm = fn($q) => $q->where('farm_id', $farm->id);

        return ['employees_count' => Employee::whereHas('enterprise', $scopeToFarm)->count()]
            + $this->periodStats(Enterprise::where('farm_id', $farm->id)->pluck('id'));
    }

    /**
     * Farm-wide content unique to the Accueil dashboard: a payroll cost trend (last 6 quinzaine
     * periods across every enterprise in the farm — Stock has no enterprise concept, so this
     * view is deliberately farm-wide, not per-division), harvest totals over that same window,
     * and a couple of headline Stock counts (just the numbers, not the full recent-activity
     * lists — those live on Stock's own dashboard so they aren't duplicated here; see
     * StockController::summaryFor()).
     */
    private function farmDashboardExtras(Farm $farm): array
    {
        // Quinzaines for the same (start_date, end_date) period exist once per enterprise, so
        // merge them into one point per period before summing — otherwise the farm-wide trend
        // would show duplicate/fragmented points instead of one combined total per period. Grouped
        // by actual date range rather than the free-text label, which different divisions can
        // enter slightly differently for what is otherwise the same real-world period.
        $farmQuinzaines = Quinzaine::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))
            ->orderByDesc('start_date')
            ->get(['id', 'start_date', 'end_date', 'label']);

        $payrollTrend = $farmQuinzaines
            ->groupBy(fn ($q) => $q->start_date->format('Y-m-d') . '_' . $q->end_date->format('Y-m-d'))
            ->map(function ($group) {
                $totals = PointageRecord::whereIn('quinzaine_id', $group->pluck('id'))
                    ->selectRaw('COALESCE(SUM(net),0) as total_net, COALESCE(SUM(hours),0) as total_hours')
                    ->first();

                return [
                    'start_date' => $group->first()->start_date,
                    'label' => $group->first()->label,
                    'total_net' => (float) $totals->total_net,
                    'cost_per_hour' => $totals->total_hours > 0 ? round($totals->total_net / $totals->total_hours, 2) : 0,
                ];
            })
            ->sortByDesc('start_date')
            ->take(6)
            ->sortBy('start_date')
            ->values();

        // Harvest totals over the same window as the trend above (all-time if there are no
        // quinzaines yet).
        $periodStart = $payrollTrend->first()['start_date'] ?? null;
        $harvestQuery = Harvest::where('farm_id', $farm->id)
            ->when($periodStart, fn ($q) => $q->where('date', '>=', $periodStart));

        return [
            'payrollTrend' => $payrollTrend,
            'harvestSummary' => [
                'total_kg' => (float) (clone $harvestQuery)->sum('quantity_kg'),
                'total_revenue' => (float) (clone $harvestQuery)->sum('total_revenue_dh'),
            ],
            'stockStats' => StockController::summaryFor($farm->id)['stats'],
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'farm_id' => 'required|exists:farms,id',
            'name' => 'required|string|max:255',
            'contract_type' => 'required|in:avec_contrat,sans_contrat',
            'default_brut_rate' => 'required|numeric',
            'invoiced_to_client' => 'nullable|boolean',
            'mo_declaree' => 'nullable|boolean',
        ]);

        $this->assertEnterpriseStructuralAccess($request, (int) $request->farm_id);

        Enterprise::create([
            'farm_id' => $request->farm_id,
            'name' => $request->name,
            'contract_type' => $request->contract_type,
            'default_brut_rate' => $request->default_brut_rate,
            'invoiced_to_client' => $request->boolean('invoiced_to_client'),
            'mo_declaree' => $request->boolean('mo_declaree', true),
            'settings' => ['currency' => 'DH']
        ]);

        return redirect()->back()->with('success', 'Division créée.');
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()->canAccessPointage(), 403);

        $enterpriseId = $request->user()->enterprise_id ?? $request->query('enterprise_id');
        
        if (!$enterpriseId) {
            // If farm manager but no enterprise selected, they can't manage enterprise settings yet
            if ($request->user()->role === 'farm_manager') {
                return redirect()->route('dashboard')->with('error', 'Veuillez choisir une division pour gérer ses paramètres.');
            }
            return redirect()->route('dashboard');
        }

        $enterprise = Enterprise::findOrFail($enterpriseId);

        // Ensure farm_manager only accesses their own farm's enterprises, and super_admin only
        // the farm they've activated in session — the ?enterprise_id= query param otherwise
        // lets either read/manage any other farm's division settings.
        $this->assertEnterpriseManagerAccess($request, $enterprise);

        $quinzaines = Quinzaine::where('enterprise_id', $enterprise->id)->latest()->get();

        return Inertia::render('Admin/Settings', [
            'enterprise' => $enterprise,
            'quinzaines' => $quinzaines,
        ]);
    }

    public function update(Request $request, Enterprise $enterprise)
    {
        $this->assertEnterpriseStructuralAccess($request, $enterprise->farm_id);

        $request->validate([
            'name' => 'required|string|max:255',
            'default_brut_rate' => 'required|numeric',
            'contract_type' => 'required|in:avec_contrat,sans_contrat',
            'invoiced_to_client' => 'nullable|boolean',
            'mo_declaree' => 'nullable|boolean',
        ]);

        $enterprise->update([
            'name' => $request->name,
            'default_brut_rate' => $request->default_brut_rate,
            'contract_type' => $request->contract_type,
            'invoiced_to_client' => $request->boolean('invoiced_to_client'),
            'mo_declaree' => $request->boolean('mo_declaree', true),
        ]);

        // Keep already-entered pointage in still-open quinzaines in sync with the new rate/contract
        // type — otherwise their stored net/brut stay priced at whatever was in effect when each
        // cell was entered, silently diverging from what a payslip would compute for the same day.
        if ($enterprise->wasChanged(['default_brut_rate', 'contract_type'])) {
            $this->payrollService->recalculateOpenRecordsForEnterprise($enterprise);
        }

        return redirect()->back()->with('success', 'Division mise à jour.');
    }

    public function destroy(Request $request, Enterprise $enterprise)
    {
        $this->assertEnterpriseStructuralAccess($request, $enterprise->farm_id);

        // quinzaines cascade on enterprise delete, and pointage_records cascade on quinzaine
        // delete — so deleting a division that has ever been pointed would silently wipe its
        // payroll history, closed periods included. Only an unused division can go.
        $hasHistory = Quinzaine::where('enterprise_id', $enterprise->id)
            ->where(fn ($q) => $q->where('is_closed', true)->orWhereHas('pointageRecords'))
            ->exists();
        if ($hasHistory) {
            return redirect()->back()->with(
                'error',
                'Impossible de supprimer cette division : elle a un historique de pointage.'
            );
        }

        try {
            // One transaction: the detach must roll back if the delete itself is refused,
            // otherwise the employees lose their division while the division stays.
            DB::transaction(function () use ($enterprise) {
                // Employees are farm-scoped but still reference a division; the FK cascades on
                // enterprise delete, so detach first to keep the worker records.
                Employee::where('enterprise_id', $enterprise->id)->update(['enterprise_id' => null]);

                $enterprise->delete();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            return redirect()->back()->with(
                'error',
                'Impossible de supprimer cette division : des données liées l\'en empêchent.'
            );
        }

        return redirect()->route('dashboard')->with('success', 'Division supprimée.');
    }

    public function closeQuinzaine(Request $request, Quinzaine $quinzaine)
    {
        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise);

        $aPreciser = $this->closeOne($quinzaine);
        if ($aPreciser > 0) {
            return redirect()->back()->withErrors([
                'quinzaine' => "Clôture impossible : {$aPreciser} jour(s) de présence sans opération (« à préciser »). Complétez-les d'abord dans la grille.",
            ]);
        }

        return redirect()->back()->with('success', 'Période clôturée.');
    }

    /**
     * Closes the period for every division the user manages (the quinzaines of the farm with the
     * same dates as $quinzaine) — all or nothing: if any still has days "à préciser", none closes
     * and the page lists them.
     */
    public function closePeriode(Request $request, Quinzaine $quinzaine)
    {
        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise);

        $period = Quinzaine::with('enterprise')
            ->whereHas('enterprise', fn ($q) => $q->where('farm_id', $quinzaine->enterprise->farm_id))
            ->when($request->user()->enterprise_id, fn ($q, $id) => $q->where('enterprise_id', $id))
            ->whereDate('start_date', $quinzaine->start_date)->whereDate('end_date', $quinzaine->end_date)
            ->where('is_closed', false)
            ->get();

        $pending = $period->map(fn ($q) => [$q->enterprise->name, PointageRecord::where('quinzaine_id', $q->id)->where('present', true)->whereNull('operation_id')->count()])
            ->filter(fn ($row) => $row[1] > 0);
        if ($pending->isNotEmpty()) {
            return redirect()->back()->withErrors([
                'quinzaine' => 'Clôture impossible, jours de présence sans opération (« à préciser ») : '
                    .$pending->map(fn ($row) => "{$row[0]} {$row[1]}")->implode(', ').". Complétez-les d'abord dans la grille.",
            ]);
        }

        // Each one closes on its own lock and snapshot, exactly like a single close; a day turned
        // "à préciser" meanwhile leaves that division open, and the page says which.
        $leftOpen = $period->filter(fn ($q) => $this->closeOne($q) > 0)->map(fn ($q) => $q->enterprise->name);
        if ($leftOpen->isNotEmpty()) {
            return redirect()->back()->withErrors(['quinzaine' => 'Restée(s) ouverte(s), des jours « à préciser » y ont été ajoutés : '.$leftOpen->implode(', ').'.']);
        }

        return redirect()->back()->with('success', 'Période clôturée pour '.$period->map(fn ($q) => $q->enterprise->name)->implode(', ').'.');
    }

    /** Closes one quinzaine; returns its number of days "à préciser" (0 = closed, or already was). */
    private function closeOne(Quinzaine $quinzaine): int
    {
        // A second close (e.g. a double-click, or this route ever getting hit again on an
        // already-closed period) must not silently regenerate the snapshot — its numbers are
        // supposed to be historically frozen the moment the quinzaine first closes, and
        // generateSnapshot() partly depends on live state (employee complement) that could
        // have changed since.
        //
        // The check, the close and both snapshots run in one transaction with the quinzaine row
        // locked FOR UPDATE: two concurrent closes can't both see "open", a snapshot failure rolls
        // the close back instead of leaving a closed period with no summary, and in-flight cell
        // writes (which share-lock the same row, see PointageController::writeWhileOpen()) finish
        // before the snapshot reads the records.
        return DB::transaction(function () use ($quinzaine) {
            $quinzaine = Quinzaine::lockForUpdate()->findOrFail($quinzaine->id);
            if ($quinzaine->is_closed) {
                return 0;
            }

            // A day marked present at the équipe step must have its operation before the period
            // is frozen: the snapshot's bloc/operation costs and the Excel fiche need it.
            $aPreciser = PointageRecord::where('quinzaine_id', $quinzaine->id)->where('present', true)->whereNull('operation_id')->count();
            if ($aPreciser > 0) {
                return $aPreciser;
            }

            $quinzaine->update(['is_closed' => true]);

            // Generate Snapshot for performance
            $this->payrollService->generateSnapshot($quinzaine);
            $this->transportService->generateSnapshot($quinzaine);

            return 0;
        });
    }

    public function addOperation(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255', 'abbreviation' => 'nullable|string|max:50', 'enterprise_id' => 'required']);
        $enterprise = Enterprise::findOrFail($request->enterprise_id);
        $this->assertEnterpriseManagerAccess($request, $enterprise);

        Operation::create([
            'name' => $request->name,
            'abbreviation' => $request->abbreviation,
            'farm_id' => $enterprise->farm_id
        ]);
        return redirect()->back();
    }

    public function addBloc(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255', 'enterprise_id' => 'required']);
        $enterprise = Enterprise::findOrFail($request->enterprise_id);
        $this->assertEnterpriseManagerAccess($request, $enterprise);

        Bloc::create([
            'name' => $request->name,
            'farm_id' => $enterprise->farm_id
        ]);
        return redirect()->back();
    }

    /**
     * Opens a pay period — for every division picked at once (enterprise_ids), the farm's usual
     * case, or a single one (enterprise_id). A division that already has these dates is skipped;
     * if all of them do, nothing is created and the page says so.
     */
    public function createQuinzaine(Request $request)
    {
        $request->validate([
            'enterprise_ids' => 'required_without:enterprise_id|array|min:1',
            'enterprise_ids.*' => 'integer',
            'enterprise_id' => 'required_without:enterprise_ids',
            'label' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $enterprises = collect($request->input('enterprise_ids', [$request->enterprise_id]))->unique()
            ->map(fn ($id) => Enterprise::findOrFail($id));
        $enterprises->each(fn ($enterprise) => $this->assertEnterpriseManagerAccess($request, $enterprise));

        [$opened, $skipped] = DB::transaction(function () use ($request, $enterprises) {
            $opened = [];
            $skipped = [];
            foreach ($enterprises as $enterprise) {
                // Guard against a double-submit opening two overlapping pay periods for the same
                // division. start_date/end_date are stored with a time component (e.g. "2026-02-01
                // 00:00:00"), so whereDate() compares the date part only.
                $duplicate = Quinzaine::where('enterprise_id', $enterprise->id)
                    ->whereDate('start_date', $request->start_date)
                    ->whereDate('end_date', $request->end_date)
                    ->exists();
                if ($duplicate) {
                    $skipped[] = $enterprise->name;

                    continue;
                }

                $quinzaine = Quinzaine::create([
                    'enterprise_id' => $enterprise->id,
                    'label' => $request->label,
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                    'is_closed' => false,
                ]);
                // A month's second quinzaine pays the division's fixed monthly salaries.
                app(\App\Services\SalaireFixeService::class)->syncQuinzaine($quinzaine->fresh());
                $opened[] = $enterprise->name;
            }

            return [$opened, $skipped];
        });

        if (! $opened) {
            return redirect()->back()->withErrors(['quinzaine' => 'Une période existe déjà pour ces dates : '.implode(', ', $skipped).'.']);
        }

        return redirect()->back()->with('success', 'Période ouverte pour '.implode(', ', $opened).'.'
            .($skipped ? ' Déjà ouverte : '.implode(', ', $skipped).'.' : ''));
    }

    public function updateQuinzaine(Request $request, Quinzaine $quinzaine)
    {
        abort_if($quinzaine->is_closed, 403, 'Cannot edit a closed quinzaine.');

        $request->validate([
            'label' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise);

        // Check for duplicate dates (excluding current quinzaine)
        $duplicate = Quinzaine::where('enterprise_id', $quinzaine->enterprise_id)
            ->where('id', '!=', $quinzaine->id)
            ->whereDate('start_date', $request->start_date)
            ->whereDate('end_date', $request->end_date)
            ->exists();
        if ($duplicate) {
            return redirect()->back()->withErrors(['start_date' => 'Une période existe déjà pour ces dates dans cette division.']);
        }

        // Check if there are pointage records that would fall outside the new date range
        $hasRecordsOutsideRange = PointageRecord::where('quinzaine_id', $quinzaine->id)
            ->where(function ($query) use ($request) {
                $query->whereDate('date', '<', $request->start_date)
                      ->orWhereDate('date', '>', $request->end_date);
            })
            ->exists();

        if ($hasRecordsOutsideRange) {
            return redirect()->back()->withErrors(['start_date' => 'Des jours sont déjà pointés en dehors de ces dates : choisissez une période qui les inclut.']);
        }

        $quinzaine->update([
            'label' => $request->label,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        return redirect()->back()->with('success', 'Période modifiée.');
    }

    public function deleteQuinzaine(Request $request, Quinzaine $quinzaine)
    {
        abort_if($quinzaine->is_closed, 403, 'Cannot delete a closed quinzaine.');

        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise);

        $quinzaine->delete();

        return redirect()->back()->with('success', 'Période supprimée.');
    }

    public function deleteOperation(Request $request, Operation $operation)
    {
        $this->assertFarmPointageAccess($request, $operation->farm_id);

        // Same reasoning as destroy() above: an operation still referenced by pointage/stock
        // records (restrict FKs, see migration 000042) would otherwise throw an uncaught QueryException
        // straight to a raw error page instead of a friendly message.
        try {
            $operation->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            return redirect()->back()->with(
                'error',
                'Impossible de supprimer cette opération : des données liées l\'en empêchent.'
            );
        }

        return redirect()->back()->with('success', 'Opération supprimée.');
    }

    public function deleteBloc(Request $request, Bloc $bloc)
    {
        $this->assertFarmPointageAccess($request, $bloc->farm_id);

        try {
            $bloc->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            return redirect()->back()->with(
                'error',
                'Impossible de supprimer ce bloc : des données liées l\'en empêchent.'
            );
        }

        return redirect()->back()->with('success', 'Bloc supprimé.');
    }
}
