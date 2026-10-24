<?php

namespace App\Console\Commands;

use App\Exports\PointageExport;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Services\FicheImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off rebuild of a farm's pointage from the office's own Excel workbooks for
 * September–October 2026 (the "Pointage" folder: one sub-folder per quinzaine, see MANIFEST).
 * Deletes the farm's quinzaines, pointage, summaries, transport costs and équipes — workers,
 * divisions, operations, blocs, stock and users are kept — then re-imports through
 * FicheImportService. Everything runs in one transaction; --dry-run rolls it back after the report.
 * The farm has been pointing in the app since: without --dry-run it asks before wiping (or needs
 * --force), so it can't erase that by being run again by mistake.
 */
class ImportFichesPointage extends Command
{
    protected $signature = 'pointage:import-fiches {dir : the extracted "Pointage" folder} {--farm= : farm id (default: the only farm)} {--dry-run} {--force : wipe without asking}';

    protected $description = 'Re-import the farm\'s pointage from its Excel fiches (Sept–Oct 2026)';

    /** Fiches per quinzaine: [path relative to dir, division: facture | non_declaree | declaree]. */
    private const MANIFEST = [
        ['label' => '1ERE Qz septembre 2026', 'start' => '2026-09-01', 'end' => '2026-09-15', 'fiches' => [
            ['1ere qnz sep/P-15092026 PERSEALAND AGRI INTERIM.xlsx', PointageExport::FICHE_FACTURE],
            ['1ere qnz sep/P-15092026 PERSEALAND NON DECLARE.xlsx', PointageExport::FICHE_NON_DECLAREE],
        ]],
        ['label' => '2EME Qz septembre 2026', 'start' => '2026-09-16', 'end' => '2026-09-30', 'fiches' => [
            ['2eme qnz sep/P-30092026 PERSEALAND AGRI INTERIM.xlsx', PointageExport::FICHE_FACTURE],
            ['2eme qnz sep/P-30092026 PERSEALAND NON DECLARE.xlsx', PointageExport::FICHE_NON_DECLAREE],
            ['2eme qnz sep/P-30092026 PERSEALAND.xlsx', PointageExport::FICHE_DECLAREE],
        ]],
        ['label' => '1ERE Qz octobre 2026', 'start' => '2026-10-01', 'end' => '2026-10-15', 'fiches' => [
            ['1ere qnz octobre/P-15102026 PERSEALAND AGRI INTERIM.xlsx', PointageExport::FICHE_FACTURE],
            ['1ere qnz octobre/P-15102026 PERSEALAND NON DECLARE.xlsx', PointageExport::FICHE_NON_DECLAREE],
            ['1ere qnz octobre/P-15102026 PERSEALAND.xlsx', PointageExport::FICHE_DECLAREE],
        ]],
        ['label' => '2EME Qz octobre 2026', 'start' => '2026-10-16', 'end' => '2026-10-31', 'fiches' => [],
            'presence' => '2eme qnz octobre/POINTAGE_TRANSPORT_2eme_Qz_OCT2026 - Copie.xlsx'],
    ];

    private const TEAMS = '1ere qnz octobre/POINTAGE_TRANSPORT_1ere_Qz_OCT2026.xlsx';

    private const NEW_OPERATIONS = ['E.G' => 'Elimination Des Gourments', 'CHA' => 'Chargement', 'POINTEUR' => 'Pointeur'];

    public function handle(FicheImportService $importer): int
    {
        $dir = rtrim($this->argument('dir'), '/\\');
        $farm = $this->option('farm') ? Farm::findOrFail($this->option('farm')) : Farm::sole();

        if (! $this->option('dry-run') && ! $this->option('force')) {
            $quinzaines = Quinzaine::whereHas('enterprise', fn ($q) => $q->where('farm_id', $farm->id));
            $records = PointageRecord::whereIn('quinzaine_id', (clone $quinzaines)->select('id'))->count();
            $this->warn("Cet import SUPPRIME toutes les quinzaines de « {$farm->name} » ({$quinzaines->count()}), leurs {$records} jour(s) pointés et les équipes, puis remet septembre–octobre 2026 tels que dans les fichiers.");
            if (! $this->confirm('Tout supprimer et réimporter ?', false)) {
                $this->info("Annulé : rien n'a été modifié. (--dry-run pour voir le résultat sans rien enregistrer.)");

                return self::FAILURE;
            }
        }

        $divisions = [];
        foreach (Enterprise::where('farm_id', $farm->id)->get() as $enterprise) {
            $divisions[PointageExport::ficheFor($enterprise)] = $enterprise->id;
        }

        $periods = [];
        foreach (self::MANIFEST as $period) {
            $fiches = [];
            foreach ($period['fiches'] as [$path, $fiche]) {
                $fiches[] = [$this->file($dir, $path), $divisions[$fiche] ?? throw new \RuntimeException("Aucune division « {$fiche} »")];
            }
            $periods[] = ['fiches' => $fiches, 'presence' => isset($period['presence']) ? $this->file($dir, $period['presence']) : null] + $period;
        }

        DB::beginTransaction();
        try {
            $report = $importer->run($farm->id, $periods, $this->file($dir, self::TEAMS), self::NEW_OPERATIONS);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report);

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->warn('Dry run : rien n\'a été enregistré.');
        } else {
            DB::commit();
            $this->info('Import enregistré.');
        }

        return self::SUCCESS;
    }

    private function file(string $dir, string $path): string
    {
        $full = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (! is_file($full)) {
            throw new \RuntimeException("Fichier introuvable : {$path}");
        }

        return $full;
    }

    private function printReport(array $report): void
    {
        $this->line('Supprimé : '.json_encode($report['deleted'], JSON_UNESCAPED_UNICODE));
        $this->table(['Fichier', 'Quinzaine', 'Division', 'Ouvriers', 'Jours', 'Net fichier', 'Net importé', 'Écart'], array_map(fn ($f) => [
            $f['file'], $f['quinzaine'], $f['division'], $f['workers'], $f['days'],
            $f['net_file'] === null ? '—' : number_format($f['net_file'], 2, '.', ' '),
            number_format($f['net_imported'], 2, '.', ' '),
            $f['net_file'] === null ? '—' : number_format($f['net_imported'] - $f['net_file'], 2, '.', ' '),
        ], $report['files']));
        $this->table(['Équipe', 'Véhicule', 'Chef', 'Ouvriers'], array_map(
            fn ($name, $e) => [$name, $e['vehicle'] ?? '—', $e['chef'] ?? '—', $e['members']],
            array_keys($report['equipes']), $report['equipes'],
        ));
        $this->line(count($report['created_employees']).' ouvrier(s) créé(s).');
        foreach ($report['created_employees'] as $line) {
            $this->line('  + '.$line);
        }
        foreach ($report['differences'] as $line) {
            $this->warn('  ≠ '.$line);
        }
        foreach ($report['unmatched'] as $line) {
            $this->warn('  ? '.$line);
        }
        foreach ($report['warnings'] as $line) {
            $this->warn('  ! '.$line);
        }
    }
}
