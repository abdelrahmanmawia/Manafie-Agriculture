<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('farm_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->foreignId('enterprise_id')->nullable()->change();
        });

        // Link existing users to the first farm if applicable
        $firstFarm = DB::table('farms')->first();
        if ($firstFarm) {
            DB::table('users')->whereNotNull('enterprise_id')->update(['farm_id' => $firstFarm->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['farm_id']);
            }
            $table->dropColumn('farm_id');
        });
    }
};
