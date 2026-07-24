<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sorties no longer take a manual price — cost is derived from StockInventory.average_cost
        // (the CUMP/weighted-average cost built up from every réception) at write time instead.
        Schema::disableForeignKeyConstraints();
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable();
        });
        Schema::enableForeignKeyConstraints();
    }
};
