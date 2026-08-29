<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // French labels the category select used to hardcode client-side — carried over here so
    // existing products keep a real display name instead of surfacing their raw English key.
    private const LEGACY_LABELS = [
        'seeds' => 'Semences',
        'fertilizers' => 'Engrais',
        'pesticides' => 'Pesticides',
        'tools' => 'Outils',
        'packaging' => 'Emballage',
        'equipment' => 'Équipement',
        'fuel' => 'Carburant',
        'vehicle_needs' => 'Besoins Véhicule',
        'other' => 'Autre',
    ];

    private const VEHICLE_RELATED_KEYS = ['fuel', 'vehicle_needs'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('category')
                ->constrained('product_categories')->restrictOnDelete();
        });

        // One product_categories row per (farm, distinct legacy key actually in use) — not one
        // per farm per every possible key, so a farm that never used e.g. 'packaging' doesn't
        // get a category nobody asked for.
        $products = DB::table('products')->select('id', 'farm_id', 'category')->get();
        $categoryIdsByFarmAndKey = [];

        foreach ($products as $product) {
            $key = $product->category ?: 'other';
            $cacheKey = $product->farm_id . '|' . $key;

            if (! isset($categoryIdsByFarmAndKey[$cacheKey])) {
                $categoryIdsByFarmAndKey[$cacheKey] = DB::table('product_categories')->insertGetId([
                    'farm_id' => $product->farm_id,
                    'name' => self::LEGACY_LABELS[$key] ?? ucfirst($key),
                    'is_vehicle_related' => in_array($key, self::VEHICLE_RELATED_KEYS, true),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('products')->where('id', $product->id)
                ->update(['category_id' => $categoryIdsByFarmAndKey[$cacheKey]]);
        }

        Schema::disableForeignKeyConstraints();
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('category');
        });
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::table('products', function (Blueprint $table) {
            $table->string('category')->nullable()->after('image');
        });
        Schema::enableForeignKeyConstraints();

        $products = DB::table('products')
            ->join('product_categories', 'products.category_id', '=', 'product_categories.id')
            ->select('products.id', 'product_categories.name')
            ->get();
        $legacyKeysByLabel = array_flip(self::LEGACY_LABELS);
        foreach ($products as $product) {
            DB::table('products')->where('id', $product->id)
                ->update(['category' => $legacyKeysByLabel[$product->name] ?? 'other']);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
