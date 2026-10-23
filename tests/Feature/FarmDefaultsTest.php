<?php

namespace Tests\Feature;

use App\Models\EquipmentType;
use App\Models\Farm;
use App\Models\StockExitType;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\FarmDefaultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Default exit/vehicle/equipment types are created with the farm (FarmObserver) — the Stock
 * pages that list them only read, where they used to insert them on first view.
 */
class FarmDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function counts(int $farmId): array
    {
        return [
            StockExitType::where('farm_id', $farmId)->count(),
            VehicleType::where('farm_id', $farmId)->count(),
            EquipmentType::where('farm_id', $farmId)->count(),
        ];
    }

    public function test_a_new_farm_gets_its_default_types(): void
    {
        $farm = Farm::create(['name' => 'New']);

        $this->assertSame([5, 6, 11], $this->counts($farm->id));
        $this->assertTrue((bool) StockExitType::where('farm_id', $farm->id)->where('key', 'maintenance')->value('requires_maintenance_log'));
    }

    public function test_a_farm_created_from_the_hub_gets_them_too(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'farm_id' => null]);

        $this->actingAs($admin)->post('/farms', ['name' => 'Ferme Hub'])->assertSessionHasNoErrors();

        $this->assertSame([5, 6, 11], $this->counts(Farm::where('name', 'Ferme Hub')->value('id')));
    }

    public function test_opening_stock_pages_never_writes_types(): void
    {
        $farm = Farm::create(['name' => 'Farm']);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        // A farm whose manager deactivated nothing but deleted every exit type: still read-only.
        StockExitType::where('farm_id', $farm->id)->delete();

        $this->actingAs($manager)->get(route('stock.manual-entries.index'))->assertOk();
        $this->actingAs($manager)->get(route('stock.vehicles.index'))->assertOk();

        $this->assertSame([0, 6, 11], $this->counts($farm->id));
    }

    public function test_seeding_is_idempotent_and_leaves_edited_lists_alone(): void
    {
        $farm = Farm::create(['name' => 'Farm']);
        StockExitType::where('farm_id', $farm->id)->where('key', 'theft')->update(['label' => 'Vol (renommé)', 'is_active' => false]);
        VehicleType::where('farm_id', $farm->id)->where('key', '!=', 'tractor')->delete();

        app(FarmDefaultsService::class)->seed($farm->id);
        app(FarmDefaultsService::class)->seed($farm->id);

        $this->assertSame([5, 1, 11], $this->counts($farm->id));
        $this->assertSame('Vol (renommé)', StockExitType::where('farm_id', $farm->id)->where('key', 'theft')->value('label'));
    }

    public function test_migration_gives_defaults_to_farms_that_never_had_them(): void
    {
        // Inserted without the model, as farms created before FarmObserver existed were.
        $farmId = DB::table('farms')->insertGetId(['name' => 'Old', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([0, 0, 0], $this->counts($farmId));

        (require database_path('migrations/2026_01_01_000045_seed_default_types_for_existing_farms.php'))->up();

        $this->assertSame([5, 6, 11], $this->counts($farmId));
    }
}
