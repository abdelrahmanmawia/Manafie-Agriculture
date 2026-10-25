<?php

namespace App\Http\Controllers;

use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Vehicle;
use App\Services\StockAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The fill-ups. A new one is recorded from Sorties de Stock (a consumption sortie of a
 * "Carburant" category product becomes a FuelTransaction — ManualStockEntryController::
 * ravitaillement()); this page lists them, paginated, and lets a wrong one be removed.
 */
class FuelTransactionController extends Controller
{
    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);
        $filters = $request->validate([
            'vehicle_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'search' => 'nullable|string|max:100',
        ]);

        $query = FuelTransaction::query()
            ->when($farmId, fn ($q) => $q->where('farm_id', $farmId))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereHas('vehicle', fn ($v) => $v->where('name', 'like', "%{$term}%")->orWhere('plate_number', 'like', "%{$term}%"))
                ->orWhereHas('driver', fn ($d) => $d->where('full_name', 'like', "%{$term}%"))
                ->orWhere('notes', 'like', "%{$term}%")));

        // Totals over every matching fill-up, not just the page shown.
        $totals = (clone $query)->selectRaw('COUNT(*) as count, COALESCE(SUM(quantity_liters), 0) as liters, COALESCE(SUM(total_cost), 0) as cost')->first();

        $fuelTransactions = $query->with('vehicle', 'product', 'driver', 'performedBy')
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $vehicles = Vehicle::when($farmId, fn ($q) => $q->where('farm_id', $farmId))->orderBy('name')->get(['id', 'name', 'plate_number', 'serial_number']);

        return Inertia::render('Stock/FuelTransactions/Index', [
            'fuelTransactions' => $fuelTransactions,
            'vehicles' => $vehicles,
            'filters' => (object) array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'totals' => ['count' => (int) $totals->count, 'liters' => (float) $totals->liters, 'cost' => (float) $totals->cost],
            'canDeleteAny' => in_array($request->user()->role, ['farm_manager', 'super_admin'], true),
        ]);
    }

    public function show(Request $request, FuelTransaction $transaction)
    {
        $this->assertFarmScope($request, $transaction->farm_id);

        $transaction->load('vehicle', 'product', 'driver', 'performedBy');

        return Inertia::render('Stock/FuelTransactions/Show', [
            'fuelTransaction' => $transaction,
        ]);
    }

    /**
     * Removes a wrong fill-up and gives its liters back to the magasin. A manager may do it any
     * time; whoever recorded it, only the day they recorded it (after that it is in the
     * vehicle's fuel cost and stays, like a verified sortie).
     */
    public function destroy(Request $request, FuelTransaction $transaction)
    {
        $this->assertFarmScope($request, $transaction->farm_id);
        $user = $request->user();
        $manager = in_array($user->role, ['farm_manager', 'super_admin'], true);
        if (! $manager && ! ($transaction->performed_by === $user->id && $transaction->created_at?->isToday())) {
            return redirect()->back()->with('error', 'Seul un responsable peut supprimer ce ravitaillement (ou son auteur, le jour même).');
        }

        DB::transaction(function () use ($transaction) {
            $transaction = FuelTransaction::lockForUpdate()->findOrFail($transaction->id);
            $movement = StockMovement::where('reference_type', 'fuel_transaction')->where('reference_id', $transaction->id)->first();
            if ($movement) {
                $inventory = $this->lockedInventoryFor($movement->product_id);
                // An "out" gives its liters back; an old "in"/adjustment row is undone the same way.
                $inventory->quantity_on_hand += match ($movement->movement_type) {
                    'out' => (float) $movement->quantity,
                    default => -(float) $movement->quantity,
                };
                $inventory->save();
                $movement->delete();
            }
            $transaction->delete();

            if ($product = Product::find($movement?->product_id ?? $transaction->product_id)) {
                StockAlertService::syncLowStock($product);
            }
        });

        return redirect()->back()->with('success', 'Ravitaillement supprimé — les litres sont revenus au magasin.');
    }
}
