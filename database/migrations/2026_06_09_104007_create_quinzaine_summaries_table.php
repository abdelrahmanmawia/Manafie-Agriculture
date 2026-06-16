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
        Schema::create('quinzaine_summaries', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('quinzaine_id')->constrained()->onDelete('cascade');
            $blueprint->decimal('total_net', 15, 2)->default(0);
            $blueprint->decimal('total_ttc', 15, 2)->default(0);
            $blueprint->decimal('total_brut', 15, 2)->default(0);
            $blueprint->decimal('total_hours', 15, 2)->default(0);
            $blueprint->integer('employee_count')->default(0);
            $blueprint->json('summary_data')->nullable(); // For breakdowns (op, bloc, employee)
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quinzaine_summaries');
    }
};
