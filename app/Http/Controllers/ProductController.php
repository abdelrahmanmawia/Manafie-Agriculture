<?php

namespace App\Http\Controllers;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Farm;
use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Operation;
use App\Models\Parcelle;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sector;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceLog;
use App\Services\StockAlertService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia; // Import Inertia

class ProductController extends Controller
{
    /**
     * show/update/toggleActive/destroy all trusted the route-bound $product with no
     * ownership check — any authenticated user could view or silently mutate another
     * farm's product by walking IDs. index()/store() were already properly scoped.
     */
    private function assertProductInScope(Request $request, Product $product): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $product->farm_id === $farmId, 403);
    }

    private function assertCategoryInScope(Request $request, ProductCategory $category): void
    {
        $farmId = $this->scopedFarmId($request);
        abort_unless($farmId && $category->farm_id === $farmId, 403);
    }

    private function categoriesFor(?int $farmId)
    {
        return ProductCategory::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->orderBy('name')
            ->get(['id', 'name', 'is_vehicle_related', 'is_active']);
    }

    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = Product::with(['stockInventory', 'category'])
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId));

        if ($request->has('category')) {
            $query->where('category_id', $request->category);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $products = $query->orderBy('name')->get();
        $unitTypes = $this->unitTypes()->original; // Get the array from the JsonResponse

        return Inertia::render('Stock/Index', [
            'products' => $products,
            'categories' => $this->categoriesFor($farmId),
            'suppliers' => Supplier::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
            'unitTypes' => $unitTypes,
            'employees' => Employee::where('is_active', true)
                ->when($farmId, fn ($q) => $q->whereHas('enterprise', fn ($eq) => $eq->where('farm_id', $farmId)))
                ->get(['id', 'full_name']),
            'vehicles' => Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name', 'plate_number', 'serial_number', 'type', 'asset_type']),
            'blocs' => Bloc::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name']),
            'sectors' => Sector::when($farmId, fn ($q) => $q->whereHas('bloc', fn ($bq) => $bq->where('farm_id', $farmId)))->get(['id', 'name', 'bloc_id']),
            'parcelles' => Parcelle::when($farmId, fn ($q) => $q->whereHas('bloc', fn ($bq) => $bq->where('farm_id', $farmId)))->get(['id', 'name', 'bloc_id', 'sector_id']),
            'operations' => Operation::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->get(['id', 'name']),
            // Lets the "Sortie" modal's "Intervention liée" select filter, client-side, to
            // whichever vehicle is picked — mirrors ManualStockEntryController::maintenanceLogsFor().
            'vehicleMaintenanceLogs' => VehicleMaintenanceLog::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->orderByDesc('performed_at')
                ->get(['id', 'vehicle_id', 'description', 'performed_at']),
            'exitTypes' => $this->exitTypesFor($farmId),
        ]);
    }

    public function unitTypes(): JsonResponse
    {
        $unitTypes = [
            'kg',
            'tonnes',
            'liters',
            'meters',
            'units',
            'boxes',
            'bags',
            'rolls'
        ];

        return response()->json($unitTypes);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $farmId = $this->scopedFarmId($request);

        $products = Product::with('stockInventory')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_active', true)
            ->get()
            ->filter(function ($product) {
                return $product->isLowStock();
            })
            ->values();

        return response()->json($products);
    }

    public function store(Request $request)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }

        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => ['required', Rule::exists('product_categories', 'id')->where('farm_id', $farmId)],
            'unit_type' => 'required|in:kg,tonnes,liters,meters,units,boxes,bags,rolls',
            'min_stock_level' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:5120',
        ]);

        $product = Product::create([
            'farm_id' => $farmId,
            'name' => $validated['name'],
            'image' => $request->hasFile('image') ? $request->file('image')->store('products', 'public') : null,
            'category_id' => $validated['category_id'],
            'unit_type' => $validated['unit_type'],
            'min_stock_level' => $validated['min_stock_level'] ?? 0,
            'unit_cost' => $validated['unit_cost'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        StockAlertService::syncLowStock($product);

        return redirect()->back()->with('success', 'Produit créé avec succès.');
    }

    public function show(Request $request, Product $product)
    {
        $this->assertProductInScope($request, $product);

        $product->load([
            'category',
            'stockInventory',
            'stockAlerts',
            'stockMovements' => function ($query) {
                $query->with(['reference' => function ($morphTo) {
                    $morphTo->morphWith([
                        ManualStockEntry::class => ['bloc', 'sector', 'parcelle', 'vehicle', 'employee'],
                        FuelTransaction::class => ['vehicle'],
                    ]);
                }])->orderBy('date', 'desc')->orderBy('created_at', 'desc');
            },
        ]);

        return Inertia::render('Stock/Show', [
            'product' => $product,
            'categories' => $this->categoriesFor($product->farm_id),
            'unitTypes' => $this->unitTypes()->original,
        ]);
    }

    public function toggleActive(Request $request, Product $product)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }
        $this->assertProductInScope($request, $product);

        $product->update(['is_active' => !$product->is_active]);

        return redirect()->back()->with('success', $product->is_active ? 'Produit activé.' : 'Produit désactivé.');
    }

    public function update(Request $request, Product $product)
    {
        // data_entry is intentionally allowed to update (see test_data_entry_can_update_product) —
        // only store/destroy/toggleActive are role-restricted. Farm-scoping still applies to everyone.
        $this->assertProductInScope($request, $product);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category_id' => ['sometimes', 'required', Rule::exists('product_categories', 'id')->where('farm_id', $product->farm_id)],
            'unit_type' => 'sometimes|required|in:kg,tonnes,liters,meters,units,boxes,bags,rolls',
            'min_stock_level' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            // Sent as a real JS boolean on a plain Inertia PUT, but as the literal string
            // "true"/"false" once a photo file forces the request into multipart/FormData —
            // Laravel's `boolean` rule strictly rejects those strings (only accepts
            // true/false/0/1/'0'/'1'), so accept them here and coerce via $request->boolean()
            // rather than trusting the raw validated value ((bool)"false" is true in PHP).
            'is_active' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
            'image' => 'nullable|image|max:5120',
        ]);

        if (array_key_exists('is_active', $validated)) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        // Only update image if a new file is provided
        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = $request->file('image')->store('products', 'public');
        } else {
            // No file uploaded - remove image from validated data to keep existing image
            unset($validated['image']);
        }

        $product->update($validated);

        // min_stock_level may have just changed, which can push the product above or below
        // the low-stock threshold without any quantity actually moving.
        StockAlertService::syncLowStock($product);

        return redirect()->back()->with('success', 'Produit mis à jour avec succès.');
    }

    public function destroy(Request $request, Product $product)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }
        $this->assertProductInScope($request, $product);

        // Archiving the product makes any open low-stock alert for it moot — resolve so it
        // doesn't linger unactionable on the Alerts page.
        $product->stockAlerts()->where('is_resolved', false)->get()->each->resolve();

        $product->delete();

        return redirect()->route('stock.products.index')->with('success', 'Produit supprimé avec succès.');
    }

    // Categories are structural configuration (like Operations/Blocs), not day-to-day stock
    // entry — data_entry can log products against a category but not add/rename/remove one.

    public function storeCategory(Request $request)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }

        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('product_categories', 'name')->where('farm_id', $farmId)],
            'is_vehicle_related' => 'boolean',
        ]);

        ProductCategory::create([
            'farm_id' => $farmId,
            'name' => $validated['name'],
            'is_vehicle_related' => $validated['is_vehicle_related'] ?? false,
        ]);

        return redirect()->back()->with('success', 'Catégorie créée avec succès.');
    }

    public function updateCategory(Request $request, ProductCategory $category)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }
        $this->assertCategoryInScope($request, $category);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('product_categories', 'name')->where('farm_id', $category->farm_id)->ignore($category->id)],
            'is_vehicle_related' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $category->update($validated);

        return redirect()->back()->with('success', 'Catégorie mise à jour.');
    }

    public function destroyCategory(Request $request, ProductCategory $category)
    {
        if (! $request->user()->canAccessStock()) {
            abort(403);
        }
        $this->assertCategoryInScope($request, $category);

        // The FK is restrictOnDelete, so this would fail at the DB level anyway — checked here
        // first so the user gets an actionable message instead of a raw SQL error.
        if ($category->products()->exists()) {
            return redirect()->back()->withErrors([
                'category' => 'Cette catégorie est utilisée par au moins un produit et ne peut pas être supprimée. Désactivez-la plutôt.',
            ]);
        }

        $category->delete();

        return redirect()->back()->with('success', 'Catégorie supprimée avec succès.');
    }
}
