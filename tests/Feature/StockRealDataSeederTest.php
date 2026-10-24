<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Product;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\Vehicle;
use Database\Seeders\StockRealDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The farm's real magasin + matériel: opening stock paired with its movement, safe to re-run. */
class StockRealDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_magasin_with_an_opening_movement_per_stocked_product_and_can_run_again(): void
    {
        $farm = Farm::create(['name' => 'Persealand']);

        $this->seed(StockRealDataSeeder::class);
        $this->seed(StockRealDataSeeder::class);

        $this->assertSame(120, Product::where('farm_id', $farm->id)->count());
        $this->assertSame(30, Vehicle::where('farm_id', $farm->id)->count());
        $this->assertSame(120, StockInventory::count());

        // Every unit on hand comes from an "Inventaire initial" movement, nothing more.
        $stocked = StockInventory::where('quantity_on_hand', '>', 0)->get();
        $this->assertSame($stocked->count(), StockMovement::count());
        $this->assertEqualsWithDelta($stocked->sum('quantity_on_hand'), StockMovement::sum('quantity'), 0.01);
        $this->assertSame(['adjustment'], StockMovement::distinct()->pluck('movement_type')->all());

        // A driver who isn't a worker in the app keeps their name in the notes.
        $this->assertStringContainsString('Chauffeur : TAHRI ABDELMOUNIM', Vehicle::where('plate_number', '5710')->sole()->notes);
    }
}
