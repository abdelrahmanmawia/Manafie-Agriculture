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
        Schema::table('harvests', function (Blueprint $table) {
            // Add estimated_kg column (nullable, for backward compatibility)
            $table->decimal('estimated_kg', 12, 2)->nullable()->after('quantity_kg');
            
            // Rename quantity_kg to actual_kg conceptually, but keep the column for now
            // We'll add actual_kg and migrate data later
            $table->decimal('actual_kg', 12, 2)->nullable()->after('estimated_kg');
            
            // Add weighing tracking fields
            $table->boolean('is_weighed')->default(false)->after('actual_kg');
            $table->timestamp('weighed_at')->nullable()->after('is_weighed');
            $table->string('weighing_batch_id')->nullable()->after('weighed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('harvests', function (Blueprint $table) {
            $table->dropColumn(['estimated_kg', 'actual_kg', 'is_weighed', 'weighed_at', 'weighing_batch_id']);
        });
    }
};
