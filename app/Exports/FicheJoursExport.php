<?php

namespace App\Exports;

use App\Models\Quinzaine;
use App\Services\EquipePaieService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithPreCalculateFormulas;

/**
 * "Fiche des jours" for one period, laid out like the farm's own "FICHE DE PAIE : TOTAL DES
 * JOURS" workbook: one sheet per transport (équipe, grouped by EquipePaieService — chef first,
 * paid by virement then espèces), each worker's days worked, H.S. hours and J.F. days, with room
 * to sign; and a RECAP sheet (per transport, by payment method) checked against the days of the
 * three division fiches. One workbook for every transport — every division of the farm, or only
 * the user's own when they are locked to one.
 */
class FicheJoursExport implements WithMultipleSheets, WithPreCalculateFormulas
{
    use Exportable;

    public function __construct(private Quinzaine $reference, private ?int $enterpriseId = null) {}

    public function sheets(): array
    {
        $service = app(EquipePaieService::class);
        [, $groups] = $service->groupes($this->reference, $this->enterpriseId, null, true);
        // A fiche of days: whoever has none (a fixed salary with no day pointed) isn't on it.
        $groups = array_values(array_filter(array_map(function ($group) {
            $group['rows'] = array_values(array_filter($group['rows'], fn ($row) => $row['days'] || $row['hs'] > 0 || $row['jf'] > 0));

            return $group;
        }, $groups), fn ($group) => $group['rows']));
        $period = EquipePaieService::periodLabel($this->reference);
        $farm = mb_strtoupper($this->reference->enterprise->farm->name ?? '');

        $titles = [];
        $sheets = [];
        foreach ($groups as $group) {
            $sheets[] = new FicheJoursSheet(EquipePaieService::sheetTitle($group['name'], $titles), $group, $period, $farm);
        }

        return array_merge(
            [new FicheJoursRecapSheet($sheets, $period, $farm, $service->joursParFiche($this->reference, $this->enterpriseId))],
            $sheets
        );
    }
}
