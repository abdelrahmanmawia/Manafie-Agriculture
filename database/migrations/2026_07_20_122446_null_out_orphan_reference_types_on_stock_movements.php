<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * reference_type/reference_id only mean something when they point at a real source
     * document (ManualStockEntry or FuelTransaction) — StockMovement::reference() is a
     * morphTo keyed off App\Providers\AppServiceProvider's morph map. Rows written by the
     * old stockIn()/stockOut() endpoints ('reception', 'manual_stock_out') never had a real
     * reference_id, so their reference_type is unresolvable and crashes eager loading. Null
     * both columns out for any row whose type isn't in the morph map.
     */
    public function up(): void
    {
        DB::table('stock_movements')
            ->whereNotIn('reference_type', ['manual_entry', 'fuel_transaction'])
            ->update(['reference_type' => null, 'reference_id' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data cleanup only — not reversible.
    }
};
