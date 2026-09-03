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
}
