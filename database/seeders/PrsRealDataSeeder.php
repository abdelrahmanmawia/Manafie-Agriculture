<?php

namespace Database\Seeders;

use App\Models\Bloc;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\QuinzaineSummary;
use App\Services\PrsImport\OperationEstimator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the real PRS payroll archive (see database/seeders/prs_manifest.php) to replace the
 * demo Employee/Quinzaine/PointageRecord data seeded by PointageSeeder. Farm/Bloc/Sector/
 * Parcelle/Operation reference data from CsvDataSeeder is real and kept as-is.
 *
 * Deliberately does NOT call PayrollService::generateSnapshot() — that recomputes every total
 * live from the enterprise's CURRENT default_brut_rate and each employee's CURRENT complement,
 * which would silently overwrite the historically-correct imported figures for any period where
 * the real rate differed from today's (confirmed: Jan-May 2026 used a different rate/formula
 * than June-July). QuinzaineSummary is built directly from each file's own real totals instead.
 */
class PrsRealDataSeeder extends Seeder
{
    // Flat-rate technician/admin rows mixed into otherwise-clean daily-rate worker rosters — not
    // "ouvrières" (field workers), confirmed with the user: OUBAKADIR YAHYA ("TECHNICIEN ASSILAH",
    // AGRI INTERIM 2QZ Juin, flat 3500/month) and MOAWIA HASSAN ABDERRAHAMANABDELRAHMAN (same
    // pattern, PERSEALAND NON DECLARE 2QZ Juin).
    private const EXCLUDED_CINS = ['UA107688', 'P06073502'];

    private array $blocCache = [];
    private array $operationCache = [];
    private ?int $fallbackOperationId = null;
    private ?int $fallbackBlocId = null;
    // Some quinzaines are split across several manifest entries (Baraka Green: one file per
    // operation for the same period) — total_ttc isn't stored per PointageRecord, so it has to
    // be accumulated across every entry touching that quinzaine, keyed by quinzaine_id.
    private array $quinzaineTtcTotals = [];
    // Employee is farm-scoped (CIN is the real identity), but manifest entries are processed
    // enterprise-block by enterprise-block, not in real chronological order — so "last entry
    // processed wins" would pick whichever enterprise's manifest block happens to come last,
    // not whichever period is actually most recent. Track each identity's latest quinzaine
    // end-date seen so far and only let a later-dated entry overwrite the "current division"
    // fields (enterprise_id, matricule, full_name, rate, ...).
    private array $employeeLatestDate = [];

    public function run(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF;');
        PointageRecord::truncate();
        QuinzaineSummary::truncate();
        Quinzaine::truncate();
        Employee::truncate();
        DB::statement('PRAGMA foreign_keys = ON;');

        [$enterpriseIds, $enterpriseFarmIds] = $this->ensureEnterprises();

        $manifest = require __DIR__ . '/prs_manifest.php';
        foreach ($manifest as $entry) {
            $this->importEntry($entry, storage_path('app/prs_archive'), $enterpriseFarmIds[$entry['enterprise']], $enterpriseIds);
        }

        $this->command?->info('PrsRealDataSeeder complete: ' . Employee::count() . ' employees, '
            . PointageRecord::count() . ' pointage records, ' . Quinzaine::count() . ' quinzaines.');
    }

    /**
     * Non-destructive counterpart to run(): imports only the manifest entries $predicate accepts,
     * without truncating anything first. Employee resolution stays CIN-based updateOrCreate (see
     * resolveEmployee()) either way, so this is safe to use for incrementally adding a new period
     * on top of an already-populated, already-in-use database — unlike run(), which always wipes
     * Employee first and would break any FK (Vehicle.default_driver_id, FuelTransaction.driver_id,
     * ManualStockEntry.employee_id) that already points at a real employee row.
     */
    public function importFiltered(callable $predicate): void
    {
        [$enterpriseIds, $enterpriseFarmIds] = $this->ensureEnterprises();

        $manifest = require __DIR__ . '/prs_manifest.php';
        foreach (array_filter($manifest, $predicate) as $entry) {
            $this->importEntry($entry, storage_path('app/prs_archive'), $enterpriseFarmIds[$entry['enterprise']], $enterpriseIds);
        }

        $this->command?->info('PrsRealDataSeeder::importFiltered complete: ' . Employee::count() . ' employees, '
            . PointageRecord::count() . ' pointage records, ' . Quinzaine::count() . ' quinzaines.');
    }

