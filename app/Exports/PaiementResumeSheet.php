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
 * The RÉSUMÉ sheet of PaiementEquipesExport: one line per équipe (workers paid, days, net, and
 * the net split by payment method), then the headcount per day and équipe — every figure a
 * formula on the équipe sheets, like the farm's own workbook.
 */
class PaiementResumeSheet implements WithEvents, WithTitle
{
    use WritesSheetCells;

    private Worksheet $ws;

    /** @param  PaiementEquipeSheet[]  $sheets */
    public function __construct(private array $sheets, private array $days, private string $period) {}

    public function title(): string
    {
        return 'RÉSUMÉ';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;
        $font = 'Calibri';
        $n = count($this->days);

        $this->text('A1', 'RÉSUMÉ  -  PAIEMENT PAR ÉQUIPE');
        $this->text('A2', $this->period);
        $this->ws->mergeCells('A1:G1');
        $this->ws->mergeCells('A2:G2');
        $this->style('A1', $this->font($font, 14, true));
        $this->style('A2', $this->font($font, 11));

        $headers = ['Équipe', 'Ouvriers à payer', 'Total jours', 'Total net (DH)', 'Virement (DH)', 'Espèces (DH)', 'RIB à vérifier (DH)'];
        foreach ($headers as $i => $label) {
            $this->text($this->cell($i + 1, 4), $label);
        }
        $this->style('A4:G4', $this->font($font, 10, true), $this->fill('C5D9F1'), $this->center(), $this->borders(Border::BORDER_THIN));

        if ($this->sheets === []) {
            $this->text('A5', 'Aucun pointage pour cette période');
            $this->ws->mergeCells('A5:G5');

            return;
        }

        $r = 5;
        foreach ($this->sheets as $sheet) {
            $ref = $this->sheetRef($sheet->title());
            $range = fn (string $name) => $ref.$this->col($sheet->column($name)).PaiementEquipeSheet::FIRST_ROW.':'.$this->col($sheet->column($name)).$sheet->lastRow();
            $total = fn (string $name) => $ref.$this->col($sheet->column($name)).$sheet->totalsRow();

            $this->text("A{$r}", $sheet->name());
            $this->formula("B{$r}", "=COUNTIF({$range('net')},\">0\")");
            $this->formula("C{$r}", '='.$total('total'));
            $this->formula("D{$r}", '='.$total('net'));
            foreach (['E' => 'VIREMENT', 'F' => 'ESPÈCES', 'G' => 'RIB À VÉRIFIER'] as $col => $method) {
                $this->formula("{$col}{$r}", "=SUMIF({$range('paiement')},\"{$method}\",{$range('net')})");
            }
            $r++;
        }
        $lastTeam = $r - 1;
        $this->text("A{$r}", 'TOTAL GÉNÉRAL');
        foreach (range('B', 'G') as $col) {
            $this->formula("{$col}{$r}", "=SUM({$col}5:{$col}{$lastTeam})");
        }
        $this->style("A5:G{$r}", $this->font($font, 10), $this->borders(Border::BORDER_THIN));
        $this->style("B5:G{$r}", ['numberFormat' => ['formatCode' => '#,##0.00']]);
        $this->style("B5:C{$r}", ['numberFormat' => ['formatCode' => '0']]);
        $this->style("A{$r}:G{$r}", $this->font($font, 10, true), $this->fill('B8D4F0'));

        // Headcount per day and équipe.
        $r += 3;
        $this->text("A{$r}", "EFFECTIF PAR JOUR ET PAR ÉQUIPE (nombre d'ouvriers présents)");
        $this->ws->mergeCells($this->range(1, $r, $n + 2, $r));
        $this->style("A{$r}", $this->font($font, 11, true));
        $header = $r + 1;
        $this->text("A{$header}", 'Équipe');
        foreach ($this->days as $i => $day) {
            $this->text($this->cell($i + 2, $header), Carbon::parse($day)->format('j'));
        }
        $this->text($this->cell($n + 2, $header), 'TOTAL');
        $this->style($this->range(1, $header, $n + 2, $header), $this->font($font, 10, true), $this->fill('C5D9F1'), $this->center(), $this->borders(Border::BORDER_THIN));

        $r = $header + 1;
        $firstLine = $r;
        foreach ($this->sheets as $sheet) {
            $ref = $this->sheetRef($sheet->title());
            $this->text("A{$r}", $sheet->name());
            foreach ($this->days as $i => $day) {
                $this->formula($this->cell($i + 2, $r), '='.$ref.$this->col($sheet->column('day') + $i).$sheet->totalsRow());
            }
            $this->formula($this->cell($n + 2, $r), '=SUM('.$this->cell(2, $r).':'.$this->cell($n + 1, $r).')');
            $r++;
        }
        $this->text("A{$r}", 'TOTAL GÉNÉRAL');
        foreach (range(2, $n + 2) as $col) {
            $letter = $this->col($col);
            $this->formula("{$letter}{$r}", "=SUM({$letter}{$firstLine}:{$letter}".($r - 1).')');
        }
        $this->style($this->range(1, $firstLine, $n + 2, $r), $this->font($font, 10), $this->borders(Border::BORDER_THIN));
        $this->style($this->range(2, $firstLine, $n + 2, $r), ['alignment' => ['horizontal' => 'center'], 'numberFormat' => ['formatCode' => '0']]);
        $this->style($this->range(1, $r, $n + 2, $r), $this->font($font, 10, true), $this->fill('B8D4F0'));

        $ws->getColumnDimension('A')->setWidth(34);
        foreach (range('B', 'G') as $letter) {
            $ws->getColumnDimension($letter)->setWidth(14);
        }
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0);
    }
}
