<?php

namespace App\Services\PrsImport;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Handles the majority of the PRS archive's pointage sheets: one header row, one row per
 * employee, a run of "day" columns (header = a bare day-of-month number) followed by summary
 * columns (SAL NET/J, COMP, SAL BRUT/J, TOTAL, H.S, J.F, NET A PAYER, NET FACTUR J, TOTAL TTC —
 * spelled slightly differently file to file). Column *positions* shift between files (an extra
 * helper column here, a missing J.F column there), so columns are matched by normalized header
 * text instead of hardcoded letters. Covers three visually different day-cell styles uniformly:
 * "B1|D.M" (bloc+operation), a bare operation code with bloc coming from a per-row Bloc column,
 * or a bare "1" (presence only, operation left null for the caller to infer).
 */
class HeaderMappedPointageParser implements SheetParserInterface
{
    /**
     * @param string[] $stopSectionLabels Case-insensitive substrings; if a standalone label row
     *                                    (like the ones findBlocLabel already recognizes, but with
     *                                    arbitrary text instead of "BLOC n") matches one of these,
     *                                    parsing stops entirely rather than continuing into
     *                                    whatever section follows. Used for sheets that append an
     *                                    unrelated sub-team after the real roster ends (e.g. a
     *                                    nursery team mixed into a Non Déclaré file for one
     *                                    period only) — HeaderMappedPointageParser otherwise has
     *                                    no way to say "don't resume on the next matching header."
     */
    public function __construct(private array $stopSectionLabels = [])
    {
    }

    private const ALIASES = [
        'id' => ['N', 'IMM'],
        'name' => ['NOM'],
        'firstname' => ['PRENOM'],
        'cin' => ['CIN'],
        'rib' => ['RIB'],
        'bloc' => ['BLOC'],
        'complement' => ['COMP'],
        'brut' => ['SALBRUTJ', 'SB'],
        'net_per_day' => ['SALNETJ', 'SALAIRENET'],
        'total_days' => ['TOTAL'],
        'hs_total' => ['HS'],
        // A bare "J,F"/"JF" header (no "CH" suffix) is a monetary amount in some files (e.g. Jan
        // Persealand) and a day-count in others — not distinguishable from header text alone, so
        // only the unambiguous "J.F CH" ("count", explicit) column is trusted for jf_count. A
        // bare JF column is never treated as a count (better to under-flag is_jf than to mark
        // hundreds of days as a holiday from what's actually a DH amount).
        'jf_count' => ['JFCH'],
        'total_net' => ['NETAPAYER', 'TOTALNET', 'TOTALNETJ'],
        'net_factur_j' => ['NETFACTURJ'],
        'total_ttc' => ['TOTALTTC'],
    ];

