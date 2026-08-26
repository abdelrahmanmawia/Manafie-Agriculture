<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // SQLite's `PRAGMA foreign_keys` is a no-op inside an active transaction, and altering a
    // column that's part of a foreign key requires SQLite to rebuild the table under the hood,
    // which needs FK checks off — see the vehicles asset-fields migration for the same pattern.
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('pointage_records', function (Blueprint $table) {
            // A record can now represent a paid public holiday the employee did NOT work (no
            // operation/bloc to attribute it to) — see PointageController::updateCell(). A
            // present-and-working day still always sets both.
            $table->foreignId('operation_id')->nullable()->change();
            $table->foreignId('bloc_id')->nullable()->change();
        });
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('pointage_records', function (Blueprint $table) {
            $table->foreignId('operation_id')->nullable(false)->change();
            $table->foreignId('bloc_id')->nullable(false)->change();
        });
        Schema::enableForeignKeyConstraints();
    }
};
