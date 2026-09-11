<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class StockInventoryExport implements FromView, ShouldAutoSize, WithTitle
{
    protected Collection $stockInventory;
    protected ?string $categoryName;

    public function __construct(Collection $stockInventory, ?string $categoryName = null)
    {
        $this->stockInventory = $stockInventory;
        $this->categoryName = $categoryName;
    }

    public function view(): View
    {
        return view('exports.stock_inventory', [
            'stockInventory' => $this->stockInventory,
            'categoryName' => $this->categoryName,
            'generatedAt' => now(),
        ]);
    }

    public function title(): string
    {
        $title = $this->categoryName ?? 'Inventaire';

        return substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], '', $title), 0, 31);
    }
}
