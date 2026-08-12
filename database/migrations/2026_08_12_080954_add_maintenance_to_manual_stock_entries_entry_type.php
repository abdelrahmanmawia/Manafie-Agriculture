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
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->string('entry_type')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('manual_stock_entries', function (Blueprint $table) {
            $table->enum('entry_type', ['consumption', 'transfer', 'loss', 'theft', 'damage'])->change();
        });
    }
};
