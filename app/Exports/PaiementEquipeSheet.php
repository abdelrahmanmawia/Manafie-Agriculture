<?php

namespace App\Exports;

use App\Exports\Concerns\WritesSheetCells;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One équipe's payment sheet (see PaiementEquipesExport): N°, NOM, PRENOM, CIN, DIVISION, a 1 for
 * each day present, TOTAL JOURS, SAL. NET/J, PRIMES, NET À PAYER, RIB, PAIEMENT, AMO / CNSS,
 * OBSERVATION and ÉMARGEMENT (signed by those paid cash), then the TOTAL line and the
 * per-payment-method summary. The RÉSUMÉ sheet reads its totals through the public positions.
 */
class PaiementEquipeSheet implements WithEvents, WithTitle
{
    use WritesSheetCells;

    public const FIRST_ROW = 5;

    private const HEADER_ROW = 4;

    private const FILL_HEADER = 'C5D9F1';

    private const FILL_TOTAL = 'B8D4F0';

    private Worksheet $ws;

    public function __construct(private string $title, private array $group, private array $days, private string $period) {}

    public function title(): string
    {
        return $this->title;
    }

    public function name(): string
    {
        return $this->group['name'];
    }

    public function lastRow(): int
    {
        return self::FIRST_ROW + max(count($this->group['rows']), 1) - 1;
    }

    public function totalsRow(): int
    {
        return $this->lastRow() + 1;
    }

