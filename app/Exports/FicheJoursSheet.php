<?php

namespace App\Exports;

use App\Exports\Concerns\WritesSheetCells;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One transport's sheet of the fiche des jours (see FicheJoursExport): a section per payment
 * method (virement, espèces, RIB à vérifier when there is one) listing N°, NOM, PRENOM, CIN,
 * TOTAL JOURS, H.S, J.F and SIGNATURE, the chef first, each section totalled, then the
 * transport's total. The RECAP sheet reads its totals through sections() and totalRow().
 */
class FicheJoursSheet implements WithEvents, WithTitle
{
    use WritesSheetCells;

    public const MODES = ['VIREMENT', 'ESPÈCES', 'RIB À VÉRIFIER'];

    private const FIRST_ROW = 6;

    private const FILL_TITLE = 'FFF2CC';

    private const FILL_SECTION = 'E7E6E6';

    private const FILL_HEADER = 'C5D9F1';

    private Worksheet $ws;

    /** mode => [rows, headerRow, firstRow, totalRow], in order; computed once. */
    private array $sections = [];

    private int $totalRow;

    public function __construct(private string $title, private array $group, private string $period, private string $farm)
    {
        $r = self::FIRST_ROW;
        foreach (self::MODES as $mode) {
            $rows = array_values(array_filter($group['rows'], fn ($row) => $row['paiement'] === $mode));
            if (! $rows) {
                continue;
            }
            $this->sections[$mode] = ['rows' => $rows, 'title' => $r, 'header' => $r + 1, 'first' => $r + 2, 'total' => $r + 2 + count($rows)];
            $r += 2 + count($rows) + 2; // title, header, rows, total, blank line
        }
        $this->totalRow = $r;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function name(): string
    {
        return $this->group['name'];
    }

    /** mode => [count, totalRow] for the payment methods this transport has. */
    public function sections(): array
    {
        return array_map(fn ($s) => ['count' => count($s['rows']), 'total' => $s['total']], $this->sections);
    }

    public function totalRow(): int
    {
        return $this->totalRow;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;
        $font = 'Calibri';

        $this->text('A1', trim($this->farm.' - FICHE DE PAIE : TOTAL DES JOURS', ' -'));
        $this->text('A2', $this->period);
        $this->text('A3', 'TRANSPORT : '.$this->group['name'].($this->group['vehicle'] ? '  ('.$this->group['vehicle'].')' : ''));
        if ($this->group['chef']) {
            $this->text('A4', "Chef d'équipe : ".$this->group['chef']);
        }
        foreach ([1, 2, 3, 4] as $row) {
            $ws->mergeCells("A{$row}:H{$row}");
        }
        $this->style('A1', $this->font($font, 14, true), $this->center());
        $this->style('A2', $this->font($font, 12, true), $this->center());
        $this->style('A3', $this->font($font, 13, true), $this->fill(self::FILL_TITLE), $this->center());
        $this->style('A4', $this->font($font, 11, true), $this->center());
        $ws->getRowDimension(1)->setRowHeight(26);
        $ws->getRowDimension(3)->setRowHeight(24);

        $totals = [];
        foreach ($this->sections as $mode => $s) {
            $this->text("A{$s['title']}", 'PAIEMENT PAR '.$mode);
            $ws->mergeCells("A{$s['title']}:H{$s['title']}");
            $this->style("A{$s['title']}:H{$s['title']}", $this->font($font, 12, true), $this->fill(self::FILL_SECTION), $this->center(), $this->borders(Border::BORDER_THIN));

            foreach (['A' => 'N°', 'B' => 'NOM', 'C' => 'PRENOM', 'D' => 'CIN', 'E' => 'TOTAL JOURS', 'F' => 'H.S', 'G' => 'J.F', 'H' => 'SIGNATURE'] as $col => $label) {
                $this->text("{$col}{$s['header']}", $label);
            }
            $this->style("A{$s['header']}:H{$s['header']}", $this->font($font, 11, true), $this->fill(self::FILL_HEADER), $this->center(), $this->borders(Border::BORDER_THIN));
            $ws->getRowDimension($s['header'])->setRowHeight(22);

            foreach ($s['rows'] as $i => $row) {
                $r = $s['first'] + $i;
                $ws->setCellValue("A{$r}", $i + 1);
                $this->text("B{$r}", $row['nom']);
                $this->text("C{$r}", $row['prenom']);
                if ($row['cin'] !== '') {
                    $this->text("D{$r}", $row['cin']);
                }
                $ws->setCellValue("E{$r}", count($row['days']));
                if ($row['hs'] > 0) {
                    $ws->setCellValue("F{$r}", $row['hs']);
                }
                if ($row['jf'] > 0) {
                    $ws->setCellValue("G{$r}", $row['jf']);
                }
                if ($row['chef']) {
                    $this->text("H{$r}", "CHEF D'ÉQUIPE");
                    $this->style("A{$r}:H{$r}", $this->font($font, 11, true), $this->fill(self::FILL_TITLE));
                }
                $ws->getRowDimension($r)->setRowHeight(30); // room to sign
            }
            $last = $s['total'] - 1;
            $this->style("A{$s['first']}:H{$last}", $this->borders(Border::BORDER_THIN), ['alignment' => ['vertical' => 'center']]);
            $this->style("A{$s['first']}:A{$last}", $this->center());
            $this->style("D{$s['first']}:H{$last}", $this->center());

            $this->text("A{$s['total']}", 'TOTAL '.$mode.'  ('.count($s['rows']).' ouvriers)');
            $ws->mergeCells("A{$s['total']}:D{$s['total']}");
            foreach (['E', 'F', 'G'] as $col) {
                $this->formula("{$col}{$s['total']}", "=SUM({$col}{$s['first']}:{$col}{$last})");
            }
            $this->style("A{$s['total']}:H{$s['total']}", $this->font($font, 11, true), $this->fill(self::FILL_HEADER), $this->center(), $this->borders(Border::BORDER_THIN));
            $ws->getRowDimension($s['total'])->setRowHeight(24);
            $totals[] = $s['total'];
        }

        $t = $this->totalRow;
        $this->text("A{$t}", 'TOTAL JOURS TRANSPORT '.mb_strtoupper($this->group['name']));
        $ws->mergeCells("A{$t}:D{$t}");
        foreach (['E', 'F', 'G'] as $col) {
            $this->formula("{$col}{$t}", $totals ? '='.implode('+', array_map(fn ($r) => "{$col}{$r}", $totals)) : '=0');
        }
        $this->style("A{$t}:H{$t}", $this->font($font, 12, true), $this->fill(self::FILL_TITLE), $this->center(), $this->borders(Border::BORDER_THIN));
        $ws->getRowDimension($t)->setRowHeight(24);
        $this->style('F'.self::FIRST_ROW.":F{$t}", ['numberFormat' => ['formatCode' => '0.##']]);

        foreach (['A' => 6, 'B' => 22, 'C' => 20, 'D' => 13, 'E' => 12, 'F' => 8, 'G' => 8, 'H' => 28] as $letter => $width) {
            $ws->getColumnDimension($letter)->setWidth($width);
        }
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setPrintArea("A1:H{$t}");
        $ws->getPageMargins()->setLeft(0.3)->setRight(0.3)->setTop(0.4)->setBottom(0.4);
    }
}
