<?php

namespace App\Services\PrsImport;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Wraps HeaderMappedPointageParser for Baraka Green's "per-operation table" sheets (F.B.V,
 * TRANSPORT, LA TAILLE, EPANDAGE FUMIER, SERRE...): each sheet lists only the employees who did
 * THAT operation, with day cells that are presence-only ("1", no bloc/operation code) — the
 * operation itself is implied by which sheet the table is on, not recorded per cell. Reuses the
 * base parser entirely and just fills in the operation name the day-cell classifier left null.
 */
class FixedOperationParser implements SheetParserInterface
{
    public function __construct(private string $operationName)
    {
    }

    public function parse(Worksheet $sheet, int $year, int $month): array
    {
        $employees = (new HeaderMappedPointageParser())->parse($sheet, $year, $month);

        foreach ($employees as &$employee) {
            foreach ($employee['days'] as &$entries) {
                foreach ($entries as &$day) {
                    $day['operation'] ??= $this->operationName;
                }
            }
        }

        return $employees;
    }
}