    /** Column number of a named column (day columns: 'day', the first one). */
    public function column(string $name): int
    {
        $s = 6 + count($this->days);

        return [
            'day' => 6, 'total' => $s, 'net_j' => $s + 1, 'primes' => $s + 2, 'net' => $s + 3, 'rib' => $s + 4,
            'paiement' => $s + 5, 'couverture' => $s + 6, 'observation' => $s + 7, 'emargement' => $s + 8,
        ][$name];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;
        $n = count($this->days);
        $last = $this->column('emargement');
        $first = self::FIRST_ROW;
        $lastRow = $this->lastRow();
        $totals = $this->totalsRow();
        $font = 'Calibri';

        $this->text('A1', 'FICHE DE PAIEMENT  -  '.mb_strtoupper($this->group['name']));
        $this->text('A2', implode('     |     ', array_filter([
            $this->group['chef'] ? "Chef d'équipe : ".$this->group['chef'] : null,
            $this->period,
            $this->group['vehicle'] ? 'Transport : '.$this->group['vehicle'] : null,
        ])));
        foreach ([1, 2] as $row) {
            $this->ws->mergeCells($this->range(1, $row, $last, $row));
        }
        $this->style('A1', $this->font($font, 14, true), $this->center());
        $this->style('A2', $this->font($font, 11, false), $this->center());
        $this->ws->getRowDimension(1)->setRowHeight(24);
        $this->ws->getRowDimension(2)->setRowHeight(20);

        $headers = [1 => 'N°', 2 => 'NOM', 3 => 'PRENOM', 4 => 'CIN', 5 => 'DIVISION'];
        foreach ($this->days as $i => $day) {
            $headers[6 + $i] = Carbon::parse($day)->format('j');
        }
        $headers += [
            $this->column('total') => "TOTAL\nJOURS", $this->column('net_j') => "SAL. NET\n/ J", $this->column('primes') => "PRIMES\n(H.S / J.F)",
            $this->column('net') => "NET A\nPAYER", $this->column('rib') => 'RIB', $this->column('paiement') => 'PAIEMENT',
            $this->column('couverture') => "AMO /\nCNSS", $this->column('observation') => 'OBSERVATION', $this->column('emargement') => 'ÉMARGEMENT',
        ];
        foreach ($headers as $col => $label) {
            $this->text($this->cell($col, self::HEADER_ROW), $label);
        }
        $this->style($this->range(1, self::HEADER_ROW, $last, self::HEADER_ROW), $this->font($font, 10, true), $this->fill(self::FILL_HEADER),
            $this->center(), $this->borders(Border::BORDER_THIN));
        $this->ws->getRowDimension(self::HEADER_ROW)->setRowHeight(32);

        $L = fn (string $name, int $row) => $this->col($this->column($name)).$row;
        foreach ($this->group['rows'] as $i => $row) {
            $r = $first + $i;
            $this->ws->setCellValue("A{$r}", $i + 1);
            $this->text("B{$r}", $row['nom']);
            $this->text("C{$r}", $row['prenom']);
            $this->text("D{$r}", $row['cin']);
            $this->text("E{$r}", $row['division']);
            foreach ($this->days as $d => $day) {
                if (in_array($day, $row['days'], true)) {
                    $this->ws->setCellValue($this->cell(6 + $d, $r), 1);
                }
            }
            $this->formula($L('total', $r), '=SUM('.$this->cell(6, $r).':'.$this->cell(5 + $n, $r).')');
            $this->ws->setCellValue($L('net_j', $r), $row['net_j']);
            if ($row['primes'] != 0) {
                $this->ws->setCellValue($L('primes', $r), $row['primes']);
            }
            $this->formula($L('net', $r), "={$L('total', $r)}*{$L('net_j', $r)}+{$L('primes', $r)}");
            if ($row['rib'] !== '') {
                $this->text($L('rib', $r), $row['rib']);
            }
            $rib = $L('rib', $r);
            $this->formula($L('paiement', $r), "=IF(TRIM({$rib})=\"\",\"ESPÈCES\",IF(LEN(SUBSTITUTE({$rib},\" \",\"\"))=24,\"VIREMENT\",\"RIB À VÉRIFIER\"))");
            $this->text($L('couverture', $r), $row['couverture']);
            $observation = array_filter([
                $row['chef'] ? "Chef d'équipe" : null,
                $row['a_preciser'] ? "{$row['a_preciser']} j. opération à préciser" : null,
            ]);
            if ($observation) {
                $this->text($L('observation', $r), implode(' — ', $observation));
            }
            $this->ws->getRowDimension($r)->setRowHeight(24);
        }

        $this->text("B{$totals}", 'TOTAL');
        $sumCols = array_merge(range(6, 5 + $n), [$this->column('total'), $this->column('primes'), $this->column('net')]);
        foreach ($sumCols as $col) {
            $letter = $this->col($col);
            $this->formula("{$letter}{$totals}", "=SUM({$letter}{$first}:{$letter}{$lastRow})");
        }

        $this->style($this->range(1, $first, $last, $totals), $this->font($font, 10), $this->borders(Border::BORDER_THIN),
            ['alignment' => ['vertical' => 'center']]);
        $this->style($this->range(6, $first, $this->column('couverture'), $totals), ['alignment' => ['horizontal' => 'center']]);
        $this->style($this->range($this->column('net_j'), $first, $this->column('net'), $totals), ['numberFormat' => ['formatCode' => '#,##0.00']]);
        $this->style($this->range($this->column('rib'), $first, $this->column('rib'), $lastRow), ['numberFormat' => ['formatCode' => '@']]);
        $this->style($this->range(1, $totals, $last, $totals), $this->font($font, 10, true), $this->fill(self::FILL_TOTAL));
        $this->style($this->range(1, self::HEADER_ROW, $last, $totals), $this->outline(Border::BORDER_MEDIUM));

        // Summary, as the workbook has it under each sheet.
        $net = $this->col($this->column('net'));
        $paiement = $this->col($this->column('paiement'));
        $netRange = "{$net}{$first}:{$net}{$lastRow}";
        $paiementRange = "{$paiement}{$first}:{$paiement}{$lastRow}";
        $lines = [
            "Nombre d'ouvriers à payer" => "=COUNTIF({$netRange},\">0\")",
            'Total jours' => '='.$L('total', $totals),
            'Total VIREMENT (DH)' => "=SUMIF({$paiementRange},\"VIREMENT\",{$netRange})",
            'Total ESPÈCES (DH)' => "=SUMIF({$paiementRange},\"ESPÈCES\",{$netRange})",
            'RIB à vérifier (DH)' => "=SUMIF({$paiementRange},\"RIB À VÉRIFIER\",{$netRange})",
            'TOTAL NET À PAYER (DH)' => '='.$L('net', $totals),
        ];
        $r = $totals + 3;
        foreach ($lines as $label => $formula) {
            $this->text("B{$r}", $label);
            $this->ws->mergeCells("B{$r}:D{$r}");
            $this->formula("E{$r}", $formula);
            $this->ws->mergeCells($this->range(5, $r, 8, $r));
            $this->style("B{$r}:H{$r}", $this->font($font, 10, $label === 'TOTAL NET À PAYER (DH)'), $this->borders(Border::BORDER_THIN));
            $this->style("E{$r}", ['numberFormat' => ['formatCode' => '#,##0.00'], 'alignment' => ['horizontal' => 'right']]);
            $r++;
        }

        foreach (['A' => 4.4, 'B' => 15, 'C' => 14, 'D' => 11, 'E' => 14] as $letter => $width) {
            $ws->getColumnDimension($letter)->setWidth($width);
        }
        foreach (range(6, 5 + $n) as $col) {
            $ws->getColumnDimension($this->col($col))->setWidth(3.6);
        }
        foreach (['total' => 7, 'net_j' => 8, 'primes' => 10, 'net' => 10.6, 'rib' => 26, 'paiement' => 14, 'couverture' => 8, 'observation' => 26, 'emargement' => 18] as $name => $width) {
            $ws->getColumnDimension($this->col($this->column($name)))->setWidth($width);
        }

        $ws->freezePane($this->cell(6, $first));
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)
            ->setPrintArea('A1:'.$this->cell($last, $r))
            ->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);
        $ws->getPageMargins()->setLeft(0.2)->setRight(0.2)->setTop(0.3)->setBottom(0.3);
    }
}
