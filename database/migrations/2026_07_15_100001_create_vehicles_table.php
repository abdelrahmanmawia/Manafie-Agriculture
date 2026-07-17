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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->string('name'); // e.g., "Tractor 1", "Truck A"
            $table->string('plate_number')->unique();
            $table->enum('type', ['tractor', 'truck', 'van', 'car', 'other']);
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->year('year')->nullable();
            $table->string('fuel_type')->default('diesel'); // diesel, gasoline, etc.
            $table->decimal('fuel_capacity_liters', 8, 2)->nullable();
            $table->foreignId('default_driver_id')->nullable()->constrained('employees');
            $table->string('current_location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
