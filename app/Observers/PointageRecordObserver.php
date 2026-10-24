<?php

namespace App\Observers;

use App\Models\PointageChange;
use App\Models\PointageRecord;

/**
 * Two jobs on every pointage day written through the model:
 *
 * - A worker on a fixed monthly salary (employees.salaire_mensuel) is paid that salary once, by
 *   SalaireFixeService, not by the day: their day records still say they were there (presence,
 *   transport, the operation worked) but pay nothing — enforced in saving() so every path that
 *   writes a day agrees.
 * - The change history (pointage_changes): who created, changed or removed the day, when, and
 *   what it was before and after. Only what matters for pay is traced (SUIVIS); a write that
 *   changes none of it (e.g. a scan re-stamping its uuid) leaves no line. Query-builder updates
 *   and deletes bypass this, so pointage code goes through the model (the import's wholesale
 *   wipe is the one exception).
 */
class PointageRecordObserver
{
    private const SUIVIS = ['present', 'operation_id', 'bloc_id', 'hours', 'quantity', 'is_jf', 'net'];

    public function saving(PointageRecord $record): void
    {
        if (($record->nature ?? PointageRecord::NATURE_JOUR) !== PointageRecord::NATURE_JOUR) {
            return;
        }

        if ($record->employee && $record->employee->salaire_mensuel !== null) {
            $record->net = 0;
        }
    }

    public function created(PointageRecord $record): void
    {
        $this->trace($record, 'created', null, $this->pick($record->getAttributes()));
    }

    public function updated(PointageRecord $record): void
    {
        $apres = $this->pick($record->getChanges());
        $avant = $this->pick(array_intersect_key($record->getOriginal(), $apres));
        // A recalculation can "change" 100.00 into 100.0025: equal once rounded, not a change.
        $changed = array_keys(array_filter($apres, fn ($value, $field) => $value !== ($avant[$field] ?? null), ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            $this->trace($record, 'updated', array_intersect_key($avant, array_flip($changed)), array_intersect_key($apres, array_flip($changed)));
        }
    }

    public function deleted(PointageRecord $record): void
    {
        $this->trace($record, 'deleted', $this->pick($record->getOriginal()), null);
    }

    private function pick(array $attributes): array
    {
        $values = array_intersect_key($attributes, array_flip(self::SUIVIS));
        foreach (['present', 'is_jf'] as $flag) {
            if (array_key_exists($flag, $values)) {
                $values[$flag] = (bool) $values[$flag];
            }
        }
        foreach (['operation_id', 'bloc_id'] as $id) {
            if (isset($values[$id])) {
                $values[$id] = (int) $values[$id];
            }
        }
        foreach (['hours', 'quantity', 'net'] as $number) {
            if (isset($values[$number])) {
                $values[$number] = round((float) $values[$number], 2);
            }
        }

        return $values;
    }

    private function trace(PointageRecord $record, string $action, ?array $avant, ?array $apres): void
    {
        PointageChange::create([
            'pointage_record_id' => $record->id,
            'employee_id' => $record->employee_id,
            'quinzaine_id' => $record->quinzaine_id,
            'date' => $record->date,
            'nature' => $record->nature ?? PointageRecord::NATURE_JOUR,
            'action' => $action,
            'avant' => $avant,
            'apres' => $apres,
            'user_id' => auth()->id(),
        ]);
    }
}
