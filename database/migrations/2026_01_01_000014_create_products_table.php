<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('image')->nullable();
            $table->string('unit_type');
            $table->decimal('min_stock_level', 10, 2)->default(0);
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            // restrictOnDelete: a category in use can't be deleted (ProductController checks this
            // up front for a friendly error, but the DB constraint is the actual safety net).
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
