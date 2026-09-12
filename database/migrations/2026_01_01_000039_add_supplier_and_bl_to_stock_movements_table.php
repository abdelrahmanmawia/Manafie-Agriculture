<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // Never actually null'd in practice — SupplierController::destroy() blocks deleting
            // a supplier that's still referenced by a movement, same as EquipmentType's guard.
            $table->foreignId('supplier_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
            $table->string('numero_bl')->nullable()->after('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('numero_bl');
        });
    }
};
