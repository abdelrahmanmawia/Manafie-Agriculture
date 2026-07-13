<?php

namespace Database\Seeders;

use App\Models\Operation;
use Illuminate\Database\Seeder;

class UpdateOperationAbbreviations extends Seeder
{
    public function run(): void
    {
        Operation::whereNull('abbreviation')->get()->each(function ($op) {
            if (preg_match('/\(([^)]+)\)/', $op->name, $matches)) {
                $op->abbreviation = $matches[1];
                $op->save();
                echo "Updated operation {$op->id}: {$op->name} -> {$op->abbreviation}\n";
            }
        });
    }
}