    /**
     * @return array{0: array<string, int>, 1: array<string, int>} [enterpriseIds, enterpriseFarmIds] keyed by enterprise name
     */
    private function ensureEnterprises(): array
    {
        $persealandFarm = Farm::firstOrCreate(['name' => 'Persealand']);

        // Array key is the manifest's own 'enterprise' identifier (every prs_manifest.php entry
        // references one of these three keys) — kept stable even though the *live* DB row for
        // 'AGRI INTERIM' was renamed to 'PRESTATAIRE MO DECLARE' at some point outside this
        // seeder (e.g. via the Farm Settings UI). Matching on the old hardcoded name here would
        // silently create a second, duplicate enterprise instead of reusing the renamed one —
        // confirmed the hard way importing 2QZ Août 2026's A.I sheet, which briefly did exactly
        // that before this fix (see the migration-safety commit for the cleanup).
        $enterprises = [
            'PERSEALAND' => [$persealandFarm, 'PERSEALAND', ['contract_type' => 'avec_contrat', 'invoiced_to_client' => false, 'default_brut_rate' => 97.44]],
            'AGRI INTERIM' => [$persealandFarm, 'PRESTATAIRE MO DECLARE', ['contract_type' => 'avec_contrat', 'invoiced_to_client' => true, 'default_brut_rate' => 97.44]],
            'PERSEALAND NON DECLARE' => [$persealandFarm, 'PERSEALAND NON DECLARE', ['contract_type' => 'avec_contrat', 'invoiced_to_client' => false, 'default_brut_rate' => 97.44]],
        ];
        $enterpriseIds = [];
        $enterpriseFarmIds = [];
        foreach ($enterprises as $key => [$farm, $dbName, $attrs]) {
            $enterpriseIds[$key] = Enterprise::updateOrCreate(
                ['farm_id' => $farm->id, 'name' => $dbName],
                $attrs + ['settings' => ['currency' => 'DH']]
            )->id;
            $enterpriseFarmIds[$key] = $farm->id;
        }

        return [$enterpriseIds, $enterpriseFarmIds];
    }

