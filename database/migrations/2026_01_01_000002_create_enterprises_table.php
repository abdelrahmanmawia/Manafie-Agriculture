<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprises', function (Blueprint $table) {
            $table->id();
            // No FK constraint on farm_id in the live database (added later via ALTER, which
            // never registered a real constraint here) — kept as a plain column to match.
            $table->unsignedBigInteger('farm_id');
            $table->string('name');
            $table->string('logo')->nullable();
            $table->json('settings')->nullable();
            $table->string('contract_type')->default('avec_contrat');
            $table->decimal('default_brut_rate', 10, 2)->default(0);
            $table->boolean('invoiced_to_client')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprises');
    }
};
