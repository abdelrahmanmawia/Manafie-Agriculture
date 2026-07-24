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
        // Lets one form (Entrée Manuelle) also cover vehicle fuel/consumables: shown only
        // when a vehicle is selected, mirroring what FuelTransaction used to capture alone.
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->decimal('odometer_km', 10, 2)->nullable();
            $table->decimal('hours_worked', 8, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'odometer_km', 'hours_worked']);
        });
    }
};
