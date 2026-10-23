<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Farm to scope a Stock/Pointage read query by. Super admins work within whichever
     * farm they activated (see FarmController::activate), stored in session; the
     * EnsureFarmSelected middleware guarantees this is set before a super_admin reaches
     * any Pointage/Stock route. Everyone else is locked to their own farm.
     */
    protected function scopedFarmId(Request $request): ?int
    {
        if ($request->user()->role === 'super_admin') {
            return session('active_farm_id') ? (int) session('active_farm_id') : null;
        }

        return $request->user()->farm_id;
    }

    /**
     * Farm a new Stock record should be written under: the super admin's active farm,
     * or the user's own farm for everyone else.
     */
    protected function resolveWriteFarmId(Request $request): int
    {
        if ($request->user()->role === 'super_admin') {
            abort_unless(session('active_farm_id'), 403, 'Veuillez sélectionner une ferme.');

            return (int) session('active_farm_id');
        }

        return $request->user()->farm_id;
    }

    /**
     * A product's StockInventory row, created if missing and locked FOR UPDATE until the
     * surrounding transaction ends — must be called inside DB::transaction(). Every
     * stock-mutating action reads quantity_on_hand/average_cost, computes in PHP, then saves;
     * without the lock two concurrent sorties could both pass the "quantité insuffisante" check
     * and one decrement (or one réception's cost averaging) would be silently lost.
     * createOrFirst() relies on the unique(product_id) index (migration 000043) so two requests
     * racing to create the first row for a product can't both insert one.
     */
    protected function lockedInventoryFor(int $productId): \App\Models\StockInventory
    {
        $inventory = \App\Models\StockInventory::where('product_id', $productId)->lockForUpdate()->first();

        if (! $inventory) {
            \App\Models\StockInventory::createOrFirst(
                ['product_id' => $productId],
                ['quantity_on_hand' => 0, 'quantity_reserved' => 0]
            );
            $inventory = \App\Models\StockInventory::where('product_id', $productId)->lockForUpdate()->firstOrFail();
        }

        return $inventory;
    }

    /**
     * Active "Type de Sortie" options for a farm's Sorties de Stock forms — configurable by
     * farm_manager/super_admin (see StockExitTypeController) instead of a hardcoded list.
     * Self-seeds the five types this app has always used on first access, so an existing farm
     * (or a brand new one) never sees an empty dropdown; the seeded keys match exactly what's
     * already stored on old manual_stock_entries rows and what the sortie form's
     * requires_maintenance_log-driven "Intervention liée" field expects.
     */
    protected function exitTypesFor(?int $farmId, ?string $mustIncludeKey = null)
    {
        if (!$farmId) {
            return collect();
        }

        if (! \App\Models\StockExitType::where('farm_id', $farmId)->exists()) {
            foreach ([
                ['key' => 'consumption', 'label' => 'Consommation'],
                ['key' => 'loss', 'label' => 'Perte'],
                ['key' => 'theft', 'label' => 'Vol'],
                ['key' => 'damage', 'label' => 'Dommage'],
                ['key' => 'maintenance', 'label' => 'Maintenance', 'requires_maintenance_log' => true],
            ] as $default) {
                \App\Models\StockExitType::create($default + ['farm_id' => $farmId]);
            }
        }

        // Editing an entry whose type was since deactivated must still show that type as an
        // option — otherwise the select silently falls back to a different one on save.
        return \App\Models\StockExitType::where('farm_id', $farmId)
            ->where(fn ($q) => $q->where('is_active', true)->when($mustIncludeKey, fn ($q2) => $q2->orWhere('key', $mustIncludeKey)))
            ->orderBy('id')
            ->get(['id', 'key', 'label', 'requires_maintenance_log']);
    }

    /**
     * Active vehicle "Type" options for a farm's Véhicules forms — configurable by
     * farm_manager/super_admin (see VehicleTypeController) instead of a hardcoded list. Same
     * self-seeding/mustIncludeKey pattern as exitTypesFor() — see there for why. Equipment
     * types stay a separate, still-hardcoded list (VehicleController::equipmentTypes()); only
     * "vehicle" asset_type entries use this table.
     */
    protected function vehicleTypesFor(?int $farmId, ?string $mustIncludeKey = null)
    {
        if (!$farmId) {
            return collect();
        }

        if (! \App\Models\VehicleType::where('farm_id', $farmId)->exists()) {
            foreach ([
                ['key' => 'tractor', 'label' => 'Tracteur'],
                ['key' => 'truck', 'label' => 'Camion'],
                ['key' => 'van', 'label' => 'Camionnette'],
                ['key' => 'car', 'label' => 'Voiture'],
                ['key' => 'quad', 'label' => 'Quad'],
                ['key' => 'other', 'label' => 'Autre'],
            ] as $default) {
                \App\Models\VehicleType::create($default + ['farm_id' => $farmId]);
            }
        }

        return \App\Models\VehicleType::where('farm_id', $farmId)
            ->where(fn ($q) => $q->where('is_active', true)->when($mustIncludeKey, fn ($q2) => $q2->orWhere('key', $mustIncludeKey)))
            ->orderBy('id')
            ->get(['id', 'key', 'label']);
    }

    /**
     * Active equipment "Type" options for a farm's Véhicules forms — configurable by
     * farm_manager/super_admin (see EquipmentTypeController) instead of a hardcoded list. Same
     * self-seeding/mustIncludeKey pattern as vehicleTypesFor()/exitTypesFor() — see there for
     * why. Only asset_type=equipment vehicles use this table.
     */
    protected function equipmentTypesFor(?int $farmId, ?string $mustIncludeKey = null)
    {
        if (!$farmId) {
            return collect();
        }

        if (! \App\Models\EquipmentType::where('farm_id', $farmId)->exists()) {
            foreach ([
                ['key' => 'pump', 'label' => 'Pompe'],
                ['key' => 'generator', 'label' => 'Générateur'],
                ['key' => 'sprayer', 'label' => 'Pulvérisateur / Atomiseur'],
                ['key' => 'compressor', 'label' => 'Compresseur'],
                ['key' => 'mulcher', 'label' => 'Broyeur'],
                ['key' => 'plow', 'label' => 'Charrue à Disque'],
                ['key' => 'mower', 'label' => 'Faucheuse'],
                ['key' => 'leveler', 'label' => 'Lame Niveleuse'],
                ['key' => 'roller', 'label' => 'Rouleau Cover Crop'],
                ['key' => 'tool', 'label' => 'Outil'],
                ['key' => 'other', 'label' => 'Autre'],
            ] as $default) {
                \App\Models\EquipmentType::create($default + ['farm_id' => $farmId]);
            }
        }

        return \App\Models\EquipmentType::where('farm_id', $farmId)
            ->where(fn ($q) => $q->where('is_active', true)->when($mustIncludeKey, fn ($q2) => $q2->orWhere('key', $mustIncludeKey)))
            ->orderBy('id')
            ->get(['id', 'key', 'label']);
    }

    /**
     * Validate a requested ?enterprise_id against the resolved farm scope before trusting it.
     * Several Pointage/Analytics/Payroll queries only apply their farm_id filter "when no
     * enterprise_id is given" — without this check, an unvalidated enterprise_id belonging to a
     * different farm would bypass that filter entirely and leak the other farm's data straight
     * through. A user with their own fixed enterprise_id is always confined to it regardless of
     * what the query string says.
     */
    protected function scopedEnterpriseId(Request $request, ?int $farmId): ?int
    {
        if ($request->user()->enterprise_id) {
            return $request->user()->enterprise_id;
        }

        $enterpriseId = $request->query('enterprise_id');
        if (!$enterpriseId) {
            return null;
        }

        $enterprise = \App\Models\Enterprise::find($enterpriseId);
        abort_unless($enterprise && $enterprise->farm_id === $farmId, 403);

        return (int) $enterpriseId;
    }

    /*
     * The record-level scope checks. Every controller goes through these instead of its own
     * copy of the role branching — several hand-rolled copies drifted apart (one leaked another
     * farm's employees, others let a division-locked user act on a sibling division).
     *
     * The rule, in one place: a record must belong to the requester's farm (scopedFarmId():
     * the super_admin's active farm, everyone else's own farm_id), and a user locked to one
     * division (users.enterprise_id) may only touch that division. Cross-tenant access is a 403.
     */

    /**
     * The record's farm must be the requester's farm. Every route-bound or payload-supplied
     * record goes through this (or one of the checks below) before it's shown or changed —
     * Stock/Transport show/update/destroy methods originally trusted the bound model outright,
     * letting anyone read or mutate another farm's records by walking IDs.
     */
    protected function assertFarmScope(Request $request, $farmId): void
    {
        $scopedFarmId = $this->scopedFarmId($request);

        abort_unless($scopedFarmId && $farmId !== null && (int) $farmId === $scopedFarmId, 403);
    }

    /**
     * The division must be in the requester's farm — and be their own if they're locked to one.
     * Accepts the model or an id (e.g. straight from a request payload); returns the model.
     */
    protected function assertEnterpriseInScope(Request $request, $enterprise): \App\Models\Enterprise
    {
        if (! $enterprise instanceof \App\Models\Enterprise) {
            $enterprise = \App\Models\Enterprise::find($enterprise);
        }
        abort_unless($enterprise, 403);

        $this->assertFarmScope($request, $enterprise->farm_id);
        $this->assertDivisionLock($request, $enterprise->id);

        return $enterprise;
    }

    /** A quinzaine belongs to exactly one division — same rule as assertEnterpriseInScope(). */
    protected function assertQuinzaineInScope(Request $request, \App\Models\Quinzaine $quinzaine): void
    {
        $this->assertEnterpriseInScope($request, $quinzaine->enterprise);
    }

    /**
     * Employees are farm-scoped (the same worker can move between divisions), so the farm is the
     * boundary — plus the division lock for a user confined to one division.
     */
    protected function assertEmployeeInScope(Request $request, \App\Models\Employee $employee): void
    {
        $this->assertFarmScope($request, $employee->farm_id);
        $this->assertDivisionLock($request, $employee->enterprise_id);
    }

    private function assertDivisionLock(Request $request, $enterpriseId): void
    {
        $lockedTo = $request->user()->enterprise_id;

        abort_if($lockedTo && (int) $enterpriseId !== (int) $lockedTo, 403);
    }

    /**
     * Divisions offered in a page's division picker/filter: the requester's farm's divisions,
     * or none for a user locked to one division (they have nothing to pick).
     */
    protected function selectableEnterprises(Request $request)
    {
        if ($request->user()->enterprise_id) {
            return collect();
        }

        return \App\Models\Enterprise::where('farm_id', $this->scopedFarmId($request))->get();
    }
}
