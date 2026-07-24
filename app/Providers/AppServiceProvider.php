<?php

namespace App\Providers;

use App\Models\FuelTransaction;
use App\Models\ManualStockEntry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // StockMovement.reference_type stores these short keys (not FQCNs) to resolve
        // StockMovement::reference() — the source document behind a movement.
        Relation::morphMap([
            'manual_entry' => ManualStockEntry::class,
            'fuel_transaction' => FuelTransaction::class,
        ]);
    }
}
