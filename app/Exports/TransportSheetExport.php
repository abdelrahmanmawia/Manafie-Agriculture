<?php

namespace App\Exports;

use App\Exports\Concerns\WritesSheetCells;
use App\Models\Equipe;
use App\Models\Farm;
use App\Models\PointageRecord;
use App\Services\TransportService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The TRANSPORT sheet of the global export (DivisionsPointageExport), laid out like the division
 * fiches (PointageExport): per vehicle, its équipes and driver, the riders each day of the period
 * (TransportService::daily — distinct workers present whose day carries the vehicle; "ABS" when
 * marked absent, the vehicle costs nothing that day), then JOURS, PERS × J, PRIX / PERS, NET / J
 * and TOTAL as the fiche's own formulas; a SANS TRANSPORT line counts the present workers on no
 * vehicle, so TOTAL PAR JOUR equals everyone present. Vehicles are farm-pooled: one sheet for all.
 */
class TransportSheetExport implements WithEvents, WithTitle
{
    use WritesSheetCells;

    private const FILL_HEADER = 'C5D9F1';

    private const FILL_TOTAL = 'B8D4F0';

    private const FILL_MUTED = 'F2F2F2';

    private const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private const FIRST_DAY_COL = 5; // A N°, B TRANSPORT, C CHAUFFEUR, D ÉQUIPE(S)

    private const HEADER_ROW = 5;

    private Worksheet $ws;

    public function __construct(private Farm $farm, private Carbon $start, private Carbon $end) {}

