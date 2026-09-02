<?php

namespace App\Http\Controllers;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PointageScanController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * Plain Blade view, not Inertia — this page must keep working with zero network
     * reachability, which Inertia's page-visit model isn't built for. All of its own data
     * (employees/blocs/operations) is fetched client-side, after auth, via stationData().
     */
    public function stationPage(Request $request)
    {
        return view('pointage.scan-station');
    }

    /**
     * Session-authenticated (called once during the station's one-time online setup, from
     * inside the normal farm.selected group) — mints a Sanctum token the offline page then
     * uses for every subsequent request, so sync doesn't depend on the session cookie
     * surviving a whole day offline. Since API requests carry no PHP session, the active
     * farm is baked into the token's abilities now, while session('active_farm_id') is
     * still available, rather than re-derived later.
     */
    public function issueToken(Request $request)
    {
        $farmId = $this->resolveWriteFarmId($request);

        $token = $request->user()->createToken('scan-station', ["farm:{$farmId}"]);

        return response()->json(['token' => $token->plainTextToken]);
    }

    /**
     * Resolves which farm a Sanctum-authenticated scan-station request is scoped to.
     * farm_manager/data_entry already carry their own farm_id on the User row; a
     * super_admin has none, so their farm was embedded as a "farm:{id}" ability on the
     * token at issueToken() time (no session is available under the api/auth:sanctum stack).
     */
    private function scanFarmId(Request $request): int
    {
        $user = $request->user();
        if ($user->farm_id) {
            return $user->farm_id;
        }

        $token = $user->currentAccessToken();
        foreach ($token->abilities ?? [] as $ability) {
            if (str_starts_with($ability, 'farm:')) {
                return (int) substr($ability, 5);
            }
        }

        abort(403, 'Aucune ferme associée à ce jeton.');
    }

    public function stationData(Request $request)
    {
        $farmId = $this->scanFarmId($request);

        $employees = Employee::where('farm_id', $farmId)
            ->where('is_active', true)
            ->whereNotNull('badge_uuid')
            ->get(['id', 'badge_uuid', 'full_name', 'matricule']);

        $blocs = Bloc::where('farm_id', $farmId)->get(['id', 'name']);

        // Piece-rate operations are out of scope for scanning (a scan alone can't capture a
        // quantity) — those stay manual, entered through the normal Grid by a supervisor.
        $operations = Operation::where('farm_id', $farmId)->whereNull('unit_rate')->get(['id', 'name']);

        return response()->json([
            'employees' => $employees,
            'blocs' => $blocs,
            'operations' => $operations,
        ]);
    }

    /**
     * Accepts a batch of queued scans and, for each, reuses the exact same presence-write
     * logic as PointageController::updateCell (PayrollService::calculate() +
     * PointageRecord::updateOrCreate on ['employee_id','quinzaine_id','date']) — already
     * idempotent, so retrying a partially-failed batch is safe. scanned_at is the only
     * time field trusted from the client; the attendance date is always derived from it
     * server-side so a stale client clock or timezone skew can't disagree with the server
     * about which calendar day is being recorded.
     */
    public function sync(Request $request)
    {
        $farmId = $this->scanFarmId($request);

        $validated = $request->validate([
            'scans' => 'required|array',
            'scans.*.scan_uuid' => 'required|string',
            'scans.*.badge_uuid' => 'required|string',
            'scans.*.scanned_at' => 'required|date',
            'scans.*.bloc_id' => 'required|exists:blocs,id',
            'scans.*.operation_id' => 'required|exists:operations,id',
        ]);

        $results = [];

        foreach ($validated['scans'] as $scan) {
            $employee = Employee::where('badge_uuid', $scan['badge_uuid'])
                ->where('farm_id', $farmId)
                ->first();

            if (!$employee) {
                $results[] = ['scan_uuid' => $scan['scan_uuid'], 'ok' => false, 'reason' => 'Badge inconnu.'];
                continue;
            }

            $date = Carbon::parse($scan['scanned_at'])->timezone(config('app.timezone'))->format('Y-m-d');

            $quinzaine = Quinzaine::with('enterprise')
                ->where('enterprise_id', $employee->enterprise_id)
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->first();

            if (!$quinzaine) {
                $results[] = ['scan_uuid' => $scan['scan_uuid'], 'ok' => false, 'reason' => 'Aucune quinzaine ouverte pour cette date.'];
                continue;
            }

            if ($quinzaine->is_closed) {
                $results[] = ['scan_uuid' => $scan['scan_uuid'], 'ok' => false, 'reason' => 'Cette période est clôturée.'];
                continue;
            }

            $operation = Operation::find($scan['operation_id']);
            if (!$operation || $operation->unit_rate || $operation->farm_id !== $farmId) {
                // Defense in depth: the station's own picker already excludes piece-rate
                // operations, but never trust a possibly-stale cached snapshot on the client.
                // The farm_id check matters more here than in a session-based controller:
                // exists:operations,id alone only proves the ID exists SOMEWHERE, and this
                // endpoint has no session to fall back on if a token/station is compromised.
                $results[] = ['scan_uuid' => $scan['scan_uuid'], 'ok' => false, 'reason' => 'Opération non valide pour le scan.'];
                continue;
            }

            $bloc = Bloc::find($scan['bloc_id']);
            if (!$bloc || $bloc->farm_id !== $farmId) {
                $results[] = ['scan_uuid' => $scan['scan_uuid'], 'ok' => false, 'reason' => 'Bloc non valide pour le scan.'];
                continue;
            }

            $calc = $this->payrollService->calculate(
                $quinzaine->enterprise->contract_type,
                $quinzaine->enterprise->default_brut_rate,
                0,
                $employee->complement,
                false,
                $quinzaine->enterprise->invoiced_to_client
            );

            // Not updateOrCreate(): its lookup compares the raw 'Y-m-d' string against the
            // `date`-cast column, but Eloquent persists that same value with a trailing
            // "00:00:00" — the two never match on a second call, so updateOrCreate silently
            // inserts a duplicate row instead of updating the existing one. whereDate() lets
            // the DB compare just the date part, which is what makes retrying a batch safe.
            $existing = PointageRecord::where('employee_id', $employee->id)
                ->where('quinzaine_id', $quinzaine->id)
                ->whereDate('date', $date)
                ->first();

            $attributes = [
                'operation_id' => $scan['operation_id'],
                'bloc_id' => $scan['bloc_id'],
                'hours' => 0,
                'quantity' => null,
                'is_jf' => false,
                'rate' => $quinzaine->enterprise->default_brut_rate,
                'brut' => $calc['brut'],
                'net' => $calc['total_net'],
                'scan_uuid' => $scan['scan_uuid'],
            ];

            if ($existing) {
                $existing->update($attributes);
            } else {
                PointageRecord::create(array_merge($attributes, [
                    'employee_id' => $employee->id,
                    'quinzaine_id' => $quinzaine->id,
                    'date' => $date,
                ]));
            }

            $results[] = ['scan_uuid' => $scan['scan_uuid'], 'ok' => true];
        }

        return response()->json(['results' => $results]);
    }
}
