<?php

namespace App\Services\PrsImport;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Fills in the operation for presence-only day cells (files that only recorded "1" rather than
 * a bloc|operation code) by cross-referencing each Bloc's own per-operation cost breakdown table
 * — the same "OPERATIONS | ABREVIATION | ..." mini-table HeaderMappedPointageParser already
 * skips past. Each cell there is a DH total for that operation on that day; dividing by the
 * period's standard daily rate estimates how many workers did that operation that day (e.g. a
 * cell of ~181 DH at a ~90.5 DH/day rate implies 2 workers) — those counts are then used to
 * assign presence-only employees in that Bloc/day to an operation, deterministically (by
 * matricule) so the same input always produces the same assignment. Best-effort: any employee
 * left unmatched (breakdown table under- or over-counts relative to presence-only headcount)
 * stays "Non spécifié" rather than being guessed further.
 */
class OperationEstimator
{
    /**
     * @param array<int, array> $employees Same shape SheetParserInterface::parse() returns.
     *                                     Mutated in place: null operations are filled where estimable.
     */
    public function apply(Worksheet $sheet, int $year, int $month, array &$employees): void
    {
        $breakdown = $this->extractBreakdownTables($sheet, $year, $month);
        if (empty($breakdown)) {
            return;
        }

        // Group presence-only day-slots by (bloc, date), each carrying a reference back to
        // exactly which employee/date to fill in, plus that employee's own daily rate to use as
        // the reference rate for the (bloc, date) group.
        $groups = [];
        foreach ($employees as $ei => $emp) {
            $rate = $emp['brut'] ?? $emp['net_per_day'] ?? null;
            foreach ($emp['days'] as $date => $entries) {
                foreach ($entries as $entryIdx => $day) {
                    if ($day['operation'] === null && $day['bloc'] !== null && $rate) {
                        $groups[$day['bloc']][$date][] = [
                            'emp_index' => $ei,
                            'entry_index' => $entryIdx,
                            'matricule' => $emp['matricule'],
                            'rate' => $rate,
                        ];
                    }
                }
            }
        }

        foreach ($breakdown as $bloc => $perDate) {
            foreach ($perDate as $date => $opAmounts) {
                if (empty($groups[$bloc][$date])) {
                    continue;
                }

                $pending = $groups[$bloc][$date];
                usort($pending, fn ($a, $b) => strcmp($a['matricule'], $b['matricule']));
                $dailyRate = $pending[0]['rate'];
                if (!$dailyRate) {
                    continue;
                }

                $assignmentIdx = 0;
                foreach ($opAmounts as $opAbbr => $dh) {
                    $count = (int) round($dh / $dailyRate);
                    for ($i = 0; $i < $count && $assignmentIdx < count($pending); $i++, $assignmentIdx++) {
                        $ref = $pending[$assignmentIdx];
                        $employees[$ref['emp_index']]['days'][$date][$ref['entry_index']]['operation'] = $opAbbr;
                    }
                }
            }
        }
    }

