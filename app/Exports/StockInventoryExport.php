<?php

namespace App\Exports;

use App\Models\Farm;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class StockInventoryExport implements FromView, ShouldAutoSize, WithTitle
{
    protected Collection $stockInventory;
    protected ?string $categoryName;
    protected ?Farm $farm;

    public function __construct(Collection $stockInventory, ?string $categoryName = null, ?Farm $farm = null)
    {
        $this->stockInventory = $stockInventory;
        $this->categoryName = $categoryName;
        $this->farm = $farm;
    }

    public function view(): View
    {
        return view('exports.stock_inventory', [
            'stockInventory' => $this->stockInventory,
            'categoryName' => $this->categoryName,
            'farm' => $this->farm,
            'generatedAt' => now(),
        ]);
    }

    public function title(): string
    {
        $title = $this->categoryName ?? 'Inventaire';

        return substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', $title), 0, 31);
    }
}
