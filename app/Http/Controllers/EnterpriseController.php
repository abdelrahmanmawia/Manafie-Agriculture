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
use Illuminate\Http\Request;
use Inertia\Inertia;

class EnterpriseController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * Division-level mutations (create/edit a division, open/close its pay periods, manage its
     * operations/blocs) are for that division's own farm_manager (or a super_admin working
     * within that farm) only — never data_entry, and never another farm's manager.
     */
    private function assertEnterpriseManagerAccess(Request $request, int $farmId): void
    {
        $user = $request->user();
        abort_if($user->role === 'data_entry', 403);

        if ($user->role === 'super_admin') {
            abort_unless((int) session('active_farm_id') === $farmId, 403);
            return;
        }

        abort_unless($user->farm_id === $farmId, 403);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $farmId = session('active_farm_id');

        if ($user->role === 'super_admin') {
            if ($farmId) {
                $farm = Farm::findOrFail($farmId);

                return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                    'farm' => $farm,
                    'stats' => [
                        'employees_count' => Employee::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))->count(),
                        'open_quinzaines' => Quinzaine::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))->where('is_closed', false)->count(),
                    ],
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
                    'stats' => ['employees_count' => 0, 'open_quinzaines' => 0],
                    'error' => 'Aucune ferme ne vous est assignée.'
                ]);
            }
            $farm = \App\Models\Farm::findOrFail($user->farm_id);
            return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                'farm' => $farm,
                'stats' => [
                    'employees_count' => Employee::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))->count(),
                    'open_quinzaines' => Quinzaine::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))->where('is_closed', false)->count(),
                ]
            ]));
        }

        // Data Entry or other roles
        if ($user->role === 'data_entry' && !$user->enterprise_id && $user->farm_id) {
            $farm = \App\Models\Farm::findOrFail($user->farm_id);
            return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                'farm' => $farm,
                'stats' => [
                    'employees_count' => Employee::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))->count(),
                    'open_quinzaines' => Quinzaine::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))->where('is_closed', false)->count(),
                ]
            ]));
        }

        if (!$user->enterprise_id) {
            return Inertia::render('Admin/Dashboard', [
                'enterprise' => null,
                'stats' => [
                    'employees_count' => 0,
                    'open_quinzaines' => 0,
                ],
                'error' => 'Aucune division ne vous est assignée.'
            ]);
        }

        $enterprise = Enterprise::findOrFail($user->enterprise_id);
        
        return Inertia::render('Admin/Dashboard', [
            'enterprise' => $enterprise,
            'stats' => [
                'employees_count' => Employee::where('enterprise_id', $enterprise->id)->count(),
                'open_quinzaines' => Quinzaine::where('enterprise_id', $enterprise->id)->where('is_closed', false)->count(),
            ]
        ]);
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
        ]);

        $this->assertEnterpriseManagerAccess($request, (int) $request->farm_id);

        Enterprise::create([
            'farm_id' => $request->farm_id,
            'name' => $request->name,
            'contract_type' => $request->contract_type,
            'default_brut_rate' => $request->default_brut_rate,
            'invoiced_to_client' => $request->boolean('invoiced_to_client'),
            'settings' => ['currency' => 'DH']
        ]);

        return redirect()->back()->with('success', 'Enterprise created successfully.');
    }

    public function settings(Request $request)
    {
        if ($request->user()->role === 'data_entry') {
            abort(403);
        }

        $enterpriseId = $request->user()->enterprise_id ?? $request->query('enterprise_id');
        
        if (!$enterpriseId) {
            // If farm manager but no enterprise selected, they can't manage enterprise settings yet
            if ($request->user()->role === 'farm_manager') {
                return redirect()->route('dashboard')->with('error', 'Veuillez choisir une division pour gérer ses paramètres.');
            }
            return redirect()->route('dashboard');
        }

        $enterprise = Enterprise::findOrFail($enterpriseId);

        // Security: Ensure farm manager only accesses their own farm's enterprises
        if ($request->user()->role === 'farm_manager' && $enterprise->farm_id != $request->user()->farm_id) {
            abort(403);
        }

        $quinzaines = Quinzaine::where('enterprise_id', $enterprise->id)->latest()->get();

        return Inertia::render('Admin/Settings', [
            'enterprise' => $enterprise,
            'quinzaines' => $quinzaines,
        ]);
    }

    public function update(Request $request, Enterprise $enterprise)
    {
        $this->assertEnterpriseManagerAccess($request, $enterprise->farm_id);

        $request->validate([
            'name' => 'required|string|max:255',
            'default_brut_rate' => 'required|numeric',
            'contract_type' => 'required|in:avec_contrat,sans_contrat',
            'invoiced_to_client' => 'nullable|boolean',
        ]);

        $enterprise->update([
            'name' => $request->name,
            'default_brut_rate' => $request->default_brut_rate,
            'contract_type' => $request->contract_type,
            'invoiced_to_client' => $request->boolean('invoiced_to_client'),
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
        $this->assertEnterpriseManagerAccess($request, $enterprise->farm_id);

        // Employees are farm-scoped but still reference a division; the FK cascades on
        // enterprise delete, so detach first to keep the worker records.
        Employee::where('enterprise_id', $enterprise->id)->update(['enterprise_id' => null]);

        try {
            $enterprise->delete();
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
        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise->farm_id);

        // A second close (e.g. a double-click, or this route ever getting hit again on an
        // already-closed period) must not silently regenerate the snapshot — its numbers are
        // supposed to be historically frozen the moment the quinzaine first closes, and
        // generateSnapshot() partly depends on live state (employee complement) that could
        // have changed since.
        if ($quinzaine->is_closed) {
            return redirect()->back()->with('success', 'Quinzaine closed successfully.');
        }

        $quinzaine->update(['is_closed' => true]);

        // Generate Snapshot for performance
        $this->payrollService->generateSnapshot($quinzaine);

        return redirect()->back()->with('success', 'Quinzaine closed successfully.');
    }

    public function addOperation(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255', 'abbreviation' => 'nullable|string|max:50', 'enterprise_id' => 'required']);
        $enterprise = Enterprise::findOrFail($request->enterprise_id);
        $this->assertEnterpriseManagerAccess($request, $enterprise->farm_id);

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
        $this->assertEnterpriseManagerAccess($request, $enterprise->farm_id);

        Bloc::create([
            'name' => $request->name,
            'farm_id' => $enterprise->farm_id
        ]);
        return redirect()->back();
    }

    public function createQuinzaine(Request $request)
    {
        $request->validate([
            'enterprise_id' => 'required',
            'label' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $enterprise = Enterprise::findOrFail($request->enterprise_id);
        $this->assertEnterpriseManagerAccess($request, $enterprise->farm_id);

        // Guard against a double-submit opening two overlapping pay periods for the same division.
        // start_date/end_date are stored with a time component (e.g. "2026-02-01 00:00:00"), so a
        // plain string match against the submitted date-only value would never hit — whereDate()
        // compares the date part only.
        $duplicate = Quinzaine::where('enterprise_id', $request->enterprise_id)
            ->whereDate('start_date', $request->start_date)
            ->whereDate('end_date', $request->end_date)
            ->exists();
        abort_if($duplicate, 422, 'Une période existe déjà pour ces dates dans cette division.');

        Quinzaine::create([
            'enterprise_id' => $request->enterprise_id,
            'label' => $request->label,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_closed' => false
        ]);

        return redirect()->back();
    }

    public function updateQuinzaine(Request $request, Quinzaine $quinzaine)
    {
        abort_if($quinzaine->is_closed, 403, 'Cannot edit a closed quinzaine.');

        $request->validate([
            'label' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise->farm_id);

        // Check for duplicate dates (excluding current quinzaine)
        $duplicate = Quinzaine::where('enterprise_id', $quinzaine->enterprise_id)
            ->where('id', '!=', $quinzaine->id)
            ->whereDate('start_date', $request->start_date)
            ->whereDate('end_date', $request->end_date)
            ->exists();
        abort_if($duplicate, 422, 'Une période existe déjà pour ces dates dans cette division.');

        // Check if there are pointage records that would fall outside the new date range
        $hasRecordsOutsideRange = PointageRecord::where('quinzaine_id', $quinzaine->id)
            ->where(function ($query) use ($request) {
                $query->whereDate('date', '<', $request->start_date)
                      ->orWhereDate('date', '>', $request->end_date);
            })
            ->exists();

        if ($hasRecordsOutsideRange) {
            abort(422, 'Impossible de modifier les dates : il existe des enregistrements de pointage en dehors de la nouvelle période. Veuillez supprimer ces enregistrements ou choisir une période qui les inclut.');
        }

        $quinzaine->update([
            'label' => $request->label,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        return redirect()->back()->with('success', 'Quinzaine updated successfully.');
    }

    public function deleteQuinzaine(Request $request, Quinzaine $quinzaine)
    {
        abort_if($quinzaine->is_closed, 403, 'Cannot delete a closed quinzaine.');

        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise->farm_id);

        $quinzaine->delete();

        return redirect()->back()->with('success', 'Quinzaine deleted successfully.');
    }

    public function deleteOperation(Request $request, Operation $operation)
    {
        $this->assertEnterpriseManagerAccess($request, $operation->farm_id);

        $operation->delete();
        return redirect()->back();
    }

    public function deleteBloc(Request $request, Bloc $bloc)
    {
        $this->assertEnterpriseManagerAccess($request, $bloc->farm_id);

        $bloc->delete();
        return redirect()->back();
    }
}
