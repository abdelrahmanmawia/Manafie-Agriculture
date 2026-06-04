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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enterprise_id')->constrained()->onDelete('cascade');
            $table->string('matricule')->unique();
            $table->string('full_name');
            $table->string('cin')->nullable();
            $table->string('cnss_number')->nullable();
            $table->date('dob')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('rib')->nullable();
            $table->enum('type', ['hafila', 'persea', 'interim']);
            $table->decimal('base_rate', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
