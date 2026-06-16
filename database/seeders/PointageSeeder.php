<?php

namespace Database\Seeders;

use App\Models\Enterprise;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Quinzaine;
use App\Models\PointageRecord;
use App\Models\QuinzaineSummary;
use App\Services\PayrollService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PointageSeeder extends Seeder
{
    public function run(): void
    {
        // Clean start for testing (SQLite compatible)
        DB::statement('PRAGMA foreign_keys = OFF;');
        PointageRecord::truncate();
        Quinzaine::truncate();
        QuinzaineSummary::truncate();
        DB::statement('PRAGMA foreign_keys = ON;');

        $payrollService = new PayrollService();
        $enterprises = Enterprise::all();

        $startDate = Carbon::parse('2024-01-01');

        // We will create 5 CLOSED quinzaines and 1 OPEN quinzaine
        for ($qNum = 1; $qNum <= 6; $qNum++) {
            $isClosed = $qNum < 6;
            $endDate = (clone $startDate)->addDays(14);

            $this->command->info("Seeding Quinzaine $qNum: {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}...");

            foreach ($enterprises as $enterprise) {
                $quinzaine = Quinzaine::create([
                    'enterprise_id' => $enterprise->id,
                    'label' => "Période " . $startDate->format('M Y') . " - Q" . ($startDate->day < 15 ? '1' : '2'),
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'is_closed' => $isClosed,
                ]);

                $employees = Employee::where('enterprise_id', $enterprise->id)->get();
                $operations = Operation::where('farm_id', $enterprise->farm_id)->get();
                $blocs = Bloc::where('farm_id', $enterprise->farm_id)
                    ->get();

                if ($employees->isEmpty()) continue;

                $recordsBatch = [];

                foreach ($employees as $employee) {
                    // For each day in the quinzaine (15 days)
                    for ($d = 0; $d < 15; $d++) {
                        $currentDay = (clone $startDate)->addDays($d);

                        // Skip Sundays (optional, but realistic)
                        if ($currentDay->isSunday()) continue;

                        $operation = $operations->random();
                        $bloc = $blocs->where('name', '!=', '')->random() ?? $blocs->first();

                        $hours = (rand(1, 10) > 8) ? rand(1, 3) : 0; // 20% chance of HS
                        $isJf = (rand(1, 100) > 98); // 2% chance of JF

                        $calc = $payrollService->calculate(
                            $enterprise->contract_type,
                            $enterprise->default_brut_rate,
                            $hours,
                            $employee->complement,
                            $isJf
                        );

                        // We use mass insert for speed but need to be careful with IDs
                        $recordsBatch[] = [
                            'employee_id' => $employee->id,
                            'quinzaine_id' => $quinzaine->id,
                            'operation_id' => $operation->id,
                            'bloc_id' => $bloc ? $bloc->id : null,
                            'date' => $currentDay->format('Y-m-d'),
                            'hours' => $hours,
                            'is_jf' => $isJf,
                            'rate' => $enterprise->default_brut_rate,
                            'brut' => $calc['brut'],
                            'net' => $calc['total_net'],
                            'cnss' => 0,
                            'amo' => 0,
                            'ir' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        // Insert in chunks of 500
                        if (count($recordsBatch) >= 500) {
                            PointageRecord::insert($recordsBatch);
                            $recordsBatch = [];
                        }
                    }
                }

                if (!empty($recordsBatch)) {
                    PointageRecord::insert($recordsBatch);
                }

                // If closed, generate the snapshot
                if ($isClosed) {
                    $payrollService->generateSnapshot($quinzaine);
                }
            }

            // Move to next quinzaine (16 days after start)
            $startDate->addDays(16);
        }

        $this->command->info("Seeding complete! " . PointageRecord::count() . " records created.");
    }
}
