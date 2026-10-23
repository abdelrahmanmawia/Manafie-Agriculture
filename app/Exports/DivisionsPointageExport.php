<?php

namespace App\Exports;

use App\Models\Enterprise;
use App\Models\Quinzaine;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithPreCalculateFormulas;

class DivisionsPointageExport implements WithMultipleSheets, WithPreCalculateFormulas
{
    use Exportable;

    protected $referenceQuinzaine;

    public function __construct(Quinzaine $referenceQuinzaine)
    {
        $this->referenceQuinzaine = $referenceQuinzaine;
    }

    /**
     * One fiche per division of the farm for the reference quinzaine's dates (an empty fiche for
     * a division with no quinzaine for them), plus the farm's vehicle usage.
     */
    public function sheets(): array
    {
        $farm = $this->referenceQuinzaine->enterprise->farm;
        $start = $this->referenceQuinzaine->start_date;
        $end = $this->referenceQuinzaine->end_date;

        $sheets = [];
        foreach (Enterprise::where('farm_id', $farm->id)->get() as $enterprise) {
            $quinzaine = Quinzaine::where('enterprise_id', $enterprise->id)
                ->whereDate('start_date', $start)
                ->whereDate('end_date', $end)
                ->first();

            $sheets[] = new PointageExport($enterprise, $start, $end, $quinzaine);
        }

        // Vehicle usage (Stock domain) is farm-pooled, not enterprise-scoped like the sheets
        // above — added once per farm rather than once per enterprise, bridging the two domains
        // only at export time by matching vehicle-usage dates to this quinzaine's own range.
        $sheets[] = new LocationExport($farm, $start, $end);

        return $sheets;
    }
}
