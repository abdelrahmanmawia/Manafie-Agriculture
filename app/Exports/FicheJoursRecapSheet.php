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
 * The RECAP sheet of the fiche des jours (FicheJoursExport): per transport, workers and days by
 * payment method, total workers and days, H.S. and J.F. — read from each transport sheet — then
 * the check: the days of the three division fiches (AGRI INTERIM, non déclaré, déclaré), their
 * total, and the gap with this fiche, which should be 0.
 */
class FicheJoursRecapSheet implements WithEvents, WithTitle
{
    use WritesSheetCells;

    private const HEADER_ROW = 5;

    private Worksheet $ws;

    /** @param FicheJoursSheet[] $sheets */
    public function __construct(private array $sheets, private string $period, private string $farm, private array $joursParFiche) {}

    public function title(): string
    {
        return 'RECAP';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;
        $font = 'Calibri';

        // "RIB à vérifier" only gets its own columns when some transport has one.
        $modes = array_values(array_filter(FicheJoursSheet::MODES, fn ($mode) => $mode !== 'RIB À VÉRIFIER'
            || collect($this->sheets)->contains(fn ($sheet) => isset($sheet->sections()[$mode]))));
        $headers = ['TRANSPORT'];
        foreach ($modes as $mode) {
            $headers[] = 'OUVRIERS '.$mode;
            $headers[] = 'JOURS '.$mode;
        }
        $headers = array_merge($headers, ['TOTAL OUVRIERS', 'TOTAL JOURS', 'H.S', 'J.F']);
        $lastCol = count($headers);
        $last = $this->col($lastCol);

        $this->text('A1', trim($this->farm.' - FICHE DE PAIE : TOTAL DES JOURS', ' -'));
        $this->text('A2', $this->period);
        $this->text('A3', 'RECAPITULATIF PAR TRANSPORT');
        foreach ([1, 2, 3] as $row) {
            $ws->mergeCells("A{$row}:{$last}{$row}");
        }
        $this->style('A1', $this->font($font, 14, true), $this->center());
        $this->style('A2', $this->font($font, 12, true), $this->center());
        $this->style('A3', $this->font($font, 13, true), $this->fill('FFF2CC'), $this->center());

        foreach ($headers as $i => $label) {
            $this->text($this->cell($i + 1, self::HEADER_ROW), $label);
        }
        $this->style('A'.self::HEADER_ROW.":{$last}".self::HEADER_ROW, $this->font($font, 11, true), $this->fill('C5D9F1'), $this->center(), $this->borders(Border::BORDER_THIN));
        $ws->getRowDimension(self::HEADER_ROW)->setRowHeight(32);

        $r = self::HEADER_ROW + 1;
        foreach ($this->sheets as $sheet) {
            $ref = "'".str_replace("'", "''", $sheet->title())."'!";
            $sections = $sheet->sections();
            $this->text("A{$r}", $sheet->name());
            $col = 2;
            $ouvriers = [];
            $jours = [];
            foreach ($modes as $mode) {
                $ws->setCellValue($this->cell($col, $r), $sections[$mode]['count'] ?? 0);
                $this->formula($this->cell($col + 1, $r), isset($sections[$mode]) ? "={$ref}E{$sections[$mode]['total']}" : '=0');
                $ouvriers[] = $this->cell($col, $r);
                $jours[] = $this->cell($col + 1, $r);
                $col += 2;
            }
            $this->formula($this->cell($col, $r), '='.implode('+', $ouvriers));
            $this->formula($this->cell($col + 1, $r), '='.implode('+', $jours));
            $this->formula($this->cell($col + 2, $r), "={$ref}F{$sheet->totalRow()}");
            $this->formula($this->cell($col + 3, $r), "={$ref}G{$sheet->totalRow()}");
            $r++;
        }
        $first = self::HEADER_ROW + 1;
        $lastData = max($first, $r - 1);
        $total = $r;
        $this->text("A{$total}", 'TOTAL GENERAL');
        for ($c = 2; $c <= $lastCol; $c++) {
            $letter = $this->col($c);
            $this->formula("{$letter}{$total}", "=SUM({$letter}{$first}:{$letter}{$lastData})");
        }
        $this->style("A{$first}:{$last}{$lastData}", $this->font($font, 11), $this->borders(Border::BORDER_THIN));
        $this->style("B{$first}:{$last}{$total}", ['alignment' => ['horizontal' => 'center']]);
        $this->style("A{$total}:{$last}{$total}", $this->font($font, 12, true), $this->fill('FFF2CC'), $this->borders(Border::BORDER_THIN));
        $this->style("A{$total}", $this->center());
        $totalJours = $this->col($lastCol - 2).$total;

        // The check against the three division fiches.
        $c = $total + 2;
        $this->text("A{$c}", 'CONTRÔLE (jours des 3 fiches de paie)');
        $ws->mergeCells("A{$c}:{$last}{$c}");
        $this->style("A{$c}:{$last}{$c}", $this->font($font, 11, true), $this->fill('E7E6E6'), $this->borders(Border::BORDER_THIN));
        $lines = [
            'AGRI INTERIM (A.I)' => $this->joursParFiche[PointageExport::FICHE_FACTURE] ?? 0,
            'NON DÉCLARÉ' => $this->joursParFiche[PointageExport::FICHE_NON_DECLAREE] ?? 0,
            trim($this->farm.' (déclaré)') => $this->joursParFiche[PointageExport::FICHE_DECLAREE] ?? 0,
        ];
        $firstLine = $c + 1;
        foreach ($lines as $label => $jours) {
            $c++;
            $this->text("A{$c}", $label);
            $ws->setCellValue("B{$c}", $jours);
        }
        $c++;
        $this->text("A{$c}", 'TOTAL DES 3 FICHES');
        $this->formula("B{$c}", "=SUM(B{$firstLine}:B".($c - 1).')');
        $c++;
        $this->text("A{$c}", 'ÉCART (fiche - fiches)');
        $this->formula("B{$c}", "={$totalJours}-B".($c - 1));
        $this->style("A{$firstLine}:B{$c}", $this->font($font, 11), $this->borders(Border::BORDER_THIN));
        $this->style('A'.($c - 1).":B{$c}", $this->font($font, 11, true));
        $this->style("B{$firstLine}:B{$c}", ['alignment' => ['horizontal' => 'center']]);

        $ws->getColumnDimension('A')->setWidth(26);
        for ($i = 2; $i <= $lastCol; $i++) {
            $ws->getColumnDimension($this->col($i))->setWidth(14);
        }
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setPrintArea("A1:{$last}{$c}");
    }
}
