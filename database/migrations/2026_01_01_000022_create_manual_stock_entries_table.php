<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_stock_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('entry_type');
            $table->decimal('quantity', 12, 2);
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
            // A maintenance intervention (VehicleMaintenanceLog) can consume several products
            // (filtre + huile + joint...), so the FK lives here rather than the other way round.
            // No FK constraint in the live database (added later via ALTER, which never
            // registered a real constraint here) — kept as a plain column to match.
            $table->unsignedBigInteger('maintenance_log_id')->nullable();
            $table->foreignId('pointage_record_id')->nullable()->constrained();
            $table->foreignId('operation_id')->nullable()->constrained('operations');
            $table->foreignId('bloc_id')->nullable()->constrained();
            $table->foreignId('sector_id')->nullable()->constrained();
            $table->foreignId('parcelle_id')->nullable()->constrained();
            $table->date('date');
            $table->foreignId('entered_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            // Lets the manual-entry form also cover vehicle fuel/consumables: shown only when a
            // vehicle is selected.
            $table->decimal('odometer_km', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_stock_entries');
    }
};
