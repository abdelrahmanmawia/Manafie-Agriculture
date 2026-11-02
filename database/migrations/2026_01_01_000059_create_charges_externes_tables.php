<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External companies under contract (the security company...) and what each period costs: only
 * the totals they send, never their people. A charge keeps the contract's terms it was entered
 * under, so a later price change doesn't rewrite the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestataires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('service', 100);
            $table->string('mode_facturation', 20)->default('par_jour'); // par_jour | forfait
            $table->decimal('prix_nuit', 10, 2)->nullable();
            $table->decimal('prix_jour', 10, 2)->nullable();
            $table->decimal('forfait', 12, 2)->nullable(); // per period
            $table->unsignedSmallInteger('effectif_nuit')->nullable();
            $table->unsignedSmallInteger('effectif_jour')->nullable();
            $table->date('contrat_debut')->nullable();
            $table->date('contrat_fin')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['farm_id', 'name']);
        });

        Schema::create('charges_externes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('farm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prestataire_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('jours_nuit')->default(0);
            $table->unsignedInteger('jours_jour')->default(0);
            // The contract's terms when this was entered.
            $table->decimal('prix_nuit', 10, 2)->nullable();
            $table->decimal('prix_jour', 10, 2)->nullable();
            $table->decimal('forfait', 12, 2)->nullable();
            $table->unsignedSmallInteger('effectif_nuit')->nullable();
            $table->unsignedSmallInteger('effectif_jour')->nullable();
            $table->decimal('montant', 12, 2);
            $table->string('statut_facture', 20)->default('non_recue'); // non_recue | recue | payee
            $table->string('facture_numero', 100)->nullable();
            $table->date('facture_date')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['prestataire_id', 'start_date', 'end_date']);
            $table->index(['farm_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges_externes');
        Schema::dropIfExists('prestataires');
    }
};
