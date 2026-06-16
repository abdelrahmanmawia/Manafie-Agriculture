<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('operations', 'farm_id')) {
            Schema::table('operations', function (Blueprint $table) {
                $table->foreignId('farm_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            });
        }

        // Link operations to farm via their enterprise
        // SQLite doesn't support UPDATE with JOIN directly in the standard way Laravel's Query Builder might generate it for some versions/drivers
        $operations = DB::table('operations')->get();
        foreach ($operations as $op) {
            $enterprise = DB::table('enterprises')->where('id', $op->enterprise_id)->first();
            if ($enterprise) {
                DB::table('operations')->where('id', $op->id)->update(['farm_id' => $enterprise->farm_id]);
            }
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->foreignId('farm_id')->nullable(false)->change();

            if (Schema::hasColumn('operations', 'enterprise_id')) {
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
        Schema::table('operations', function (Blueprint $table) {
            $table->foreignId('enterprise_id')->nullable()->constrained()->onDelete('cascade');
            
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['farm_id']);
            }
            $table->dropColumn('farm_id');
        });
    }
};
