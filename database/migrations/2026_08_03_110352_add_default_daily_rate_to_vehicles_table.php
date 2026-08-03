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
            // Pre-fills a usage-grid cell's rate; still editable per day since real rates differ
            // per piece of equipment (e.g. 300 vs 150 DH/day).
            $table->decimal('default_daily_rate', 10, 2)->nullable()->after('fuel_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('default_daily_rate');
        });
    }
};
