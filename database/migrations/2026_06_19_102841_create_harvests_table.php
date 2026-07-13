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
        Schema::create('harvests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('bloc_id')->constrained()->onDelete('cascade'); // References blocs table
            $table->date('date');
            $table->string('variety'); // Hass, Fuerte, Lambhass, Zutano, etc.
            $table->decimal('quantity_kg', 12, 2); // Quantities in kilograms
            $table->integer('boxes_count')->nullable(); // Number of boxes/crates harvested
            $table->string('grade')->nullable(); // Catégorie 1, Catégorie 2, Écart de tri, etc.
            $table->decimal('unit_price_dh', 8, 2)->nullable(); // Price per kg
            $table->decimal('total_revenue_dh', 12, 2)->nullable(); // quantity_kg * unit_price_dh
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('harvests');
    }
};
