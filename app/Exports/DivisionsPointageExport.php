<?php

namespace App\Exports;

use App\Models\Quinzaine;
use App\Models\Enterprise;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class DivisionsPointageExport implements WithMultipleSheets
{
    use Exportable;

    protected $referenceQuinzaine;

    public function __construct(Quinzaine $referenceQuinzaine)
    {
        $this->referenceQuinzaine = $referenceQuinzaine;
    }

    /**
     * @return array
     */
    public function sheets(): array
    {
        $sheets = [];

        // Get all enterprises associated with the farm of the selected reference quinzaine
        $farm = $this->referenceQuinzaine->enterprise->farm;
        $enterprises = Enterprise::where('farm_id', $farm->id)->get();

        foreach ($enterprises as $enterprise) {
            // Try to find a quinzaine for the current enterprise that matches the period of the reference quinzaine
            $quinzaineForEnterprise = Quinzaine::where('enterprise_id', $enterprise->id)
                ->where('start_date', $this->referenceQuinzaine->start_date)
                ->where('end_date', $this->referenceQuinzaine->end_date)
                ->first();

            if ($quinzaineForEnterprise) {
                // If a matching quinzaine is found, use PointageExport
                $sheets[] = new PointageExport($quinzaineForEnterprise, $enterprise);
            } else {
                // If no matching quinzaine is found, use EmptyPointageExport
                $sheets[] = new EmptyPointageExport(
                    $enterprise,
                    $this->referenceQuinzaine->start_date,
                    $this->referenceQuinzaine->end_date
                );
            }
        }

        return $sheets;
    }
}
