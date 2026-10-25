<?php

namespace App\Http\Controllers;

use App\Exports\StockInventoryExport;
use App\Exports\StockMovementsExport;
use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockInventory;
use App\Services\StockAlertService;
use App\Services\StockHistory;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia; // Import Inertia
use Maatwebsite\Excel\Facades\Excel;

class StockInventoryController extends Controller
{
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
                $q->where('products.min_stock_level', '>', 0)
                    ->whereColumn('products.min_stock_level', '>=', 'stock_inventory.quantity_on_hand');
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
        $this->assertFarmScope($request, $inventory->product->farm_id);

        $this->loadMovementRelations($inventory);

        return Inertia::render('Stock/Inventory/Show', [
            'stockInventory' => $inventory,
            // A sortie with no note falls back to showing its own type (Consommation/Perte/...)
            // instead of a blank cell — same list that fills the "Type de Sortie" dropdown.
            'exitTypes' => $this->exitTypesFor($this->scopedFarmId($request)),
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

        $category = ProductCategory::find($request->category_id);
        abort_unless($category, 403);
        $this->assertFarmScope($request, $category->farm_id);

        return $category;
    }

    public function exportExcel(Request $request)
    {
        $category = $this->exportCategoryFor($request);
        $farm = Farm::find($this->scopedFarmId($request));
        $filename = ($category ? Str::slug($category->name) : 'Inventaire').'_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(
            new StockInventoryExport($this->farmInventory($request), $category?->name, $farm),
            $filename
        );
    }

