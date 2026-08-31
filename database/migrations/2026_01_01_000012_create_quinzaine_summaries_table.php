<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quinzaine_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quinzaine_id')->unique()->constrained()->onDelete('cascade');
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2)->default(0);
            $table->decimal('total_brut', 15, 2)->default(0);
            $table->decimal('total_hours', 15, 2)->default(0);
            $table->integer('employee_count')->default(0);
            $table->json('summary_data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quinzaine_summaries');
    }
};
