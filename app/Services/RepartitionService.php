<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The farm's two pointage steps. First who came, per équipe: markPresent() records each worker
 * present with the operation still "à préciser" (pointage_records.present, no operation/bloc —
 * paid as a worked day, since the operation doesn't change a day's pay). Then what they did, per
 * division: either worker by worker in the Grid, or by headcount with repartir() — "B1 Irrigation
 * 4, B2 Désherbage 5" — which assigns the day's "à préciser" workers to those operations itself.
 * Both end in the same per-worker records, so pay, transport, invoicing and the Excel fiche
 * don't care which way a day was entered.
 */
class RepartitionService
{
    public function __construct(
        private PayrollService $payrollService,
        private TransportService $transportService,
    ) {}

    /**
     * Marks each employee present on $date in their own division's quinzaine for the same dates
     * as $reference (the équipe Grid spans divisions). Workers who already have that day — worked,
     * à préciser or a holiday — are left as they are; so are divisions with no such quinzaine or
     * whose quinzaine is closed. Returns how many days were added.
     */
    public function markPresent(Quinzaine $reference, string $date, Collection $employees): int
    {
        $quinzaines = Quinzaine::with('enterprise')
            ->whereIn('enterprise_id', $employees->pluck('enterprise_id')->unique())
            ->whereDate('start_date', $reference->start_date)
            ->whereDate('end_date', $reference->end_date)
            ->get()
            ->keyBy('enterprise_id');

        return DB::transaction(function () use ($quinzaines, $date, $employees) {
            // Same share lock + is_closed re-read as PointageController::writeWhileOpen().
            $open = Quinzaine::whereIn('id', $quinzaines->pluck('id'))->orderBy('id')->sharedLock()
                ->where('is_closed', false)->pluck('id');

            $added = 0;
            foreach ($employees as $employee) {
                $quinzaine = $quinzaines->get($employee->enterprise_id);
                if (! $quinzaine || ! $open->contains($quinzaine->id)) {
                    continue;
                }
                $exists = PointageRecord::where('employee_id', $employee->id)->where('quinzaine_id', $quinzaine->id)
                    ->where('nature', PointageRecord::NATURE_JOUR)->whereDate('date', $date)->exists();
                if ($exists) {
                    continue;
                }

                $enterprise = $quinzaine->enterprise;
                $calc = $this->payrollService->calculate(
                    $enterprise->contract_type, $enterprise->default_brut_rate, 0, $employee->complement, false, $enterprise->invoiced_to_client
                );
                PointageRecord::create([
                    'employee_id' => $employee->id,
                    'quinzaine_id' => $quinzaine->id,
                    'date' => $date,
                    'present' => true,
                    'operation_id' => null,
                    'bloc_id' => null,
                    'hours' => 0,
                    'quantity' => null,
                    'is_jf' => false,
                    'rate' => $enterprise->default_brut_rate,
                    'brut' => $calc['brut'],
                    'net' => $calc['total_net'],
                    'transport_vehicle_id' => $this->transportService->riderVehicleId($employee->id),
                ]);
                $added++;
            }

            return $added;
        });
    }

    /**
     * Sets the division's operations for one day by headcount. $lignes are the day's totals per
     * operation ([['bloc_id', 'operation_id', 'effectif'], ...]): workers already on an operation
     * count toward its line and are never moved; the "à préciser" workers fill the rest — first
     * those who were on that same operation the previous day they worked, then the others by name.
     * Any "à préciser" worker left over stays that way. Returns [assigned, still à préciser].
     */
    public function repartir(Quinzaine $quinzaine, string $date, array $lignes): array
    {
        $present = PointageRecord::with('employee:id,full_name')
            ->where('quinzaine_id', $quinzaine->id)->whereDate('date', $date)->where('present', true)->get();
        $pending = $present->whereNull('operation_id')->values();
        $key = fn ($blocId, $operationId) => $blocId.'|'.$operationId;

        $errors = [];
        $requested = array_sum(array_column($lignes, 'effectif'));
        if ($requested > $present->count()) {
            $errors[] = "{$requested} demandés, mais seulement {$present->count()} présents ce jour-là.";
        }

        $capacity = [];
        foreach ($lignes as $ligne) {
            $k = $key($ligne['bloc_id'], $ligne['operation_id']);
            $already = $present->filter(fn ($r) => $key($r->bloc_id, $r->operation_id) === $k)->count();
            if ($already > $ligne['effectif']) {
                $errors[] = "{$ligne['label']} : {$already} ouvriers y sont déjà affectés, plus que l'effectif {$ligne['effectif']} — corrigez-les dans la grille.";
            }
            $capacity[$k] = ($capacity[$k] ?? 0) + max(0, $ligne['effectif'] - $already);
        }
        if (array_sum($capacity) > $pending->count()) {
            $errors[] = 'Il ne reste que '.$pending->count().' ouvrier(s) « à préciser » à répartir, pour '.array_sum($capacity).' place(s).';
        }
        if ($errors) {
            throw ValidationException::withMessages(['repartition' => implode(' ', $errors)]);
        }

        $previous = $this->previousOperations($pending->pluck('employee_id'), $date);
        $assigned = [];
        // First pass: keep each worker on yesterday's operation where it has room.
        foreach ($pending as $record) {
            $k = $previous->get($record->employee_id);
            if ($k !== null && ($capacity[$k] ?? 0) > 0) {
                $assigned[$record->id] = $k;
                $capacity[$k]--;
            }
        }
        // Second pass: the rest, by name, into whatever is left.
        foreach ($pending->sortBy(fn ($r) => $r->employee?->full_name)->reject(fn ($r) => isset($assigned[$r->id])) as $record) {
            $k = collect($capacity)->filter(fn ($left) => $left > 0)->keys()->first();
            if ($k === null) {
                break;
            }
            $assigned[$record->id] = $k;
            $capacity[$k]--;
        }

        foreach ($assigned as $recordId => $k) {
            [$blocId, $operationId] = explode('|', $k);
            // Pay doesn't depend on the operation of a day-rate day, so only the detail changes.
            PointageRecord::whereKey($recordId)->update(['bloc_id' => $blocId, 'operation_id' => $operationId, 'scan_uuid' => null]);
        }

        return [count($assigned), $pending->count() - count($assigned)];
    }

    /** Each employee's bloc|operation on the last day they worked one before $date. */
    private function previousOperations(Collection $employeeIds, string $date): Collection
    {
        return PointageRecord::whereIn('employee_id', $employeeIds)
            ->whereNotNull('operation_id')->whereNotNull('bloc_id')
            ->whereDate('date', '<', $date)
            ->orderBy('date')
            ->get(['employee_id', 'bloc_id', 'operation_id'])
            ->keyBy('employee_id')
            ->map(fn ($r) => $r->bloc_id.'|'.$r->operation_id);
    }
}
