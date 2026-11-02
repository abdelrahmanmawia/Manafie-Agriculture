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
 * The Analyses page as a workbook, for the same filters (AnalyticsService): the key figures, the
 * net by operation (all of them, plus what's on none, adding up to the net) and by bloc, the
 * periods, and the harvest when there is one.
 */
class AnalyticsExport implements WithEvents, WithPreCalculateFormulas, WithTitle
{
    use WritesSheetCells;

    private const FONT = 'Calibri';

    private const FILL_HEADER = 'C5D9F1';

    private const FILL_TOTAL = 'B8D4F0';

    private const MONEY = '#,##0.00';

    private Worksheet $ws;

    private int $r = 1;

    public function __construct(private array $d, private array $contexte) {}

    public function title(): string
    {
        return 'ANALYSES';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->build($event->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $this->ws = $ws;

        $this->text('A1', 'ANALYSES & STATISTIQUES');
        $ws->mergeCells('A1:D1');
        $this->style('A1:D1', $this->font(self::FONT, 16, true, '3B7D23'), $this->center());
        $ws->getRowDimension(1)->setRowHeight(30);
        $this->r = 2;
        foreach ($this->contexte + ['Périodes' => $this->periodes()] as $label => $value) {
            $this->ligne([$label, $value]);
            $ws->mergeCells($this->range(2, $this->r - 1, 4, $this->r - 1));
            $this->style('A'.($this->r - 1), ['font' => ['bold' => true]]);
        }
        $this->r++;

        $this->chiffresCles();
        $this->netParOperation();
        $this->netParBloc();
        $this->evolution();
        $this->production();

        $ws->getColumnDimension('A')->setWidth(36);
        foreach (['B', 'C', 'D', 'E'] as $col) {
            $ws->getColumnDimension($col)->setWidth(18);
        }
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
    }

    private function periodes(): string
    {
        $trend = collect($this->d['trend']);
        if ($trend->isEmpty()) {
            return '—';
        }

        return $trend->count() === 1 ? $trend->first()->label : $trend->first()->label.' → '.$trend->last()->label;
    }

    private function chiffresCles(): void
    {
        $cout = $this->d['cout'];
        $this->titre('CHIFFRES CLÉS', 2);
        if ($cout) {
            $this->ligne(['Coût pour la ferme'.($cout['transport'] === null ? ' (hors transport)' : ''), $cout['total']], true);
            foreach (['ttc' => 'TTC intérim', 'net_direct' => 'Net direct', 'marge' => 'Marge 4 %', 'transport' => 'Transport (+ 10 %)'] as $key => $label) {
                if ($cout[$key] !== null) {
                    $this->ligne(['   '.$label, $cout[$key]]);
                }
            }
        }
        $this->ligne(['Net versé aux ouvriers', (float) $this->d['totalNet']], true);
        $this->ligne(['Ouvriers payés sur la période', $this->d['employeeCount']], false, '0');
        $this->ligne(['Net moyen par jour travaillé', round($this->d['netMoyenParJour'], 2)]);
        $this->ligne([($this->d['coutBaseNet'] ? 'Net' : 'Coût').' moyen / ha par quinzaine', round($this->d['avgCostPerHa'], 2)]);
        $this->r++;
    }

    private function netParOperation(): void
    {
        $this->titre('NET PAR OPÉRATION', 2);
        $this->entete(['Opération', 'Net (DH)']);
        $first = $this->r;
        foreach ($this->d['operations'] as $op) {
            $this->ligne([$op->name, (float) $op->total_net]);
        }
        foreach ([['jf', 'J.F non travaillés (sans opération)'], ['salaires', 'Salaires fixes (sans opération)'], ['a_preciser', 'Jours à préciser']] as [$key, $label]) {
            if (($this->d['horsBlocs'][$key] ?? 0) > 0) {
                $this->ligne([$label, $this->d['horsBlocs'][$key]]);
            }
        }
        $this->total('TOTAL', 'B', $first);
        $this->r++;
    }

    private function netParBloc(): void
    {
        $this->titre('NET PAR BLOC', 4);
        $this->entete(['Bloc', 'Surface (ha)', 'Net (DH)', 'Net / ha']);
        $first = $this->r;
        foreach ($this->d['blocCosts'] as $b) {
            $this->ligne([$b->name, $b->area_ha !== null ? (float) $b->area_ha : null, (float) $b->total_net, $b->charge_per_ha]);
        }
        $this->total('TOTAL DES BLOCS', 'C', $first);
        $this->r++;
    }

    private function evolution(): void
    {
        $this->titre('PAR PÉRIODE', 3);
        $this->entete(['Période', 'Net (DH)', ($this->d['coutBaseNet'] ? 'Net' : 'Coût').' / ha']);
        foreach ($this->d['trend'] as $t) {
            $this->ligne([$t->label.($t->en_cours ? ' (en cours)' : ''), (float) $t->total_net, $t->cost_per_ha]);
        }
        $this->r++;
    }

    private function production(): void
    {
        if ($this->d['totalHarvestKg'] <= 0) {
            return;
        }

        $this->titre('PRODUCTION', 2);
        $base = $this->d['coutBaseNet'] ? 'Net' : 'Coût';
        $this->ligne(['Total récolte (Kg)', $this->d['totalHarvestKg']]);
        $this->ligne(["Chiffre d'affaires (DH)", $this->d['totalHarvestRevenue']]);
        foreach ([["{$base} main d'œuvre / Kg", $this->d['costPerKg']], ['Rendement (Kg/ha)', $this->d['yieldPerHa']], ['Revenu / ha', $this->d['revenuePerHa']],
            ["Marge sur main-d'œuvre / ha", $this->d['margePerHa']], ["{$base} main d'œuvre % du CA", $this->d['laborCostPercentage']]] as [$label, $value]) {
            if ($value !== null) {
                $this->ligne([$label, round($value, 2)]);
            }
        }
        $this->r++;

        $this->titre('RÉCOLTE PAR VARIÉTÉ', 3);
        $this->entete(['Variété', 'Kg', 'Revenu (DH)']);
        foreach ($this->d['harvestByVariety'] as $h) {
            $this->ligne([(string) $h->variety, (float) $h->total_kg, (float) $h->total_revenue]);
        }
        $this->r++;

        $this->titre('RENDEMENT PAR BLOC', 5);
        $this->entete(['Bloc', 'Surface (ha)', 'Kg', 'Kg / ha', 'Revenu (DH)']);
        foreach ($this->d['harvestByBloc'] as $h) {
            $this->ligne([$h->name, $h->area_ha !== null ? (float) $h->area_ha : null, (float) $h->total_kg, $h->yield_per_ha, (float) $h->total_revenue]);
        }
    }

    private function titre(string $label, int $cols): void
    {
        $range = $this->range(1, $this->r, $cols, $this->r);
        $this->text($this->cell(1, $this->r), $label);
        $this->ws->mergeCells($range);
        $this->style($range, $this->font(self::FONT, 12, true), $this->fill(self::FILL_TOTAL), $this->center(), $this->outline(Border::BORDER_MEDIUM));
        $this->ws->getRowDimension($this->r)->setRowHeight(24);
        $this->r++;
    }

    private function entete(array $labels): void
    {
        foreach ($labels as $i => $label) {
            $this->text($this->cell($i + 1, $this->r), $label);
        }
        $this->style($this->range(1, $this->r, count($labels), $this->r), $this->font(self::FONT, 10, true), $this->fill(self::FILL_HEADER), $this->center(), $this->borders(Border::BORDER_THIN));
        $this->r++;
    }

    private function ligne(array $values, bool $gras = false, string $format = self::MONEY): void
    {
        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }
            is_string($value)
                ? $this->text($this->cell($i + 1, $this->r), $value)
                : $this->ws->setCellValue($this->cell($i + 1, $this->r), $value);
        }
        $this->style($this->range(1, $this->r, count($values), $this->r), $this->font(self::FONT, 11, $gras), $this->borders(Border::BORDER_THIN));
        if (count($values) > 1) {
            $this->style($this->range(2, $this->r, count($values), $this->r), ['numberFormat' => ['formatCode' => $format]]);
        }
        $this->r++;
    }

    private function total(string $label, string $col, int $first): void
    {
        $this->text('A'.$this->r, $label);
        $this->formula($col.$this->r, "=SUM({$col}{$first}:{$col}".($this->r - 1).')');
        $this->style("A{$this->r}:{$col}{$this->r}", $this->font(self::FONT, 11, true), $this->fill(self::FILL_TOTAL), $this->borders(Border::BORDER_THIN));
        $this->style($col.$this->r, ['numberFormat' => ['formatCode' => self::MONEY]]);
        $this->r++;
    }
}
