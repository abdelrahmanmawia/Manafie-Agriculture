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
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->dropColumn('hours_worked');
        });
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->decimal('hours_worked', 8, 2)->nullable();
        });
        Schema::enableForeignKeyConstraints();
    }
};
