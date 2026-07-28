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

    public function index(Request $request)
    {
        $user = $request->user();
        $farmId = session('active_farm_id');

        if ($user->role === 'super_admin') {
            if ($farmId) {
                $farm = Farm::with(['enterprises' => function($q) {
                    $q->withCount(['employees', 'quinzaines']);
                }])->findOrFail($farmId);

                return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                    'farm' => $farm,
                    'enterprises' => $farm->enterprises,
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
                    'enterprises' => [],
                    'stats' => ['employees_count' => 0, 'open_quinzaines' => 0],
                    'error' => 'Aucune ferme ne vous est assignée.'
                ]);
            }
            $farm = \App\Models\Farm::with('enterprises')->findOrFail($user->farm_id);
            return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                'farm' => $farm,
                'enterprises' => Enterprise::where('farm_id', $farm->id)->withCount(['employees', 'quinzaines'])->get(),
                'stats' => [
                    'employees_count' => Employee::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))->count(),
                    'open_quinzaines' => Quinzaine::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))->where('is_closed', false)->count(),
                ]
            ]));
        }

        // Data Entry or other roles
        if ($user->role === 'data_entry' && !$user->enterprise_id && $user->farm_id) {
            $farm = \App\Models\Farm::with('enterprises')->findOrFail($user->farm_id);
            return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                'farm' => $farm,
                'enterprises' => Enterprise::where('farm_id', $farm->id)->withCount(['employees', 'quinzaines'])->get(),
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
     * Pointage + Stock data merged onto the farm-level Accueil dashboard: a payroll cost
     * trend (last 6 quinzaine periods across every enterprise in the farm — Stock has no
     * enterprise concept, so this view is deliberately farm-wide, not per-division), harvest
     * totals over that same window, and the Stock dashboard's own KPIs (see
     * StockController::summaryFor()).
     */
    private function farmDashboardExtras(Farm $farm): array
    {
        // Quinzaines for the same (start_date, label) period exist once per enterprise, so
        // merge them into one point per period before summing — otherwise the farm-wide trend
        // would show duplicate/fragmented points instead of one combined total per period.
        $farmQuinzaines = Quinzaine::whereHas('enterprise', fn($q) => $q->where('farm_id', $farm->id))
            ->orderByDesc('start_date')
            ->get(['id', 'start_date', 'label']);

        $payrollTrend = $farmQuinzaines
            ->groupBy(fn ($q) => $q->start_date . '_' . $q->label)
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
            'stockSummary' => StockController::summaryFor($farm->id),
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'farm_id' => 'required|exists:farms,id',
            'name' => 'required|string|max:255',
            'contract_type' => 'required|in:avec_contrat,sans_contrat',
            'default_brut_rate' => 'required|numeric',
        ]);
        
        Enterprise::create([
            'farm_id' => $request->farm_id,
            'name' => $request->name,
            'contract_type' => $request->contract_type,
            'default_brut_rate' => $request->default_brut_rate,
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
        $request->validate([
            'name' => 'required|string|max:255',
            'default_brut_rate' => 'required|numeric',
            'contract_type' => 'required|in:avec_contrat,sans_contrat',
        ]);

        $enterprise->update([
            'name' => $request->name,
            'default_brut_rate' => $request->default_brut_rate,
            'contract_type' => $request->contract_type,
        ]);

        return redirect()->back()->with('success', 'Division mise à jour.');
    }

    public function closeQuinzaine(Quinzaine $quinzaine)
    {
        $quinzaine->update(['is_closed' => true]);
        
        // Generate Snapshot for performance
        $this->payrollService->generateSnapshot($quinzaine);

        return redirect()->back()->with('success', 'Quinzaine closed successfully.');
    }

    public function addOperation(Request $request)
    {
        $request->validate(['name' => 'required|string|max:255', 'abbreviation' => 'nullable|string|max:50', 'enterprise_id' => 'required']);
        $enterprise = Enterprise::findOrFail($request->enterprise_id);
        
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

        Quinzaine::create([
            'enterprise_id' => $request->enterprise_id,
            'label' => $request->label,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_closed' => false
        ]);

        return redirect()->back();
    }

    public function deleteOperation(Operation $operation)
    {
        $operation->delete();
        return redirect()->back();
    }

    public function deleteBloc(Bloc $bloc)
    {
        $bloc->delete();
        return redirect()->back();
    }
}
