<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Quinzaine;
use App\Services\TransportService;

class GenerateTransportSnapshots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transport:snapshot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate transport cost snapshots for all closed quinzaines that do not have one yet.';

    /**
     * Execute the console command.
     */
    public function handle(TransportService $transportService)
    {
        $quinzaines = Quinzaine::where('is_closed', true)
            ->whereDoesntHave('transportSnapshots')
            ->get();

        if ($quinzaines->isEmpty()) {
            $this->info('No closed quinzaines without transport snapshots found.');
            return;
        }

        $this->info('Generating transport snapshots for ' . $quinzaines->count() . ' quinzaines...');

        foreach ($quinzaines as $quinzaine) {
            $this->info('Processing: ' . ($quinzaine->label ?: $quinzaine->id));
            $transportService->generateSnapshot($quinzaine);
        }

        $this->info('All transport snapshots generated successfully!');
    }
}
