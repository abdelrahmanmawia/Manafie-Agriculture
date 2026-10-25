<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the fuel categories: a consumption sortie of their products is a vehicle's fill-up,
 * recorded as a FuelTransaction (Carburant page, fuel cost per vehicle), not a plain sortie.
 * The farm's existing "Carburant" category gets the mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->boolean('is_carburant')->default(false)->after('is_vehicle_related');
        });

        DB::table('product_categories')->whereRaw('LOWER(name) = ?', ['carburant'])->update(['is_carburant' => true]);
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('is_carburant');
        });
    }
};
