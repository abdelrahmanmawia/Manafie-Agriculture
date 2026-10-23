<?php

namespace App\Exports;

use App\Models\Enterprise;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Services\PayrollService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithPreCalculateFormulas;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One division's "FICHE DE POINTAGE DES OUVRIERS" for a quinzaine, laid out exactly like the
 * farm's own Excel fiches (October 2026 references: "P-AGRI INTERIM", "PERSEALAND",
 * "PERSEALAND NON DECLARE"). Like those, it's a live workbook, not a dump of computed numbers:
 * each day cell holds the "BLOC|ABREVIATION" code worked that day, and every total — TOTAL (days),
 * TOTAL NET, NET FACTUR J / TTC, the per-bloc operation tables and the footer — is the fiche's own
 * Excel formula, so the office can still correct a cell in Excel and see everything follow.
 * The inputs written as values are what the system knows: SAL NET/J, H.S hours, J.F count.
 *
 * Three fiches (ficheFor()): a division that invoices a client gets the AGRI INTERIM one
 * (NET FACTUR J / TOTAL TTC, MARGE); the others get the declared-workforce one (RIB, COMP,
 * SAL BRUT) or, when Enterprise::mo_declaree is false, the "MO NON DECLARE" one (no RIB, footer
 * with a 4% margin). The fiche formulas mirror PayrollService (net per day, H.S. at net/8, a JF
 * day paid NET/J on top, NET FACTUR J dated by OCTOBER_2026_INVOICING_FROM) with the fiches' own
 * rounding (90.87 and 11.36 per hour for a 97.44 brut), so a total can differ from the payslip
 * by a few centimes on H.S. What the formula can't express (piece-rate days, a NET/J that changed
 * during the period) is added to TOTAL NET as a constant, so TOTAL NET is what the worker is paid.
 */
class PointageExport implements WithEvents, WithPreCalculateFormulas, WithTitle
{
    public const FICHE_FACTURE = 'facture';

    public const FICHE_DECLAREE = 'declaree';

    public const FICHE_NON_DECLAREE = 'non_declaree';

    private const FILL_HEADER = 'C5D9F1';

    private const FILL_TOTAL = 'B8D4F0';

    private const FILL_FOOTER_LIGHT = 'DCEAF7';

    private const TITLE_GREEN = '3B7D23';

    private const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private PayrollService $payroll;

    private Worksheet $ws;

    private string $fiche;

    /** Column numbers of the main table and of the bloc tables, see layout(). */
    private array $c;

    private array $theme;

    private float $stdNetJ;

    private float $hsRate;

    public function __construct(
        private Enterprise $enterprise,
        private Carbon $start,
        private Carbon $end,
        private ?Quinzaine $quinzaine = null,
    ) {
        $this->payroll = app(PayrollService::class);
        $this->fiche = self::ficheFor($enterprise);
    }

    public static function ficheFor(Enterprise $enterprise): string
    {
        // Same fallback as PayrollService::calculateInvoicing() for a division never set explicitly.
        if ($enterprise->invoiced_to_client ?? ($enterprise->contract_type === 'avec_contrat')) {
            return self::FICHE_FACTURE;
        }

        return $enterprise->mo_declaree === false ? self::FICHE_NON_DECLAREE : self::FICHE_DECLAREE;
    }

