<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia; // Import Inertia

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('stockInventory')
            ->where('farm_id', $request->user()->farm_id);

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('reference_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $products = $query->orderBy('name')->get();

        // Get categories and unit types
        $categories = $this->categories()->original; // Get the array from the JsonResponse
        $unitTypes = $this->unitTypes()->original; // Get the array from the JsonResponse

        return Inertia::render('Stock/Index', [
            'products' => $products,
            'categories' => $categories,
            'unitTypes' => $unitTypes,
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = [
            'seeds',
            'fertilizers',
            'pesticides',
            'tools',
            'packaging',
            'equipment',
            'fuel',
            'other'
        ];

        return response()->json($categories);
    }

    public function unitTypes(): JsonResponse
    {
        $unitTypes = [
            'kg',
            'liters',
            'units',
            'boxes',
            'bags'
        ];

        return response()->json($unitTypes);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $products = Product::with('stockInventory')
            ->where('farm_id', $request->user()->farm_id)
            ->where('is_active', true)
            ->get()
            ->filter(function ($product) {
                return $product->isLowStock();
            })
            ->values();

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'reference_code' => 'required|string|unique:products,reference_code',
            'barcode' => 'nullable|string|unique:products,barcode',
            'category' => 'required|in:seeds,fertilizers,pesticides,tools,packaging,equipment,fuel,other',
            'unit_type' => 'required|in:kg,liters,units,boxes,bags',
            'min_stock_level' => 'nullable|numeric|min:0',
            'max_stock_level' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'storage_location' => 'nullable|string|max:255',
            'specifications' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $product = Product::create([
            'farm_id' => $request->user()->farm_id,
            'name' => $validated['name'],
            'reference_code' => $validated['reference_code'],
            'barcode' => $validated['barcode'] ?? null,
            'category' => $validated['category'],
            'unit_type' => $validated['unit_type'],
            'min_stock_level' => $validated['min_stock_level'] ?? 0,
            'max_stock_level' => $validated['max_stock_level'] ?? null,
            'unit_cost' => $validated['unit_cost'] ?? null,
            'supplier' => $validated['supplier'] ?? null,
            'storage_location' => $validated['storage_location'] ?? null,
            'specifications' => $validated['specifications'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json($product, 201);
    }

    public function show(Product $product)
    {
        $product->load('stockInventory', 'stockMovements', 'stockAlerts');

        return Inertia::render('Stock/Show', [
            'product' => $product,
        ]);
    }

    public function edit(Product $product)
    {
        $categories = $this->categories()->original;
        $unitTypes = $this->unitTypes()->original;

        return Inertia::render('Stock/Edit', [
            'product' => $product,
            'categories' => $categories,
            'unitTypes' => $unitTypes,
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'reference_code' => 'sometimes|required|string|unique:products,reference_code,' . $product->id,
            'barcode' => 'nullable|string|unique:products,barcode,' . $product->id,
            'category' => 'sometimes|required|in:seeds,fertilizers,pesticides,tools,packaging,equipment,fuel,other',
            'unit_type' => 'sometimes|required|in:kg,liters,units,boxes,bags',
            'min_stock_level' => 'nullable|numeric|min:0',
            'max_stock_level' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier' => 'nullable|string|max:255',
            'storage_location' => 'nullable|string|max:255',
            'specifications' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $product->update($validated);

        return response()->json($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }
}
