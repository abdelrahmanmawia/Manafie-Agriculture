<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            // avec_contrat used to always imply both the CNSS-style worker deduction AND a
            // client invoice (net_factur_j/total_ttc). Real production data shows some
            // avec_contrat divisions (the farm's own direct workforce) need the deduction
            // but never invoice a client — only actual interim/placement agencies do. This
            // flag decouples "is a client invoiced?" from contract_type entirely.
            $table->boolean('invoiced_to_client')->default(false)->after('contract_type');
        });

        // Known from the real production spreadsheet audit: AGRI INTERIM is the interim
        // placement agency that actually invoices the farm for placed workers.
        DB::table('enterprises')->where('name', 'AGRI INTERIM')->update(['invoiced_to_client' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropColumn('invoiced_to_client');
        });
    }
};
