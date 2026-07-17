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
        Schema::create('manual_stock_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->enum('entry_type', ['consumption', 'transfer', 'loss', 'theft', 'damage']);
            $table->decimal('quantity', 12, 2);
            $table->foreignId('employee_id')->nullable()->constrained('employees'); // Who used/consumed
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles'); // If for vehicle
            $table->foreignId('pointage_record_id')->nullable()->constrained(); // Link to work session
            $table->foreignId('operation_id')->nullable()->constrained('operations'); // What operation
            $table->foreignId('bloc_id')->nullable()->constrained();
            $table->foreignId('sector_id')->nullable()->constrained();
            $table->foreignId('parcelle_id')->nullable()->constrained();
            $table->date('date');
            $table->foreignId('entered_by')->constrained('users'); // Who manually entered this
            $table->text('notes')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_stock_entries');
    }
};
