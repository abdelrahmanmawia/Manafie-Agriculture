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

    /** Thin grey lines inside, a medium frame around: one table. */
    private function table(string $range): void
    {
        $this->style($range, ['borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'BFBFBF']],
            'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '375623']],
        ]]);
    }

    /**
     * Lines grouped by $key (a day, a month): the key's cell is merged over its lines, each group
     * shaded in turn and set apart by a line, like the farm's sheet. $write fills one line's row.
     */
    private function groups(array $lines, string $key, int $row, string $last, callable $label, callable $write): int
    {
        $shade = false;
        foreach (collect($lines)->groupBy($key) as $value => $group) {
            $start = $row;
            foreach ($group as $line) {
                $write($line, $row);
                $this->ws->getRowDimension($row)->setRowHeight(18);
                $row++;
            }
            $end = $row - 1;
            $label("A{$start}", (string) $value);
            if ($end > $start) {
                $this->ws->mergeCells("A{$start}:A{$end}");
            }
            if ($shade) {
                $this->style("B{$start}:{$last}{$end}", $this->fill('EEF5E9'));
            }
            $this->style("A{$start}:A{$end}", $this->fill('E2EFDA'), $this->font('Calibri', 11, true), $this->center());
            $this->style("A{$start}:{$last}{$start}", ['borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '7F7F7F']]]]);
            $shade = ! $shade;
        }

        return $row;
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
        $qty = ['numberFormat' => ['formatCode' => '#,##0.00;-#,##0.00;;@']]; // an empty bloc stays blank
        $money = ['numberFormat' => ['formatCode' => '#,##0.00 "DH";-#,##0.00 "DH";;@']];
        $header = function (int $row) use ($last, $font, $ws) {
            $this->style("A{$row}:{$last}{$row}", $this->font($font, 11, true, 'FFFFFF'), $this->fill('548235'), $this->center());
            $ws->getRowDimension($row)->setRowHeight(30);
        };
        $section = function (int $row, string $title) use ($ws, $last, $font) {
            $this->text("A{$row}", $title);
            $ws->mergeCells("A{$row}:{$last}{$row}");
            $this->style("A{$row}:{$last}{$row}", $this->font($font, 12, true, '375623'), $this->fill('E2EFDA'), $this->center(),
                ['borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '375623']]]]);
            $ws->getRowDimension($row)->setRowHeight(24);
        };
        $blocHeaders = function (int $row, string $a, string $b, string $total = 'TOTAL', string $cout = 'COÛT', bool $surface = true) use ($blocs, $first, $totalCol, $coutCol) {
            $this->text("A{$row}", $a);
            $this->text("B{$row}", $b);
            $this->text("C{$row}", 'UNITÉ');
            foreach ($blocs as $i => $bloc) {
                $this->text($this->cell($first + $i, $row), $surface && $bloc['area_ha'] > 0 ? $bloc['name']."\n".$this->num($bloc['area_ha']).' ha' : $bloc['name']);
            }
            $this->text($this->cell($totalCol, $row), $total);
            $this->text($this->cell($coutCol, $row), $cout);
        };
        // The recap tables have no date: their first cell spans A:B.
        $name = function (int $row, string $value, bool $formula = false) use ($ws) {
            $formula ? $this->formula("A{$row}", $value) : $this->text("A{$row}", $value);
            $ws->mergeCells("A{$row}:B{$row}");
        };
        $moisLabel = fn (string $mois) => ucfirst(self::MOIS[(int) substr($mois, 5, 2) - 1]).' '.substr($mois, 0, 4);

        // Title.
        $this->text('A1', 'SITUATION DES ENGRAIS');
        $this->text('A2', trim($this->farm.'  —  '.$this->period, ' —'));
        $ws->mergeCells("A1:{$last}1");
        $ws->mergeCells("A2:{$last}2");
        $this->style("A1:{$last}1", $this->font($font, 16, true, 'FFFFFF'), $this->fill('375623'), $this->center());
        $this->style("A2:{$last}2", $this->font($font, 12, true, '375623'), $this->fill('E2EFDA'), $this->center());
        $ws->getRowDimension(1)->setRowHeight(30);
        $ws->getRowDimension(2)->setRowHeight(20);

        // The journal, each bloc's surface right under its name (the doses divide by it).
        $h = $first;
        $blocHeaders($h, 'DATE', "TYPE D'ENGRAIS", 'TOTAL', 'COÛT', false);
        $header($h);
        $s = $h + 1;
        $this->text("B{$s}", 'Surface (ha)');
        foreach ($blocs as $i => $bloc) {
            if ($bloc['area_ha'] > 0) {
                $ws->setCellValue($this->cell($first + $i, $s), $bloc['area_ha']);
            }
        }
        $this->formula($this->cell($totalCol, $s), '=SUM('.$blocRange($s).')');
        $this->style("A{$s}:{$last}{$s}", $this->font($font, 10, false, '595959'), ['font' => ['italic' => true]], $this->fill('F2F2F2'), $this->center());
        $surface = fn (int $i) => '$'.$this->col($first + $i).'$'.$s;
        $surfaceTotale = '$'.$this->col($totalCol).'$'.$s;

        $line = function (array $ligne, int $row) use ($ws, $first, $totalCol, $coutCol, $blocRange) {
            $this->text("B{$row}", $ligne['engrais']);
            $this->text("C{$row}", $this->unite($ligne['unit_type']));
            foreach ($ligne['quantites'] as $i => $q) {
                if ($q != 0) {
                    $ws->setCellValue($this->cell($first + $i, $row), $q);
                }
            }
            $this->formula($this->cell($totalCol, $row), '=SUM('.$blocRange($row).')');
            $ws->setCellValue($this->cell($coutCol, $row), round($ligne['cout'], 2));
        };
        // Quantities centred, TOTAL bold, COÛT in DH, over rows $r1..$r2.
        $body = function (int $r1, int $r2) use ($font, $totalCol, $coutCol, $last, $qty, $money) {
            $this->style("B{$r1}:{$last}{$r2}", $this->font($font, 11), ['alignment' => ['vertical' => 'center']]);
            $this->style("C{$r1}:".$this->cell($totalCol, $r2), ['alignment' => ['horizontal' => 'center']], $qty);
            $this->style($this->cell($totalCol, $r1).':'.$this->cell($totalCol, $r2), ['font' => ['bold' => true]]);
            $this->style($this->cell($coutCol, $r1).':'.$this->cell($coutCol, $r2), $money, ['alignment' => ['horizontal' => 'right']]);
        };

        $j1 = $s + 1;
        $r = $this->groups($this->suivi['lignes'], 'date', $j1, $last,
            function (string $cell, string $date) use ($ws) {
                $ws->setCellValue($cell, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime($date)));
                $this->style($cell, ['numberFormat' => ['formatCode' => 'dd/mm/yyyy']]);
            }, $line);
        if (! $this->suivi['lignes']) {
            $this->text("B{$r}", "Aucune sortie d'engrais sur la période");
            $r++;
        }
        $j2 = max($j1, $r - 1);
        $body($j1, $j2);
        $this->text("A{$r}", 'TOTAL COÛT DE LA PÉRIODE');
        $ws->mergeCells("A{$r}:".$this->col($totalCol)."{$r}");
        $this->formula($this->cell($coutCol, $r), "=SUM({$this->col($coutCol)}{$j1}:{$this->col($coutCol)}{$j2})");
        $this->style("A{$r}:{$last}{$r}", $this->font($font, 12, true), $this->fill('FFF2CC'), $money, ['alignment' => ['horizontal' => 'right', 'vertical' => 'center']]);
        $ws->getRowDimension($r)->setRowHeight(22);
        $this->table("A{$h}:{$last}{$r}");
        $r += 2;

        // Récapitulatif per engrais: quantities (SUMIF over the journal), then dose per hectare.
        $section($r++, 'RÉCAPITULATIF PAR ENGRAIS');
        $rh = $r;
        $blocHeaders($r, '', '', 'TOTAL FERME');
        $name($r, 'ENGRAIS');
        $header($r++);
        $recap = [];
        foreach ($this->suivi['engrais'] as $engrais) {
            $name($r, $engrais['engrais']);
            $this->text("C{$r}", $this->unite($engrais['unit_type']));
            for ($i = 0; $i <= $n; $i++) { // every bloc, then the cost
                $c = $this->col($i < $n ? $first + $i : $coutCol);
                $this->formula("{$c}{$r}", "=SUMIF(\$B\${$j1}:\$B\${$j2},\$A{$r},{$c}\${$j1}:{$c}\${$j2})");
            }
            $this->formula($this->cell($totalCol, $r), '=SUM('.$blocRange($r).')');
            $ws->getRowDimension($r)->setRowHeight(18);
            $recap[] = $r++;
        }
        if ($recap) {
            $body($recap[0], end($recap));
            $this->style("A{$recap[0]}:B".end($recap), $this->font($font, 11, true), ['alignment' => ['vertical' => 'center', 'horizontal' => 'left', 'indent' => 1]]);
            $this->style($this->cell($totalCol, $recap[0]).':'.$this->cell($totalCol, end($recap)), $this->fill('EEF5E9'));
            $this->table("A{$rh}:{$last}".end($recap));
        }
        $r++;

        $section($r++, 'DOSE PAR HECTARE (quantité ÷ surface du bloc)');
        $dh = $r;
        $blocHeaders($r, '', '', 'FERME', 'COÛT / ha');
        $name($r, 'ENGRAIS');
        $header($r++);
        $d1 = $r;
        foreach ($recap as $row) {
            $name($r, "=A{$row}", true);
            $this->formula("C{$r}", "=C{$row}&\"/ha\"");
            foreach ($blocs as $i => $bloc) {
                if ($bloc['area_ha'] > 0) {
                    $c = $this->col($first + $i);
                    $this->formula("{$c}{$r}", "={$c}{$row}/{$surface($i)}");
                }
            }
            $this->formula($this->cell($totalCol, $r), '=IF('.$surfaceTotale.'>0,'.$this->cell($totalCol, $row).'/'.$surfaceTotale.',"")');
            $this->formula($this->cell($coutCol, $r), '=IF('.$surfaceTotale.'>0,'.$this->cell($coutCol, $row).'/'.$surfaceTotale.',"")');
            $ws->getRowDimension($r)->setRowHeight(18);
            $r++;
        }
        if ($recap) {
            $body($d1, $r - 1);
            $this->style("A{$d1}:B".($r - 1), $this->font($font, 11, true), ['alignment' => ['vertical' => 'center', 'horizontal' => 'left', 'indent' => 1]]);
            $this->style($this->cell($totalCol, $d1).':'.$this->cell($totalCol, $r - 1), $this->fill('EEF5E9'));
            $this->table("A{$dh}:{$last}".($r - 1));
        }
        $r++;

        // Cost per bloc and per hectare.
        $section($r++, 'COÛT PAR BLOC');
        $ch = $r;
        $blocHeaders($r, '', '', 'FERME', '');
        $this->text("C{$r}", '');
        $name($r, '');
        $header($r++);
        $cr = $r;
        $name($r, 'COÛT (DH)');
        foreach ($this->suivi['couts'] as $i => $cout) {
            $ws->setCellValue($this->cell($first + $i, $r), round($cout, 2));
        }
        $this->formula($this->cell($totalCol, $r), '=SUM('.$blocRange($r).')');
        $r++;
        $name($r, 'COÛT / ha (DH)');
        foreach ($blocs as $i => $bloc) {
            if ($bloc['area_ha'] > 0) {
                $c = $this->col($first + $i);
                $this->formula("{$c}{$r}", "={$c}{$cr}/{$surface($i)}");
            }
        }
        $this->formula($this->cell($totalCol, $r), '=IF('.$surfaceTotale.'>0,'.$this->cell($totalCol, $cr).'/'.$surfaceTotale.',"")');
        $this->style("A{$cr}:{$last}{$r}", $this->font($font, 11, true), $this->fill('FFF2CC'), ['numberFormat' => ['formatCode' => '#,##0.00']], ['alignment' => ['vertical' => 'center']]);
        $this->style($this->col($first)."{$cr}:{$last}{$r}", ['alignment' => ['horizontal' => 'center']]);
        $ws->getRowDimension($cr)->setRowHeight(20);
        $ws->getRowDimension($r)->setRowHeight(20);
        $this->table("A{$ch}:{$last}{$r}");
        $end = $r;
        $r += 2;

        // Month by month, the month merged over its engrais.
        if ($this->suivi['par_mois']) {
            $section($r++, 'PAR MOIS');
            $mh = $r;
            $blocHeaders($r, 'MOIS', 'ENGRAIS');
            $header($r++);
            $m1 = $r;
            $r = $this->groups($this->suivi['par_mois'], 'mois', $m1, $last,
                fn (string $cell, string $mois) => $this->text($cell, $moisLabel($mois)), $line);
            $body($m1, $r - 1);
            $this->table("A{$mh}:{$last}".($r - 1));
            $end = $r - 1;
        }

        $ws->getColumnDimension('A')->setWidth(14);
        $ws->getColumnDimension('B')->setWidth(28);
        $ws->getColumnDimension('C')->setWidth(8);
        for ($c = $first; $c <= $totalCol; $c++) {
            $ws->getColumnDimension($this->col($c))->setWidth(13);
        }
        $ws->getColumnDimension($last)->setWidth(16);
        $ws->setShowGridlines(false);
        $ws->freezePane('C'.($s + 1));
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setHorizontalCentered(true)
            ->setPrintArea("A1:{$last}{$end}")->setRowsToRepeatAtTopByStartAndEnd($h, $s);
        $ws->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.6);
        $ws->getHeaderFooter()->setOddFooter('&L&8Suivi des engrais — '.$this->period.'&R&8Page &P / &N');
    }
}
