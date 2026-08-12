<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Enterprise;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Quinzaine;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CsvDataSeeder::class,
            PrsRealDataSeeder::class,
            // StockSeeder generated random demo products/vehicles — replaced by real farm
            // data below. Left in the repo (unused) in case a non-Persealand dev environment
            // ever needs quick placeholder Stock data again.
            StockRealDataSeeder::class,
        ]);
    }
}
