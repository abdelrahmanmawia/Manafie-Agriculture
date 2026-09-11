<?php

namespace App\Http\Controllers;

use App\Exports\StockInventoryExport;
use App\Exports\StockMovementsExport;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\ProductCategory;
use App\Models\StockInventory;
use App\Models\Product;
use App\Services\StockAlertService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia; // Import Inertia
use Maatwebsite\Excel\Facades\Excel;

class StockInventoryController extends Controller
{
    /**
     * show()/count() trusted the route-bound/validated product with no ownership check —
     * any authenticated user could view or adjust another farm's inventory by walking IDs.
     */
    private function assertProductInScope(Request $request, Product $product): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $product->farm_id === $farmId, 403);
    }

    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = StockInventory::with('product')
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            });

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->has('low_stock')) {
            // quantity_on_hand lives on stock_inventory (this table), min_stock_level on
            // products — whereColumn inside whereHas previously compared both names against
            // the products table alone, which has no quantity_on_hand column.
            $query->whereHas('product', function ($q) {
                $q->whereColumn('products.min_stock_level', '>=', 'stock_inventory.quantity_on_hand');
            });
        }

        $inventory = $query->get();

        return Inertia::render('Stock/Inventory/Index', [
            'stockInventory' => $inventory,
            'categories' => ProductCategory::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Shared by show() and the two per-product movement exports — same eager-loads either way.
     */
    private function loadMovementRelations(StockInventory $inventory): StockInventory
    {
        $inventory->load([
            'product',
            'product.category',
            'product.stockMovements.performedBy',
            'product.stockMovements.reference' => function ($morphTo) {
                $morphTo->morphWith([
                    ManualStockEntry::class => ['bloc', 'sector', 'parcelle', 'vehicle'],
                    FuelTransaction::class => ['vehicle'],
                ]);
            },
        ]);

        return $inventory;
    }

    public function show(Request $request, StockInventory $inventory)
    {
        $this->assertProductInScope($request, $inventory->product);

        $this->loadMovementRelations($inventory);

        return Inertia::render('Stock/Inventory/Show', [
            'stockInventory' => $inventory,
        ]);
    }

    private function farmInventory(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        return StockInventory::with('product.category')
            ->when($farmId, fn ($query) => $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId)))
            ->when($request->filled('category_id'), fn ($query) => $query->whereHas('product', fn ($q) => $q->where('category_id', $request->category_id)))
            ->get();
    }

    /**
     * Resolves the optional `?category_id=` export filter — validated against the current farm
     * the same way every other route param here is (assertProductInScope's sibling), so a
     * category from another farm can't be probed for its name via the exported PDF title.
     */
    private function exportCategoryFor(Request $request): ?ProductCategory
    {
        if (! $request->filled('category_id')) {
            return null;
        }

        $farmId = $this->scopedFarmId($request);
        $category = ProductCategory::find($request->category_id);
        abort_unless($category && $farmId && $category->farm_id === $farmId, 403);

        return $category;
    }

    public function exportExcel(Request $request)
    {
        $category = $this->exportCategoryFor($request);
        $filename = ($category ? Str::slug($category->name) : 'Inventaire') . '_' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(
            new StockInventoryExport($this->farmInventory($request), $category?->name),
            $filename
        );
    }

    public function exportPdf(Request $request)
    {
        $category = $this->exportCategoryFor($request);

        $pdf = Pdf::loadView('exports.stock_inventory', [
            'stockInventory' => $this->farmInventory($request),
            'categoryName' => $category?->name,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $filename = ($category ? Str::slug($category->name) : 'Inventaire') . '_' . now()->format('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }

    public function exportMovementsExcel(Request $request, StockInventory $inventory)
    {
        $this->assertProductInScope($request, $inventory->product);
        $this->loadMovementRelations($inventory);

        $filename = 'Mouvements_' . Str::slug($inventory->product->name) . '_' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new StockMovementsExport($inventory), $filename);
    }

    public function exportMovementsPdf(Request $request, StockInventory $inventory)
    {
        $this->assertProductInScope($request, $inventory->product);
        $this->loadMovementRelations($inventory);

        $pdf = Pdf::loadView('exports.stock_movements', [
            'inventory' => $inventory,
            'movements' => $inventory->product->stockMovements,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape');

        $filename = 'Mouvements_' . Str::slug($inventory->product->name) . '_' . now()->format('Y-m-d') . '.pdf';

        return $pdf->download($filename);
    }

    // The magasinier's physical stock count: what's actually on the shelf vs. what the system
    // thinks is there. Superseded the older adjust() (kept no reason a count doesn't already
    // capture via the previous/counted note below) and no longer keys the inventory row by
    // batch_number — every other write path (stockIn, and this one) treats StockInventory as one
    // row per product, so scoping by batch here too would silently create a second, disconnected
    // row instead of updating the one the rest of the app already reads.
    public function count(Request $request)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'counted_quantity' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, $request) {
            $product = Product::findOrFail($validated['product_id']);
            $this->assertProductInScope($request, $product);

            $inventory = StockInventory::firstOrCreate(
                ['product_id' => $product->id],
                [
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                ]
            );

            $oldQuantity = $inventory->quantity_on_hand;
            $inventory->quantity_on_hand = $validated['counted_quantity'];
            $inventory->last_count_date = now();
            $inventory->save();

            $difference = $validated['counted_quantity'] - $oldQuantity;
            $inventory->product->stockMovements()->create([
                'movement_type' => 'adjustment',
                'quantity' => $difference,
                'unit_cost' => $product->unit_cost,
                'total_cost' => $product->unit_cost * $difference,
                'performed_by' => $request->user()->id,
                'date' => now(),
                'notes' => "Comptage de stock. Précédent : {$oldQuantity}, Compté : {$validated['counted_quantity']}",
            ]);

            StockAlertService::syncLowStock($product);
        });

        return redirect()->back()->with('success', 'Comptage de stock enregistré avec succès.');
    }
}
