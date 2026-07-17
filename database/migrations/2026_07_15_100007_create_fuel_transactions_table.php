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
        Schema::create('fuel_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
            $table->foreignId('product_id')->constrained('products'); // Links to fuel product

            $table->enum('transaction_type', ['fueling', 'transfer', 'adjustment']);
            $table->decimal('quantity_liters', 10, 2);
            $table->decimal('unit_price_per_liter', 8, 2)->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('employees'); // Who took the fuel
            $table->foreignId('performed_by')->nullable()->constrained('users'); // Who entered the transaction
            $table->date('date');
            $table->decimal('odometer_km', 10, 2)->nullable(); // Vehicle odometer reading
            $table->decimal('hours_worked', 8, 2)->nullable(); // For tractors - hours of operation
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fuel_transactions');
    }
};
