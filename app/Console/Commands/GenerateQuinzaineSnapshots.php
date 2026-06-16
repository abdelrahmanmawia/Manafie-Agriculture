<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Quinzaine;
use App\Services\PayrollService;

class GenerateQuinzaineSnapshots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'quinzaine:snapshot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate snapshots for all closed quinzaines that do not have one yet.';

    /**
     * Execute the console command.
     */
    public function handle(PayrollService $payrollService)
    {
        $quinzaines = Quinzaine::where('is_closed', true)
            ->whereDoesntHave('summary')
            ->get();

        if ($quinzaines->isEmpty()) {
            $this->info('No closed quinzaines without snapshots found.');
            return;
        }

        $this->info('Generating snapshots for ' . $quinzaines->count() . ' quinzaines...');

        foreach ($quinzaines as $quinzaine) {
            $this->info('Processing: ' . ($quinzaine->label ?: $quinzaine->id));
            $payrollService->generateSnapshot($quinzaine);
        }

        $this->info('All snapshots generated successfully!');
    }
}