    public function parse(Worksheet $sheet, int $year, int $month): array
    {
        $highestRow = $sheet->getHighestRow();
        $highestColIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        [$headerRow, $columns, $dayColumns] = $this->detectHeader($sheet, $highestRow, $highestColIdx);
        if ($headerRow === null) {
            return [];
        }

        $employees = [];
        // Sheets are segmented per Bloc: [employees] -> "TOTAL PAR JOUR" -> a per-operation cost
        // breakdown table ("OPERATIONS | ABREVIATION | ...", reusing the name/cin columns for an
        // operation's name/abbreviation) -> "TOTAL BLOC n" -> the header repeats -> next Bloc's
        // employees. This pattern repeats once per Bloc (not just once at the end of the sheet),
        // so entering the breakdown table must SKIP past it and resume, never stop for good, or
        // every Bloc after the first is silently dropped.
        $inBreakdownTable = false;
        // Some sections have no per-row "Bloc" column at all (replaced by e.g. a CNSS column) —
        // the bloc is only given once, as a standalone "BLOC 3" label cell just above the header.
        $currentSectionBloc = null;

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            // Some files also shift column *positions* from one Bloc section to the next (an
            // extra/missing column), so a header detected once at the top of the sheet can
            // misalign further down (e.g. a later section's RIB/CIN column landing in what was
            // "Bloc" for the first section). Re-detect the header fresh at every repeated header
            // row instead of trusting the original position — cheap since sheets are small, and
            // a real employee row can't satisfy this (it requires literal "NOM"/"N"/"CIN" text).
            $freshHeader = $this->parseHeaderColumnsAt($sheet, $row, $highestColIdx);
            if ($freshHeader !== null) {
                [$columns, $dayColumns] = $freshHeader;
                $inBreakdownTable = false;
                continue;
            }

            $sectionBlocLabel = $this->findBlocLabel($sheet, $row, $highestColIdx);
            if ($sectionBlocLabel !== null) {
                $currentSectionBloc = $sectionBlocLabel;
                continue;
            }

            if (!empty($this->stopSectionLabels)) {
                $standaloneLabel = $this->findStandaloneLabel($sheet, $row, $highestColIdx);
                if ($standaloneLabel !== null) {
                    foreach ($this->stopSectionLabels as $needle) {
                        if (stripos($standaloneLabel, $needle) !== false) {
                            break 2;
                        }
                    }
                }
            }

            $name = $this->cellValue($sheet, $columns['name'] ?? null, $row);
            if (!$name || trim((string) $name) === '') {
                continue;
            }

            $trimmedName = trim((string) $name);
            $cinValue = trim((string) ($this->cellValue($sheet, $columns['cin'] ?? null, $row) ?? ''));

            if (strcasecmp($trimmedName, 'OPERATIONS') === 0 && strcasecmp($cinValue, 'ABREVIATION') === 0) {
                $inBreakdownTable = true;
                continue;
            }
            if ($inBreakdownTable || stripos($trimmedName, 'TOTAL') === 0) {
                continue;
            }

            $firstName = $this->cellValue($sheet, $columns['firstname'] ?? null, $row);
            $fullName = trim($name . ' ' . ($firstName ?? ''));
            $matricule = trim((string) ($this->cellValue($sheet, $columns['id'] ?? null, $row) ?? ''));
            if ($matricule === '') {
                // A handful of rows have no ID cell at all — fall back to CIN so the row isn't lost.
                $matricule = trim((string) ($this->cellValue($sheet, $columns['cin'] ?? null, $row) ?? ''));
            }
            if ($matricule === '') {
                continue;
            }

            $rowBloc = $this->cellValue($sheet, $columns['bloc'] ?? null, $row);
            $rowBloc = $rowBloc ? trim((string) $rowBloc) : $currentSectionBloc;

            $days = [];
            foreach ($dayColumns as $day => $colIdx) {
                $raw = $this->cellValue($sheet, $colIdx, $row);
                if ($raw === null || $raw === '') {
                    continue;
                }
                $raw = trim((string) $raw);

                if (preg_match('/^B(\d+)\s*\|\s*(.+)$/i', $raw, $m)) {
                    $bloc = 'B' . $m[1];
                    $operation = trim($m[2]);
                } elseif (preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
                    // Presence-only day (bare "1"): bloc from the row, no operation recorded.
                    $bloc = $rowBloc;
                    $operation = null;
                } else {
                    // Bare operation code, bloc comes from the row-level Bloc column.
                    $operation = preg_replace('/\/\d+$/', '', $raw); // strip "/2" half-day suffixes
                    $bloc = $rowBloc;
                }

                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                // A date can carry more than one entry (a piece-rate employee doing meterage in
                // more than one Bloc on the same calendar day) — always a list, even though this
                // parser itself only ever appends one entry per row/date.
                $days[$date][] = ['bloc' => $bloc, 'operation' => $operation ?: null];
            }

            $employees[] = [
                'matricule' => $matricule,
                'full_name' => $fullName,
                'last_name' => trim((string) $name) ?: null,
                'first_name' => $firstName !== null ? (trim((string) $firstName) ?: null) : null,
                'cin' => $this->cellValue($sheet, $columns['cin'] ?? null, $row),
                'rib' => $this->cellValue($sheet, $columns['rib'] ?? null, $row),
                'complement' => (float) ($this->cellValue($sheet, $columns['complement'] ?? null, $row) ?? 0),
                'brut' => $this->numOrNull($this->cellValue($sheet, $columns['brut'] ?? null, $row)),
                'net_per_day' => $this->numOrNull($this->cellValue($sheet, $columns['net_per_day'] ?? null, $row)),
                'total_days' => (int) ($this->cellValue($sheet, $columns['total_days'] ?? null, $row) ?? count($days)),
                'hs_total' => (float) ($this->cellValue($sheet, $columns['hs_total'] ?? null, $row) ?? 0),
                'jf_count' => (int) ($this->cellValue($sheet, $columns['jf_count'] ?? null, $row) ?? 0),
                'total_net' => $this->numOrNull($this->cellValue($sheet, $columns['total_net'] ?? null, $row)),
                'net_factur_j' => $this->numOrNull($this->cellValue($sheet, $columns['net_factur_j'] ?? null, $row)),
                'total_ttc' => $this->numOrNull($this->cellValue($sheet, $columns['total_ttc'] ?? null, $row)),
                'days' => $days,
            ];
        }

