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
            // Some low-value, non-individually-serialized implements (broyeurs, charrues...)
            // are tracked by count rather than one row per unit — see project notes from the
            // "INVENTAIRE DES ENGINS" import review. Defaults to 1 for everything else.
            $table->unsignedInteger('quantity')->default(1)->after('status');
            // Reservoir/tank capacity for citernes and pulvérisateurs — has no equivalent
            // column until now.
            $table->decimal('capacity_liters', 10, 2)->nullable()->after('fuel_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'capacity_liters']);
        });
    }
};
