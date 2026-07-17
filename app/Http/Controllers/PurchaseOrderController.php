<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockInventory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia; // Import Inertia

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::with('items.product', 'farm')
            ->where('farm_id', $request->user()->farm_id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('from_date')) {
            $query->where('order_date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('order_date', '<=', $request->to_date);
        }

        $orders = $query->orderBy('order_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Stock/PurchaseOrders/Index', [
            'purchaseOrders' => $orders,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => 'required|string|unique:purchase_orders,order_number',
            'supplier_id' => 'nullable|integer',
            'supplier_name' => 'nullable|string|max:255',
            'order_date' => 'required|date',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_ordered' => 'required|numeric|min:0',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $order = PurchaseOrder::create([
                'farm_id' => $request->user()->farm_id,
                'order_number' => $validated['order_number'],
                'supplier_id' => $validated['supplier_id'] ?? null,
                'supplier_name' => $validated['supplier_name'] ?? null,
                'order_date' => $validated['order_date'],
                'expected_date' => $validated['expected_date'] ?? null,
                'status' => 'pending',
                'total_amount' => 0,
                'notes' => $validated['notes'] ?? null,
            ]);

            $totalAmount = 0;

            foreach ($validated['items'] as $itemData) {
                $totalPrice = $itemData['quantity_ordered'] * $itemData['unit_price'];
                $totalAmount += $totalPrice;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'quantity_ordered' => $itemData['quantity_ordered'],
                    'quantity_received' => 0,
                    'unit_price' => $itemData['unit_price'],
                    'total_price' => $totalPrice,
                ]);
            }

            $order->update(['total_amount' => $totalAmount]);

            return response()->json($order->load('items.product'), 201);
        });
    }

    public function show(PurchaseOrder $order)
    {
        $order->load('items.product', 'farm', 'receivedBy');

        return Inertia::render('Stock/PurchaseOrders/Show', [
            'purchaseOrder' => $order,
        ]);
    }

    public function edit(PurchaseOrder $order)
    {
        $order->load('items.product');
        $products = Product::where('farm_id', $order->farm_id)->get(['id', 'name', 'unit_type', 'unit_cost']);

        return Inertia::render('Stock/PurchaseOrders/Edit', [
            'purchaseOrder' => $order,
            'products' => $products,
        ]);
    }

    public function update(Request $request, PurchaseOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'nullable|integer',
            'supplier_name' => 'nullable|string|max:255',
            'expected_date' => 'nullable|date|after_or_equal:order_date',
            'status' => 'nullable|in:pending,ordered,partial,received,cancelled',
            'notes' => 'nullable|string',
        ]);

        $order->update($validated);

        return response()->json($order->load('items.product'));
    }

    public function destroy(PurchaseOrder $order): JsonResponse
    {
        $order->delete();

        return response()->json(null, 204);
    }

    public function receive(Request $request, PurchaseOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.item_id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received' => 'required|numeric|min:0',
            'items.*.batch_number' => 'nullable|string',
            'received_date' => 'required|date',
        ]);

        return DB::transaction(function () use ($validated, $request, $order) {
            $allReceived = true;
            $anyReceived = false;

            foreach ($validated['items'] as $itemData) {
                $item = PurchaseOrderItem::findOrFail($itemData['item_id']);
                $item->quantity_received = $itemData['quantity_received'];
                $item->received_date = $validated['received_date'];
                $item->batch_number = $itemData['batch_number'] ?? null;
                $item->save();

                if ($item->quantity_received > 0) {
                    $anyReceived = true;
                }

                if (!$item->isFullyReceived()) {
                    $allReceived = false;
                }

                // Create stock movement for received items
                if ($itemData['quantity_received'] > 0) {
                    $product = Product::findOrFail($item->product_id);

                    StockMovement::create([
                        'product_id' => $product->id,
                        'movement_type' => 'in',
                        'quantity' => $itemData['quantity_received'],
                        'unit_cost' => $item->unit_price,
                        'total_cost' => $itemData['quantity_received'] * $item->unit_price,
                        'reference_type' => 'purchase_order',
                        'reference_id' => $order->id,
                        'performed_by' => $request->user()->id,
                        'date' => $validated['received_date'],
                        'notes' => "Received from purchase order {$order->order_number}",
                    ]);

                    // Update inventory
                    $inventory = StockInventory::firstOrCreate(
                        [
                            'product_id' => $product->id,
                            'batch_number' => $itemData['batch_number'] ?? null,
                        ],
                        [
                            'quantity_on_hand' => 0,
                            'quantity_reserved' => 0,
                            'average_cost' => $item->unit_price,
                        ]
                    );

                    $inventory->quantity_on_hand += $itemData['quantity_received'];
                    $inventory->last_restock_date = $validated['received_date'];
                    $inventory->save();
                }
            }

            // Update order status
            if ($allReceived) {
                $order->status = 'received';
            } elseif ($anyReceived) {
                $order->status = 'partial';
            } else {
                $order->status = 'ordered';
            }

            $order->received_by = $request->user()->id;
            $order->received_at = now();
            $order->save();

            return response()->json($order->load('items.product'));
        });
    }

    public function addItem(Request $request, PurchaseOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity_ordered' => 'required|numeric|min:0',
            'unit_price' => 'required|numeric|min:0',
        ]);

        $totalPrice = $validated['quantity_ordered'] * $validated['unit_price'];

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'product_id' => $validated['product_id'],
            'quantity_ordered' => $validated['quantity_ordered'],
            'quantity_received' => 0,
            'unit_price' => $validated['unit_price'],
            'total_price' => $totalPrice,
        ]);

        // Update order total
        $order->total_amount += $totalPrice;
        $order->save();

        return response()->json($item->load('product'), 201);
    }
}
