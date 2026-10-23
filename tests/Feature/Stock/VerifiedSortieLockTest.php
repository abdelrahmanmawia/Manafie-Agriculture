<?php

namespace Tests\Feature\Stock;

use App\Models\Farm;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A verified sortie is locked: nobody edits or deletes it (so its StockMovement audit row and
 * the stock it took out stay as verified) until a farm_manager/super_admin unlocks it. Anyone
 * with Stock access — the magasinier included — can verify.
 */
class VerifiedSortieLockTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $magasinier;
    private Product $product;
    private ManualStockEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $farm = Farm::create(['name' => 'Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        $this->magasinier = User::factory()->create([
            'role' => 'data_entry', 'farm_id' => $farm->id, 'can_access_pointage' => false, 'can_access_stock' => true,
        ]);
        $this->product = Product::create([
            'farm_id' => $farm->id, 'name' => 'Engrais', 'category' => 'fertilizers', 'unit_type' => 'kg',
            'min_stock_level' => 0, 'unit_cost' => 10, 'is_active' => true,
        ]);

        $this->actingAs($this->manager)->post(route('stock.movements.in'), [
            'product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 10, 'date' => now()->toDateString(),
        ]);
        $this->actingAs($this->magasinier)->post(route('stock.manual-entries.store'), [
            'product_id' => $this->product->id, 'entry_type' => 'consumption', 'quantity' => 10, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->entry = ManualStockEntry::sole();
    }

    private function verifyAs(User $user)
    {
        return $this->actingAs($user)->post(route('stock.manual-entries.verify', $this->entry));
    }

    private function onHand(): float
    {
        return (float) StockInventory::where('product_id', $this->product->id)->value('quantity_on_hand');
    }

    public function test_the_magasinier_can_verify_a_sortie(): void
    {
        $this->verifyAs($this->magasinier)->assertRedirect();

        $this->assertTrue((bool) $this->entry->fresh()->is_verified);
        $this->assertSame($this->magasinier->id, $this->entry->fresh()->verified_by);
    }

    public function test_verifying_again_keeps_the_first_verifier(): void
    {
        $this->verifyAs($this->magasinier);
        $this->verifyAs($this->manager);

        $this->assertSame($this->magasinier->id, $this->entry->fresh()->verified_by);
    }

    public function test_a_verified_sortie_cannot_be_updated_by_anyone(): void
    {
        $this->verifyAs($this->magasinier);

        foreach ([$this->magasinier, $this->manager] as $user) {
            $this->actingAs($user)->put(route('stock.manual-entries.update', $this->entry), ['quantity' => 50])
                ->assertSessionHas('error');
        }

        $this->assertEquals(10, $this->entry->fresh()->quantity);
        $this->assertEquals(10, StockMovement::where('reference_id', $this->entry->id)->value('quantity'));
        $this->assertEquals(90, $this->onHand());
    }

    public function test_a_verified_sortie_cannot_be_deleted_by_anyone(): void
    {
        $this->verifyAs($this->magasinier);

        foreach ([$this->magasinier, $this->manager] as $user) {
            $this->actingAs($user)->delete(route('stock.manual-entries.destroy', $this->entry->id))
                ->assertSessionHas('error');
        }

        $this->assertDatabaseHas('manual_stock_entries', ['id' => $this->entry->id]);
        $this->assertEquals(90, $this->onHand());
    }

    public function test_the_edit_page_of_a_verified_sortie_redirects_to_its_details(): void
    {
        $this->verifyAs($this->magasinier);

        $this->actingAs($this->manager)->get(route('stock.manual-entries.edit', $this->entry))
            ->assertRedirect(route('stock.manual-entries.show', $this->entry));
    }

    public function test_the_magasinier_cannot_unlock_a_verified_sortie(): void
    {
        $this->verifyAs($this->magasinier);

        $this->actingAs($this->magasinier)->post(route('stock.manual-entries.unverify', $this->entry))->assertForbidden();

        $this->assertTrue((bool) $this->entry->fresh()->is_verified);
    }

    public function test_a_manager_unlocks_then_the_sortie_can_be_corrected(): void
    {
        $this->verifyAs($this->magasinier);

        $this->actingAs($this->manager)->post(route('stock.manual-entries.unverify', $this->entry))->assertRedirect();
        $this->assertFalse((bool) $this->entry->fresh()->is_verified);
        $this->assertNull($this->entry->fresh()->verified_by);

        $this->actingAs($this->magasinier)->put(route('stock.manual-entries.update', $this->entry), ['quantity' => 15])
            ->assertSessionHasNoErrors();
        $this->assertEquals(15, $this->entry->fresh()->quantity);
        $this->assertEquals(85, $this->onHand());
    }

    public function test_an_unverified_sortie_can_still_be_edited_and_deleted_by_the_magasinier(): void
    {
        $this->actingAs($this->magasinier)->put(route('stock.manual-entries.update', $this->entry), ['quantity' => 12])
            ->assertSessionHasNoErrors();
        $this->assertEquals(88, $this->onHand());

        $this->actingAs($this->magasinier)->delete(route('stock.manual-entries.destroy', $this->entry->id))
            ->assertSessionMissing('error');
        $this->assertDatabaseMissing('manual_stock_entries', ['id' => $this->entry->id]);
        $this->assertEquals(100, $this->onHand());
    }
}
