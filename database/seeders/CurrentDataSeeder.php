<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Restores an exact snapshot of the farm's real data, taken 2026-08-31 after a manual
 * cleanup (removed June/July quinzaines and every employee with no August pointage record,
 * wiped all Stock/Magasin data). Replaces CsvDataSeeder's old hardcoded structural data
 * (which had drifted from reality — wrong enterprise names, 34 vs the real 43 operations,
 * missing a manually-created user) and PrsRealDataSeeder's Excel-based import (which would
 * recreate the June/July data this snapshot deliberately excludes).
 *
 * Fixture files live in database/seeders/data/*.json — each one is a raw dump of a table's
 * rows (via DB::table($table)->get()), inserted back with DB::table()->insert() so IDs are
 * preserved exactly (pointage_records.employee_id/quinzaine_id and similar FKs depend on
 * that) and no model events/casts run in between.
 */
class CurrentDataSeeder extends Seeder
{
    /**
     * Parent-to-child order, both for clearing (reverse) and inserting (forward).
     */
    private const TABLES = [
        'farms',
        'enterprises',
        'users',
        'operations',
        'blocs',
        'sectors',
        'parcelles',
        'employees',
        'quinzaines',
        'pointage_records',
        'quinzaine_summaries',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (array_reverse(self::TABLES) as $table) {
                DB::table($table)->delete();
            }

            foreach (self::TABLES as $table) {
                $rows = json_decode(file_get_contents(__DIR__ . "/data/{$table}.json"), true);

                // One multi-row INSERT per chunk, not per table — pointage_records alone is
                // 650+ rows and SQLite caps the number of bound parameters per statement.
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });
    }
}
