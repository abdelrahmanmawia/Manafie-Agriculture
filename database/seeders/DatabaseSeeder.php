<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CurrentDataSeeder::class,

            // CsvDataSeeder (hardcoded structural data — had drifted from reality: wrong
            // enterprise names, 34 vs the real 43 operations, missing a manually-created
            // user) and PrsRealDataSeeder (Excel-based import — would recreate the June/July
            // quinzaines and 99 employees a 2026-08-31 cleanup deliberately removed) are both
            // superseded by CurrentDataSeeder's snapshot of the real, current data. Left in
            // the repo unused in case a non-Persealand dev environment ever needs a from-
            // scratch structural seed again — see StockSeeder for the same convention.

        ]);
    }
}