    public function title(): string
    {
        return mb_substr(trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $this->enterprise->name)), 0, 31);
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;
        $brut = (float) $this->enterprise->default_brut_rate;
        $contract = $this->enterprise->contract_type;
        $this->stdNetJ = round($this->payroll->calculate($contract, $brut)['sal_net_j'], 2);
        $this->hsRate = round($this->payroll->calculate($contract, $brut, 1)['hs_pay'], 2);

        [$days, $rows] = $this->rows();
        $this->layout(count($days));

        $this->writeTitle($days);
        $this->writeHeader($days);
        $first = $this->c['firstRow'];
        $last = $first + count($rows) - 1;

        if ($rows === []) {
            $this->writeEmpty();
        } else {
            foreach ($rows as $i => $row) {
                $this->writeEmployee($first + $i, $row, $days);
            }
            $totalsRow = $this->writeTotals($first, $last, $days);
            [$blocTotals, $lastBlocRow] = $this->writeBlocs($rows, $days, $first, $last, $totalsRow + 3);
            $this->writeFooter($blocTotals, $lastBlocRow + 3, $totalsRow);
        }

        $this->pageSetup($last);
    }

    /**
     * The fiche's rows: every employee of the division who worked at least one day this period,
     * by matricule, with what the system knows about their period.
     */
    private function rows(): array
    {
        $days = collect(CarbonPeriod::create($this->start, $this->end))->map(fn ($d) => $d->format('Y-m-d'))->all();
        if (! $this->quinzaine) {
            return [$days, []];
        }

        $records = PointageRecord::where('quinzaine_id', $this->quinzaine->id)
            ->whereHas('employee', fn ($q) => $q->where('enterprise_id', $this->enterprise->id))
            ->with(['employee', 'operation', 'bloc'])
            ->get();

        // A record saved (by an old data-entry mistake) outside the quinzaine's own dates still
        // gets its own column rather than silently disappearing from the totals.
        $stray = $records->map(fn ($r) => $r->date->format('Y-m-d'))->unique()->diff($days)->sort()->values();
        $days = array_merge($days, $stray->all());

        $contract = $this->enterprise->contract_type;
        $rows = [];
        foreach ($records->groupBy('employee_id') as $employeeRecords) {
            $worked = $employeeRecords->filter(fn ($r) => $r->operation_id !== null && $r->bloc_id !== null);
            if ($worked->isEmpty()) {
                continue;
            }
            $employee = $employeeRecords->first()->employee;

            $codes = [];
            foreach ($worked as $record) {
                $codes[$record->date->format('Y-m-d')] = $this->code($record->bloc->name, $this->abbreviation($record->operation));
            }

            // NET/J as it was when the days were entered: the most common net of a plain day (no
            // H.S., J.F. or quantity; the latest wins a tie), else the employee's current one.
            $netJ = $worked->filter(fn ($r) => $r->quantity === null && ! $r->is_jf && (float) $r->hours == 0)
                ->sortByDesc('date')
                ->map(fn ($r) => number_format($r->net, 2, '.', ''))
                ->countBy()->sortDesc()->keys()->first();
            $netJ = $netJ !== null
                ? (float) $netJ
                : round($this->payroll->calculate($contract, $this->enterprise->default_brut_rate, 0, $employee->complement)['sal_net_j'], 2);

            $hs = (float) $worked->sum('hours');
            $jf = $employeeRecords->where('is_jf', true)->count();

            // What the fiche formula can't express — piece-rate days, a NET/J that changed during
            // the period — is added to TOTAL NET as a constant so it still equals what the worker
            // is paid. Below 1 DH it's just the fiche's own rounding (11.36/h), left alone.
            $adjustment = round($employeeRecords->sum('net') - ($worked->count() + $jf) * $netJ - $hs * $this->hsRate, 2);

            [$nom, $prenom] = $employee->last_name || $employee->first_name
                ? [$employee->last_name ?? '', $employee->first_name ?? '']
                : array_pad(explode(' ', $employee->full_name, 2), 2, '');

            $rows[] = [
                'matricule' => (string) $employee->matricule,
                'nom' => $nom,
                'prenom' => $prenom,
                'cin' => (string) $employee->cin,
                'rib' => (string) $employee->rib,
                'codes' => $codes,
                'net_j' => $netJ,
                // The day rate the records were priced at — what PayrollService::invoicingForRecords() invoices on.
                'brut' => (float) ($employeeRecords->whereNull('quantity')->first()?->rate ?? $this->enterprise->default_brut_rate),
                'hs' => $hs,
                'jf' => $jf,
                // The operation the fiche charges this worker's H.S. and J.F. to: their main one.
                'op' => collect($codes)->countBy()->sortDesc()->keys()->first(),
                'adjustment' => abs($adjustment) >= 1 ? $adjustment : 0.0,
            ];
        }

        usort($rows, fn ($a, $b) => strnatcmp($a['matricule'], $b['matricule']));

        return [$days, $rows];
    }

    /** Column positions of each fiche, for $n day columns. */
    private function layout(int $n): void
    {
        $this->theme = match ($this->fiche) {
            self::FICHE_FACTURE => [
                'font' => 'Aptos Narrow', 'header_fill' => self::FILL_HEADER, 'title_font' => 'Baskerville Old Face',
                'fiche_font' => 'Bell MT', 'fiche_size' => 16, 'campagne_font' => 'Times New Roman', 'big' => 16,
                'names_bold' => true, 'money_header' => 16, 'total_size' => 18, 'day_font' => 14,
                'heights' => [44.4, 44.4, 52.05, 46.8], 'data_height' => 49.2, 'footer_fill' => self::FILL_TOTAL,
            ],
            self::FICHE_DECLAREE => [
                'font' => 'Calibri', 'header_fill' => self::FILL_TOTAL, 'title_font' => 'Baskerville Old Face',
                'fiche_font' => 'Bell MT', 'fiche_size' => 12, 'campagne_font' => 'Times New Roman', 'big' => 16,
                'names_bold' => false, 'money_header' => 10, 'total_size' => 14, 'day_font' => 14,
                'heights' => [54.6, 54.6, 47.4, 25.2, 40.05], 'data_height' => 53.4, 'footer_fill' => self::FILL_TOTAL,
            ],
            default => [
                'font' => 'Calibri', 'header_fill' => self::FILL_HEADER, 'title_font' => 'Calibri',
                'fiche_font' => 'Calibri', 'fiche_size' => 12, 'campagne_font' => 'Calibri', 'big' => 12,
                'names_bold' => true, 'money_header' => 10, 'total_size' => 14, 'day_font' => 11,
                'heights' => [31.8, 31.8, 43.8, 30, 40.05], 'data_height' => 45.6, 'footer_fill' => self::FILL_FOOTER_LIGHT,
            ],
        };

        if ($this->fiche === self::FICHE_NON_DECLAREE) {
            $s = 5 + $n;
            $this->c = [
                'headerRow' => 5, 'firstRow' => 6, 'rib' => null, 'day' => 5, 'net_j' => $s, 'comp' => null, 'brut' => null,
                'marge' => null, 'total' => $s + 1, 'hs' => $s + 2, 'jf' => $s + 3, 'jf_dh' => $s + 4, 'total_net' => $s + 5,
                'factur' => null, 'ttc' => null, 'op' => $s + 7,
                'bloc_name' => 3, 'bloc_abbr' => 4, 'bloc_day' => 5,
                'bloc_total' => 5 + $n, 'bloc_jf' => 6 + $n, 'bloc_hs' => 7 + $n, 'bloc_net' => 10 + $n,
                'footer_label' => [$s - 3, $s + 1], 'footer_value' => [$s + 2, $s + 5],
                'print_last' => $s + 5,
            ];

            return;
        }

        $s = 6 + $n;
        $common = ['rib' => 5, 'day' => 6, 'net_j' => $s, 'comp' => $s + 1, 'brut' => $s + 2, 'bloc_name' => 5, 'bloc_abbr' => 6, 'bloc_day' => 7];
        $this->c = $this->fiche === self::FICHE_FACTURE
            ? $common + [
                'headerRow' => 4, 'firstRow' => 5, 'marge' => $s + 3, 'total' => $s + 4, 'jf' => $s + 5, 'jf_dh' => null,
                'hs' => $s + 6, 'total_net' => $s + 7, 'factur' => $s + 8, 'ttc' => $s + 9, 'op' => $s + 11,
                'bloc_total' => 8 + $n, 'bloc_jf' => 9 + $n, 'bloc_hs' => 10 + $n, 'bloc_net' => 12 + $n,
                'footer_label' => [$s - 3, $s + 2], 'footer_value' => [$s + 3, $s + 4], 'footer_ttc' => [$s + 5, $s + 7],
                'print_last' => $s + 9,
            ]
            : $common + [
                'headerRow' => 5, 'firstRow' => 6, 'marge' => null, 'total' => $s + 3, 'jf' => $s + 4, 'jf_dh' => $s + 5,
                'hs' => $s + 6, 'total_net' => $s + 7, 'factur' => null, 'ttc' => null, 'op' => $s + 9,
                'bloc_total' => 8 + $n, 'bloc_jf' => 9 + $n, 'bloc_hs' => 10 + $n, 'bloc_net' => 11 + $n,
                'footer_label' => [$s + 4, $s + 5], 'footer_value' => [$s + 6, $s + 7],
                'print_last' => $s + 7,
            ];
    }

    private function writeTitle(array $days): void
    {
        $c = $this->c;
        $t = $this->theme;
        $start = $this->start;
        $period = ($start->day <= 15 ? '1re' : '2e').' Quinzaine '.self::MONTHS[$start->month].' '.$start->year;
        $campagne = 'CAMPAGNE  :   '.$start->year;
        $green = $this->fiche === self::FICHE_NON_DECLAREE ? null : self::TITLE_GREEN;

        [$nameRange, $ficheFrom, $ficheTo, $campagneRange] = match ($this->fiche) {
            self::FICHE_FACTURE => ['A1:D2', 5, $c['net_j'] + 4, [$c['net_j'] + 6, 1, $c['net_j'] + 9, 2]],
            self::FICHE_DECLAREE => ['A1:C1', 4, $c['net_j'] + 4, [$c['net_j'] + 5, 1, $c['net_j'] + 7, 2]],
            default => ['A1:B2', 3, $c['net_j'] + 2, [$c['net_j'] + 5, 1, $c['net_j'] + 5, 2]],
        };

        $this->text('A1', strtoupper($this->enterprise->name));
        $this->ws->mergeCells($nameRange);
        $this->style($nameRange, $this->font($t['title_font'], $t['big'], true, $green, $this->fiche !== self::FICHE_NON_DECLAREE), $this->center(), $this->outline(Border::BORDER_MEDIUM));

        foreach ([1 => 'FICHE DE POINTAGE DES OUVRIERS', 2 => $period] as $row => $label) {
            $range = $this->range($ficheFrom, $row, $ficheTo, $row);
            $this->text($this->cell($ficheFrom, $row), $label);
            $this->ws->mergeCells($range);
            $this->style($range, $this->font($t['fiche_font'], $t['fiche_size'], true, null, $row === 2 || $this->fiche !== self::FICHE_NON_DECLAREE), $this->center(), $this->outline(Border::BORDER_MEDIUM));
        }

        $range = $this->range(...$campagneRange);
        $this->text($this->cell($campagneRange[0], 1), $campagne);
        $this->ws->mergeCells($range);
        $this->style($range, $this->font($t['campagne_font'], $t['big'], true), $this->center(), $this->outline(Border::BORDER_MEDIUM));

        // "LA SOMME" — its value is set by writeFooter()/writeEmpty() once the cell it points at is known.
        [$label, $labelRange, $dh] = match ($this->fiche) {
            self::FICHE_DECLAREE => ['Somme : ', 'A3:A3', 'C3'],
            self::FICHE_FACTURE => ['LA SOMME   :', 'A3:B3', 'D3'],
            default => ['LA SOMME   :', 'A3:B3', 'E3'],
        };
        $this->text('A3', $label);
        if ($labelRange !== 'A3:A3') {
            $this->ws->mergeCells($labelRange);
        }
        $this->text($dh, 'DH');
        $this->style('A3:'.$dh, $this->font($t['title_font'] === 'Calibri' ? 'Calibri' : $t['font'], $t['big'], true, 'FF0000'), ['alignment' => ['vertical' => 'center']]);
        $this->style($this->sommeCell(), ['numberFormat' => ['formatCode' => '#,##0.00']]);

        if ($this->fiche === self::FICHE_DECLAREE) {
            $this->ws->mergeCells('A4:B4');
        }
        if ($this->fiche === self::FICHE_NON_DECLAREE) {
            $range = $this->range($c['day'], 4, $c['day'] + count($days) - 1, 4);
            $this->text($this->cell($c['day'], 4), 'MO NON DECLARE ');
            $this->ws->mergeCells($range);
            $this->style($range, $this->font('Calibri', 10), $this->center(), $this->borders(Border::BORDER_THIN));
        }

        foreach ($t['heights'] as $i => $height) {
            $this->ws->getRowDimension($i + 1)->setRowHeight($height);
        }
    }

    private function sommeCell(): string
    {
        return $this->fiche === self::FICHE_DECLAREE ? 'B3' : 'C3';
    }

    private function writeHeader(array $days): void
    {
        $c = $this->c;
        $t = $this->theme;
        $row = $c['headerRow'];

        $labels = [1 => 'N°', 2 => 'NOM', 3 => 'PRENOM', 4 => 'CIN'];
        if ($c['rib']) {
            $labels[$c['rib']] = 'RIB';
        }
        foreach ($days as $i => $day) {
            $labels[$c['day'] + $i] = Carbon::parse($day)->format($this->fiche === self::FICHE_FACTURE ? 'd' : 'j');
        }
        $labels += array_filter([
            $c['net_j'] => $this->fiche === self::FICHE_NON_DECLAREE ? 'SAL NET/J' : 'SAL  NET / J',
            $c['comp'] => 'COMP',
            $c['brut'] => 'SAL BRUT / J',
            $c['marge'] => 'MARGE',
            $c['total'] => 'TOTAL',
            $c['jf'] => $this->fiche === self::FICHE_NON_DECLAREE ? 'J.F' : 'J.F CH',
            $c['jf_dh'] => $this->fiche === self::FICHE_NON_DECLAREE ? 'J.F/DH' : 'J.F /DH',
            $c['hs'] => $this->fiche === self::FICHE_NON_DECLAREE ? 'H.S' : 'H.S ',
            $c['total_net'] => $this->fiche === self::FICHE_NON_DECLAREE ? 'TOTALNET' : 'TOTAL NET',
            $c['factur'] => 'NET FACTUR J',
            $c['ttc'] => 'TOTAL TTC ',
            $c['op'] => 'OP H.S / J.F',
        ], fn ($v, $k) => $k !== '' && $k !== null, ARRAY_FILTER_USE_BOTH);
        unset($labels['']);

        foreach ($labels as $col => $label) {
            $this->text($this->cell($col, $row), $label);
        }

        $range = $this->range(1, $row, $c['print_last'], $row);
        $this->style($range, $this->font($t['font'], 10, true), $this->fill($t['header_fill']), $this->center(), $this->borders(Border::BORDER_THIN), $this->outline(Border::BORDER_MEDIUM));
        if ($t['money_header'] !== 10) {
            $this->style($this->range($c['net_j'], $row, $c['print_last'], $row), $this->font($t['font'], $t['money_header'], true));
            $this->style($this->range($c['total_net'], $row, $c['print_last'], $row), $this->font($t['font'], $t['total_size'], true));
        }
        $this->style($this->cell($c['op'], $row), $this->font($t['font'], 10, true), $this->fill($t['header_fill']), $this->center());
        $this->ws->getRowDimension($row)->setRowHeight(end($t['heights']));
    }

    private function writeEmployee(int $r, array $row, array $days): void
    {
        $c = $this->c;
        $t = $this->theme;

        $this->text($this->cell(1, $r), $row['matricule']);
        $this->text($this->cell(2, $r), $row['nom']);
        $this->text($this->cell(3, $r), $row['prenom']);
        $this->text($this->cell(4, $r), $row['cin']);
        if ($c['rib']) {
            $this->text($this->cell($c['rib'], $r), $row['rib']);
        }
        foreach ($days as $i => $day) {
            if (isset($row['codes'][$day])) {
                $this->text($this->cell($c['day'] + $i, $r), $row['codes'][$day]);
            }
        }

        $L = fn (string $key) => $this->col($c[$key]).$r;
        $firstDay = $this->col($c['day']).$r;
        $lastDay = $this->col($c['day'] + count($days) - 1).$r;

        $this->ws->setCellValue($L('net_j'), $row['net_j']);
        if ($row['hs'] > 0) {
            $this->ws->setCellValue($L('hs'), $row['hs']);
        }
        if ($row['jf'] > 0) {
            $this->ws->setCellValue($L('jf'), $row['jf']);
        }
        $this->ws->setCellValue($L('total'), "=COUNTA({$firstDay}:{$lastDay})");

        $adjustment = $row['adjustment'] != 0 ? '+('.$this->num($row['adjustment']).')' : '';
        $hsRate = $this->num($this->hsRate);

        if ($this->fiche === self::FICHE_NON_DECLAREE) {
            $this->ws->setCellValue($L('jf_dh'), "=ROUND({$L('jf')}*{$L('net_j')},2)");
            $this->ws->setCellValue($L('total_net'), "=ROUND({$L('total')}*{$L('net_j')}+{$L('hs')}*{$hsRate}+{$L('jf_dh')}{$adjustment},2)");
        } else {
            $this->ws->setCellValue($L('comp'), "=+{$L('net_j')}-{$this->num($this->stdNetJ)}");
            $this->ws->setCellValue($L('brut'), $row['brut']);
            if ($c['jf_dh']) {
                $this->ws->setCellValue($L('jf_dh'), "=ROUND({$L('jf')}*{$L('net_j')},2)");
            }
            $this->ws->setCellValue($L('total_net'), "=({$L('total')}*{$L('net_j')})+{$L('hs')}*{$hsRate}+({$L('jf')}*{$L('net_j')}){$adjustment}");
        }

        if ($this->fiche === self::FICHE_FACTURE) {
            $this->ws->setCellValue($L('marge'), "=MAX(0,{$L('factur')}-{$L('brut')})");
            [$factur, $ttc] = $this->invoicingFormulas($L);
            $this->ws->setCellValue($L('factur'), $factur);
            $this->ws->setCellValue($L('ttc'), $ttc);
        }

        $this->text($L('op'), (string) $row['op']);

        $range = $this->range(1, $r, $c['print_last'], $r);
        $this->style($range, $this->font($t['font'], 14), $this->center(), ['borders' => ['vertical' => ['borderStyle' => Border::BORDER_THIN], 'outline' => ['borderStyle' => Border::BORDER_THIN], 'horizontal' => ['borderStyle' => Border::BORDER_HAIR]]], ['numberFormat' => ['formatCode' => '0.00']]);
        $this->style($this->range(1, $r, 4, $r), ['font' => ['bold' => $t['names_bold']], 'numberFormat' => ['formatCode' => '@']]);
        if ($c['rib']) {
            $this->style($this->cell($c['rib'], $r), ['font' => ['size' => 16], 'numberFormat' => ['formatCode' => '@']]);
        }
        $this->style($this->range($c['day'], $r, $c['day'] + count($days) - 1, $r), ['font' => ['size' => $t['day_font']]]);
        $this->style($this->range($c['total_net'], $r, $c['print_last'], $r), [
            'font' => ['size' => $t['total_size'], 'bold' => $this->fiche === self::FICHE_FACTURE],
            'numberFormat' => ['formatCode' => '#,##0.00'],
        ]);
        $this->style($this->cell($c['op'], $r), $this->font($t['font'], 11));
        $this->ws->getRowDimension($r)->setRowHeight($t['data_height']);
    }

    /**
     * NET FACTUR J / TOTAL TTC as the fiche writes them — the same formula PayrollService::
     * calculateInvoicing() uses for this quinzaine (October 2026 or the earlier one).
     */
    private function invoicingFormulas(\Closure $L): array
    {
        $p = PayrollService::class;
        $base = "{$L('brut')}*{$this->num($p::CHARGE_RATE)}+{$this->num($p::FIXED_CHARGE_1)}+{$this->num($p::FIXED_CHARGE_2)}";
        $tax = $this->num($p::TAX_ADJUSTMENT);
        $service = $this->num($p::SERVICE_TAX);
        $hours = $p::STANDARD_WORKDAY_HOURS;

        if ($this->payroll->usesOctober2026Invoicing($this->start)) {
            return [
                "=+(({$base}+{$L('comp')}*{$this->num($p::COMP_INVOICE_RATE)})*{$tax}+(({$L('jf')}+{$L('hs')})*{$L('brut')}/{$hours}/{$L('total')})*{$tax})*{$service}",
                "=+{$L('factur')}*{$L('total')}",
            ];
        }

        return [
            "=+(({$base}+{$L('comp')})*{$tax}+(({$L('jf')}*{$L('brut')}+{$L('hs')}*{$L('brut')}/{$hours})/{$L('total')})*{$tax})*{$service}",
            "=+{$L('factur')}*{$L('total')}+{$L('jf')}*{$L('net_j')}*{$this->num($p::JF_TOTAL_TTC_MULTIPLIER)}",
        ];
    }

    private function writeTotals(int $first, int $last, array $days): int
    {
        $c = $this->c;
        $t = $this->theme;
        $r = $last + 1;

        $this->text($this->cell(2, $r), 'TOTAL PAR JOUR');
        $this->ws->mergeCells($this->range(2, $r, $c['day'] - 1, $r));
        foreach ($days as $i => $day) {
            $col = $this->col($c['day'] + $i);
            $this->ws->setCellValue("{$col}{$r}", "=COUNTA({$col}{$first}:{$col}{$last})");
        }
        foreach (range($c['net_j'], $c['print_last']) as $col) {
            $letter = $this->col($col);
            $this->ws->setCellValue("{$letter}{$r}", "=SUM({$letter}{$first}:{$letter}{$last})");
        }

        $this->style($this->range(2, $r, $c['print_last'], $r), $this->font($t['font'], 10, true), $this->center(), $this->borders(Border::BORDER_THIN), ['numberFormat' => ['formatCode' => '#,##0.00']]);
        $this->style($this->range($c['day'], $r, $c['print_last'], $r), $this->fill(self::FILL_TOTAL));
        $this->style($this->range($c['day'], $r, $c['day'] + count($days) - 1, $r), ['numberFormat' => ['formatCode' => '0']]);
        $this->style($this->range($c['total_net'], $r, $c['print_last'], $r), ['font' => ['size' => $t['total_size']]]);
        $this->style($this->range(1, $c['headerRow'], $c['print_last'], $r), $this->outline(Border::BORDER_MEDIUM));
        $this->ws->getRowDimension($r)->setRowHeight(40);

        if ($this->fiche === self::FICHE_DECLAREE) {
            $this->formula($this->sommeCell(), '=+'.$this->col($c['total_net']).$r);
        }

        return $r;
    }

    /**
     * One table per bloc worked this period, one line per operation worked in it: what each day's
     * workers on it cost (their SAL NET/J), its days, and the J.F / H.S. of the workers whose main
     * operation it is. Returns the TOT NET cell of each bloc's total line, and the last row used.
     */
    private function writeBlocs(array $rows, array $days, int $first, int $last, int $r): array
    {
        $c = $this->c;
        $t = $this->theme;
        $n = count($days);

        $blocs = [];
        foreach ($rows as $row) {
            foreach ($row['codes'] as $code) {
                [$bloc, $abbr] = explode('|', $code, 2);
                $blocs[$bloc][$abbr] = true;
            }
        }
        $operationNames = $this->operationNames();
        uksort($blocs, 'strnatcmp');

        $main = fn (string $key) => '$'.$this->col($c[$key]).'$'.$first.':$'.$this->col($c[$key]).'$'.$last;
        $daysRange = '$'.$this->col($c['day']).'$'.$first.':$'.$this->col($c['day'] + $n - 1).'$'.$last;

        $totals = [];
        foreach ($blocs as $bloc => $abbrs) {
            $abbrs = array_keys($abbrs);
            usort($abbrs, fn ($a, $b) => strnatcasecmp($operationNames[$a] ?? $a, $operationNames[$b] ?? $b));
            $q = $this->quote($bloc);

            $titleRange = $this->range($c['bloc_day'], $r, $c['bloc_day'] + $n - 1, $r);
            $this->text($this->cell($c['bloc_day'], $r), strtoupper(preg_replace('/^B(\d+)$/i', 'BLOC $1', $bloc)));
            $this->ws->mergeCells($titleRange);
            $this->style($titleRange, $this->font($t['font'], 10, true), $this->center(), $this->borders(Border::BORDER_THIN));

            $header = $r + 1;
            $labels = [$c['bloc_name'] => 'OPERATIONS', $c['bloc_abbr'] => 'ABREVIATION',
                $c['bloc_total'] => 'Total', $c['bloc_jf'] => 'J.F', $c['bloc_hs'] => 'H.SUP', $c['bloc_net'] => 'TOT NET'];
            foreach ($days as $i => $day) {
                $labels[$c['bloc_day'] + $i] = Carbon::parse($day)->format($this->fiche === self::FICHE_FACTURE ? 'd' : 'j');
            }
            foreach ($labels as $col => $label) {
                $this->text($this->cell($col, $header), $label);
            }
            $this->style($this->range($c['bloc_name'], $header, $c['bloc_net'], $header), $this->font($t['font'], 10, true), $this->fill($t['header_fill']), $this->center(), $this->borders(Border::BORDER_THIN));
            $this->ws->getRowDimension($header)->setRowHeight(40);

            $opRow = $header + 1;
            foreach ($abbrs as $abbr) {
                $this->text($this->cell($c['bloc_name'], $opRow), $operationNames[$abbr] ?? $abbr);
                $this->text($this->cell($c['bloc_abbr'], $opRow), $abbr);
                $abbrCell = '$'.$this->col($c['bloc_abbr']).$opRow;
                $match = "\"{$q}|\"&{$abbrCell}";

                foreach ($days as $i => $day) {
                    $dayCol = $this->col($c['day'] + $i);
                    $this->ws->setCellValue($this->cell($c['bloc_day'] + $i, $opRow),
                        "=SUMPRODUCT(({$dayCol}\${$first}:{$dayCol}\${$last}=({$match}))*{$main('net_j')})");
                }

                $dayCells = $this->col($c['bloc_day']).$opRow.':'.$this->col($c['bloc_day'] + $n - 1).$opRow;
                $jfCell = $this->col($c['bloc_jf']).$opRow;
                $hsCell = $this->col($c['bloc_hs']).$opRow;
                $hsRate = $this->num($this->hsRate);
                if ($this->fiche === self::FICHE_DECLAREE) {
                    $this->ws->setCellValue($this->cell($c['bloc_total'], $opRow), "=SUM({$dayCells})");
                    $this->ws->setCellValue($jfCell, "=SUMIF({$main('op')},{$match},{$main('jf_dh')})");
                    $this->ws->setCellValue($hsCell, "=SUMIF({$main('op')},{$match},{$main('hs')})*{$hsRate}");
                    $totalCell = $this->col($c['bloc_total']).$opRow;
                    $this->ws->setCellValue($this->cell($c['bloc_net'], $opRow), "={$totalCell}+{$jfCell}+{$hsCell}");
                } else {
                    $this->ws->setCellValue($this->cell($c['bloc_total'], $opRow), "=COUNTIF({$daysRange},{$match})");
                    $this->ws->setCellValue($jfCell, $this->fiche === self::FICHE_FACTURE
                        ? "=SUMPRODUCT(({$main('op')}=({$match}))*{$main('jf')}*{$main('net_j')})"
                        : "=SUMIF({$main('op')},{$match},{$main('jf_dh')})");
                    $this->ws->setCellValue($hsCell, "=SUMIF({$main('op')},{$match},{$main('hs')})");
                    $this->ws->setCellValue($this->cell($c['bloc_net'], $opRow), "=SUM({$dayCells})+{$jfCell}+{$hsCell}*{$hsRate}");
                }

                $this->style($this->range($c['bloc_name'], $opRow, $c['bloc_net'], $opRow), $this->font($t['font'], $t['day_font']), $this->center(), $this->fill('FFFFFF'), ['numberFormat' => ['formatCode' => '0.00']], ['borders' => ['vertical' => ['borderStyle' => Border::BORDER_THIN], 'outline' => ['borderStyle' => Border::BORDER_THIN]]]);
                $this->style($this->cell($c['bloc_name'], $opRow), ['font' => ['bold' => $this->fiche === self::FICHE_FACTURE], 'alignment' => ['horizontal' => 'left']]);
                $this->style($this->cell($c['bloc_net'], $opRow), ['numberFormat' => ['formatCode' => '#,##0.00']]);
                $this->ws->getRowDimension($opRow)->setRowHeight(40);
                $opRow++;
            }

            $this->text($this->cell($c['bloc_name'], $opRow), 'TOTAL '.strtoupper(preg_replace('/^B(\d+)$/i', 'BLOC $1', $bloc)));
            if ($this->fiche !== self::FICHE_NON_DECLAREE) {
                $this->ws->mergeCells($this->range($c['bloc_name'], $opRow, $c['bloc_abbr'], $opRow));
            }
            foreach (array_merge(range($c['bloc_day'], $c['bloc_day'] + $n - 1), [$c['bloc_total'], $c['bloc_jf'], $c['bloc_hs'], $c['bloc_net']]) as $col) {
                $letter = $this->col($col);
                $this->ws->setCellValue("{$letter}{$opRow}", "=SUM({$letter}".($header + 1).":{$letter}".($opRow - 1).')');
            }
            $this->style($this->range($c['bloc_name'], $opRow, $c['bloc_net'], $opRow), $this->font($t['font'], 10, true), $this->fill(self::FILL_TOTAL), $this->center(), $this->borders(Border::BORDER_THIN), $this->outline(Border::BORDER_MEDIUM), ['numberFormat' => ['formatCode' => '#,##0.00']]);
            $this->ws->getRowDimension($opRow)->setRowHeight(40);

            $totals[] = $this->col($c['bloc_net']).$opRow;
            $lastRow = $opRow;
            $r = $opRow + 2;
        }

        return [$totals, $lastRow ?? $r];
    }

    private function writeFooter(array $blocTotals, int $r, int $totalsRow): void
    {
        $c = $this->c;
        $t = $this->theme;
        $blocSum = $blocTotals === [] ? '0' : implode('+', $blocTotals);
        $netOuvriers = $this->col($c['total_net']).$totalsRow;

        $lines = match ($this->fiche) {
            self::FICHE_FACTURE => [['TOTAL FACTURE AVEC MARGE 4% ET 20% DE TVA', "={$blocSum}"]],
            self::FICHE_DECLAREE => [['TOTAL', "={$blocSum}"], ['NET OUVRIERS', "=+{$netOuvriers}"], ['ECART', null]],
            default => [['MO NON DECLARE', "={$blocSum}"], ['TOTAL  AVEC MARGE DE 4%', null], ['TOTAL NET OUVRIERS', "=+{$netOuvriers}"], ['ECART OPERATIONS', null]],
        };

        $first = $r;
        foreach ($lines as $i => [$label, $formula]) {
            $row = $r + $i;
            $value = $this->cell($c['footer_value'][0], $row);
            $formula ??= match ($label) {
                'ECART' => "=ROUND({$this->cell($c['footer_value'][0], $first)}-{$netOuvriers},2)",
                'TOTAL  AVEC MARGE DE 4%' => "={$this->cell($c['footer_value'][0], $first)}*1.04",
                default => "=ROUND({$this->cell($c['footer_value'][0], $first)}-{$netOuvriers},2)",
            };

            $this->text($this->cell($c['footer_label'][0], $row), $label);
            $this->ws->mergeCells($this->range($c['footer_label'][0], $row, $c['footer_label'][1], $row));
            $this->ws->setCellValue($value, $formula);
            $this->ws->mergeCells($this->range($c['footer_value'][0], $row, $c['footer_value'][1], $row));
            $lastCol = $c['footer_value'][1];

            if ($this->fiche === self::FICHE_FACTURE) {
                $ttc = $this->cell($c['footer_ttc'][0], $row);
                $this->ws->setCellValue($ttc, '=+'.$this->col($c['ttc']).$totalsRow);
                $this->ws->mergeCells($this->range($c['footer_ttc'][0], $row, $c['footer_ttc'][1], $row));
                $lastCol = $c['footer_ttc'][1];
                $this->formula($this->sommeCell(), '=+'.$ttc);
            }

            $this->style($this->range($c['footer_label'][0], $row, $lastCol, $row), $this->font($t['font'], $this->fiche === self::FICHE_NON_DECLAREE ? 16 : 18, true), $this->fill($t['footer_fill']), $this->center(), $this->borders(Border::BORDER_THIN), $this->outline(Border::BORDER_MEDIUM), ['numberFormat' => ['formatCode' => '#,##0.00']]);
            $this->ws->getRowDimension($row)->setRowHeight(52.05);

            if ($label === 'TOTAL  AVEC MARGE DE 4%') {
                $this->formula($this->sommeCell(), '='.$value);
            }
        }

        $this->c['last_row'] = $r + count($lines) - 1;
    }

    private function writeEmpty(): void
    {
        $c = $this->c;
        $r = $c['firstRow'];
        $this->text($this->cell(2, $r), 'Aucun pointage pour cette période');
        $this->ws->mergeCells($this->range(2, $r, $c['print_last'], $r));
        $this->style($this->range(1, $r, $c['print_last'], $r), $this->font($this->theme['font'], 12), $this->center(), $this->outline(Border::BORDER_MEDIUM));
        $this->ws->setCellValue($this->sommeCell(), 0);
        $this->c['last_row'] = $r;
    }

    private function pageSetup(int $lastEmployeeRow): void
    {
        $c = $this->c;
        $ws = $this->ws;

        $widths = match ($this->fiche) {
            self::FICHE_FACTURE => ['A' => 6.44, 'B' => 16.78, 'C' => 16.22, 'D' => 16.33, 'day' => 11.33, 'rib' => 39.11,
                'net_j' => 16, 'comp' => 14, 'brut' => 16, 'marge' => 14, 'total' => 12, 'jf' => 10, 'hs' => 13.78,
                'total_net' => 18.66, 'factur' => 16.11, 'ttc' => 21.55, 'op' => 16],
            self::FICHE_DECLAREE => ['A' => 8, 'B' => 19.33, 'C' => 20.78, 'D' => 13.22, 'day' => 11.55, 'rib' => 39.11,
                'net_j' => 11.55, 'comp' => 11.55, 'brut' => 11.55, 'total' => 11.55, 'jf' => 11.55, 'jf_dh' => 11.55,
                'hs' => 13.44, 'total_net' => 16.11, 'op' => 16],
            default => ['A' => 6.89, 'B' => 20.33, 'C' => 25.11, 'D' => 15.44, 'day' => 8.33,
                'net_j' => 10.33, 'total' => 8.55, 'hs' => 11.11, 'jf' => 8.11, 'jf_dh' => 12, 'total_net' => 15.89, 'op' => 16],
        };
        foreach (['A', 'B', 'C', 'D'] as $letter) {
            $ws->getColumnDimension($letter)->setWidth($widths[$letter]);
        }
        foreach (range($c['day'], $c['net_j'] - 1) as $col) {
            $ws->getColumnDimension($this->col($col))->setWidth($widths['day']);
        }
        foreach ($widths as $key => $width) {
            if (! in_array($key, ['A', 'B', 'C', 'D', 'day'], true) && ($c[$key] ?? null)) {
                $ws->getColumnDimension($this->col($c[$key]))->setWidth($width);
            }
        }

        $ws->freezePane($this->cell($c['day'], $c['firstRow']));

        $setup = $ws->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0);
        $setup->setPrintArea('A1:'.$this->col($c['print_last']).(($c['last_row'] ?? $lastEmployeeRow) + 1));
        $setup->setRowsToRepeatAtTopByStartAndEnd($c['headerRow'], $c['headerRow']);
        $ws->getPageMargins()->setLeft(0.2)->setRight(0.16)->setTop(0.3)->setBottom(0.3);
    }

    private function operationNames(): array
    {
        $names = [];
        $operations = \App\Models\Operation::where('farm_id', $this->enterprise->farm_id)->get(['name', 'abbreviation']);
        foreach ($operations as $operation) {
            $names[$this->abbreviation($operation)] = $operation->name;
        }

        return $names;
    }

    private function abbreviation($operation): string
    {
        return $operation->abbreviation ?: $operation->name;
    }

    private function code(string $bloc, string $abbreviation): string
    {
        return $bloc.'|'.$abbreviation;
    }

    /** Escapes a value for use inside a formula's "..." string literal. */
    private function quote(string $value): string
    {
        return str_replace('"', '""', $value);
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private function text(string $cell, string $value): void
    {
        $this->ws->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function formula(string $cell, string $formula): void
    {
        $this->ws->setCellValue($cell, $formula);
    }

    private function col(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    private function cell(int $col, int $row): string
    {
        return $this->col($col).$row;
    }

    private function range(int $col1, int $row1, int $col2, int $row2): string
    {
        return $this->cell($col1, $row1).':'.$this->cell($col2, $row2);
    }

    /** Applies style parts merged recursively (two parts may both set e.g. 'borders'). */
    private function style(string $range, array ...$parts): void
    {
        $this->ws->getStyle($range)->applyFromArray(array_replace_recursive(...$parts));
    }

    private function font(string $name, float $size, bool $bold = false, ?string $color = null, bool $underline = false): array
    {
        return ['font' => array_filter([
            'name' => $name, 'size' => $size, 'bold' => $bold,
            'underline' => $underline ? 'single' : null,
            'color' => $color ? ['rgb' => $color] : null,
        ], fn ($v) => $v !== null)];
    }

    private function fill(string $rgb): array
    {
        return ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb]]];
    }

    private function center(): array
    {
        return ['alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true]];
    }

    private function borders(string $style): array
    {
        return ['borders' => ['allBorders' => ['borderStyle' => $style]]];
    }

    private function outline(string $style): array
    {
        return ['borders' => ['outline' => ['borderStyle' => $style]]];
    }
}