    private function importEntry(array $entry, string $archiveDir, int $farmId, array $enterpriseIds): void
    {
        $path = $archiveDir . DIRECTORY_SEPARATOR . $entry['file'];
        if (!file_exists($path)) {
            $this->command?->warn("Missing file, skipping: {$entry['file']}");
            return;
        }

        if (isset($entry['memory_limit'])) {
            ini_set('memory_limit', $entry['memory_limit']);
        }

        $enterpriseId = $enterpriseIds[$entry['enterprise']];
        [$startDate, $endDate] = $this->periodDates($entry['year'], $entry['month'], $entry['period']);

        $reader = IOFactory::createReaderForFile($path);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($entry['sheet']);
        if (!$sheet) {
            $this->command?->warn("Missing sheet '{$entry['sheet']}' in {$entry['file']}, skipping.");
            return;
        }

        // 'parser' is either a class-string (default constructor) or a closure factory, for
        // parsers like FixedOperationParser that need constructor arguments per manifest entry.
        $parser = $entry['parser'] instanceof \Closure ? ($entry['parser'])() : new $entry['parser']();
        $parsedEmployees = $parser->parse($sheet, $entry['year'], $entry['month']);
        $parsedEmployees = array_values(array_filter(
            $parsedEmployees,
            fn ($data) => !in_array(trim((string) ($data['cin'] ?? '')), self::EXCLUDED_CINS, true)
        ));
        (new OperationEstimator())->apply($sheet, $entry['year'], $entry['month'], $parsedEmployees);

        $quinzaine = Quinzaine::where('enterprise_id', $enterpriseId)
            ->whereDate('start_date', $startDate)
            ->whereDate('end_date', $endDate)
            ->first();
        if (!$quinzaine) {
            $quinzaine = Quinzaine::create([
                'enterprise_id' => $enterpriseId,
                'label' => $entry['period'] . 'QZ ' . $this->frenchMonth($entry['month']) . ' ' . $entry['year'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_closed' => $entry['is_closed'] ?? true,
            ]);
        }

        // 'jf_date' (singular, one day-of-month int) and 'jf_dates' (plural, array of them) are
        // both accepted — a period can have more than one J.F. this size of workforce spans.
        $jfDays = $entry['jf_dates'] ?? (isset($entry['jf_date']) ? [$entry['jf_date']] : []);
        $jfDates = array_map(
            fn ($day) => sprintf('%04d-%02d-%02d', $entry['year'], $entry['month'], $day),
            $jfDays
        );

        foreach ($parsedEmployees as $data) {
            if (empty($data['days'])) {
                continue;
            }

            $employee = $this->resolveEmployee($entry, $farmId, $enterpriseId, $data, $endDate);

            $this->insertPointageRecords($employee, $quinzaine, $data, $farmId, $jfDates);

            $this->quinzaineTtcTotals[$quinzaine->id] = ($this->quinzaineTtcTotals[$quinzaine->id] ?? 0) + ($data['total_ttc'] ?? 0);
        }

        $this->buildSummary($quinzaine, $this->quinzaineTtcTotals[$quinzaine->id] ?? 0);
    }

    /**
     * Employee identity is CIN-first (farm-scoped): the same real worker keeps one row across
     * enterprises. Employees with no recorded CIN fall back to (enterprise, matricule) — the
     * best available identity when CIN is missing, at the cost of not merging that specific
     * person's rows across enterprises (matches the migration's own nullable-CIN tradeoff).
     */
    private function resolveEmployee(array $entry, int $farmId, int $enterpriseId, array $data, string $endDate): Employee
    {
        $cin = trim((string) ($data['cin'] ?? ''));
        $identity = $cin !== ''
            ? ['farm_id' => $farmId, 'cin' => $cin]
            : ['farm_id' => $farmId, 'enterprise_id' => $enterpriseId, 'matricule' => $data['matricule'], 'cin' => null];

        $key = $cin !== '' ? "cin:{$farmId}:{$cin}" : "nc:{$farmId}:{$enterpriseId}:{$data['matricule']}";
        $isLatest = !isset($this->employeeLatestDate[$key]) || $endDate >= $this->employeeLatestDate[$key];

        // A later period can genuinely have weaker data than an earlier one already on file — a
        // sheet with no COMP/SAL BRUT/J column this period parses those fields to blank/0, and a
        // row with no N° falls back to CIN for matricule, all indistinguishable from "this field
        // really is blank" without cross-referencing what's already known. Confirmed the hard way
        // importing 2QZ Août 2026's IRR sheet (no brut/comp column at all that period): it briefly
        // overwrote real base_rate/complement/matricule/rib with blank-derived values for every
        // employee on that sheet before this fix. Never let an update sheet regress the identity/
        // rate fields below a brand-new employee's own first-ever values below.
        $existing = Employee::where($identity)->first();

        $newMatricule = trim((string) ($data['matricule'] ?? ''));
        $matricule = (!$existing || ($newMatricule !== '' && $newMatricule !== $cin))
            ? $data['matricule']
            : $existing->matricule;

        $newRib = trim((string) ($data['rib'] ?? ''));
        $rib = (!$existing || $newRib !== '') ? $data['rib'] : $existing->rib;

        // Only an explicit 'SAL BRUT/J'-style column is trustworthy for the employee's *standing*
        // rate — resolveBrutRate()'s total_net/days fallback is a fine approximation for *this
        // period's own* PointageRecord.rate below, but not stable enough to overwrite base_rate
        // with: total_net can include J.F./H.S., which skews the average well above the real
        // daily rate.
        $baseRate = (!$existing || ($data['brut'] ?? null))
            ? $this->resolveBrutRate($data)
            : $existing->base_rate;

        // Same reasoning as base_rate: a sheet with no COMP column at all parses complement to 0,
        // indistinguishable from "really is zero" — prefer the employee's last known complement.
        $complement = (!$existing || (($data['complement'] ?? 0) != 0))
            ? $data['complement']
            : $existing->complement;

        $attrs = [
            'enterprise_id' => $enterpriseId,
            'matricule' => $matricule,
            'full_name' => $data['full_name'],
            'last_name' => $data['last_name'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'rib' => $rib,
            'complement' => $complement,
            'base_rate' => $baseRate,
            'type' => str_contains($entry['enterprise'], 'AGRI INTERIM') ? 'interim' : 'persea',
            'is_active' => true,
        ];

        if ($isLatest) {
            $this->employeeLatestDate[$key] = $endDate;
            return Employee::updateOrCreate($identity, $attrs);
        }

        return Employee::firstOrCreate($identity, $attrs);
    }

    /**
     * Sheets like "UNITE" have neither a per-day rate column nor total_net's usual siblings —
     * only a period TOTAL/TOTAL NET column — so fall back to that total spread across the
     * employee's worked days rather than leaving the rate at 0.
     */
    private function resolveBrutRate(array $data): float
    {
        if ($data['brut'] ?? null) {
            return $data['brut'];
        }
        if ($data['net_per_day'] ?? null) {
            return $data['net_per_day'];
        }
        $days = count($data['days'] ?? []);
        if ($days > 0 && ($data['total_net'] ?? 0) > 0) {
            return $data['total_net'] / $days;
        }

        return 0;
    }

    /**
     * @param string[] $jfDates Every known J.F. calendar date for this period ('Y-m-d'), not just
     *                          the ones this particular employee is credited for — see below.
     */
    private function insertPointageRecords(Employee $employee, Quinzaine $quinzaine, array $data, int $farmId, array $jfDates = []): void
    {
        $dates = array_keys($data['days']);
        if (empty($dates) && !(!empty($jfDates) && ($data['jf_count'] ?? 0) > 0)) {
            return;
        }
        sort($dates);

        // Each date maps to a LIST of entries, not a single one — the same employee can
        // genuinely do piece-rate meterage in more than one Bloc on the same calendar day
        // (confirmed against the real source file). Piece-rate entries already carry their own
        // exact net and must NOT participate in the flat-rate "last day absorbs the delta"
        // distribution below, which assumes every plain day is worth the same net_per_day.
        $isPlainDate = fn ($d) => empty(array_filter($data['days'][$d], fn ($e) => isset($e['net_override'])));
        $plainDates = array_values(array_filter($dates, $isPlainDate));
        $totalPlainDays = count($plainDates);

        $brut = $this->resolveBrutRate($data);
        $perDayNet = $totalPlainDays > 0 ? ($data['net_per_day'] ?? (($data['total_net'] ?? 0) / $totalPlainDays)) : 0;
        $sumOfPlainDays = $perDayNet * $totalPlainDays;

        // Every known J.F. calendar date for this period (see prs_manifest.php's 'jf_dates') and
        // this employee's own credited count ('J.F CH'): place each credited holiday precisely on
        // a real calendar date instead of guessing which of the period's days it was. A date this
        // employee actually worked (a real entry already parsed for it) is unambiguous and always
        // wins a credit first — paid double, per the source formula. Any credits left over after
        // that go to the earliest *other* known J.F. dates, unworked, as a synthetic presence-less
        // record (mirrors the interactive grid's own "paid holiday without presence" behavior in
        // PointageController). If there are more known J.F. dates than this employee has credits
        // for (someone hired partway through, etc.), the surplus dates are left as ordinary days —
        // never assumed to be this employee's holiday just because they were everyone else's.
        $jfCount = $data['jf_count'] ?? 0;
        $hasPreciseJf = !empty($jfDates) && $jfCount > 0;
        $workedJfDates = $hasPreciseJf ? array_values(array_intersect($jfDates, $plainDates)) : [];
        $remainingJfBudget = max(0, $jfCount - count($workedJfDates));
        $unworkedJfDates = $hasPreciseJf
            ? array_slice(array_values(array_diff($jfDates, $workedJfDates)), 0, $remainingJfBudget)
            : [];
        $creditedJfDates = array_merge($workedJfDates, $unworkedJfDates);
        // Excludes every credited JF date: each one's own net is fixed below, independent of this
        // leftover distribution.
        $leftoverPlainDates = $hasPreciseJf ? array_values(array_diff($plainDates, $creditedJfDates)) : $plainDates;
        $lastPlainDate = !empty($leftoverPlainDates) ? end($leftoverPlainDates) : null;
        // The file gives period totals (hs/jf/total_net), not a per-day breakdown — absorb
        // whatever the period total doesn't already explain (overtime, rounding — the JF portion
        // is now accounted for separately above) into the last non-JF worked day.
        $jfShare = $perDayNet * count($creditedJfDates);
        $adjustment = $totalPlainDays > 0 ? (($data['total_net'] ?? $sumOfPlainDays) - $sumOfPlainDays - $jfShare) : 0;

        // The source file's own H.S. sheet gives a day-by-day breakdown, but it isn't reliable
        // enough to import directly — confirmed with the user (2QZ Août 2026: ASSAL DRISS's H.S.
        // sheet credits 4.2h to a day he has no presence record for at all, since it's one of his
        // unworked J.F. days). Rather than trust that per-day placement, spread each employee's
        // period H.S. total evenly across the real (non-JF-credited) worked days instead — user's
        // explicit call: total net must still match the Excel total (unaffected either way, it's
        // already reconciled via $adjustment below), the H.S. hours split across days just needs
        // to look plausible, not reproduce the H.S. sheet's own placement.
        $hsTotal = round($data['hs_total'] ?? 0, 2);
        $hsDistributionDates = !empty($leftoverPlainDates) ? $leftoverPlainDates : $plainDates;
        $hsPerDate = [];
        if ($hsTotal > 0 && !empty($hsDistributionDates)) {
            $n = count($hsDistributionDates);
            $base = floor(($hsTotal / $n) * 100) / 100;
            $allocated = 0;
            foreach ($hsDistributionDates as $i => $d) {
                $hsPerDate[$d] = $i === $n - 1 ? round($hsTotal - $allocated, 2) : $base;
                $allocated += $base;
            }
        }

        $rows = [];
        $adjustmentApplied = false;
        foreach ($dates as $date) {
            $isJfDate = in_array($date, $workedJfDates, true);
            $isLastPlainDate = $date === $lastPlainDate;
            $isFirstEntryForDate = true;

            // A day can carry MORE than one piece-rate (net_override) entry — the same
            // employee genuinely doing meterage in more than one Bloc the same calendar day
            // (confirmed against real source data). The DB's unique (employee_id, quinzaine_id,
            // date) constraint — added to close a real duplicate-row bug from a double-submit
            // race — means only one row per day can exist though, same limit the live Grid
            // itself already has (PointageController::updateCell()'s piece-rate branch only
            // ever writes one record per day). Merge same-date net_override entries into one
            // row instead of emitting one each, which would collide on that constraint and
            // abort the whole import batch: sum their net (money must never be lost), keep the
            // operation/bloc from whichever entry has the largest net (the day's dominant
            // activity), and only sum quantity when every merged entry shares that operation
            // (summing meters across two different operations wouldn't mean anything).
            $overrideEntries = array_values(array_filter($data['days'][$date], fn ($e) => isset($e['net_override'])));
            if (!empty($overrideEntries)) {
                usort($overrideEntries, fn ($a, $b) => $b['net_override'] <=> $a['net_override']);
                $dominant = $overrideEntries[0];
                $mergedNet = array_sum(array_column($overrideEntries, 'net_override'));
                $sameOperation = count(array_unique(array_column($overrideEntries, 'operation'))) === 1;
                $mergedQuantity = $sameOperation
                    ? array_sum(array_map(fn ($e) => $e['quantity'] ?? 0, $overrideEntries))
                    : ($dominant['quantity'] ?? null);
                $dayRate = $dominant['rate_override'] ?? $brut;
                $rows[] = [
                    'employee_id' => $employee->id,
                    'quinzaine_id' => $quinzaine->id,
                    'operation_id' => $this->resolveOperation($dominant['operation'], $farmId),
                    'bloc_id' => $this->resolveBloc($dominant['bloc'], $farmId),
                    'date' => $date,
                    'hours' => 0,
                    'quantity' => $mergedQuantity,
                    'is_jf' => false,
                    'rate' => $dayRate,
                    'brut' => $dayRate,
                    'net' => $mergedNet,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach ($data['days'][$date] as $dayInfo) {
                if (isset($dayInfo['net_override'])) {
                    continue;
                }

                if ($isJfDate) {
                    $rows[] = [
                        'employee_id' => $employee->id,
                        'quinzaine_id' => $quinzaine->id,
                        'operation_id' => $this->resolveOperation($dayInfo['operation'], $farmId),
                        'bloc_id' => $this->resolveBloc($dayInfo['bloc'], $farmId),
                        'date' => $date,
                        'hours' => 0,
                        'quantity' => null,
                        'is_jf' => true,
                        'rate' => $brut,
                        'brut' => $brut,
                        'net' => $perDayNet * 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    continue;
                }

                // The rounding/H.S.-total leftover is a once-per-employee-per-period amount — apply
                // it to only the first plain entry encountered on the last (non-JF) plain date, even
                // if that date happens to carry more than one plain entry. The H.S. *hours* value
                // itself is handled separately below via $hsPerDate, so it can land on several days.
                $applyAdjustment = $isLastPlainDate && !$adjustmentApplied;
                $adjustmentApplied = $adjustmentApplied || $applyAdjustment;

                $hoursForRow = $isFirstEntryForDate ? ($hsPerDate[$date] ?? 0) : 0;
                $isFirstEntryForDate = false;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'quinzaine_id' => $quinzaine->id,
                    'operation_id' => $this->resolveOperation($dayInfo['operation'], $farmId),
                    'bloc_id' => $this->resolveBloc($dayInfo['bloc'], $farmId),
                    'date' => $date,
                    'hours' => $hoursForRow,
                    'quantity' => null,
                    'is_jf' => !$hasPreciseJf && $applyAdjustment && $jfCount > 0,
                    'rate' => $brut,
                    'brut' => $brut,
                    'net' => $applyAdjustment ? $perDayNet + $adjustment : $perDayNet,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach ($unworkedJfDates as $unworkedJfDate) {
            $rows[] = [
                'employee_id' => $employee->id,
                'quinzaine_id' => $quinzaine->id,
                'operation_id' => null,
                'bloc_id' => null,
                'date' => $unworkedJfDate,
                'hours' => 0,
                'quantity' => null,
                'is_jf' => true,
                'rate' => $brut,
                'brut' => $brut,
                'net' => $perDayNet,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Final safety net: guarantee no two rows for this employee share a date before they
        // ever reach the DB, regardless of how they arose (e.g. a day with both piece-rate
        // meterage AND flat-rate presence, from a stacked-operation sheet's two sections) —
        // the unique (employee_id, quinzaine_id, date) constraint would otherwise reject the
        // whole insert batch, silently dropping every row after the collision.
        $mergedByDate = [];
        foreach ($rows as $row) {
            if (!isset($mergedByDate[$row['date']])) {
                $mergedByDate[$row['date']] = $row;
                continue;
            }
            $existing = $mergedByDate[$row['date']];
            $dominant = $row['net'] >= $existing['net'] ? $row : $existing;
            $mergedByDate[$row['date']] = array_merge($dominant, ['net' => $row['net'] + $existing['net']]);
        }
        $rows = array_values($mergedByDate);

        // Deliberately NOT scoped to this quinzaine_id: Employee identity is now CIN-unified
        // across enterprises, so the same real worker's calendar dates can otherwise collide
        // across two different enterprises' quinzaines for what's really the same period (e.g.
        // Persealand and Baraka Green both recording the same person for the same days). Manifest
        // entries are listed in source-of-truth priority order (Persealand before Baraka Green,
        // etc.) so "first entry to claim a date wins" also resolves those cross-file duplicates,
        // on top of its original purpose: Baraka Green's own per-operation sheet split for one
        // quinzaine, which must not double-count the same employee/date twice either.
        $existingDates = PointageRecord::where('employee_id', $employee->id)
            ->pluck('date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->all();
        $rows = array_values(array_filter($rows, fn ($row) => !in_array($row['date'], $existingDates, true)));

        if (!empty($rows)) {
            PointageRecord::insert($rows);
        }
    }

    private function buildSummary(Quinzaine $quinzaine, float $totalTtc): void
    {
        $totals = PointageRecord::where('quinzaine_id', $quinzaine->id)
            ->selectRaw('COALESCE(SUM(net),0) as total_net, COALESCE(SUM(brut),0) as total_brut, COALESCE(SUM(hours),0) as total_hours')
            ->first();

        $employeeCount = PointageRecord::where('quinzaine_id', $quinzaine->id)->distinct('employee_id')->count('employee_id');

        $employeeNets = PointageRecord::where('quinzaine_id', $quinzaine->id)
            ->selectRaw('employee_id, SUM(net) as total_net')
            ->groupBy('employee_id')
            ->pluck('total_net', 'employee_id')
            ->toArray();

        $opCosts = PointageRecord::where('pointage_records.quinzaine_id', $quinzaine->id)
            ->join('operations', 'pointage_records.operation_id', '=', 'operations.id')
            ->selectRaw('operations.name, SUM(pointage_records.net) as total_net')
            ->groupBy('operations.name')
            ->orderByDesc('total_net')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'total_net' => (float) $r->total_net])
            ->toArray();

        $blocCosts = PointageRecord::where('pointage_records.quinzaine_id', $quinzaine->id)
            ->join('blocs', 'pointage_records.bloc_id', '=', 'blocs.id')
            ->selectRaw('blocs.name, SUM(pointage_records.net) as total_net')
            ->groupBy('blocs.name')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'total_net' => (float) $r->total_net])
            ->toArray();

        QuinzaineSummary::updateOrCreate(
            ['quinzaine_id' => $quinzaine->id],
            [
                'total_net' => $totals->total_net,
                'total_ttc' => $totalTtc,
                'total_brut' => $totals->total_brut,
                'total_hours' => $totals->total_hours,
                'employee_count' => $employeeCount,
                'summary_data' => [
                    'employee_nets' => $employeeNets,
                    'op_costs' => $opCosts,
                    'bloc_costs' => $blocCosts,
                ],
            ]
        );
    }

    private function resolveBloc(?string $name, int $farmId): int
    {
        if (!$name) {
            return $this->fallbackBlocId ??= Bloc::firstOrCreate(
                ['name' => 'Non spécifié', 'farm_id' => $farmId]
            )->id;
        }
        $key = $farmId . '|' . $name;
        return $this->blocCache[$key] ??= Bloc::firstOrCreate(['name' => $name, 'farm_id' => $farmId])->id;
    }

    private function resolveOperation(?string $abbreviation, int $farmId): int
    {
        if (!$abbreviation) {
            return $this->fallbackOperationId ??= Operation::firstOrCreate(
                ['abbreviation' => 'N/A', 'farm_id' => $farmId],
                ['name' => 'Non spécifié']
            )->id;
        }
        $key = $farmId . '|' . strtoupper($abbreviation);
        if (isset($this->operationCache[$key])) {
            return $this->operationCache[$key];
        }

        $existing = Operation::where('farm_id', $farmId)
            ->whereRaw('UPPER(abbreviation) = ?', [strtoupper($abbreviation)])
            ->first();
        $operation = $existing ?? Operation::create([
            'farm_id' => $farmId,
            'name' => $abbreviation,
            'abbreviation' => $abbreviation,
        ]);

        return $this->operationCache[$key] = $operation->id;
    }

    private function periodDates(int $year, int $month, string $period): array
    {
        $start = $period === '1' ? 1 : 16;
        $lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $end = $period === '1' ? 15 : $lastDay;

        return [
            sprintf('%04d-%02d-%02d', $year, $month, $start),
            sprintf('%04d-%02d-%02d', $year, $month, $end),
        ];
    }

    private function frenchMonth(int $month): string
    {
        $months = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai',
            6 => 'Juin', 7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre',
            11 => 'Novembre', 12 => 'Décembre'];
        return $months[$month];
    }
}
