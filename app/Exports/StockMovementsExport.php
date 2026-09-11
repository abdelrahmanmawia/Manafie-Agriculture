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

    public function __construct(StockInventory $inventory)
    {
        $this->inventory = $inventory;
    }

    public function view(): View
    {
        return view('exports.stock_movements', [
            'inventory' => $this->inventory,
            'movements' => $this->inventory->product->stockMovements,
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
