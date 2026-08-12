<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLite's `PRAGMA foreign_keys` is a no-op inside an active transaction, and altering
    // a column that other tables reference (plate_number/type below) requires SQLite to
    // recreate the vehicles table under the hood, which needs FK checks off to drop the
    // old copy — so this migration must run outside Laravel's automatic transaction wrap.
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('asset_type')->default('vehicle')->after('farm_id');
            $table->string('status')->default('operational')->after('is_active');
            $table->string('serial_number')->nullable()->after('plate_number');
            $table->date('purchase_date')->nullable()->after('default_daily_rate');
            $table->decimal('purchase_value', 10, 2)->nullable()->after('purchase_date');
        });

        Schema::disableForeignKeyConstraints();
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('plate_number')->nullable()->change();
            $table->string('type')->change();
        });
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['asset_type', 'status', 'serial_number', 'purchase_date', 'purchase_value']);
        });
    }
};
