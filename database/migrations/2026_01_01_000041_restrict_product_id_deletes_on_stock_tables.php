<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Product uses SoftDeletes and nothing in the app ever calls forceDelete() on one — but the
// product_id FK on these four tables was still set to cascade, meaning a real hard delete would
// silently wipe stock history that this app treats as a permanent audit record (see
// StockMovement's own comments). Switching to restrict means that scenario fails loudly at the
// DB level instead of quietly destroying data.
//
// SQLite can't drop a foreign key via ALTER TABLE at all (confirmed directly — Laravel throws
// "SQLite doesn't support dropping foreign keys" outright, not just for the composite-index edge
// case seen in earlier migrations), so on that connection (test suite only, where these tables
// always run against an empty fresh DB) each table is dropped and recreated instead, reproducing
// its exact current schema minus the product_id FK's onDelete behavior.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->recreateForSqlite();

            return;
        }

        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign("{$table}_product_id_foreign");
            });
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            });
        }
    }

    public function down(): void
    {
        // SQLite: the test suite only ever runs migrations forward from an empty DB (RefreshDatabase),
        // never rolls one back — not worth reproducing the recreate-dance in reverse for a path
        // nothing exercises.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign("{$table}_product_id_foreign");
            });
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            });
        }
    }

    private function tables(): array
    {
        return ['stock_inventory', 'stock_movements', 'stock_alerts', 'manual_stock_entries'];
    }

    private function recreateForSqlite(): void
    {
        Schema::dropIfExists('stock_inventory');
        Schema::create('stock_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->decimal('quantity_on_hand', 12, 2)->default(0);
            $table->decimal('quantity_reserved', 12, 2)->default(0);
            $table->decimal('quantity_available', 12, 2)->storedAs('quantity_on_hand - quantity_reserved');
            $table->date('last_restock_date')->nullable();
            $table->date('last_count_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('average_cost', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('stock_movements');
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('numero_bl')->nullable();
            $table->string('movement_type');
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users');
            $table->date('date');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('stock_alerts');
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->enum('alert_type', ['low_stock', 'overstock', 'expired', 'expiring_soon']);
            $table->decimal('threshold_value', 10, 2);
            $table->decimal('current_value', 10, 2);
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('manual_stock_entries');
        Schema::create('manual_stock_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->string('entry_type');
            $table->decimal('quantity', 12, 2);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('employee_name')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
            $table->unsignedBigInteger('maintenance_log_id')->nullable();
            $table->foreignId('pointage_record_id')->nullable()->constrained();
            $table->foreignId('operation_id')->nullable()->constrained('operations');
            $table->foreignId('bloc_id')->nullable()->constrained();
            $table->foreignId('sector_id')->nullable()->constrained();
            $table->foreignId('parcelle_id')->nullable()->constrained();
            $table->date('date');
            $table->foreignId('entered_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->decimal('odometer_km', 10, 2)->nullable();
            $table->timestamps();
        });
    }
};