        return $employees;
    }

    /**
     * @return array{0: ?int, 1: array<string, int>, 2: array<int, int>}
     */
    private function detectHeader(Worksheet $sheet, int $highestRow, int $highestColIdx): array
    {
        // Most sheets have their header within the first ~5 rows, but some (e.g. the "UNITE"
        // sheets, which carry a title block + a "SOMME :" summary line first) push it to row 11.
        for ($row = 1; $row <= min(20, $highestRow); $row++) {
            $parsed = $this->parseHeaderColumnsAt($sheet, $row, $highestColIdx);
            if ($parsed !== null) {
                return [$row, $parsed[0], $parsed[1]];
            }
        }

        return [null, [], []];
    }

    /**
     * Reads $row as if it were a header row and returns its column map, or null if $row doesn't
     * actually look like one. Used both for the initial header search (first 8 rows) and to
     * re-detect a fresh column map whenever a repeated header row appears further down the sheet.
     *
     * @return ?array{0: array<string, int>, 1: array<int, int>}
     */
    private function parseHeaderColumnsAt(Worksheet $sheet, int $row, int $highestColIdx): ?array
    {
        $columns = [];
        $dayColumns = [];
        $hasId = false;
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
                    if ($field === 'id') $hasId = true;
                    if ($field === 'name') $hasName = true;
                    if ($field === 'cin') $hasCin = true;
                }
            }
        }

        // The per-operation table sheets (Baraka Green's F.B.V/TRANSPORT/SERRE/...) have no ID
        // column at all — only NOM/PRENOM/CIN — so CIN alone is an acceptable substitute
        // identifier for header detection too (matricule falls back to CIN per row anyway).
        if (($hasId || $hasCin) && $hasName && count($dayColumns) > 0) {
            return [$columns, $dayColumns];
        }

        return null;
    }

    /**
     * Detects a standalone "BLOC 3" (or "Bloc1", any spacing) label cell, used as a section-wide
     * bloc value on sheets whose repeated header has no per-row Bloc column. Returns "B3" (short
     * form, matching the "B1"/"B2"/"B3" used everywhere else) or null if this row isn't one.
     */
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

    /**
     * A row whose sole non-empty content is one text cell — the same shape findBlocLabel already
     * recognizes for "BLOC n" markers, generalized to arbitrary text so stopSectionLabels can
     * match section titles like "PEPINIERE- HASSLAND".
     */
    private function findStandaloneLabel(Worksheet $sheet, int $row, int $highestColIdx): ?string
    {
        $texts = [];
        for ($col = 1; $col <= $highestColIdx; $col++) {
            $raw = $this->cellValue($sheet, $col, $row);
            if ($raw !== null && trim((string) $raw) !== '') {
                $texts[] = trim((string) $raw);
            }
        }

        return count($texts) === 1 && !is_numeric($texts[0]) ? $texts[0] : null;
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
