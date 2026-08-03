<?php

namespace App\Services\PrsImport;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Handles sheets like "UNITE ET LOCATION PERSEALAND"'s own F.B.V sheet: several operation tables
 * stacked back to back in ONE sheet (a lone label row — e.g. "CONTRÔLE SERRE BANNE" — immediately
 * followed by a NOM/PRENOM/CIN + day-columns header, then that operation's employees, then the
 * next label/header/employees block). Unlike FixedOperationParser (one operation per whole
 * SHEET), the operation here changes per block within the same sheet, so each block's own label
 * is picked up fresh instead of being supplied by the caller.
 *
 * Two row shapes exist within these blocks, told apart per ROW rather than per section (both
 * shapes can appear under the very same header, and the same person can appear in both across the
 * sheet): most rows are presence-only (a bare "1", no Bloc concept). But a "Fixation Brise Vent"
 * -style block pays by the METER instead of by the day — those rows carry a Bloc code (B1/B2/B3)
 * in the gap between CIN and the day columns, and only the FIRST row of an employee's block has
 * their NOM/PRENOM/CIN at all; subsequent bloc rows for the same person repeat only the Bloc code
 * and that day's meter quantities, so the employee identity has to be carried forward. In that
 * shape each day cell is a linear-meter quantity, not a presence flag, and the "rate" column
 * (reused via the same net_per_day alias as the flat-rate blocks' daily rate) is the per-meter
 * price — read fresh from the file every time since it "can change" between periods, never a
 * hardcoded constant.
 */
class StackedOperationParser implements SheetParserInterface
{
    private const ALIASES = [
        'name' => ['NOM'],
        'firstname' => ['PRENOM'],
        'cin' => ['CIN'],
        'rib' => ['RIB'],
        'brut' => ['SALBRUTJ', 'SB'],
        'net_per_day' => ['SALNETJ', 'SALAIRENET'],
        'total_net' => ['NETAPAYER', 'TOTALNET', 'TOTALNETJ', 'SALAIRENET', 'TOTAL'],
    ];

    public function parse(Worksheet $sheet, int $year, int $month): array
    {
        $highestRow = $sheet->getHighestRow();
        $highestColIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        // Keyed by CIN (or name, if no CIN) so a person's contributions merge across multiple
        // bloc-rows within one piece-rate block, AND across otherwise-separate blocks in the same
        // sheet (e.g. someone with zero presence days in one block but real meters in another).
        $employeesByKey = [];
        $columns = null;
        $dayColumns = [];
        $currentOperation = null;
        $currentIdentityKey = null;

        for ($row = 1; $row <= $highestRow; $row++) {
            $header = $this->parseHeaderColumnsAt($sheet, $row, $highestColIdx);
            if ($header !== null) {
                [$columns, $dayColumns] = $header;
                $currentOperation = $this->findPrecedingLabel($sheet, $row, $highestColIdx) ?? $currentOperation;
                $currentIdentityKey = null;
                continue;
            }

            if ($columns === null) {
                continue;
            }

            $name = $this->cellValue($sheet, $columns['name'] ?? null, $row);
            $hasName = $name !== null && trim((string) $name) !== '';
            $rowBloc = $this->detectRowBloc($sheet, $row, $columns, $dayColumns);

            if ($hasName) {
                $trimmedName = trim((string) $name);
                if (stripos($trimmedName, 'TOTAL') === 0 || stripos($trimmedName, 'SOMME') === 0) {
                    $currentIdentityKey = null;
                    continue;
                }

                $firstName = $this->cellValue($sheet, $columns['firstname'] ?? null, $row);
                $fullName = trim($trimmedName . ' ' . ($firstName ?? ''));
                $cin = trim((string) ($this->cellValue($sheet, $columns['cin'] ?? null, $row) ?? ''));
                $matricule = $cin !== '' ? $cin : $trimmedName;
                if ($matricule === '') {
                    continue;
                }

                $key = $cin !== '' ? $cin : $trimmedName;
                $currentIdentityKey = $key;
                if (!isset($employeesByKey[$key])) {
                    $employeesByKey[$key] = [
                        'matricule' => $matricule,
                        'full_name' => $fullName,
                        'last_name' => $trimmedName ?: null,
                        'first_name' => $firstName !== null ? (trim((string) $firstName) ?: null) : null,
                        'cin' => $cin !== '' ? $cin : null,
                        'rib' => null,
                        'complement' => 0,
                        'brut' => null,
                        'net_per_day' => null,
                        'hs_total' => 0,
                        'jf_count' => 0,
                        'total_net' => null,
                        'net_factur_j' => null,
                        'total_ttc' => null,
                        'days' => [],
                    ];
                }
                $rib = $this->cellValue($sheet, $columns['rib'] ?? null, $row);
                if ($rib !== null) {
                    $employeesByKey[$key]['rib'] = $rib;
                }
            } elseif ($rowBloc === null || $currentIdentityKey === null) {
                // Neither a fresh employee row nor a bloc-continuation row for one — irrelevant
                // (blank spacer, subtotal-only row, etc).
                continue;
            }

            $key = $currentIdentityKey;
            $rate = $this->numOrNull($this->cellValue($sheet, $columns['net_per_day'] ?? null, $row));
            $brut = $this->numOrNull($this->cellValue($sheet, $columns['brut'] ?? null, $row));
            $totalNet = $this->numOrNull($this->cellValue($sheet, $columns['total_net'] ?? null, $row));
            if ($brut !== null) {
                $employeesByKey[$key]['brut'] = $brut;
            }
            if ($rowBloc === null && $rate !== null) {
                // Only trust net_per_day as a flat daily rate for presence rows — for piece-rate
                // rows this same column is the per-meter price, not a daily rate.
                $employeesByKey[$key]['net_per_day'] = $rate;
            }
            if ($totalNet !== null) {
                $employeesByKey[$key]['total_net'] = $totalNet;
            }

            foreach ($dayColumns as $day => $colIdx) {
                $raw = $this->cellValue($sheet, $colIdx, $row);
                if ($raw === null || $raw === '' || !is_numeric($raw) || (float) $raw == 0) {
                    continue;
                }
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

                // A date can carry more than one entry: a piece-rate employee can genuinely do
                // meterage in more than one Bloc on the same calendar day (confirmed against the
                // real source file — multiple Bloc rows for one employee populate the same
                // day-column position) — always append, never overwrite.
                if ($rowBloc !== null) {
                    $employeesByKey[$key]['days'][$date][] = [
                        'bloc' => $rowBloc,
                        'operation' => $currentOperation,
                        'net_override' => (float) $raw * (float) $rate,
                        'rate_override' => (float) $rate,
                        'quantity' => (float) $raw,
                    ];
                } else {
                    $employeesByKey[$key]['days'][$date][] = ['bloc' => null, 'operation' => $currentOperation];
                }
            }
        }

        $employees = [];
        foreach ($employeesByKey as $data) {
            if (empty($data['days'])) {
                continue;
            }
            $data['total_days'] = count($data['days']);
            $employees[] = $data;
        }

        return $employees;
    }

    /**
     * A piece-rate row carries a Bloc code (B1/B2/B3) in the gap between the CIN column and the
     * first day column — the flat-rate/presence rows never populate that gap at all. Returns the
     * uppercased Bloc code for this row, or null if this row has no such marker (presence row).
     */
    private function detectRowBloc(Worksheet $sheet, int $row, array $columns, array $dayColumns): ?string
    {
        if (empty($dayColumns)) {
            return null;
        }
        $firstDayCol = min($dayColumns);
        $cinCol = $columns['cin'] ?? 0;

        for ($col = $cinCol + 1; $col < $firstDayCol; $col++) {
            $raw = $this->cellValue($sheet, $col, $row);
            if ($raw !== null && preg_match('/^B\d+$/i', trim((string) $raw))) {
                return strtoupper(trim((string) $raw));
            }
        }

        return null;
    }

    /**
     * @return ?array{0: array<string, int>, 1: array<int, int>}
     */
    private function parseHeaderColumnsAt(Worksheet $sheet, int $row, int $highestColIdx): ?array
    {
        $columns = [];
        $dayColumns = [];
        $hasName = false;
        $hasCin = false;

        for ($col = 1; $col <= $highestColIdx; $col++) {
            $raw = $this->cellValue($sheet, $col, $row);
            if ($raw === null || $raw === '') {
                continue;
            }
            $normalized = $this->normalize((string) $raw);

            if (preg_match('/^\d{1,2}$/', trim((string) $raw)) && (int) $raw >= 1 && (int) $raw <= 31) {
                $dayColumns[(int) $raw] = $col;
                continue;
            }

            foreach (self::ALIASES as $field => $aliases) {
                if (isset($columns[$field])) {
                    continue;
                }
                if (in_array($normalized, $aliases, true)) {
                    $columns[$field] = $col;
                    if ($field === 'name') {
                        $hasName = true;
                    }
                    if ($field === 'cin') {
                        $hasCin = true;
                    }
                }
            }
        }

        if ($hasName && $hasCin && count($dayColumns) > 0) {
            return [$columns, $dayColumns];
        }

        return null;
    }

    /**
     * Each operation block's employee header is preceded (within a few rows) by a lone label
     * cell — e.g. a row whose only non-empty content is "CONTRÔLE SERRE BANNE". Rows with more
     * than one non-empty cell (a "SOMME : =formula" row, a previous block's title bar) are
     * skipped rather than treated as the label, so the search keeps walking back to the real one.
     */
    private function findPrecedingLabel(Worksheet $sheet, int $headerRow, int $highestColIdx): ?string
    {
        for ($row = $headerRow - 1; $row >= max(1, $headerRow - 5); $row--) {
            $texts = [];
            for ($col = 1; $col <= $highestColIdx; $col++) {
                $raw = $this->cellValue($sheet, $col, $row);
                if ($raw !== null && trim((string) $raw) !== '') {
                    $texts[] = trim((string) $raw);
                }
            }
            if (count($texts) === 1 && !is_numeric($texts[0])) {
                return $texts[0];
            }
        }

        return null;
    }

    private function normalize(string $s): string
    {
        $s = str_replace('°', '', $s);
        $s = preg_replace('/[^A-Za-z0-9]/', '', $s);
        return strtoupper($s);
    }

    private function cellValue(Worksheet $sheet, ?int $colIdx, int $row)
    {
        if ($colIdx === null) {
            return null;
        }
        $coord = Coordinate::stringFromColumnIndex($colIdx) . $row;
        $cell = $sheet->getCell($coord);
        $value = $cell->getValue();
        if (is_string($value) && strpos($value, '=') === 0) {
            $value = $cell->getOldCalculatedValue();
        }
        return $value;
    }

    private function numOrNull($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