    public function title(): string
    {
        return 'TRANSPORT';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;
        $font = 'Calibri';
        $days = array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($this->start, $this->end)));
        $n = count($days);
        $d1 = self::FIRST_DAY_COL;
        $dn = $d1 + $n - 1;
        [$cJours, $cPers, $cPrix, $cNetJ, $cTotal] = [$dn + 1, $dn + 2, $dn + 3, $dn + 4, $dn + 5];
        $last = $cTotal;

        $daily = app(TransportService::class)->daily($this->start, $this->end, $this->farm->id);
        $equipes = Equipe::where('farm_id', $this->farm->id)->whereNotNull('transport_vehicle_id')->orderBy('name')->get()->groupBy('transport_vehicle_id');
        // Present workers that day on no vehicle (an équipe without transport, or none at all).
        $sansTransport = PointageRecord::query()
            ->whereHas('quinzaine.enterprise', fn ($q) => $q->where('farm_id', $this->farm->id))
            ->whereNull('transport_vehicle_id')->where('present', true)->where('nature', PointageRecord::NATURE_JOUR)
            ->whereDate('date', '>=', $this->start->toDateString())->whereDate('date', '<=', $this->end->toDateString())
            ->groupBy(DB::raw('DATE(date)'))
            ->selectRaw('DATE(date) as day, COUNT(DISTINCT employee_id) as n')
            ->pluck('n', 'day')->mapWithKeys(fn ($v, $k) => [Carbon::parse($k)->toDateString() => (int) $v]);

        // Title, as on the division fiches.
        $period = ($this->start->day <= 15 ? '1re' : '2e').' Quinzaine '.self::MONTHS[$this->start->month].' '.$this->start->year;
        $this->text('A1', 'TRANSPORT');
        $ws->mergeCells('A1:B2');
        $this->style('A1:B2', $this->font($font, 16, true, '3B7D23', true), $this->center(), $this->outline(Border::BORDER_MEDIUM));
        foreach ([1 => 'FICHE DE TRANSPORT DES OUVRIERS', 2 => $period] as $row => $label) {
            $range = $this->range(3, $row, $cPrix, $row);
            $this->text($this->cell(3, $row), $label);
            $ws->mergeCells($range);
            $this->style($range, $this->font($font, 14, true, null, true), $this->center(), $this->outline(Border::BORDER_MEDIUM));
        }
        $campagne = $this->range($cNetJ, 1, $cTotal, 2);
        $this->text($this->cell($cNetJ, 1), 'CAMPAGNE  :   '.$this->start->year);
        $ws->mergeCells($campagne);
        $this->style($campagne, $this->font($font, 14, true), $this->center(), $this->outline(Border::BORDER_MEDIUM));
        $this->text('A3', 'LA SOMME   :');
        $ws->mergeCells('A3:B3');
        $this->text('D3', 'DH');
        $this->style('A3:D3', $this->font($font, 14, true, 'FF0000'), ['alignment' => ['vertical' => 'center']]);
        $this->style('C3', ['numberFormat' => ['formatCode' => '#,##0.00']]);
        foreach ([1 => 31.8, 2 => 31.8, 3 => 36, 4 => 12] as $row => $height) {
            $ws->getRowDimension($row)->setRowHeight($height);
        }

        // Header.
        $h = self::HEADER_ROW;
        $labels = [1 => 'N°', 2 => 'TRANSPORT', 3 => 'CHAUFFEUR', 4 => 'ÉQUIPE(S)'];
        foreach ($days as $i => $day) {
            $labels[$d1 + $i] = Carbon::parse($day)->format('j');
        }
        $labels += [$cJours => 'JOURS', $cPers => 'PERS. × J', $cPrix => 'PRIX / PERS', $cNetJ => 'NET / J (forfait)', $cTotal => 'TOTAL'];
        foreach ($labels as $col => $label) {
            $this->text($this->cell($col, $h), $label);
        }
        $this->style($this->range(1, $h, $last, $h), $this->font($font, 10, true), $this->fill(self::FILL_HEADER), $this->center(), $this->borders(Border::BORDER_THIN), $this->outline(Border::BORDER_MEDIUM));
        $ws->getRowDimension($h)->setRowHeight(40.05);

        // One line per vehicle.
        $r = $h + 1;
        $first = $r;
        $dayRange = fn (int $row) => $this->range($d1, $row, $dn, $row);
        foreach ($daily['vehicles']->values() as $i => $vehicle) {
            $ws->setCellValue("A{$r}", $i + 1);
            $this->text("B{$r}", (string) $vehicle->code);
            $this->text("C{$r}", (string) $vehicle->driver_name);
            $this->text("D{$r}", ($equipes[$vehicle->id] ?? collect())->pluck('name')->implode(' + '));
            foreach ($days as $j => $day) {
                $cell = $daily['days'][$vehicle->id][$day] ?? null;
                if ($cell && $cell['absent']) {
                    $this->text($this->cell($d1 + $j, $r), 'ABS');
                } elseif ($cell && $cell['riders'] > 0) {
                    $ws->setCellValue($this->cell($d1 + $j, $r), $cell['riders']);
                }
            }
            // JOURS = days it rode (a number in the day cell; "ABS" days are text, not counted).
            $this->formula($this->cell($cJours, $r), '=COUNT('.$dayRange($r).')');
            $this->formula($this->cell($cPers, $r), '=SUM('.$dayRange($r).')');
            if ($vehicle->price_per_person !== null) {
                $ws->setCellValue($this->cell($cPrix, $r), (float) $vehicle->price_per_person);
            }
            if ($vehicle->fixed_net_per_day !== null) {
                $ws->setCellValue($this->cell($cNetJ, $r), (float) $vehicle->fixed_net_per_day);
            }
            // TransportService::dailyCost: the flat net per day when set, else riders × price.
            $L = fn ($c) => $this->cell($c, $r);
            $this->formula($L($cTotal), "=IF({$L($cNetJ)}<>\"\",{$L($cJours)}*{$L($cNetJ)},{$L($cPers)}*{$L($cPrix)})");
            $r++;
        }
        $vehiclesLast = $r - 1;
        // Present workers on no vehicle: counted, not charged.
        $this->text("B{$r}", 'SANS TRANSPORT');
        $this->text("D{$r}", 'ouvriers présents sans véhicule');
        foreach ($days as $j => $day) {
            if (($sansTransport[$day] ?? 0) > 0) {
                $ws->setCellValue($this->cell($d1 + $j, $r), $sansTransport[$day]);
            }
        }
        $this->formula($this->cell($cPers, $r), '=SUM('.$dayRange($r).')');
        $this->style($this->range(1, $r, $last, $r), $this->fill(self::FILL_MUTED), ['font' => ['italic' => true, 'color' => ['rgb' => '595959']]]);
        $lastRow = $r;

        $this->style($this->range(1, $first, $last, $lastRow), $this->font($font, 12), $this->center(),
            ['borders' => ['vertical' => ['borderStyle' => Border::BORDER_THIN], 'outline' => ['borderStyle' => Border::BORDER_THIN], 'horizontal' => ['borderStyle' => Border::BORDER_HAIR]]]);
        $this->style($this->range(2, $first, 4, $lastRow), ['alignment' => ['horizontal' => 'left', 'wrapText' => true]]);
        $this->style($this->range(2, $first, 2, $vehiclesLast), ['font' => ['bold' => true]]);
        $this->style($this->range($cPrix, $first, $cTotal, $lastRow), ['numberFormat' => ['formatCode' => '#,##0.00']]);
        $this->style($this->range($cTotal, $first, $cTotal, $lastRow), ['font' => ['bold' => true, 'size' => 14]]);
        for ($row = $first; $row <= $lastRow; $row++) {
            $ws->getRowDimension($row)->setRowHeight(45.6);
        }

        // TOTAL PAR JOUR: everyone present that day, on a vehicle or not; the period's cost.
        $t = $lastRow + 1;
        $this->text($this->cell(2, $t), 'TOTAL PAR JOUR');
        $ws->mergeCells($this->range(2, $t, 4, $t));
        foreach (range($d1, $cPers) as $col) {
            $letter = $this->col($col);
            $this->formula("{$letter}{$t}", "=SUM({$letter}{$first}:{$letter}{$lastRow})");
        }
        $total = $this->col($cTotal);
        $this->formula("{$total}{$t}", "=SUM({$total}{$first}:{$total}{$vehiclesLast})");
        $this->style($this->range(2, $t, $last, $t), $this->font($font, 10, true), $this->center(), $this->borders(Border::BORDER_THIN));
        $this->style($this->range($d1, $t, $last, $t), $this->fill(self::FILL_TOTAL));
        $this->style($this->range($cTotal, $t, $cTotal, $t), ['font' => ['size' => 14], 'numberFormat' => ['formatCode' => '#,##0.00']]);
        $this->style($this->range(1, $h, $last, $t), $this->outline(Border::BORDER_MEDIUM));
        $ws->getRowDimension($t)->setRowHeight(40);
        $this->formula('C3', "=+{$total}{$t}");

        // Widths, print like the fiches (landscape, one page wide).
        foreach (['A' => 6.89, 'B' => 30, 'C' => 20, 'D' => 24] as $letter => $width) {
            $ws->getColumnDimension($letter)->setWidth($width);
        }
        foreach (range($d1, $dn) as $col) {
            $ws->getColumnDimension($this->col($col))->setWidth(6.5);
        }
        foreach ([$cJours => 8.5, $cPers => 10, $cPrix => 11, $cNetJ => 12, $cTotal => 15.89] as $col => $width) {
            $ws->getColumnDimension($this->col($col))->setWidth($width);
        }
        $ws->freezePane($this->cell($d1, $first));
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:'.$this->col($last).$t);
    }
}
