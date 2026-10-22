<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Migration 000040 dropped the (product_id, batch_number) unique index along with batch_number,
// leaving nothing to stop two concurrent "first movement for this product" requests from each
// inserting their own stock_inventory row. Every write path treats this table as exactly one row
// per product (see Controller::lockedInventoryFor(), whose createOrFirst() relies on this index).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_inventory', function (Blueprint $table) {
            $table->unique('product_id');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('stock_inventory', function (Blueprint $table) {
                $table->dropUnique(['product_id']);
            });

            return;
        }

        // MySQL adopts the unique index as the product_id FK's supporting index and then refuses
        // to drop it (error 1553) — same dance as migration 000040: drop the FK, drop the index,
        // re-add the FK (restrict, per 000041) so it gets its own plain index back.
        Schema::table('stock_inventory', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });
        Schema::table('stock_inventory', function (Blueprint $table) {
            $table->dropUnique(['product_id']);
        });
        Schema::table('stock_inventory', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
        });
    }
};
