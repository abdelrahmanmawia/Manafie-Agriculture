<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marks (or unmarks) a jour férié for every open division of a farm at once, instead of one
 * cell at a time in the Grid.
 *
 * Who is owed the paid holiday follows Code du travail art. 219 §2 (loi 65-99; applies to
 * agricultural and forestry operations through décret 2-04-426): the worker must have worked
 * immediately before the holiday, or thirteen days during the month preceding it. Interpreted,
 * as agreed with the business, as:
 *  - "immediately before" = the division's last day with any worked pointage before the
 *    holiday (so a Sunday or a non-working day in between doesn't disqualify anyone);
 *  - "the month preceding" = the 30 calendar days before the holiday, counting days worked in
 *    any division of the farm.
 *
 * Effect per worker of each covered division:
 *  - worked that day (day-rate)       -> the record becomes JF: NET/J for the work + NET/J for
 *                                        the holiday (art. 219: +100% when working the holiday);
 *  - didn't work, eligible            -> an unworked-JF record: one NET/J (same record shape as
 *                                        PointageController::writeCell()'s unworked-JF branch);
 *  - didn't work, not eligible        -> nothing; listed in the summary;
 *  - piece-rate (worked by the piece that day, or only piece-rate days in the window) -> left
 *    alone and listed "à traiter manuellement": art. 220 pays them 1/26 of the previous 26 days'
 *    pay, which this app doesn't compute.
 */
class JourFerieService
{
    const LOOKBACK_DAYS = 30;

    const MIN_DAYS_WORKED = 13;

    public function __construct(private PayrollService $payrollService) {}

    /**
     * @param  int|null  $enterpriseId  set for a division-locked user: only their division
     * @return array one summary row per division the holiday was applied to
     */
    public function mark(int $farmId, ?int $enterpriseId, Carbon $date): array
    {
        $day = $date->toDateString();

        return DB::transaction(function () use ($farmId, $enterpriseId, $day) {
            $summary = [];

            foreach ($this->lockOpenQuinzaines($farmId, $enterpriseId, $day) as $quinzaine) {
                $enterprise = $quinzaine->enterprise;
                $row = ['division' => $enterprise->name, 'worked' => 0, 'paid' => 0, 'already' => 0, 'not_eligible' => [], 'manual' => []];

                $recordsOnDay = PointageRecord::where('quinzaine_id', $quinzaine->id)->whereDate('date', $day)->get()->keyBy('employee_id');
                $employees = Employee::where('enterprise_id', $enterprise->id)->where('is_active', true)->get()
                    ->merge(Employee::whereIn('id', $recordsOnDay->keys())->get())
                    ->unique('id')
                    ->sortBy('full_name');

                $lastWorkDay = $this->lastWorkDay($enterprise->id, $day);
                $history = $this->workHistory($employees->pluck('id'), $day, $lastWorkDay);

                foreach ($employees as $employee) {
                    $record = $recordsOnDay->get($employee->id);

                    if ($record && $record->quantity !== null) {
                        $row['manual'][] = $employee->full_name;
                    } elseif ($record && $record->is_jf) {
                        $row['already']++;
                    } elseif ($record) {
                        $this->writeJf($record, $quinzaine, $employee, true);
                        $row['worked']++;
                    } else {
                        $worked = $history->get($employee->id, collect());
                        $eligible = ($lastWorkDay && $worked->has($lastWorkDay))
                            || $worked->filter(fn ($pieceOnly, $d) => $d < $day && $d >= $this->windowStart($day))->count() >= self::MIN_DAYS_WORKED;

                        if (! $eligible) {
                            $row['not_eligible'][] = $employee->full_name;
                        } elseif ($worked->isNotEmpty() && $worked->every(fn ($pieceOnly) => $pieceOnly)) {
                            $row['manual'][] = $employee->full_name;
                        } else {
                            PointageRecord::create(['employee_id' => $employee->id, 'quinzaine_id' => $quinzaine->id, 'date' => $day]
                                + $this->unworkedJfValues($quinzaine, $employee));
                            $row['paid']++;
                        }
                    }
                }

                $summary[] = $row;
            }

            return $summary;
        });
    }

    /**
     * Reverses mark() for that day: unworked-JF records are removed, worked days go back to a
     * normal day. Piece-rate records were never touched by mark(), so they're left alone.
     */
    public function unmark(int $farmId, ?int $enterpriseId, Carbon $date): array
    {
        $day = $date->toDateString();

        return DB::transaction(function () use ($farmId, $enterpriseId, $day) {
            $summary = [];

            foreach ($this->lockOpenQuinzaines($farmId, $enterpriseId, $day) as $quinzaine) {
                $row = ['division' => $quinzaine->enterprise->name, 'removed' => 0, 'reverted' => 0];

                $records = PointageRecord::with('employee')->where('quinzaine_id', $quinzaine->id)
                    ->whereDate('date', $day)->where('is_jf', true)->whereNull('quantity')->get();

                foreach ($records as $record) {
                    if ($record->operation_id === null) {
                        $record->delete();
                        $row['removed']++;
                    } else {
                        $this->writeJf($record, $quinzaine, $record->employee, false);
                        $row['reverted']++;
                    }
                }

                $summary[] = $row;
            }

            return $summary;
        });
    }

    /**
     * Open quinzaines covering the day, locked FOR UPDATE in id order — the same row lock
     * EnterpriseController::closeQuinzaine() takes, so a period can't close half-marked.
     */
    private function lockOpenQuinzaines(int $farmId, ?int $enterpriseId, string $day): Collection
    {
        return Quinzaine::with('enterprise')
            ->whereHas('enterprise', fn ($q) => $q->where('farm_id', $farmId))
            ->when($enterpriseId, fn ($q) => $q->where('enterprise_id', $enterpriseId))
            ->where('is_closed', false)
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** The division's last day with any worked pointage before the holiday, as 'Y-m-d'. */
    private function lastWorkDay(int $enterpriseId, string $day): ?string
    {
        $date = PointageRecord::whereHas('quinzaine', fn ($q) => $q->where('enterprise_id', $enterpriseId))
            ->whereNotNull('operation_id')
            ->whereDate('date', '<', $day)
            ->max('date');

        return $date ? substr((string) $date, 0, 10) : null;
    }

    /**
     * Days each employee worked (any division of the farm) from the start of the window — or the
     * division's last work day, if older — up to the day before the holiday, as
     * [employee_id => ['Y-m-d' => bool $pieceRateOnlyThatDay]].
     */
    private function workHistory(Collection $employeeIds, string $day, ?string $lastWorkDay): Collection
    {
        $from = $lastWorkDay !== null && $lastWorkDay < $this->windowStart($day) ? $lastWorkDay : $this->windowStart($day);

        return PointageRecord::whereIn('employee_id', $employeeIds)
            ->whereNotNull('operation_id')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<', $day)
            ->get(['employee_id', 'date', 'quantity'])
            ->groupBy('employee_id')
            ->map(fn ($records) => $records
                ->groupBy(fn ($r) => $r->date->toDateString())
                ->map(fn ($sameDay) => $sameDay->every(fn ($r) => $r->quantity !== null)));
    }

    private function windowStart(string $day): string
    {
        return Carbon::parse($day)->subDays(self::LOOKBACK_DAYS)->toDateString();
    }

    /** Sets or clears JF on a worked day-rate record, recomputing its pay at its own frozen rate. */
    private function writeJf(PointageRecord $record, Quinzaine $quinzaine, Employee $employee, bool $isJf): void
    {
        $calc = $this->payrollService->calculate(
            $quinzaine->enterprise->contract_type,
            $record->rate,
            $record->hours,
            $employee->complement,
            $isJf,
            $quinzaine->enterprise->invoiced_to_client,
            true
        );

        // scan_uuid cleared: a holiday set here is a manual entry the scan station must not
        // overwrite (same as any Grid write, see PointageController::upsertPointageRecord()).
        $record->update(['is_jf' => $isJf, 'brut' => $calc['brut'], 'net' => $calc['total_net'], 'scan_uuid' => null]);
    }

    /** Same values PointageController::writeCell() stores for a paid, unworked JF day. */
    private function unworkedJfValues(Quinzaine $quinzaine, Employee $employee): array
    {
        $enterprise = $quinzaine->enterprise;
        $calc = $this->payrollService->calculate(
            $enterprise->contract_type, $enterprise->default_brut_rate, 0, $employee->complement, true, $enterprise->invoiced_to_client, false
        );

        return [
            'operation_id' => null,
            'bloc_id' => null,
            'hours' => 0,
            'quantity' => null,
            'is_jf' => true,
            'rate' => $enterprise->default_brut_rate,
            'brut' => $calc['brut'],
            'net' => $calc['total_net'],
        ];
    }
}
