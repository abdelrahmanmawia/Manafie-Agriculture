<?php

namespace App\Services;

use App\Models\Bloc;
use App\Models\ManualStockEntry;
use Illuminate\Support\Collection;

/**
 * The "Suivi des engrais" report (the farm's own sheet: DATE, TYPE D'ENGRAIS, one column per
 * bloc, TOTAL): the consumption sorties of the products whose category is marked engrais
 * (`product_categories.is_engrais`), over a StockReportPeriod. Quantities are per bloc in the
 * order of `blocs`; a sortie without a bloc gets a "Sans bloc" column only when there is one.
 * Costs are what the sorties' movements recorded (CUMP at the time). Shared by the page and
 * its Excel export (SuiviEngraisExport).
 */
class SuiviEngraisService
{
    public static function build(?int $farmId, StockReportPeriod $period): array
    {
        $entries = $period->apply(ManualStockEntry::query())
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('entry_type', 'consumption')
            ->whereHas('product.category', fn ($q) => $q->where('is_engrais', true))
            ->with('product', 'stockMovement')
            ->get();

        $blocs = Bloc::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->orderBy('name')
            ->get(['id', 'name', 'area_ha'])
            ->map(fn ($bloc) => ['id' => $bloc->id, 'name' => $bloc->name, 'area_ha' => (float) $bloc->area_ha])
            ->values();
        if ($entries->contains(fn ($entry) => ! $entry->bloc_id || ! $blocs->contains('id', $entry->bloc_id))) {
            $blocs->push(['id' => null, 'name' => 'Sans bloc', 'area_ha' => 0.0]);
        }
        $column = $blocs->filter(fn ($bloc) => $bloc['id'])->pluck('id')->flip(); // bloc id => its column
        $sansBloc = $blocs->count() - 1; // the "Sans bloc" column, when there is one
        $columnOf = fn ($entry) => $column[$entry->bloc_id] ?? $sansBloc;
        $surface = $blocs->sum('area_ha');

        // One line per day and engrais, in the farm's sheet order.
        $lignes = $entries
            ->groupBy(fn ($entry) => $entry->date->toDateString().'|'.$entry->product->name)
            ->sortKeys()
            ->map(fn ($group) => self::line($group, $blocs->count(), $columnOf) + ['date' => $group->first()->date->toDateString()])
            ->values();

        $engrais = $entries->groupBy(fn ($entry) => $entry->product->name)->sortKeys()
            ->map(function ($group) use ($blocs, $columnOf, $surface) {
                $line = self::line($group, $blocs->count(), $columnOf);
                // Dose per hectare: on each bloc its own area, overall the farm's.
                $line['doses'] = $blocs->map(fn ($bloc, $i) => $bloc['area_ha'] > 0 ? $line['quantites'][$i] / $bloc['area_ha'] : null)->all();
                $line['dose_totale'] = $surface > 0 ? $line['total'] / $surface : null;

                return $line;
            })->values();

        $couts = array_fill(0, $blocs->count(), 0.0);
        foreach ($entries as $entry) {
            $couts[$columnOf($entry)] += (float) ($entry->stockMovement->total_cost ?? 0);
        }
        $coutTotal = array_sum($couts);

        // Month by month when the period spans more than one.
        $mois = $entries->groupBy(fn ($entry) => $entry->date->format('Y-m'));
        $parMois = $mois->count() > 1
            ? $entries->groupBy(fn ($entry) => $entry->date->format('Y-m').'|'.$entry->product->name)->sortKeys()
                ->map(fn ($group) => self::line($group, $blocs->count(), $columnOf) + ['mois' => $group->first()->date->format('Y-m')])
                ->values()
            : collect();

        return [
            'blocs' => $blocs->all(),
            'surface' => $surface,
            'lignes' => $lignes->all(),
            'engrais' => $engrais->all(),
            'couts' => $couts,
            'couts_ha' => $blocs->map(fn ($bloc, $i) => $bloc['area_ha'] > 0 ? $couts[$i] / $bloc['area_ha'] : null)->all(),
            'cout_total' => $coutTotal,
            'cout_ha' => $surface > 0 ? $coutTotal / $surface : null,
            'par_mois' => $parMois->all(),
            'jours' => $lignes->pluck('date')->unique()->count(),
        ];
    }

    /** One engrais' sorties added up: per bloc column, total, cost. */
    private static function line(Collection $group, int $columns, callable $columnOf): array
    {
        $quantites = array_fill(0, $columns, 0.0);
        foreach ($group as $entry) {
            $quantites[$columnOf($entry)] += (float) $entry->quantity;
        }
        $product = $group->first()->product;

        return [
            'product_id' => $product->id,
            'engrais' => $product->name,
            'unit_type' => $product->unit_type,
            'quantites' => $quantites,
            'total' => array_sum($quantites),
            'cout' => (float) $group->sum(fn ($entry) => $entry->stockMovement->total_cost ?? 0),
        ];
    }
}
