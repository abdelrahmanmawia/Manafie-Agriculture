<?php

namespace App\Exports;

use App\Exports\Concerns\WritesSheetCells;
use App\Services\ChargeExterneService;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithPreCalculateFormulas;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The Charges externes page as a workbook (ChargeExterneService::suivi()), with its filters: one
 * line per charge, oldest first, its checks against the contract, the totals as formulas; the
 * periods with nothing entered follow, so a total isn't read as complete when it isn't.
 */
class ChargesExternesExport implements WithEvents, WithPreCalculateFormulas, WithTitle
{
    use WritesSheetCells;

    private const FONT = 'Calibri';

    private const FILL_HEADER = 'C5D9F1';

    private const FILL_TOTAL = 'B8D4F0';

    private const MONEY = '#,##0.00';

    private Worksheet $ws;

    public function __construct(private array $suivi) {}

    public function title(): string
    {
        return 'CHARGES EXTERNES';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;
        $labels = ['Prestataire', 'Service', 'Période', 'Jours nuit', 'Attendus', 'Écart', 'Jours jour', 'Attendus', 'Écart', 'Montant', 'Selon contrat', 'Coût / jour', 'Facture N°', 'Date facture', 'Statut'];
        $derniere = count($labels);

        $this->text('A1', 'CHARGES EXTERNES — '.$this->suivi['annee']);
        $ws->mergeCells($this->range(1, 1, $derniere, 1));
        $this->style($this->range(1, 1, $derniere, 1), $this->font(self::FONT, 14, true, '3B7D23'), $this->center());
        $ws->getRowDimension(1)->setRowHeight(28);

        $h = 3;
        foreach ($labels as $i => $label) {
            $this->text($this->cell($i + 1, $h), $label);
        }
        $this->style($this->range(1, $h, $derniere, $h), $this->font(self::FONT, 10, true), $this->fill(self::FILL_HEADER), $this->center(), $this->borders(Border::BORDER_THIN));
        $ws->getRowDimension($h)->setRowHeight(30);

        $r = $h + 1;
        foreach (collect($this->suivi['lignes'])->sortBy('end_date') as $l) {
            $this->text("A{$r}", $l['prestataire']);
            $this->text("B{$r}", $l['service']);
            $this->text("C{$r}", $l['periode']);
            foreach (['D' => 'jours_nuit', 'E' => 'attendu_nuit', 'F' => 'ecart_nuit', 'G' => 'jours_jour', 'H' => 'attendu_jour', 'I' => 'ecart_jour', 'J' => 'montant', 'K' => 'montant_contrat', 'L' => 'cout_jour'] as $col => $champ) {
                if ($l[$champ] !== null) {
                    $ws->setCellValue("{$col}{$r}", $l[$champ]);
                }
            }
            $this->text("M{$r}", (string) $l['facture_numero']);
            $this->text("N{$r}", (string) $l['facture_date']);
            $this->text("O{$r}", ChargeExterneService::STATUTS[$l['statut_facture']] ?? '');
            $r++;
        }
        $last = $r - 1;

        $this->text("A{$r}", 'TOTAL');
        $ws->mergeCells("A{$r}:C{$r}");
        foreach (['D', 'G', 'J'] as $col) {
            $this->formula("{$col}{$r}", $last > $h ? "=SUM({$col}".($h + 1).":{$col}{$last})" : '=0');
        }
        $this->formula("L{$r}", "=IF(D{$r}+G{$r}>0,J{$r}/(D{$r}+G{$r}),0)");

        $this->style($this->range(1, $h + 1, $derniere, $r), $this->font(self::FONT, 11), $this->borders(Border::BORDER_THIN));
        foreach (['J', 'K', 'L'] as $col) {
            $this->style("{$col}".($h + 1).":{$col}{$r}", ['numberFormat' => ['formatCode' => self::MONEY]]);
        }
        $this->style($this->range(1, $r, $derniere, $r), $this->font(self::FONT, 11, true), $this->fill(self::FILL_TOTAL));

        if (count($this->suivi['manquantes']) > 0) {
            $r += 2;
            $this->text("A{$r}", 'NON SAISI (pas dans le total)');
            $this->style("A{$r}", $this->font(self::FONT, 11, true, 'C00000'));
            foreach ($this->suivi['manquantes'] as $m) {
                $r++;
                $this->text("A{$r}", $m['prestataire']);
                $this->text("B{$r}", $m['service']);
                $this->text("C{$r}", $m['label']);
            }
        }

        foreach (['A' => 26, 'B' => 18, 'C' => 24, 'D' => 9, 'E' => 9, 'F' => 8, 'G' => 9, 'H' => 9, 'I' => 8, 'J' => 14, 'K' => 14, 'L' => 11, 'M' => 16, 'N' => 12, 'O' => 11] as $col => $width) {
            $ws->getColumnDimension($col)->setWidth($width);
        }
        $ws->freezePane($this->cell(1, $h + 1));
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
    }
}
