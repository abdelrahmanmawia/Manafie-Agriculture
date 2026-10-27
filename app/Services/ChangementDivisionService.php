<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use Illuminate\Support\Facades\DB;

/**
 * A worker moved to another division takes the days of their old division's open periods with
 * them: each day goes to the new division's open quinzaine covering its date, so it is paid,
 * listed and closed with the division they now work for (left behind, the équipe Grid didn't show
 * them and refused to point those days again). Closed periods keep their days — they are history —
 * and so does a day the new division has no open quinzaine for, or already has. Records are
 * moved through the model, so the change history keeps them; their pay is repriced afterwards
 * (SalaireFixeService::syncEmployee, called by EmployeeController::update).
 */
class ChangementDivisionService
{
    /** @return array{deplaces: int, restes: int} */
    public function deplacerJours(Employee $employee, int $ancienneDivisionId): array
    {
        return DB::transaction(function () use ($employee, $ancienneDivisionId) {
            $anciennes = Quinzaine::where('enterprise_id', $ancienneDivisionId)->where('is_closed', false)->lockForUpdate()->pluck('id');
            $nouvelles = Quinzaine::where('enterprise_id', $employee->enterprise_id)->where('is_closed', false)->lockForUpdate()->get();
            $deplaces = 0;
            $restes = 0;

            $jours = PointageRecord::where('employee_id', $employee->id)
                ->where('nature', PointageRecord::NATURE_JOUR)
                ->whereIn('quinzaine_id', $anciennes)
                ->orderBy('date')->get();
            foreach ($jours as $jour) {
                $cible = $nouvelles->first(fn ($q) => $jour->date->between($q->start_date, $q->end_date));
                $dejaLa = $cible && PointageRecord::where('employee_id', $employee->id)->where('quinzaine_id', $cible->id)
                    ->where('nature', PointageRecord::NATURE_JOUR)->whereDate('date', $jour->date)->exists();
                if (! $cible || $dejaLa) {
                    $restes++;

                    continue;
                }
                $jour->update(['quinzaine_id' => $cible->id]);
                $deplaces++;
            }

            return ['deplaces' => $deplaces, 'restes' => $restes];
        });
    }
}
