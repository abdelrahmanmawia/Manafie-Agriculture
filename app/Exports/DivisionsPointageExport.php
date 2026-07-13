<?php

namespace App\Exports;

use App\Models\Quinzaine;
use App\Models\Enterprise;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class DivisionsPointageExport implements WithMultipleSheets
{
    use Exportable;

    protected $quinzaine;

    public function __construct(Quinzaine $quinzaine)
    {
        $this->quinzaine = $quinzaine;
    }

    /**
     * @return array
     */
    public function sheets(): array
    {
        $sheets = [];

        // Get all enterprises associated with the farm of the selected quinzaine
        // Assuming a quinzaine belongs to an enterprise, and an enterprise belongs to a farm.
        // And we want to export all enterprises within that farm.
        $farm = $this->quinzaine->enterprise->farm;
        $enterprises = Enterprise::where('farm_id', $farm->id)->get();

        foreach ($enterprises as $enterprise) {
            $sheets[] = new PointageExport($this->quinzaine, $enterprise);
        }

        return $sheets;
    }
}
