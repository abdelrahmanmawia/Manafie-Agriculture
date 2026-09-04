<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('abbreviation')->nullable();
            // Piece-rate price (e.g. "10 DH per meter" for Fixation Brise Vent). Null means the
            // operation is paid at the employee's normal daily rate, not per-unit.
            $table->decimal('unit_rate', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