    public function exportPdf(Request $request)
    {
        $category = $this->exportCategoryFor($request);

        $pdf = Pdf::loadView('exports.stock_inventory', [
            'stockInventory' => $this->farmInventory($request),
            'categoryName' => $category?->name,
            'farm' => Farm::find($this->scopedFarmId($request)),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $filename = ($category ? Str::slug($category->name) : 'Inventaire').'_'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }

    public function exportMovementsExcel(Request $request, StockInventory $inventory)
    {
        $this->assertFarmScope($request, $inventory->product->farm_id);
        $this->loadMovementRelations($inventory);

        $exitTypesByKey = $this->exitTypesFor($inventory->product->farm_id)->pluck('label', 'key')->toArray();
        $filename = 'Mouvements_'.Str::slug($inventory->product->name).'_'.now()->format('Y-m-d').'.xlsx';

        return Excel::download(new StockMovementsExport($inventory, $exitTypesByKey), $filename);
    }

    public function exportMovementsPdf(Request $request, StockInventory $inventory)
    {
        $this->assertFarmScope($request, $inventory->product->farm_id);
        $this->loadMovementRelations($inventory);

        $pdf = Pdf::loadView('exports.stock_movements', [
            'inventory' => $inventory,
            'farm' => $inventory->product->farm,
            'movements' => $inventory->product->stockMovements
                ->sortBy([['date', 'asc'], ['id', 'asc']])
                ->values(),
            // A sortie with no note falls back to showing its own type (Consommation/Perte/...)
            // instead of a blank Observations cell — same labels the "Type de Sortie" dropdown uses.
            'exitTypesByKey' => $this->exitTypesFor($inventory->product->farm_id)->pluck('label', 'key')->toArray(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $filename = 'Mouvements_'.Str::slug($inventory->product->name).'_'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }

    // The magasinier's physical stock count: what's actually on the shelf vs. what the system
    // thinks is there. Superseded the older adjust() (kept no reason a count doesn't already
    // capture via the previous/counted note below) — every write path treats StockInventory as
    // one row per product.
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
            $this->assertFarmScope($request, $product->farm_id);

            $count = $this->recordCount($product, (float) $validated['counted_quantity'], Carbon::today(), $request->user()->id);
            if (is_string($count)) {
                throw ValidationException::withMessages(['counted_quantity' => $count]);
            }
        });

        return redirect()->back()->with('success', 'Comptage de stock enregistré avec succès.');
    }

    /**
     * One product counted on $date: the difference with what the books said at the end of that
     * day becomes an "adjustment" movement dated that day, at the CUMP, and is applied to today's
     * quantity — so a sheet counted yesterday and entered today keeps the sorties made since.
     * Returns the difference and its value, or a refusal message. Runs inside the caller's transaction.
     */
    private function recordCount(Product $product, float $counted, Carbon $date, int $userId): array|string
    {
        $inventory = $this->lockedInventoryFor($product->id);
        if ($inventory->last_count_date && $inventory->last_count_date->gt($date)) {
            return "{$product->name} : déjà compté le {$inventory->last_count_date->format('d/m/Y')}, après cette date.";
        }

        $movedAfter = (float) (StockHistory::movedAfter(collect([$product->id]), $date)[$product->id] ?? 0);
        $book = (float) $inventory->quantity_on_hand - $movedAfter;
        $difference = round($counted - $book, 2);
        if ($counted + $movedAfter < -0.001) {
            return "{$product->name} : avec les sorties faites depuis le {$date->format('d/m/Y')}, ce comptage laisserait un stock négatif.";
        }

        $inventory->quantity_on_hand = (float) $inventory->quantity_on_hand + $difference;
        $inventory->last_count_date = $date;
        $inventory->save();

        $cost = (float) ($inventory->average_cost ?? $product->unit_cost ?? 0);
        if ($difference != 0) {
            $product->stockMovements()->create([
                'movement_type' => 'adjustment',
                'quantity' => $difference,
                'unit_cost' => $cost,
                'total_cost' => round($cost * $difference, 2),
                'performed_by' => $userId,
                'date' => $date->toDateString(),
                'notes' => 'Comptage de stock. Précédent : '.(float) round($book, 2).', Compté : '.(float) $counted,
            ]);
        }
        StockAlertService::syncLowStock($product);

        return ['difference' => $difference, 'valeur' => round($cost * $difference, 2)];
    }

    /** The farm's active products to count, by category then name, with the books at the end of $date. */
    private function comptageLignes(int $farmId, ?int $categoryId, Carbon $date)
    {
        $products = Product::with('category', 'stockInventory')
            ->where('farm_id', $farmId)
            ->where('is_active', true)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->get()
            ->sortBy(fn ($p) => [$p->category->name ?? 'zzz', $p->name])
            ->values();
        $book = StockHistory::quantitiesAt($products->pluck('id'), $date);

        return $products->map(fn ($p) => [
            'product_id' => $p->id,
            'name' => $p->name,
            'category' => $p->category->name ?? 'Sans catégorie',
            'unit_type' => $p->unit_type,
            'quantity' => round((float) ($book[$p->id] ?? 0), 2),
            'cost' => (float) ($p->stockInventory->first()?->average_cost ?? $p->unit_cost ?? 0),
            'last_count_date' => $p->stockInventory->first()?->last_count_date?->toDateString(),
        ]);
    }

    private function comptageDate(Request $request): Carbon
    {
        $date = $request->filled('date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->date)
            ? Carbon::createFromFormat('!Y-m-d', $request->date)
            : Carbon::today();

        return $date->gt(Carbon::today()) ? Carbon::today() : $date;
    }

    /** The whole magasin (or one category) counted at once: one quantity per product. */
    public function comptage(Request $request)
    {
        abort_unless($request->user()->canAccessStock(), 403);
        $farmId = $this->scopedFarmId($request);
        $category = $this->exportCategoryFor($request);
        $date = $this->comptageDate($request);

        return Inertia::render('Stock/Inventory/Comptage', [
            'lignes' => $this->comptageLignes($farmId, $category?->id, $date),
            'categories' => ProductCategory::where('farm_id', $farmId)->orderBy('name')->get(['id', 'name']),
            'categoryId' => $category?->id,
            'date' => $date->toDateString(),
        ]);
    }

    public function storeComptage(Request $request)
    {
        abort_unless($request->user()->canAccessStock(), 403);
        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'lignes' => 'required|array|min:1',
            'lignes.*.product_id' => 'required|integer|distinct',
            'lignes.*.counted_quantity' => 'required|numeric|min:0',
        ], [
            'lignes.required' => 'Saisissez au moins une quantité comptée.',
            'date.before_or_equal' => 'Le comptage ne peut pas être daté dans le futur.',
        ]);
        $date = Carbon::createFromFormat('!Y-m-d', $validated['date']);

        $counted = collect($validated['lignes'])->keyBy('product_id');
        $products = Product::whereIn('id', $counted->keys())->orderBy('id')->get();
        abort_unless($products->count() === $counted->count() && $products->every(fn ($p) => $p->farm_id === $farmId), 403);

        $resultat = DB::transaction(function () use ($products, $counted, $date, $request) {
            $refus = [];
            $ecarts = 0;
            $valeur = 0.0;
            foreach ($products as $product) {
                $count = $this->recordCount($product, (float) $counted[$product->id]['counted_quantity'], $date, $request->user()->id);
                if (is_string($count)) {
                    $refus[] = $count;

                    continue;
                }
                if ($count['difference'] != 0) {
                    $ecarts++;
                    $valeur += $count['valeur'];
                }
            }
            // All or nothing: a sheet is entered again once corrected, never half-applied.
            if ($refus) {
                throw ValidationException::withMessages(['lignes' => implode(' ', $refus)]);
            }

            return [$ecarts, $valeur];
        });
        [$ecarts, $valeur] = $resultat;

        return redirect()->route('stock.inventory.comptage', ['date' => $date->toDateString()])->with('success', sprintf(
            'Comptage du %s enregistré : %d produit(s) compté(s), %d écart(s)%s.',
            $date->format('d/m/Y'),
            $products->count(),
            $ecarts,
            $ecarts ? ', valeur nette '.number_format($valeur, 2, ',', ' ').' DH' : ''
        ));
    }

    /** The sheet the magasinier fills by hand; ?aveugle=1 leaves out the book quantities. */
    public function comptagePdf(Request $request)
    {
        abort_unless($request->user()->canAccessStock(), 403);
        $farmId = $this->scopedFarmId($request);
        $category = $this->exportCategoryFor($request);

        $pdf = Pdf::loadView('exports.stock_comptage', [
            'groups' => $this->comptageLignes($farmId, $category?->id, Carbon::today())->groupBy('category'),
            'categoryName' => $category?->name,
            'aveugle' => $request->boolean('aveugle'),
            'farm' => Farm::find($farmId),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Fiche_comptage_'.($category ? Str::slug($category->name).'_' : '').now()->format('Y-m-d').'.pdf');
    }
}
