<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harvests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->onDelete('cascade');
            $table->foreignId('bloc_id')->constrained()->onDelete('cascade');
            // No FK constraint on sector_id/parcelle_id in the live database (added later via
            // ALTER, which never registered a real constraint here) — kept as plain columns.
            $table->unsignedBigInteger('sector_id')->nullable();
            $table->unsignedBigInteger('parcelle_id')->nullable();
            $table->date('date');
            $table->string('variety');
            $table->decimal('quantity_kg', 12, 2);
            $table->decimal('estimated_kg', 12, 2)->nullable();
            $table->decimal('actual_kg', 12, 2)->nullable();
            $table->boolean('is_weighed')->default(false);
            $table->timestamp('weighed_at')->nullable();
            $table->string('weighing_batch_id')->nullable();
            $table->integer('boxes_count')->nullable();
            $table->string('grade')->nullable();
            $table->decimal('unit_price_dh', 8, 2)->nullable();
            $table->decimal('total_revenue_dh', 12, 2)->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harvests');
    }
};
