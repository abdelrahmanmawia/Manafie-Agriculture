<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Drives whether the sortie form shows vehicle-specific fields (odomètre, véhicule)
            // for a product in this category.
            $table->boolean('is_vehicle_related')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['farm_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
