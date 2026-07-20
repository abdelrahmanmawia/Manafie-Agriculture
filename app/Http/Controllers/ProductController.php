<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia; // Import Inertia

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        $query = Product::with('stockInventory')
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId));

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
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
            'unitTypes' => $unitTypes,        ]);
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:seeds,fertilizers,pesticides,tools,packaging,equipment,fuel,other',
            'unit_type' => 'required|in:kg,liters,units,boxes,bags',
            'min_stock_level' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:5120',
        ]);

        Product::create([
            'farm_id' => $this->resolveWriteFarmId($request),
            'name' => $validated['name'],
            'image' => $request->hasFile('image') ? $request->file('image')->store('products', 'public') : null,
            'category' => $validated['category'],
            'unit_type' => $validated['unit_type'],
            'min_stock_level' => $validated['min_stock_level'] ?? 0,
            'unit_cost' => $validated['unit_cost'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return redirect()->back();
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

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|in:seeds,fertilizers,pesticides,tools,packaging,equipment,fuel,other',
            'unit_type' => 'sometimes|required|in:kg,liters,units,boxes,bags',
            'min_stock_level' => 'nullable|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:5120',
        ]);

        if ($request->hasFile('image')) {
            if ($product->image) {
                Storage::disk('public')->delete($product->image);
            }
            $validated['image'] = $request->file('image')->store('products', 'public');
        }

        $product->update($validated);

        return redirect()->route('stock.products.show', $product);
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->route('stock.products.index');
    }
}
