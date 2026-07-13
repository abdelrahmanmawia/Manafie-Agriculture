<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Bloc;
use App\Models\Farm;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportPlotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:plots {file? : The path to the Excel file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import farm parcelles/plots from the planning Excel file';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file') ?: 'D:/Projects/Pointage-App/Planning les blocs.xlsx';

        if (!file_exists($filePath)) {
            $this->error("Excel file not found at: {$filePath}");
            return Command::FAILURE;
        }

        $this->info("Loading Excel file: {$filePath}...");
        
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getSheetByName('Planning');
            if (!$sheet) {
                $this->error("Sheet named 'Planning' not found in the Excel file.");
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error loading Excel file: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Get default farm
        $farm = Farm::where('name', 'Persealand')->first() ?? Farm::first();
        if (!$farm) {
            $this->error("No Farm found in database. Please run seeders first.");
            return Command::FAILURE;
        }
        $this->info("Importing into Farm: {$farm->name} (ID: {$farm->id})");

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        
        $activeBloc = 'B1';
        $activeSector = 'S1';
        
        $headerMap = [];
        $importCount = 0;

        for ($row = 1; $row <= $highestRow; $row++) {
            $rowData = $sheet->rangeToArray('A' . $row . ':' . $highestColumn . $row, NULL, TRUE, FALSE)[0];
            
            // Trim values
            foreach ($rowData as $k => $v) {
                if (is_string($v)) {
                    $rowData[$k] = trim($v);
                }
            }

            // Filter out empty rows
            $hasData = false;
            foreach ($rowData as $val) {
                if ($val !== null && $val !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) continue;

            // Check if this is a header row
            if (isset($rowData[0]) && (strtolower($rowData[0]) === 'bloc' || strtolower(trim($rowData[0])) === 'bloc')) {
                $headerMap = [];
                foreach ($rowData as $index => $cellValue) {
                    if (empty($cellValue)) continue;
                    
                    // Normalize header text
                    $norm = strtolower(trim(str_replace(['/', '\\', "\u{00a0}"], ' ', $cellValue)));
                    
                    if (str_contains($norm, 'total')) $headerMap['total_trees'] = $index;
                    elseif (str_contains($norm, 'bloc')) $headerMap['bloc'] = $index;
                    elseif (str_contains($norm, 'secteur')) $headerMap['sector'] = $index;
                    elseif ($norm === 'parcelle') $headerMap['parcelle'] = $index;
                    elseif (str_contains($norm, 'hass')) $headerMap['hass'] = $index;
                    elseif (str_contains($norm, 'fuerte')) $headerMap['fuerte'] = $index;
                    elseif (str_contains($norm, 'lambhass')) $headerMap['lambhass'] = $index;
                    elseif (str_contains($norm, 'zutano')) $headerMap['zutano'] = $index;
                    elseif (str_contains($norm, 'superfaicier m') || str_contains($norm, 'superficie m')) $headerMap['area_m2'] = $index;
                    elseif (str_contains($norm, 'superfaicier ha') || str_contains($norm, 'superficie ha')) $headerMap['area_ha'] = $index;
                    elseif (str_contains($norm, 'ectarment') || str_contains($norm, 'ecartement')) $headerMap['spacing'] = $index;
                }
                $this->info("Header mapped at row {$row}. Columns found: " . implode(', ', array_keys($headerMap)));
                continue;
            }

            // Skip total rows
            $isTotal = false;
            foreach ($rowData as $cell) {
                if (is_string($cell) && (str_contains(strtolower($cell), 'total') || str_contains(strtolower($cell), 'géneral'))) {
                    $isTotal = true;
                    break;
                }
            }
            if ($isTotal) continue;

            // Ensure we have mapped parcelle column and it has data
            $parcelleIdx = $headerMap['parcelle'] ?? null;
            if ($parcelleIdx === null || !isset($rowData[$parcelleIdx]) || empty($rowData[$parcelleIdx])) {
                continue;
            }

            $parcelleVal = $rowData[$parcelleIdx];
            // If parcelle is just "Parcelle", it is a header row copy, skip
            if (strtolower($parcelleVal) === 'parcelle') continue;

            // Track active Block (Roman Numerals to B1/B2/B3)
            $blocIdx = $headerMap['bloc'] ?? null;
            if ($blocIdx !== null && isset($rowData[$blocIdx]) && !empty($rowData[$blocIdx])) {
                $rawBloc = $rowData[$blocIdx];
                if ($rawBloc === 'Ⅰ' || $rawBloc === 'I') {
                    $activeBloc = 'B1';
                } elseif ($rawBloc === 'Ⅱ' || $rawBloc === 'II') {
                    $activeBloc = 'B2';
                } elseif ($rawBloc === 'Ⅲ' || $rawBloc === 'III') {
                    $activeBloc = 'B3';
                } else {
                    // Fallback
                    $activeBloc = $rawBloc;
                }
            }

            // Track active Sector
            $sectorIdx = $headerMap['sector'] ?? null;
            if ($sectorIdx !== null && isset($rowData[$sectorIdx]) && !empty($rowData[$sectorIdx])) {
                $activeSector = $rowData[$sectorIdx];
            }

            // Extract numeric values based on dynamic header map
            $hass = isset($headerMap['hass']) ? (int)($rowData[$headerMap['hass']] ?? 0) : 0;
            $fuerte = isset($headerMap['fuerte']) ? (int)($rowData[$headerMap['fuerte']] ?? 0) : 0;
            $lambhass = isset($headerMap['lambhass']) ? (int)($rowData[$headerMap['lambhass']] ?? 0) : 0;
            $zutano = isset($headerMap['zutano']) ? (int)($rowData[$headerMap['zutano']] ?? 0) : 0;
            $areaM2 = isset($headerMap['area_m2']) ? (float)($rowData[$headerMap['area_m2']] ?? 0) : 0.0;
            $areaHa = isset($headerMap['area_ha']) ? (float)($rowData[$headerMap['area_ha']] ?? 0) : 0.0;
            $spacing = isset($headerMap['spacing']) ? (string)($rowData[$headerMap['spacing']] ?? '6*3') : '6*3';
            $totalTrees = isset($headerMap['total_trees']) ? (int)($rowData[$headerMap['total_trees']] ?? 0) : 0;

            // Generate concatenated unique name
            $fullName = "{$activeBloc} - {$activeSector} - {$parcelleVal}";

            // Update or Create the Plot
            Bloc::updateOrCreate(
                [
                    'farm_id' => $farm->id,
                    'name' => $fullName
                ],
                [
                    'parent_bloc' => $activeBloc,
                    'sector' => $activeSector,
                    'parcelle' => $parcelleVal,
                    'hass_trees' => $hass,
                    'fuerte_trees' => $fuerte,
                    'lambhass_trees' => $lambhass,
                    'zutano_trees' => $zutano,
                    'area_m2' => $areaM2,
                    'area_ha' => $areaHa,
                    'spacing' => $spacing,
                    'total_trees' => $totalTrees
                ]
            );

            $importCount++;
        }

        $this->info("Imported/Updated {$importCount} detailed parcelles.");

        // Now aggregate totals back to parent blocks B1, B2, B3
        $parentBlocks = ['B1', 'B2', 'B3'];
        foreach ($parentBlocks as $blockName) {
            $childPlots = Bloc::where('farm_id', $farm->id)
                ->where('parent_bloc', $blockName)
                ->whereNotNull('parcelle') // ensures we exclude the parent block row itself
                ->get();

            if ($childPlots->isEmpty()) continue;

            $totalHass = $childPlots->sum('hass_trees');
            $totalFuerte = $childPlots->sum('fuerte_trees');
            $totalLambhass = $childPlots->sum('lambhass_trees');
            $totalZutano = $childPlots->sum('zutano_trees');
            $totalM2 = $childPlots->sum('area_m2');
            $totalHa = $childPlots->sum('area_ha');
            $sumTrees = $childPlots->sum('total_trees');

            // Find or create parent block
            Bloc::updateOrCreate(
                [
                    'farm_id' => $farm->id,
                    'name' => $blockName
                ],
                [
                    'parent_bloc' => $blockName,
                    'sector' => null,
                    'parcelle' => null,
                    'hass_trees' => $totalHass,
                    'fuerte_trees' => $totalFuerte,
                    'lambhass_trees' => $totalLambhass,
                    'zutano_trees' => $totalZutano,
                    'area_m2' => $totalM2,
                    'area_ha' => $totalHa,
                    'spacing' => 'N/A',
                    'total_trees' => $sumTrees
                ]
            );
            $this->info("Aggregated and updated totals for main block: {$blockName}");
        }

        $this->info("Finished import successfully!");
        return Command::SUCCESS;
    }
}
