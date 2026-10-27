<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the fertilizer categories: their consumption sorties make the "Suivi des engrais" report
 * (per day and bloc, doses per hectare). The farm's existing "Engrais" category gets the mark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->boolean('is_engrais')->default(false)->after('is_carburant');
        });

        DB::table('product_categories')->whereRaw('LOWER(name) LIKE ?', ['engrais%'])->update(['is_engrais' => true]);
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('is_engrais');
        });
    }
};
