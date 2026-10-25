<?php

namespace App\Http\Controllers;

use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\StockAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia; // Import Inertia

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $filters = $request->validate([
            'movement_type' => 'nullable|in:in,out,adjustment',
            'product_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'search' => 'nullable|string|max:100',
        ]);

        $query = StockMovement::with([
            'product',
            'performedBy',
            'supplier',
            'reference' => function ($morphTo) {
                $morphTo->morphWith([
                    ManualStockEntry::class => ['bloc', 'sector', 'parcelle', 'vehicle', 'employee'],
                    FuelTransaction::class => ['vehicle'],
                ]);
            },
        ])
            ->when($farmId, function ($query) use ($farmId) {
                $query->whereHas('product', fn ($q) => $q->where('farm_id', $farmId));
            });

        $query->when($filters['movement_type'] ?? null, fn ($q, $type) => $q->where('movement_type', $type))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%"))
                ->orWhere('notes', 'like', "%{$term}%")
                ->orWhere('numero_bl', 'like', "%{$term}%")));

        $movements = $query->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        $products = Product::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit_type', 'unit_cost']);

        return Inertia::render('Stock/Movements/Index', [
            'stockMovements' => $movements,
            'filters' => (object) array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'canAnnuler' => $request->user()->peutSaisir('stock'),
            'products' => $products,
            'suppliers' => Supplier::when($farmId, fn ($q) => $q->where('farm_id', $farmId))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
            // A sortie with no note falls back to showing its own type (Consommation/Perte/...)
            // instead of a blank Observations cell — same list that fills the dropdown below.
            'exitTypes' => $this->exitTypesFor($farmId),
        ]);
    }

    public function stockIn(Request $request)
    {
        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('farm_id', $farmId)],
            'numero_bl' => 'nullable|string|max:255',
            'date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated, $request, $farmId) {
            $product = Product::findOrFail($validated['product_id']);
            abort_unless($product->farm_id === $farmId, 403);
            $unitCost = $validated['unit_cost'] ?? $product->unit_cost ?? 0;

            StockMovement::create([
                'product_id' => $product->id,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'numero_bl' => $validated['numero_bl'] ?? null,
                'movement_type' => 'in',
                'quantity' => $validated['quantity'],
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost * $validated['quantity'],
                // No reference_type: a réception has no source document to link back to —
                // see StockMovement::reference() and AppServiceProvider's morph map.
                'performed_by' => $request->user()->id,
                'date' => $validated['date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update inventory: quantity + weighted-average cost
            $inventory = $this->lockedInventoryFor($product->id);

            $previousQuantity = (float) $inventory->quantity_on_hand;
            $newQuantity = $previousQuantity + $validated['quantity'];

            $inventory->average_cost = $newQuantity > 0
                ? ((((float) ($inventory->average_cost ?? 0)) * $previousQuantity) + ($unitCost * $validated['quantity'])) / $newQuantity
                : $unitCost;
            $inventory->quantity_on_hand = $newQuantity;
            $inventory->last_restock_date = $validated['date'];
            $inventory->save();

            StockAlertService::syncLowStock($product);
        });

        return redirect()->back()->with('success', 'Réception de stock enregistrée avec succès.');
    }

    /**
     * Cancels a wrong réception (Stock saisie: the magasinier): a negative adjustment at the réception's own
     * cost takes its quantity and value back out, and the CUMP is recomputed without it. Refused
     * once part of it has already gone out — fix that with a count instead.
     */
    public function annuler(Request $request, StockMovement $movement)
    {
        $this->assertFarmScope($request, $movement->product()->first()?->farm_id);
        $validated = $request->validate(['motif' => 'required|string|max:255'], ['motif.required' => 'Indiquez pourquoi la réception est annulée.']);

        DB::transaction(function () use ($movement, $validated, $request) {
            $inventory = $this->lockedInventoryFor($movement->product_id);
            $movement = StockMovement::lockForUpdate()->findOrFail($movement->id);
            if ($movement->movement_type !== 'in' || $movement->reference_type !== null || $movement->annulation_id) {
                throw ValidationException::withMessages(['motif' => $movement->annulation_id ? 'Cette réception est déjà annulée.' : 'Seule une réception peut être annulée.']);
            }

            $quantity = (float) $movement->quantity;
            $onHand = (float) $inventory->quantity_on_hand;
            if ($quantity > $onHand + 0.001) {
                throw ValidationException::withMessages(['motif' => 'Une partie est déjà sortie du magasin (reste '.number_format($onHand, 2, ',', ' ').' en stock) : corrigez par un comptage.']);
            }

            // The stock's value without this réception, spread over what is left.
            $cost = (float) $movement->unit_cost;
            $remaining = round($onHand - $quantity, 2);
            if ($remaining > 0) {
                $inventory->average_cost = max(0, $onHand * (float) $inventory->average_cost - $quantity * $cost) / $remaining;
            }
            $inventory->quantity_on_hand = $remaining;
            $inventory->save();

            $annulation = StockMovement::create([
                'product_id' => $movement->product_id,
                'movement_type' => 'adjustment',
                'quantity' => -$quantity,
                'unit_cost' => $cost,
                'total_cost' => round(-$quantity * $cost, 2),
                'performed_by' => $request->user()->id,
                'date' => now()->toDateString(),
                'notes' => 'Annulation de la réception du '.$movement->date->format('d/m/Y')
                    .($movement->numero_bl ? ' (BL '.$movement->numero_bl.')' : '').' : '.$validated['motif'],
            ]);
            $movement->update(['annulation_id' => $annulation->id]);

            StockAlertService::syncLowStock($movement->product);
        });

        return redirect()->back()->with('success', 'Réception annulée : la quantité est retirée du stock et le CUMP recalculé.');
    }
}
