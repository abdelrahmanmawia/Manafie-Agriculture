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
        Schema::table('vehicles', function (Blueprint $table) {
            // Only vehicles flagged for location (rental) tracking show up in the Location grid —
            // most vehicles are just used directly by the farm, not rented out/tracked that way.
            $table->boolean('is_location')->default(false)->after('default_daily_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('is_location');
        });
    }
};
