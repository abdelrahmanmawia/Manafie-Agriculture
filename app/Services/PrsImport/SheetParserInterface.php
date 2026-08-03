<?php

namespace App\Services\PrsImport;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

interface SheetParserInterface
{
    /**
     * @return array<int, array{
     *   matricule: string,
     *   full_name: string,
     *   cin: ?string,
     *   rib: ?string,
     *   complement: float,
     *   brut: ?float,
     *   net_per_day: ?float,
     *   total_days: int,
     *   hs_total: float,
     *   jf_count: int,
     *   total_net: ?float,
     *   net_factur_j: ?float,
     *   total_ttc: ?float,
     *   days: array<string, array{bloc: ?string, operation: ?string}>,
     * }>
     */
    public function parse(Worksheet $sheet, int $year, int $month): array;
}
