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
        // Added and dropped in separate Schema::table() calls: on SQLite, combining
        // an addColumn with a dropColumn in one call triggers a doctrine/dbal table
        // rebuild that silently discards the added column.
        Schema::table('products', function (Blueprint $table) {
            $table->string('image')->nullable()->after('name');
        });

        Schema::disableForeignKeyConstraints();

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'reference_code',
                'barcode',
                'supplier',
                'storage_location',
                'specifications',
                'max_stock_level',
            ]);
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('products', function (Blueprint $table) {
            $table->string('reference_code')->unique()->after('name');
            $table->string('barcode')->nullable();
            $table->string('supplier')->nullable();
            $table->string('storage_location')->nullable();
            $table->json('specifications')->nullable();
            $table->decimal('max_stock_level', 10, 2)->nullable();
        });

        Schema::enableForeignKeyConstraints();

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