    /**
     * @return array<string, array<string, array<string, float>>> [bloc][date][operation_abbr] = DH
     */
    private function extractBreakdownTables(Worksheet $sheet, int $year, int $month): array
    {
        $highestRow = $sheet->getHighestRow();
        $highestColIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $result = [];

        $currentBloc = null;
        $dayColumns = null; // this table's own day-column map, independent of the main table's
        $opCol = null;
        $abbrCol = null; // some files have no ABREVIATION column at all; null is a valid state

        for ($row = 1; $row <= $highestRow; $row++) {
            $blocLabel = $this->findBlocLabel($sheet, $row, $highestColIdx);
            if ($blocLabel !== null) {
                $currentBloc = $blocLabel;
                continue;
            }

            // "OPERATIONS"/"ABREVIATION" column *positions* vary file to file (B/D in one file,
            // D/E in another) and ABREVIATION is sometimes absent entirely — so both are found by
            // scanning the whole row for their literal text rather than trusting fixed letters.
            $headerCols = $this->findBreakdownHeaderColumns($sheet, $row, $highestColIdx);
            if ($headerCols !== null) {
                $days = $this->findDayColumns($sheet, $row, $highestColIdx);
                if (!empty($days)) {
                    [$opCol, $abbrCol] = $headerCols;
                    $dayColumns = $days;
                }
                continue;
            }

            if ($dayColumns === null || $currentBloc === null) {
                continue;
            }

            $col1 = trim((string) ($this->cellValue($sheet, $opCol, $row) ?? ''));
            $col2 = $abbrCol !== null ? trim((string) ($this->cellValue($sheet, $abbrCol, $row) ?? '')) : '';

            if ($col1 === '' || stripos($col1, 'TOTAL') === 0) {
                $dayColumns = null; // end of this bloc's breakdown table
                continue;
            }

            $abbr = $col2 !== '' ? $col2 : $col1;
            foreach ($dayColumns as $day => $colIdx) {
                $amount = $this->cellValue($sheet, $colIdx, $row);
                if (!is_numeric($amount) || (float) $amount <= 0) {
                    continue;
                }
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $result[$currentBloc][$date][$abbr] = (float) $amount;
            }
        }

        return $result;
    }

    /**
     * Locates the "OPERATIONS"/"ABREVIATION" header cells anywhere in the row (their column
     * *positions* aren't consistent across files). Returns [opCol, abbrCol] with abbrCol null
     * when the row has no ABREVIATION cell (some files' breakdown tables only ever spell out
     * the full operation name), or null entirely if this row has no "OPERATIONS" cell at all.
     *
     * @return ?array{0: int, 1: ?int}
     */
    private function findBreakdownHeaderColumns(Worksheet $sheet, int $row, int $highestColIdx): ?array
    {
        $opCol = null;
        $abbrCol = null;
        for ($col = 1; $col <= $highestColIdx; $col++) {
            $raw = $this->cellValue($sheet, $col, $row);
            if ($raw === null || $raw === '') {
                continue;
            }
            $trimmed = trim((string) $raw);
            if (strcasecmp($trimmed, 'OPERATIONS') === 0) {
                $opCol = $col;
            } elseif (strcasecmp($trimmed, 'ABREVIATION') === 0) {
                $abbrCol = $col;
            }
        }

        return $opCol !== null ? [$opCol, $abbrCol] : null;
    }

    /** @return array<int, int> day-of-month => column index */
    private function findDayColumns(Worksheet $sheet, int $headerRow, int $highestColIdx): array
    {
        $dayColumns = [];
        for ($col = 1; $col <= $highestColIdx; $col++) {
            $raw = $this->cellValue($sheet, $col, $headerRow);
            if ($raw === null || $raw === '') {
                continue;
            }
            $trimmed = trim((string) $raw);
            if (preg_match('/^\d{1,2}$/', $trimmed) && (int) $trimmed >= 1 && (int) $trimmed <= 31) {
                $dayColumns[(int) $trimmed] = $col;
            }
        }

        return $dayColumns;
    }

    private function findBlocLabel(Worksheet $sheet, int $row, int $highestColIdx): ?string
    {
        for ($col = 1; $col <= $highestColIdx; $col++) {
            $raw = $this->cellValue($sheet, $col, $row);
            if ($raw === null || $raw === '') {
                continue;
            }
            if (preg_match('/^BLOC\s*(\d+)$/i', trim((string) $raw), $m)) {
                return 'B' . $m[1];
            }
        }

        return null;
    }

    private function cellValue(Worksheet $sheet, int $colIdx, int $row)
    {
        $coord = Coordinate::stringFromColumnIndex($colIdx) . $row;
        $cell = $sheet->getCell($coord);
        $value = $cell->getValue();
        if (is_string($value) && strpos($value, '=') === 0) {
            $value = $cell->getOldCalculatedValue();
        }
        return $value;
    }
}
