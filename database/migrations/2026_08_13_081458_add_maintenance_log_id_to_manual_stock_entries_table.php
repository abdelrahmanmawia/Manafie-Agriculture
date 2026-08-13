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
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            // A maintenance intervention (VehicleMaintenanceLog) can consume several products
            // (filtre + huile + joint...), so the FK lives here rather than the other way
            // round — one log can have many linked sorties, not the reverse.
            $table->foreignId('maintenance_log_id')->nullable()->after('vehicle_id')
                ->constrained('vehicle_maintenance_logs')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_log_id');
        });
    }
};
