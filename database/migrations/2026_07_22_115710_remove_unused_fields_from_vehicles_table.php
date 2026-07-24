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
        Schema::disableForeignKeyConstraints();
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['brand', 'year', 'current_location', 'fuel_capacity_liters']);
        });
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('brand')->nullable();
            $table->year('year')->nullable();
            $table->string('current_location')->nullable();
            $table->decimal('fuel_capacity_liters', 8, 2)->nullable();
        });
        Schema::enableForeignKeyConstraints();
    }
};
