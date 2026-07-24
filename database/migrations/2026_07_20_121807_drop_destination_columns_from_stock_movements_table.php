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
        // These duplicated the destination context already stored on the source document
        // (ManualStockEntry has bloc/sector/parcelle/vehicle, FuelTransaction has vehicle) —
        // resolved now via StockMovement::reference() (polymorphic on reference_type/reference_id).
        // SQLite rebuilds the table per dropColumn/dropForeign call, so each must be its own migration step.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('bloc_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('sector_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('parcelle_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('vehicle_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('bloc_id')->nullable()->constrained();
            $table->foreignId('sector_id')->nullable()->constrained();
            $table->foreignId('parcelle_id')->nullable()->constrained();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
        });
    }
};
