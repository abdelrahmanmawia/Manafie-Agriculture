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
        // Optional attribution: which bloc/opération this fill-up's fuel was for, so it can be
        // counted in Coût par Hectare / Consommation par Opération alongside ManualStockEntry.
        Schema::table('fuel_transactions', function (Blueprint $table) {
            $table->foreignId('operation_id')->nullable()->constrained();
            $table->foreignId('bloc_id')->nullable()->constrained();
            $table->foreignId('sector_id')->nullable()->constrained();
            $table->foreignId('parcelle_id')->nullable()->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fuel_transactions', function (Blueprint $table) {
            $table->dropColumn('operation_id');
        });
        Schema::table('fuel_transactions', function (Blueprint $table) {
            $table->dropColumn('bloc_id');
        });
        Schema::table('fuel_transactions', function (Blueprint $table) {
            $table->dropColumn('sector_id');
        });
        Schema::table('fuel_transactions', function (Blueprint $table) {
            $table->dropColumn('parcelle_id');
        });
    }
};
