<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
            $table->foreignId('product_id')->constrained('products');
            $table->string('transaction_type');
            $table->decimal('quantity_liters', 10, 2);
            $table->decimal('unit_price_per_liter', 8, 2)->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users');
            $table->date('date');
            $table->decimal('odometer_km', 10, 2)->nullable();
            $table->text('notes')->nullable();
            // Optional attribution: which bloc/opération this fill-up's fuel was for. No FK
            // constraint in the live database (added later via ALTER, which never registered a
            // real constraint here) — kept as plain columns.
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->unsignedBigInteger('bloc_id')->nullable();
            $table->unsignedBigInteger('sector_id')->nullable();
            $table->unsignedBigInteger('parcelle_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_transactions');
    }
};
