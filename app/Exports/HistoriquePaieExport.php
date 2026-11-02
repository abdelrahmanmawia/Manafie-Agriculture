<?php

namespace App\Exports;

use App\Exports\Concerns\WritesSheetCells;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithPreCalculateFormulas;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The Historique de Paie table as a workbook (HistoriquePaieService::filtrer()): one line per
 * worker, one column per period with its status, the cumul and the totals as formulas.
 */
class HistoriquePaieExport implements WithEvents, WithPreCalculateFormulas, WithTitle
{
    use WritesSheetCells;

    private const FONT = 'Calibri';

    private const FILL_HEADER = 'C5D9F1';

    private const FILL_TOTAL = 'B8D4F0';

    private const MONEY = '#,##0.00';

    private const STATUTS = ['en_cours' => 'en cours', 'ouverte' => 'non clôturée', 'cloturee' => 'clôturée'];

    private const PREMIERE_PERIODE = 5; // A matricule, B nom, C CIN, D division

    private Worksheet $ws;

    public function __construct(private array $d, private ?string $division) {}

    public function title(): string
    {
        return 'HISTORIQUE';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;
        $periods = $this->d['periods']->values();
        $first = self::PREMIERE_PERIODE;
        $cumul = $first + $periods->count();

        $this->text('A1', 'HISTORIQUE DE PAIE — NET PAR SALARIÉ ET PAR PÉRIODE');
        $ws->mergeCells($this->range(1, 1, $cumul, 1));
        $this->style($this->range(1, 1, $cumul, 1), $this->font(self::FONT, 14, true, '3B7D23'), $this->center());
        $this->text('A2', (string) $this->division);
        $ws->mergeCells($this->range(1, 2, $cumul, 2));
        $this->style($this->range(1, 2, $cumul, 2), $this->font(self::FONT, 11, true), $this->center());
        $ws->getRowDimension(1)->setRowHeight(28);

        $h = 4;
        foreach (['Matricule', 'Nom & prénom', 'CIN', 'Division'] as $i => $label) {
            $this->text($this->cell($i + 1, $h), $label);
        }
        foreach ($periods as $i => $period) {
            $this->text($this->cell($first + $i, $h), $period->label."\n".(self::STATUTS[$period->statut] ?? ''));
        }
        $this->text($this->cell($cumul, $h), 'CUMUL');
        $this->style($this->range(1, $h, $cumul, $h), $this->font(self::FONT, 10, true), $this->fill(self::FILL_HEADER), $this->center(), $this->borders(Border::BORDER_THIN));
        $ws->getRowDimension($h)->setRowHeight(36);

        $r = $h + 1;
        foreach ($this->d['employees'] as $emp) {
            $lignes = collect($this->d['history'][$emp->id] ?? [])->keyBy('period_key');
            $this->text("A{$r}", (string) $emp->matricule);
            $this->text("B{$r}", (string) $emp->full_name);
            $this->text("C{$r}", (string) $emp->cin);
            $this->text("D{$r}", (string) ($emp->enterprise->name ?? ''));
            foreach ($periods as $i => $period) {
                $net = (float) ($lignes[$period->key]['total_net'] ?? 0);
                if ($net != 0) {
                    $ws->setCellValue($this->cell($first + $i, $r), round($net, 2));
                }
            }
            $this->formula($this->cell($cumul, $r), '=SUM('.$this->range($first, $r, $cumul - 1, $r).')');
            $r++;
        }
        $last = $r - 1;

        $this->text("A{$r}", 'TOTAL');
        $ws->mergeCells("A{$r}:D{$r}");
        foreach (range($first, $cumul) as $col) {
            $letter = $this->col($col);
            $this->formula("{$letter}{$r}", $last >= $h + 1 ? "=SUM({$letter}".($h + 1).":{$letter}{$last})" : '=0');
        }
        $this->style($this->range(1, $h + 1, $cumul, $r), $this->font(self::FONT, 11), $this->borders(Border::BORDER_THIN));
        $this->style($this->range($first, $h + 1, $cumul, $r), ['numberFormat' => ['formatCode' => self::MONEY]]);
        $this->style($this->range(1, $r, $cumul, $r), $this->font(self::FONT, 11, true), $this->fill(self::FILL_TOTAL));
        $this->style($this->range($cumul, $h + 1, $cumul, $last >= $h + 1 ? $last : $h + 1), ['font' => ['bold' => true]]);

        foreach (['A' => 10, 'B' => 30, 'C' => 13, 'D' => 26] as $col => $width) {
            $ws->getColumnDimension($col)->setWidth($width);
        }
        foreach (range($first, $cumul) as $col) {
            $ws->getColumnDimension($this->col($col))->setWidth(15);
        }
        $ws->freezePane($this->cell($first, $h + 1));
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($h, $h);
    }
}
