<?php

namespace App\Http\Controllers;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\QuinzaineSummary;
use App\Models\TransportSnapshot;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\SalaireFixeService;
use App\Services\TransportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
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
     * Creating/renaming/deleting a division (its contract type included) is the pointeur's,
     * like everything in Pointage: these routes sit outside the Pointage group, so they check
     * saisie themselves. A division-locked account can't touch the farm's divisions.
     */
    private function assertEnterpriseStructuralAccess(Request $request, int $farmId): void
    {
        abort_unless($request->user()->peutSaisir('pointage') && ! $request->user()->enterprise_id, 403);
        $this->assertFarmScope($request, $farmId);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $farmId = session('active_farm_id');

        // Someone who works in exactly one domain (the pointeur, the magasinier) has no real use
        // for the Hub — straight into the zone they work in. Everyone else lands on the Hub.
        if ($user->role !== 'super_admin' && $user->farm_id) {
            if ($user->peutConsulter('pointage') && ! $user->peutConsulter('stock')) {
                return redirect()->route('pointage.today');
            }
            if ($user->peutConsulter('stock') && ! $user->peutConsulter('pointage')) {
                return redirect()->route('stock.dashboard');
            }
        }

        if ($user->role === 'super_admin') {
            if ($farmId) {
                $farm = Farm::findOrFail($farmId);

                return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                    'farm' => $farm,
                    'stats' => $this->farmStats($farm),
                    'isSuperAdmin' => true,
                ]));
            }

            return Inertia::render('Admin/SuperDashboard', [
                'farms' => \App\Models\Farm::withCount('enterprises')->get(),
            ]);
        }

        if ($user->role === 'farm_manager') {
            if (! $user->farm_id) {
                return Inertia::render('Admin/FarmDashboard', [
                    'farm' => null,
                    'stats' => ['employees_count' => 0, 'open_quinzaines' => 0, 'pointes_periode' => 0, 'overdue_quinzaines' => 0],
                    'error' => 'Aucune ferme ne vous est assignée.',
                ]);
            }
            $farm = \App\Models\Farm::findOrFail($user->farm_id);

            return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                'farm' => $farm,
                'stats' => $this->farmStats($farm),
            ]));
        }

        // Data Entry or other roles
        if ($user->role === 'data_entry' && ! $user->enterprise_id && $user->farm_id) {
            $farm = \App\Models\Farm::findOrFail($user->farm_id);

            return Inertia::render('Admin/FarmDashboard', array_merge($this->farmDashboardExtras($farm), [
                'farm' => $farm,
                'stats' => $this->farmStats($farm),
            ]));
        }

        if (! $user->enterprise_id) {
            return Inertia::render('Admin/Dashboard', [
                'enterprise' => null,
                'stats' => [
                    'employees_count' => 0,
                    'open_quinzaines' => 0,
                    'pointes_periode' => 0,
                    'overdue_quinzaines' => 0,
                ],
                'error' => 'Aucune division ne vous est assignée.',
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
        $scopeToFarm = fn ($q) => $q->where('farm_id', $farm->id);

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
        $farmQuinzaines = Quinzaine::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farm->id))
            ->orderByDesc('start_date')
            ->get(['id', 'start_date', 'end_date', 'label']);

        $payrollTrend = $farmQuinzaines
            ->groupBy(fn ($q) => $q->start_date->format('Y-m-d').'_'.$q->end_date->format('Y-m-d'))
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
            'pointageDuJour' => $this->pointageDuJour($farm),
        ];
    }

    /**
     * Where the pointage stands, farm-wide: today (who came, who still has no operation) and the
     * open periods (days pointed, net so far, days still "à préciser").
     */
    private function pointageDuJour(Farm $farm): array
    {
        $quinzaineIds = Quinzaine::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farm->id))->pluck('id');
        $openIds = Quinzaine::whereIn('id', $quinzaineIds)->where('is_closed', false)->pluck('id');
        $jours = fn ($ids) => PointageRecord::whereIn('quinzaine_id', $ids)->where('nature', PointageRecord::NATURE_JOUR)->where('present', true);
        // What the open periods cost the farm: TTC of the invoiced divisions, net (+ 4% when not
        // declared) of the direct ones, and their transport (CoutFermeService).
        $periodes = app(\App\Services\CoutFermeService::class)->parPeriode(Quinzaine::with('enterprise')->whereIn('id', $openIds)->get(), $farm->id);
        $cout = collect($periodes);
        // Today's work: the workers on each operation and the blocs it's on, biggest first.
        $operationsJour = (clone $jours($quinzaineIds))->whereDate('date', now()->toDateString())->whereNotNull('operation_id')
            ->with('operation:id,name,abbreviation', 'bloc:id,name')->get(['operation_id', 'bloc_id', 'employee_id'])
            ->groupBy('operation_id')
            ->map(fn ($days) => [
                'operation' => $days->first()->operation->name ?? '',
                'abreviation' => $days->first()->operation->abbreviation ?? null,
                'blocs' => $days->map(fn ($d) => $d->bloc->name ?? null)->filter()->unique()->sort()->values(),
                'effectif' => $days->pluck('employee_id')->unique()->count(),
            ])
            ->sortByDesc('effectif')->values();

        return [
            'presents' => (clone $jours($quinzaineIds))->whereDate('date', now()->toDateString())->distinct()->count('employee_id'),
            'a_preciser_jour' => (clone $jours($quinzaineIds))->whereDate('date', now()->toDateString())->whereNull('operation_id')->count(),
            'operations_jour' => $operationsJour,
            'jours_periode' => (clone $jours($openIds))->count(),
            'net_periode' => round((float) PointageRecord::whereIn('quinzaine_id', $openIds)->sum('net'), 2),
            'cout_periode' => [
                'ttc' => round($cout->sum('ttc'), 2),
                'net_direct' => round($cout->sum('net_direct'), 2),
                'marge' => round($cout->sum('marge'), 2),
                'transport' => round($cout->sum('transport'), 2),
                'externes' => round($cout->sum('externes'), 2),
                'total' => round($cout->sum('total'), 2),
            ],
            'a_preciser_periode' => (clone $jours($openIds))->whereNull('operation_id')->count(),
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
            'settings' => ['currency' => 'DH'],
        ]);

        return redirect()->back()->with('success', 'Division créée.');
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()->canAccessPointage(), 403);

        $enterpriseId = $request->user()->enterprise_id ?? $request->query('enterprise_id');

        if (! $enterpriseId) {
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

        return Inertia::render('Admin/Settings', [
            'enterprise' => $enterprise,
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
        $this->assertTerminee($request, $quinzaine);

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
     * same dates as $quinzaine) — all or nothing, in one transaction: if any still has days "à
     * préciser", or a snapshot fails, none closes and the page lists what's missing.
     */
    public function closePeriode(Request $request, Quinzaine $quinzaine)
    {
        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise);
        $this->assertTerminee($request, $quinzaine);

        $period = Quinzaine::with('enterprise')
            ->whereHas('enterprise', fn ($q) => $q->where('farm_id', $quinzaine->enterprise->farm_id))
            ->when($request->user()->enterprise_id, fn ($q, $id) => $q->where('enterprise_id', $id))
            ->whereDate('start_date', $quinzaine->start_date)->whereDate('end_date', $quinzaine->end_date)
            ->where('is_closed', false)
            ->orderBy('id')
            ->get();

        // Each one closes on its own lock and snapshot, exactly like a single close; thrown from
        // inside the transaction, the refusal rolls back the divisions already closed.
        DB::transaction(function () use ($period) {
            $pending = $period->map(fn ($q) => [$q->enterprise->name, $this->closeOne($q)])->filter(fn ($row) => $row[1] > 0);
            if ($pending->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'quinzaine' => 'Clôture impossible, jours de présence sans opération (« à préciser ») : '
                        .$pending->map(fn ($row) => "{$row[0]} {$row[1]}")->implode(', ').". Complétez-les d'abord dans la grille.",
                ]);
            }
        });

        return redirect()->back()->with('success', 'Période clôturée pour '.$period->map(fn ($q) => $q->enterprise->name)->implode(', ').'.');
    }

    /**
     * Back to open, for a period closed by mistake: only a super_admin, since a close is what
     * the payroll relies on. Its snapshot goes (the next close writes it again), and so does the
     * period's frozen transport, live again until every division is closed once more.
     */
    public function reopenQuinzaine(Request $request, Quinzaine $quinzaine)
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise);

        DB::transaction(function () use ($quinzaine) {
            Quinzaine::lockForUpdate()->findOrFail($quinzaine->id)->update(['is_closed' => false]);
            QuinzaineSummary::where('quinzaine_id', $quinzaine->id)->delete();
            TransportSnapshot::whereIn('quinzaine_id', $this->memePeriode($quinzaine)->pluck('id'))->delete();
        });

        Log::info('Quinzaine rouverte', ['quinzaine' => $quinzaine->id, 'label' => $quinzaine->label, 'division' => $quinzaine->enterprise->name, 'user' => $request->user()->id]);

        return redirect()->back()->with('success', 'Période rouverte pour '.$quinzaine->enterprise->name.'.');
    }

    /** A period not over yet only closes when the request says so: nothing can be pointed after. */
    private function assertTerminee(Request $request, Quinzaine $quinzaine): void
    {
        if ($quinzaine->end_date->gte(today()) && ! $request->boolean('avant_fin')) {
            throw ValidationException::withMessages([
                'quinzaine' => "Cette période n'est pas terminée (fin le {$quinzaine->end_date->format('d/m/Y')}) : confirmez la clôture avant la fin.",
            ]);
        }
    }

    /** The quinzaines of the farm with the same dates: one pay period across its divisions. */
    private function memePeriode(Quinzaine $quinzaine)
    {
        return Quinzaine::with('enterprise')
            ->whereHas('enterprise', fn ($q) => $q->where('farm_id', $quinzaine->enterprise->farm_id))
            ->whereDate('start_date', $quinzaine->start_date)->whereDate('end_date', $quinzaine->end_date)
            ->get();
    }

    /**
     * A division's periods never overlap: each day of a worker's pay has to belong to one.
     * $sauf: the periods being moved themselves. Throws under $champ; an exact duplicate when
     * opening is sorted out before this.
     */
    private function assertDatesLibres(Enterprise $enterprise, string $start, string $end, array $sauf, string $champ): void
    {
        $chevauche = Quinzaine::where('enterprise_id', $enterprise->id)->whereNotIn('id', $sauf)
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')->first();
        if ($chevauche) {
            throw ValidationException::withMessages([$champ => "Ces dates chevauchent une période de {$enterprise->name} : "
                .($chevauche->label ? "« {$chevauche->label} » " : '')."({$chevauche->start_date->format('d/m/Y')} – {$chevauche->end_date->format('d/m/Y')})."]);
        }
    }

    /** The dates of a period being opened or edited: a pay period is 31 days at most. */
    private function validateDates(Request $request): void
    {
        $request->validate([
            'label' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);
        if (Carbon::parse($request->start_date)->diffInDays(Carbon::parse($request->end_date)) >= 31) {
            throw ValidationException::withMessages(['end_date' => 'Une période ne peut pas dépasser 31 jours.']);
        }
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
            'farm_id' => $enterprise->farm_id,
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
            'farm_id' => $enterprise->farm_id,
        ]);

        return redirect()->back();
    }

    /**
     * Opens a pay period — for every division picked at once (enterprise_ids), the farm's usual
     * case, or a single one (enterprise_id). A division that already has these dates is skipped;
     * if all of them do, nothing is created and the page says so. Dates overlapping a division's
     * other period refuse the whole request.
     */
    public function createQuinzaine(Request $request)
    {
        $request->validate([
            'enterprise_ids' => 'required_without:enterprise_id|array|min:1',
            'enterprise_ids.*' => 'integer',
            'enterprise_id' => 'required_without:enterprise_ids',
        ]);
        $this->validateDates($request);

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
                $this->assertDatesLibres($enterprise, $request->start_date, $request->end_date, [], 'quinzaine');

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

        $this->validateDates($request);

        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise);

        $period = $this->memePeriode($quinzaine);
        $locked = $request->user()->enterprise_id;

        if ($quinzaine->start_date->toDateString() === Carbon::parse($request->start_date)->toDateString()
            && $quinzaine->end_date->toDateString() === Carbon::parse($request->end_date)->toDateString()) {
            // The label alone. A division-locked user names only their own line of the period.
            ($locked ? $period->where('enterprise_id', $locked) : $period)->each->update(['label' => $request->label]);

            return redirect()->back()->with('success', 'Période modifiée.');
        }

        // The dates are the period's, across its divisions: moving one alone would split the
        // period in two, each counting the transport of the days they share.
        if ($locked && $period->count() > 1) {
            return redirect()->back()->withErrors(['start_date' => 'Les dates concernent toutes les divisions de la période : seul un compte de toute la ferme peut les changer.']);
        }
        $fermees = $period->where('is_closed', true);
        if ($fermees->isNotEmpty()) {
            return redirect()->back()->withErrors(['start_date' => 'Une division de cette période est déjà clôturée ('.$fermees->map(fn ($q) => $q->enterprise->name)->implode(', ').') : ses dates ne peuvent plus changer.']);
        }

        foreach ($period as $q) {
            $this->assertDatesLibres($q->enterprise, $request->start_date, $request->end_date, $period->pluck('id')->all(), 'start_date');
        }

        // Check if there are pointed days that would fall outside the new date range (a fixed
        // salary's row is no day: it follows the period, below)
        $hasRecordsOutsideRange = PointageRecord::whereIn('quinzaine_id', $period->pluck('id'))
            ->where('nature', PointageRecord::NATURE_JOUR)
            ->where(function ($query) use ($request) {
                $query->whereDate('date', '<', $request->start_date)
                    ->orWhereDate('date', '>', $request->end_date);
            })
            ->exists();

        if ($hasRecordsOutsideRange) {
            return redirect()->back()->withErrors(['start_date' => 'Des jours sont déjà pointés en dehors de ces dates : choisissez une période qui les inclut.']);
        }

        DB::transaction(function () use ($period, $request) {
            foreach ($period as $q) {
                $q->update([
                    'label' => $request->label,
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                ]);
                app(SalaireFixeService::class)->datesChanged($q->fresh());
            }
        });

        return redirect()->back()->with('success', 'Période modifiée pour '.$period->map(fn ($q) => $q->enterprise->name)->implode(', ').'.');
    }

    public function deleteQuinzaine(Request $request, Quinzaine $quinzaine)
    {
        abort_if($quinzaine->is_closed, 403, 'Cannot delete a closed quinzaine.');

        $this->assertEnterpriseManagerAccess($request, $quinzaine->enterprise);

        // Deleting a period cascades its days away in the database, past the change history:
        // only an empty one (opened by mistake) goes. Its automatic fixed-salary rows don't count.
        $jours = PointageRecord::where('quinzaine_id', $quinzaine->id)->where('nature', 'jour')->count();
        if ($jours > 0) {
            return redirect()->back()->withErrors(['quinzaine' => "« {$quinzaine->label} » a déjà {$jours} jour(s) pointé(s) : elle ne peut pas être supprimée. Corrigez ses dates, ou effacez les jours dans la grille."]);
        }

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
