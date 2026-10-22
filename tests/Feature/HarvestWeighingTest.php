<?php

namespace Tests\Feature;

use App\Models\Bloc;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HarvestController::bulkWeigh(): a total weight is shared by box count with a box-less harvest
 * counted as one box on both sides of the ratio (so the shares add up to exactly the weight
 * entered), and a batch is weighed all-or-nothing.
 */
class HarvestWeighingTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private User $manager;
    private Bloc $bloc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::create(['name' => 'Farm']);
        $this->manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farm->id]);
        $this->bloc = Bloc::create(['farm_id' => $this->farm->id, 'name' => 'B1']);
    }

    private function harvest(?int $boxes, array $overrides = []): Harvest
    {
        return Harvest::create(array_merge([
            'farm_id' => $this->farm->id, 'bloc_id' => $this->bloc->id, 'date' => '2026-10-05',
            'variety' => 'Hass', 'quantity_kg' => 0, 'boxes_count' => $boxes, 'unit_price_dh' => 10,
        ], $overrides));
    }

    private function weighTotal(array $harvests, float $totalKg)
    {
        return $this->actingAs($this->manager)->post('/harvests/bulk-weigh', [
            'harvest_ids' => collect($harvests)->pluck('id')->all(),
            'weighing_type' => 'total',
            'total_weight_kg' => $totalKg,
        ]);
    }

    public function test_total_weight_is_shared_by_boxes_and_adds_up_exactly(): void
    {
        $a = $this->harvest(10);
        $b = $this->harvest(0);    // no box count: weighs as one box
        $c = $this->harvest(30);

        $this->weighTotal([$a, $b, $c], 410)->assertSessionHasNoErrors();

        // 41 "boxes" in all -> 10 kg per box.
        $this->assertEqualsWithDelta(100, $a->fresh()->actual_kg, 0.01);
        $this->assertEqualsWithDelta(10, $b->fresh()->actual_kg, 0.01);
        $this->assertEqualsWithDelta(300, $c->fresh()->actual_kg, 0.01);
        $this->assertEqualsWithDelta(410, Harvest::sum('actual_kg'), 0.01);
        $this->assertEqualsWithDelta(1000, $a->fresh()->total_revenue_dh, 0.01);
    }

    public function test_harvests_without_any_box_count_share_the_weight_equally(): void
    {
        $a = $this->harvest(null);
        $b = $this->harvest(null);

        $this->weighTotal([$a, $b], 90)->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(45, $a->fresh()->actual_kg, 0.01);
        $this->assertEqualsWithDelta(45, $b->fresh()->actual_kg, 0.01);
    }

    public function test_individual_weights_are_applied_per_harvest(): void
    {
        $a = $this->harvest(5, ['estimated_kg' => 250]);
        $b = $this->harvest(5, ['estimated_kg' => 250]);

        $this->actingAs($this->manager)->post('/harvests/bulk-weigh', [
            'harvest_ids' => [$a->id, $b->id],
            'weighing_type' => 'individual',
            'individual_weights' => [$a->id => 240],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(240, $a->fresh()->actual_kg, 0.01);
        $this->assertEqualsWithDelta(250, $b->fresh()->actual_kg, 0.01); // falls back to the estimate
        $this->assertSame($a->fresh()->weighing_batch_id, $b->fresh()->weighing_batch_id);
    }

    public function test_a_failure_mid_batch_weighs_nothing(): void
    {
        $a = $this->harvest(10);
        $b = $this->harvest(10);

        // Simulate the second harvest's update failing after the first one was written.
        Harvest::updating(function (Harvest $harvest) use ($b) {
            if ($harvest->id === $b->id) {
                throw new \RuntimeException('disk full');
            }
        });

        $this->weighTotal([$a, $b], 200)->assertStatus(500);

        $this->assertFalse((bool) $a->fresh()->is_weighed);
        $this->assertNull($a->fresh()->weighing_batch_id);
    }

    public function test_another_farms_harvest_aborts_the_whole_batch(): void
    {
        $own = $this->harvest(10);
        $otherFarm = Farm::create(['name' => 'Other']);
        $foreign = $this->harvest(10, [
            'farm_id' => $otherFarm->id,
            'bloc_id' => Bloc::create(['farm_id' => $otherFarm->id, 'name' => 'X'])->id,
        ]);

        $this->weighTotal([$own, $foreign], 200)->assertForbidden();

        $this->assertFalse((bool) $own->fresh()->is_weighed);
    }
}
