<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enterprise_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('matricule');
            $table->string('full_name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('cin')->nullable();
            $table->string('cnss_number')->nullable();
            $table->date('dob')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('rib')->nullable();
            $table->decimal('base_rate', 10, 2)->default(0);
            $table->decimal('complement', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('badge_uuid')->nullable()->unique();
            $table->string('photo_path')->nullable();
            $table->timestamps();

            $table->unique(['farm_id', 'cin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
