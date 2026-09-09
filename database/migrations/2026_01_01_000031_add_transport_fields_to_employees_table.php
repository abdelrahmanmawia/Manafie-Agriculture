<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Distinct from the existing `address` column (the employee's CIN/legal address) —
            // this is where they actually live/commute from, and drives transport pricing.
            $table->foreignId('residence_location_id')->nullable()->after('address')
                ->constrained('transport_locations')->nullOnDelete();
            $table->foreignId('transport_vehicle_id')->nullable()->after('residence_location_id')
                ->constrained('transport_vehicles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('residence_location_id');
            $table->dropConstrainedForeignId('transport_vehicle_id');
        });
    }
};
