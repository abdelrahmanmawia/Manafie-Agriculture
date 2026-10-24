<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The farm points in two steps: first who came, per équipe (the chefs' sheets), then what each
// one did, per division. "Came" can't be told from the operation any more — a day can now be
// worked with its operation still to be entered ("à préciser"). pointage_records.present is the
// "worked" fact; operation/bloc are the detail. The only records not present are the unworked
// paid jours fériés (no operation, is_jf), which is all a record without an operation meant
// until now.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pointage_records', function (Blueprint $table) {
            $table->boolean('present')->default(true)->after('date');
        });

        DB::table('pointage_records')->whereNull('operation_id')->update(['present' => false]);
    }

    public function down(): void
    {
        Schema::table('pointage_records', function (Blueprint $table) {
            $table->dropColumn('present');
        });
    }
};
