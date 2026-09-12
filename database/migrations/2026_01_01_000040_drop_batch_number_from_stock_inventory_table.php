<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// batch_number (lot/batch tracking) was only ever a snapshot on stock_inventory — overwritten
// on every new Entrée, never kept per-transaction — and was displayed but never actually
// populated on any real record. Now that stock_movements.numero_bl captures a real, per-delivery
// reference number, this redundant/unused field is being dropped rather than kept alongside it.
//
// The (product_id, batch_number) unique index is MySQL's supporting index for the product_id
// foreign key (same root cause as 2026_01_01_000036's transport_vehicle_attendances fix) — MySQL
// refuses to drop it without the FK being dropped first. SQLite can't ALTER away a FK/unique
// index at all, so on that connection (test suite only, where this migration always runs against
// an empty fresh DB) this drops and recreates the table instead, matching the same precedent.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::dropIfExists('stock_inventory');
            Schema::create('stock_inventory', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->onDelete('cascade');
                $table->decimal('quantity_on_hand', 12, 2)->default(0);
                $table->decimal('quantity_reserved', 12, 2)->default(0);
                $table->decimal('quantity_available', 12, 2)->storedAs('quantity_on_hand - quantity_reserved');
                $table->date('last_restock_date')->nullable();
                $table->date('last_count_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->decimal('average_cost', 10, 2)->nullable();
                $table->timestamps();
            });

            return;
        }

        Schema::table('stock_inventory', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });
        Schema::table('stock_inventory', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'batch_number']);
            $table->dropColumn('batch_number');
        });
        Schema::table('stock_inventory', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('stock_inventory', function (Blueprint $table) {
            $table->string('batch_number')->nullable();
            $table->unique(['product_id', 'batch_number']);
        });
    }
};
