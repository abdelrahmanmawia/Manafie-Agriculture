<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The division's Excel sheet (PointageExport) follows one of the farm's three fiche models: a
// division that invoices a client gets the "AGRI INTERIM" fiche (NET FACTUR J / TTC), and the
// others get either the declared-workforce fiche (RIB, COMP, SAL BRUT) or the "MO NON DECLARE"
// one (no RIB/COMP/BRUT, totals with a 4% margin). Pay is computed the same way for both — only
// the fiche differs — so it's a flag on the division rather than something derived. Existing
// divisions named "... NON DECLARE" are the non-declared ones.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->boolean('mo_declaree')->default(true)->after('invoiced_to_client');
        });

        DB::table('enterprises')->where('name', 'like', '%NON DECLAR%')->update(['mo_declaree' => false]);
    }

    public function down(): void
    {
        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropColumn('mo_declaree');
        });
    }
};
