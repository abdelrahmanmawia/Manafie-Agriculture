<?php

namespace Tests\Feature\Stock;

use App\Models\Farm;
use App\Models\ManualStockEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockInventory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Excel/PDF export endpoints added to StockInventoryController: the farm-wide
 * inventory list export and the per-product movement history export, plus that the latter
 * respects the same cross-farm scoping as show()/count() (StockCrossFarmScopingTest's finding).
 */
class StockExportTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farmA;
    private Farm $farmB;
    private User $managerA;
    private Product $productA;
    private StockInventory $inventoryA;
    private StockInventory $inventoryB;
    private ProductCategory $categoryA;
    private ProductCategory $categoryB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farmA = Farm::create(['name' => 'Farm A']);
        $this->farmB = Farm::create(['name' => 'Farm B']);

        $this->managerA = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farmA->id]);

        $this->categoryA = ProductCategory::create(['farm_id' => $this->farmA->id, 'name' => 'Engrais']);
        $this->categoryB = ProductCategory::create(['farm_id' => $this->farmB->id, 'name' => 'Engrais']);
        $otherCategoryA = ProductCategory::create(['farm_id' => $this->farmA->id, 'name' => 'Semences']);

        $this->productA = Product::create([
            'farm_id' => $this->farmA->id, 'name' => 'Engrais NPK', 'category_id' => $this->categoryA->id,
            'unit_type' => 'kg', 'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);
        $otherProductA = Product::create([
            'farm_id' => $this->farmA->id, 'name' => 'Semence Mais', 'category_id' => $otherCategoryA->id,
            'unit_type' => 'kg', 'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);
        $productB = Product::create([
            'farm_id' => $this->farmB->id, 'name' => 'Product B', 'category_id' => $this->categoryB->id,
            'unit_type' => 'kg', 'min_stock_level' => 10, 'unit_cost' => 5, 'is_active' => true,
        ]);

        $this->inventoryA = StockInventory::create([
            'product_id' => $this->productA->id, 'quantity_on_hand' => 500, 'quantity_reserved' => 0,
        ]);
        StockInventory::create([
            'product_id' => $otherProductA->id, 'quantity_on_hand' => 300, 'quantity_reserved' => 0,
        ]);
        $this->inventoryB = StockInventory::create([
            'product_id' => $productB->id, 'quantity_on_hand' => 200, 'quantity_reserved' => 0,
        ]);

        StockMovement::create([
            'product_id' => $this->productA->id, 'movement_type' => 'in', 'quantity' => 500,
            'unit_cost' => 5, 'total_cost' => 2500, 'performed_by' => $this->managerA->id, 'date' => '2026-01-01',
        ]);
    }

    /**
     * Excel::download() returns a BinaryFileResponse, which streams straight from disk —
     * getContent() always returns false for it (Symfony's own behavior), so read the
     * underlying temp file it already wrote instead.
     */
    private function loadWorkbook(\Illuminate\Testing\TestResponse $response): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        return \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());
    }

    public function test_inventory_excel_export_downloads_successfully(): void
    {
        $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-excel'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_inventory_pdf_export_downloads_successfully(): void
    {
        $response = $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-pdf'))
            ->assertOk();

        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_product_movements_excel_export_downloads_successfully(): void
    {
        $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-movements-excel', $this->inventoryA))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_movements_export_running_balance_handles_signed_adjustments_correctly(): void
    {
        // 'in'/'out' always store a positive magnitude, but 'adjustment' (from a stock count)
        // stores a SIGNED delta — treating every non-'in' row as a subtraction used to silently
        // subtract a count INCREASE instead of adding it. Covers both an increasing and a
        // decreasing adjustment on top of the 500 'in' already seeded in setUp().
        $supplier = \App\Models\Supplier::create(['farm_id' => $this->farmA->id, 'name' => 'GDIRAGRI sarl']);
        StockMovement::create([
            'product_id' => $this->productA->id, 'movement_type' => 'out', 'quantity' => 50,
            'unit_cost' => 5, 'total_cost' => 250, 'date' => '2026-01-02',
        ]);
        StockMovement::create([
            'product_id' => $this->productA->id, 'movement_type' => 'adjustment', 'quantity' => 30, // increase
            'date' => '2026-01-03',
        ]);
        StockMovement::create([
            'product_id' => $this->productA->id, 'movement_type' => 'adjustment', 'quantity' => -10, // decrease
            'date' => '2026-01-04',
        ]);
        StockMovement::create([
            'product_id' => $this->productA->id, 'supplier_id' => $supplier->id, 'numero_bl' => 'BL-42',
            'movement_type' => 'in', 'quantity' => 20, 'date' => '2026-01-05',
        ]);
        // Running balance should read: 500, 450, 480, 470, 490 — never drop the +30 to a -30.

        $response = $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-movements-excel', $this->inventoryA))
            ->assertOk();

        $sheet = $this->loadWorkbook($response)->getActiveSheet();
        $allText = collect($sheet->toArray())->flatten()->filter()->implode(' | ');

        $this->assertStringContainsString('490', $allText); // final running balance
        $this->assertStringContainsString('GDIRAGRI sarl', $allText);
        $this->assertStringContainsString('BL-42', $allText);
        // The increasing adjustment (+30) must never render as a negative/subtracted value.
        $this->assertStringNotContainsString('-30', $allText);
    }

    public function test_sortie_with_no_note_shows_its_exit_type_instead_of_a_blank_or_auto_message(): void
    {
        $entryNoNote = ManualStockEntry::create([
            'farm_id' => $this->farmA->id, 'product_id' => $this->productA->id, 'entry_type' => 'loss',
            'quantity' => 10, 'date' => '2026-01-02', 'entered_by' => $this->managerA->id,
        ]);
        StockMovement::create([
            'product_id' => $this->productA->id, 'movement_type' => 'out', 'quantity' => 10,
            'reference_type' => 'manual_entry', 'reference_id' => $entryNoNote->id, 'date' => '2026-01-02',
        ]);

        $entryWithNote = ManualStockEntry::create([
            'farm_id' => $this->farmA->id, 'product_id' => $this->productA->id, 'entry_type' => 'consumption',
            'quantity' => 5, 'date' => '2026-01-03', 'entered_by' => $this->managerA->id, 'notes' => 'Épandu bloc A',
        ]);
        StockMovement::create([
            'product_id' => $this->productA->id, 'movement_type' => 'out', 'quantity' => 5,
            'reference_type' => 'manual_entry', 'reference_id' => $entryWithNote->id, 'date' => '2026-01-03',
            'notes' => 'Épandu bloc A',
        ]);

        $response = $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-movements-excel', $this->inventoryA))
            ->assertOk();

        $sheet = $this->loadWorkbook($response)->getActiveSheet();
        $allText = collect($sheet->toArray())->flatten()->filter()->implode(' | ');

        // No note -> falls back to the exit type's real label (from the dropdown), not blank
        // and not the old "Manual entry: loss" auto text.
        $this->assertStringContainsString('Perte', $allText);
        $this->assertStringNotContainsString('Manual entry', $allText);
        // A real note always wins over the type fallback.
        $this->assertStringContainsString('Épandu bloc A', $allText);
    }

    public function test_product_movements_pdf_export_downloads_successfully(): void
    {
        $response = $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-movements-pdf', $this->inventoryA))
            ->assertOk();

        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_farm_manager_cannot_export_another_farms_product_movements(): void
    {
        $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-movements-excel', $this->inventoryB))
            ->assertForbidden();

        $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-movements-pdf', $this->inventoryB))
            ->assertForbidden();
    }

    public function test_unfiltered_excel_export_groups_every_category_and_shows_only_designation_and_quantity(): void
    {
        $response = $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-excel'))
            ->assertOk();

        $sheet = $this->loadWorkbook($response)->getActiveSheet();
        $allText = collect($sheet->toArray())->flatten()->filter()->implode(' | ');

        $this->assertStringContainsString('ENGRAIS', $allText);
        $this->assertStringContainsString('SEMENCES', $allText);
        $this->assertStringContainsString('Engrais NPK', $allText);
        $this->assertStringContainsString('Semence Mais', $allText);
        // Both products' own category column value never appears as row data (only as the
        // section header) — i.e. no per-row "Engrais"/"Semences" cell like the old 8-column
        // layout had.
        $this->assertStringNotContainsString('Coût Unitaire', $allText);
        $this->assertStringNotContainsString('Statut', $allText);
    }

    public function test_excel_export_filtered_by_category_shows_only_that_categorys_products(): void
    {
        $response = $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-excel', ['category_id' => $this->categoryA->id]))
            ->assertOk();

        $sheet = $this->loadWorkbook($response)->getActiveSheet();
        $allText = collect($sheet->toArray())->flatten()->filter()->implode(' | ');

        $this->assertStringContainsString('Engrais NPK', $allText);
        $this->assertStringContainsString('ENGRAIS', $allText); // title
        $this->assertStringNotContainsString('Semence Mais', $allText);
        $this->assertStringNotContainsString('SEMENCES', $allText);
    }

    public function test_farm_manager_cannot_filter_export_by_another_farms_category(): void
    {
        $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-excel', ['category_id' => $this->categoryB->id]))
            ->assertForbidden();

        $this->actingAs($this->managerA)
            ->get(route('stock.inventory.export-pdf', ['category_id' => $this->categoryB->id]))
            ->assertForbidden();
    }
}
