<?php

namespace App\Exports;

use App\Models\Quinzaine;
use App\Services\EquipePaieService;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithPreCalculateFormulas;

/**
 * "Paiement par équipe" for one period: what each worker is paid, grouped the way the farm pays —
 * by chef d'équipe — across every division, laid out like the farm's own "POINTAGE PAR TRANSPORT"
 * workbook (grouped by EquipePaieService): a RÉSUMÉ sheet (per équipe: workers, days, net, by payment method; headcount per day)
 * and one sheet per équipe (+ "Sans équipe"). NET À PAYER = TOTAL JOURS × SAL. NET/J + PRIMES,
 * where PRIMES is everything else the records pay (H.S., J.F., piece-rate, a NET/J that changed)
 * so it always equals the records' stored net. How each worker is paid (PAIEMENT) follows the
 * workbook's own formula on the RIB; their coverage (AMO / CNSS) follows their division.
 */
class PaiementEquipesExport implements WithMultipleSheets, WithPreCalculateFormulas
{
    use Exportable;

    /** $equipe: only that équipe's sheet ('none' = "Sans équipe"), as asked from its Grid; null = all. */
    public function __construct(private Quinzaine $reference, private ?int $enterpriseId = null, private int|string|null $equipe = null) {}

    public function sheets(): array
    {
        [$days, $groups] = app(EquipePaieService::class)->groupes($this->reference, $this->enterpriseId, $this->equipe);
        $period = EquipePaieService::periodLabel($this->reference);

        $titles = [];
        $sheets = [];
        foreach ($groups as $group) {
            $sheets[] = new PaiementEquipeSheet(EquipePaieService::sheetTitle($group['name'], $titles), $group, $days, $period);
        }

        return array_merge([new PaiementResumeSheet($sheets, $days, $period)], $sheets);
    }
}
