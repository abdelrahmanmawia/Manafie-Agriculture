<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The configurable option lists every farm starts with: "Type de Sortie" (stock_exit_types),
 * vehicle types and equipment types. Farm managers can then rename, deactivate or add to them
 * (StockExitTypeController / VehicleTypeController / EquipmentTypeController).
 *
 * Seeded once when a farm is created (FarmObserver), and for farms that existed before that by
 * migration 000045 — the pages that read these lists used to insert them on first view instead
 * (a GET that writes), and two first views at once could collide on the unique (farm_id, key)
 * index as a 500.
 *
 * The exit-type keys are what old manual_stock_entries.entry_type rows already hold, and
 * `maintenance` drives the sortie form's "Intervention liée" field (requires_maintenance_log).
 */
class FarmDefaultsService
{
    const EXIT_TYPES = [
        ['key' => 'consumption', 'label' => 'Consommation'],
        ['key' => 'loss', 'label' => 'Perte'],
        ['key' => 'theft', 'label' => 'Vol'],
        ['key' => 'damage', 'label' => 'Dommage'],
        ['key' => 'maintenance', 'label' => 'Maintenance', 'requires_maintenance_log' => true],
    ];

    const VEHICLE_TYPES = [
        ['key' => 'tractor', 'label' => 'Tracteur'],
        ['key' => 'truck', 'label' => 'Camion'],
        ['key' => 'van', 'label' => 'Camionnette'],
        ['key' => 'car', 'label' => 'Voiture'],
        ['key' => 'quad', 'label' => 'Quad'],
        ['key' => 'other', 'label' => 'Autre'],
    ];

    const EQUIPMENT_TYPES = [
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
    ];

    /**
     * Gives a farm each default list it has no rows for at all. A list the farm already has —
     * even one whose defaults were since renamed or deactivated — is left alone, so this is safe
     * to run any number of times. insertOrIgnore() makes a concurrent run a no-op rather than a
     * unique-index error.
     */
    public function seed(int $farmId): void
    {
        $this->seedTable('stock_exit_types', self::EXIT_TYPES, $farmId, ['requires_maintenance_log' => false]);
        $this->seedTable('vehicle_types', self::VEHICLE_TYPES, $farmId);
        $this->seedTable('equipment_types', self::EQUIPMENT_TYPES, $farmId);
    }

    private function seedTable(string $table, array $defaults, int $farmId, array $columnDefaults = []): void
    {
        if (DB::table($table)->where('farm_id', $farmId)->exists()) {
            return;
        }

        $now = now();
        DB::table($table)->insertOrIgnore(array_map(
            fn ($row) => $row + $columnDefaults + ['farm_id' => $farmId, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            $defaults
        ));
    }
}
