<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create a default farm if none exists
        $farm = DB::table('farms')->where('name', 'Persealand')->first();
        if (!$farm) {
            $farmId = DB::table('farms')->insertGetId([
                'name' => 'Persealand',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $farmId = $farm->id;
        }

        if (!Schema::hasColumn('enterprises', 'farm_id')) {
            Schema::table('enterprises', function (Blueprint $table) {
                $table->foreignId('farm_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            });
        }

        // Link existing enterprises to the default farm
        DB::table('enterprises')->whereNull('farm_id')->update(['farm_id' => $farmId]);

        // Make farm_id non-nullable
        Schema::table('enterprises', function (Blueprint $table) {
            $table->foreignId('farm_id')->nullable(false)->change();
        });

        if (!Schema::hasColumn('blocs', 'farm_id')) {
            Schema::table('blocs', function (Blueprint $table) {
                $table->foreignId('farm_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            });
        }

        // Link existing blocs to the default farm
        DB::table('blocs')->whereNull('farm_id')->update(['farm_id' => $farmId]);

        Schema::table('blocs', function (Blueprint $table) {
            $table->foreignId('farm_id')->nullable(false)->change();
            
            // Drop enterprise_id after linking to farm if it still exists
            if (Schema::hasColumn('blocs', 'enterprise_id')) {
                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign(['enterprise_id']);
                }
                $table->dropColumn('enterprise_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blocs', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->constrained()->onDelete('cascade');
            $table->dropForeign(['farm_id']);
            $table->dropColumn('farm_id');
        });

        Schema::table('enterprises', function (Blueprint $table) {
            $table->dropForeign(['farm_id']);
            $table->dropColumn('farm_id');
        });
    }
};
