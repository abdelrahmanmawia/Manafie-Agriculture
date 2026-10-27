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
 * The "Suivi des engrais" workbook for a period (SuiviEngraisService): the farm's sheet — DATE,
 * TYPE D'ENGRAIS, one column per bloc, TOTAL, with the blocs' surface on top — then the
 * récapitulatif per engrais (SUMIF over the journal), its dose per hectare, the cost per bloc
 * and per hectare, and month by month when the period spans several. Totals and doses are
 * Excel formulas; quantities and costs (CUMP at the time) are values.
 */
class SuiviEngraisExport implements WithEvents, WithPreCalculateFormulas, WithTitle
{
    use WritesSheetCells;

    private const UNITES = ['kg' => 'kg', 'tonnes' => 't', 'liters' => 'L', 'meters' => 'm', 'units' => 'unité', 'boxes' => 'boîte', 'bags' => 'sac', 'rolls' => 'rouleau'];

    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private Worksheet $ws;

    public function __construct(private array $suivi, private string $period, private string $farm) {}

    public function title(): string
    {
        return 'SUIVI ENGRAIS';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function unite(?string $type): string
    {
        return self::UNITES[$type] ?? (string) $type;
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;
        $font = 'Calibri';
        $blocs = $this->suivi['blocs'];
        $n = count($blocs);
        $first = 4;               // first bloc column (A date, B engrais, C unité)
        $totalCol = $first + $n;  // TOTAL
        $coutCol = $totalCol + 1; // COÛT
        $last = $this->col($coutCol);
        $blocRange = fn (int $row) => $this->range($first, $row, $totalCol - 1, $row);
        $header = fn (int $row) => $this->style("A{$row}:{$last}{$row}", $this->font($font, 11, true), $this->fill('C5D9F1'), $this->center(), $this->borders(Border::BORDER_THIN));
        $section = function (int $row, string $title) use ($ws, $last, $font) {
            $this->text("A{$row}", $title);
            $ws->mergeCells("A{$row}:{$last}{$row}");
            $this->style("A{$row}:{$last}{$row}", $this->font($font, 12, true), $this->fill('E2EFDA'), $this->center(), $this->borders(Border::BORDER_THIN));
        };
        $blocHeaders = function (int $row, string $a, string $b) use ($blocs, $first, $totalCol, $coutCol) {
            $this->text("A{$row}", $a);
            $this->text("B{$row}", $b);
            $this->text("C{$row}", 'UNITÉ');
            foreach ($blocs as $i => $bloc) {
                $this->text($this->cell($first + $i, $row), $bloc['name']);
            }
            $this->text($this->cell($totalCol, $row), 'TOTAL');
            $this->text($this->cell($coutCol, $row), 'COÛT (DH)');
        };

        $this->text('A1', trim('SITUATION DES ENGRAIS - '.$this->farm, ' -'));
        $this->text('A2', $this->period);
        $ws->mergeCells("A1:{$last}1");
        $ws->mergeCells("A2:{$last}2");
        $this->style('A1', $this->font($font, 14, true), $this->center());
        $this->style('A2', $this->font($font, 12, true), $this->center());

        // The journal, with each bloc's surface right under its name.
        $h = $first;
        $blocHeaders($h, 'DATE', "TYPE D'ENGRAIS");
        $header($h);
        $s = $h + 1;
        $this->text("B{$s}", 'SURFACE (ha)');
        foreach ($blocs as $i => $bloc) {
            if ($bloc['area_ha'] > 0) {
                $ws->setCellValue($this->cell($first + $i, $s), $bloc['area_ha']);
            }
        }
        $this->formula($this->cell($totalCol, $s), '=SUM('.$blocRange($s).')');
        $this->style("A{$s}:{$last}{$s}", $this->font($font, 10, false, '595959'), ['font' => ['italic' => true]], $this->fill('F2F2F2'), $this->borders(Border::BORDER_THIN));
        $surface = fn (int $i) => '$'.$this->col($first + $i).'$'.$s;
        $surfaceTotale = '$'.$this->col($totalCol).'$'.$s;

        $r = $s + 1;
        $j1 = $r;
        foreach ($this->suivi['lignes'] as $ligne) {
            $ws->setCellValue("A{$r}", \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime($ligne['date'])));
            $this->text("B{$r}", $ligne['engrais']);
            $this->text("C{$r}", $this->unite($ligne['unit_type']));
            foreach ($ligne['quantites'] as $i => $q) {
                if ($q != 0) {
                    $ws->setCellValue($this->cell($first + $i, $r), $q);
                }
            }
            $this->formula($this->cell($totalCol, $r), '=SUM('.$blocRange($r).')');
            $ws->setCellValue($this->cell($coutCol, $r), round($ligne['cout'], 2));
            $r++;
        }
        $j2 = max($j1, $r - 1);
        if (! $this->suivi['lignes']) {
            $this->text("B{$r}", 'Aucune sortie d\'engrais sur la période');
            $r++;
        }
        $this->style("A{$j1}:{$last}".($r - 1), $this->font($font, 11), $this->borders(Border::BORDER_THIN));
        $this->style("A{$j1}:A".($r - 1), ['numberFormat' => ['formatCode' => 'dd/mm/yyyy'], 'alignment' => ['horizontal' => 'center']]);
        $this->text("A{$r}", 'TOTAL COÛT');
        $ws->mergeCells("A{$r}:".$this->col($totalCol)."{$r}");
        $this->formula($this->cell($coutCol, $r), "=SUM({$this->col($coutCol)}{$j1}:{$this->col($coutCol)}{$j2})");
        $this->style("A{$r}:{$last}{$r}", $this->font($font, 11, true), $this->fill('FFF2CC'), $this->borders(Border::BORDER_THIN));
        $r += 2;

        // Récapitulatif per engrais: quantities (SUMIF over the journal), then dose per hectare.
        $section($r++, 'RÉCAPITULATIF PAR ENGRAIS');
        $blocHeaders($r, '', 'ENGRAIS');
        $header($r++);
        $recap = [];
        foreach ($this->suivi['engrais'] as $engrais) {
            $this->text("B{$r}", $engrais['engrais']);
            $this->text("C{$r}", $this->unite($engrais['unit_type']));
            for ($i = 0; $i <= $n; $i++) { // every bloc, then the cost
                $c = $this->col($i < $n ? $first + $i : $coutCol);
                $this->formula("{$c}{$r}", "=SUMIF(\$B\${$j1}:\$B\${$j2},\$B{$r},{$c}\${$j1}:{$c}\${$j2})");
            }
            $this->formula($this->cell($totalCol, $r), '=SUM('.$blocRange($r).')');
            $recap[] = $r++;
        }
        if ($recap) {
            $this->style("A{$recap[0]}:{$last}".end($recap), $this->font($font, 11), $this->borders(Border::BORDER_THIN));
            $this->style($this->cell($totalCol, $recap[0]).':'.$this->cell($totalCol, end($recap)), ['font' => ['bold' => true]]);
        }
        $r++;

        $section($r++, 'DOSE PAR HECTARE (quantité / surface du bloc)');
        $blocHeaders($r, '', 'ENGRAIS');
        $this->text($this->cell($totalCol, $r), 'FERME');
        $this->text($this->cell($coutCol, $r), 'COÛT / ha');
        $header($r++);
        $d1 = $r;
        foreach ($recap as $row) {
            $this->formula("B{$r}", "=B{$row}");
            $this->formula("C{$r}", "=C{$row}&\"/ha\"");
            foreach ($blocs as $i => $bloc) {
                if ($bloc['area_ha'] > 0) {
                    $c = $this->col($first + $i);
                    $this->formula("{$c}{$r}", "={$c}{$row}/{$surface($i)}");
                }
            }
            $this->formula($this->cell($totalCol, $r), '=IF('.$surfaceTotale.'>0,'.$this->cell($totalCol, $row).'/'.$surfaceTotale.',"")');
            $this->formula($this->cell($coutCol, $r), '=IF('.$surfaceTotale.'>0,'.$this->cell($coutCol, $row).'/'.$surfaceTotale.',"")');
            $r++;
        }
        if ($recap) {
            $this->style("A{$d1}:{$last}".($r - 1), $this->font($font, 11), $this->borders(Border::BORDER_THIN), ['numberFormat' => ['formatCode' => '#,##0.00']]);
        }
        $r++;

        // Cost per bloc and per hectare.
        $section($r++, 'COÛT PAR BLOC');
        $blocHeaders($r, '', '');
        $this->text("C{$r}", '');
        $this->text($this->cell($coutCol, $r), '');
        $header($r++);
        $cr = $r;
        $this->text("B{$r}", 'COÛT (DH)');
        foreach ($this->suivi['couts'] as $i => $cout) {
            $ws->setCellValue($this->cell($first + $i, $r), round($cout, 2));
        }
        $this->formula($this->cell($totalCol, $r), '=SUM('.$blocRange($r).')');
        $r++;
        $this->text("B{$r}", 'COÛT / ha (DH)');
        foreach ($blocs as $i => $bloc) {
            if ($bloc['area_ha'] > 0) {
                $c = $this->col($first + $i);
                $this->formula("{$c}{$r}", "={$c}{$cr}/{$surface($i)}");
            }
        }
        $this->formula($this->cell($totalCol, $r), '=IF('.$surfaceTotale.'>0,'.$this->cell($totalCol, $cr).'/'.$surfaceTotale.',"")');
        $this->style("A{$cr}:{$last}{$r}", $this->font($font, 11, true), $this->borders(Border::BORDER_THIN), ['numberFormat' => ['formatCode' => '#,##0.00']]);
        $r += 2;

        // Month by month.
        if ($this->suivi['par_mois']) {
            $section($r++, 'PAR MOIS');
            $blocHeaders($r, 'MOIS', 'ENGRAIS');
            $header($r++);
            $m1 = $r;
            foreach ($this->suivi['par_mois'] as $ligne) {
                $this->text("A{$r}", ucfirst(self::MOIS[(int) substr($ligne['mois'], 5, 2) - 1]).' '.substr($ligne['mois'], 0, 4));
                $this->text("B{$r}", $ligne['engrais']);
                $this->text("C{$r}", $this->unite($ligne['unit_type']));
                foreach ($ligne['quantites'] as $i => $q) {
                    if ($q != 0) {
                        $ws->setCellValue($this->cell($first + $i, $r), $q);
                    }
                }
                $this->formula($this->cell($totalCol, $r), '=SUM('.$blocRange($r).')');
                $ws->setCellValue($this->cell($coutCol, $r), round($ligne['cout'], 2));
                $r++;
            }
            $this->style("A{$m1}:{$last}".($r - 1), $this->font($font, 11), $this->borders(Border::BORDER_THIN));
            $this->style("A{$m1}:A".($r - 1), ['alignment' => ['horizontal' => 'center']]);
        }

        $this->style($this->col($first).($h + 1).":{$last}{$r}", ['alignment' => ['horizontal' => 'center']]);
        $this->style($this->col($first).$j1.":{$last}{$r}", ['numberFormat' => ['formatCode' => '#,##0.##']]);
        $this->style($this->col($coutCol).$j1.":{$last}{$r}", ['numberFormat' => ['formatCode' => '#,##0.00']]);
        $this->style($this->col($first).$d1.':'.$last.($cr + 1), ['numberFormat' => ['formatCode' => '#,##0.00']]);

        $ws->getColumnDimension('A')->setWidth(12);
        $ws->getColumnDimension('B')->setWidth(26);
        $ws->getColumnDimension('C')->setWidth(9);
        for ($c = $first; $c <= $coutCol; $c++) {
            $ws->getColumnDimension($this->col($c))->setWidth(13);
        }
        $ws->freezePane('A'.($s + 1));
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setPrintArea("A1:{$last}{$r}");
    }
}
