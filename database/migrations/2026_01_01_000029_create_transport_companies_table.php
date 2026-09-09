<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Bank account the company gets paid on — separate from any employee's own RIB.
            $table->string('rib')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['farm_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_companies');
    }
};
