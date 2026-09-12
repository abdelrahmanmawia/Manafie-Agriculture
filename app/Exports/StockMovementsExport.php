<?php

namespace App\Exports;

use App\Models\StockInventory;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class StockMovementsExport implements FromView, ShouldAutoSize, WithTitle
{
    protected StockInventory $inventory;
    protected array $exitTypesByKey;

    public function __construct(StockInventory $inventory, array $exitTypesByKey = [])
    {
        $this->inventory = $inventory;
        $this->exitTypesByKey = $exitTypesByKey;
    }

    public function view(): View
    {
        return view('exports.stock_movements', [
            'inventory' => $this->inventory,
            'farm' => $this->inventory->product->farm,
            // Oldest-first so the "Stock" column can accumulate a running balance top-to-bottom,
            // matching the paper ledger — the on-screen Inventory/Show.jsx table (untouched)
            // keeps its own newest-first order for that different use case.
            'movements' => $this->inventory->product->stockMovements
                ->sortBy([['date', 'asc'], ['id', 'asc']])
                ->values(),
            // A sortie with no note falls back to showing its own type (Consommation/Perte/...)
            // instead of a blank Observations cell — same labels the "Type de Sortie" dropdown uses.
            'exitTypesByKey' => $this->exitTypesByKey,
            'generatedAt' => now(),
        ]);
    }

    public function title(): string
    {
        // Excel sheet titles can't exceed 31 chars or contain []:*?/\ — the product name is
        // free-form user input, so both are trimmed/stripped here.
        $safeName = str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', $this->inventory->product->name);

        return substr($safeName, 0, 31) ?: 'Mouvements';
    }
}
