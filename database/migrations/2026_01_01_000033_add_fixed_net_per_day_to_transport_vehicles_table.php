<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_vehicles', function (Blueprint $table) {
            // Null = auto (sum of current riders' residenceLocation.price_per_person, see
            // TransportVehicle::netPerDay()); set = this vehicle's daily cost is exactly this
            // number regardless of who's currently assigned — e.g. a flat rate negotiated with
            // the transport company instead of per-rider pricing.
            $table->decimal('fixed_net_per_day', 8, 2)->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('transport_vehicles', function (Blueprint $table) {
            $table->dropColumn('fixed_net_per_day');
        });
    }
};
