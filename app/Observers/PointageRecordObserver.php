<?php

namespace App\Observers;

use App\Models\PointageRecord;

/**
 * A worker on a fixed monthly salary (employees.salaire_mensuel) is paid that salary once, by
 * SalaireFixeService, not by the day: their day records still say they were there (presence,
 * transport, the operation worked) but pay nothing. Enforced here so every path that writes a day
 * — the Grid, "tous présents", the scan station, jours fériés, recalculations — agrees.
 */
class PointageRecordObserver
{
    public function saving(PointageRecord $record): void
    {
        if (($record->nature ?? PointageRecord::NATURE_JOUR) !== PointageRecord::NATURE_JOUR) {
            return;
        }

        if ($record->employee && $record->employee->salaire_mensuel !== null) {
            $record->net = 0;
        }
    }
}
