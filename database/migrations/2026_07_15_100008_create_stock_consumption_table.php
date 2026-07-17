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
        Schema::create('stock_consumption', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_movement_id')->constrained()->onDelete('cascade');
            $table->foreignId('pointage_record_id')->nullable()->constrained(); // Manual link to attendance
            $table->foreignId('harvest_id')->nullable()->constrained();
            $table->foreignId('operation_id')->nullable()->constrained(); // Assuming an operations table exists
            $table->foreignId('bloc_id')->nullable()->constrained();
            $table->foreignId('sector_id')->nullable()->constrained();
            $table->foreignId('parcelle_id')->nullable()->constrained();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles'); // For fuel consumption
            $table->decimal('quantity_per_hectare', 10, 2)->nullable();
            $table->decimal('area_hectares', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_consumption');
    }
};
