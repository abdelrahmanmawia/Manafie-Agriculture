<?php

use App\Services\PrsImport\HeaderMappedPointageParser;
use App\Services\PrsImport\FixedOperationParser;
use App\Services\PrsImport\StackedOperationParser;

/**
 * Maps each real PRS archive file/sheet to the enterprise, period, and parser it needs.
 *
 * Scope: just the 3 most recent quinzaines (2QZ Juin, 1QZ Juillet, 2QZ Juillet 2026), for
 * Persealand + Agri Intérim only — see the plan at
 * C:\Users\pc\.claude\plans\encapsulated-discovering-frog.md for the reasoning behind each
 * sheet's enterprise assignment (several sheets per period workbook, not just one P.L + one A.I).
 *
 * period: '1' = days 1-15, '2' = day 16 to end of month.
 */
return [
    // --- 2QZ Juin 2026 ---
    [
        'file' => 'P-30062026 PERSEALAND-AGRI INTERIM xlsx.xlsx', 'sheet' => 'Feuil1',
        'enterprise' => 'AGRI INTERIM', 'year' => 2026, 'month' => 6, 'period' => '2',
        'parser' => HeaderMappedPointageParser::class,
    ],
    [
        'file' => 'P-30062026 PERSEALAND NON DECLARE.xlsx', 'sheet' => 'MO',
        'enterprise' => 'PERSEALAND NON DECLARE', 'year' => 2026, 'month' => 6, 'period' => '2',
        // "PEPINIERE- HASSLAND" is a separate nursery sub-team appended after the real roster and
        // its cost-breakdown tables end (row 80+) — specific to this file/period, not a real part
        // of the Non Déclaré roster.
        'parser' => fn () => new HeaderMappedPointageParser(stopSectionLabels: ['PEPINIERE']),
    ],
    [
        'file' => 'P-30062026 UNITE ET LOCATION PERSEALAND.xlsx', 'sheet' => 'F.B.V',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 6, 'period' => '2',
        'parser' => StackedOperationParser::class,
    ],

    // --- 1QZ Juillet 2026 (source: master workbook with A.I/P.L/M.O/UNITE/F.B.V/TRANS sheets) ---
    [
        'file' => '1re Qz Juillet 2026 P.L.xlsm', 'sheet' => 'A.I',
        'enterprise' => 'AGRI INTERIM', 'year' => 2026, 'month' => 7, 'period' => '1',
        'parser' => HeaderMappedPointageParser::class,
    ],
    [
        'file' => '1re Qz Juillet 2026 P.L.xlsm', 'sheet' => 'P.L',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 7, 'period' => '1',
        'parser' => HeaderMappedPointageParser::class,
    ],
    [
        'file' => '1re Qz Juillet 2026 P.L.xlsm', 'sheet' => 'M.O',
        'enterprise' => 'PERSEALAND NON DECLARE', 'year' => 2026, 'month' => 7, 'period' => '1',
        'parser' => HeaderMappedPointageParser::class,
    ],
    [
        'file' => '1re Qz Juillet 2026 P.L.xlsm', 'sheet' => 'UNITE',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 7, 'period' => '1',
        'parser' => HeaderMappedPointageParser::class,
    ],
    [
        // Not one operation — the F.B.V sheet stacks 3: a flat-rate presence section, a
        // piece-rate-by-meter section (Fixation Brise Vent), and an (empty this period)
        // Construction section. StackedOperationParser tells them apart per row.
        'file' => '1re Qz Juillet 2026 P.L.xlsm', 'sheet' => 'F.B.V',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 7, 'period' => '1',
        'parser' => StackedOperationParser::class,
    ],
    [
        'file' => '1re Qz Juillet 2026 P.L.xlsm', 'sheet' => 'TRANS',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 7, 'period' => '1',
        'parser' => fn () => new FixedOperationParser('Transport'),
    ],

    // --- 2QZ Juillet 2026 (source: master workbook, no M.O sheet this period) ---
    [
        'file' => '2eme Qz Juillet 2026 P.L.xlsm', 'sheet' => 'A.I',
        'enterprise' => 'AGRI INTERIM', 'year' => 2026, 'month' => 7, 'period' => '2',
        'parser' => HeaderMappedPointageParser::class,
    ],
    [
        'file' => '2eme Qz Juillet 2026 P.L.xlsm', 'sheet' => 'P.L',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 7, 'period' => '2',
        'parser' => HeaderMappedPointageParser::class,
    ],
    [
        'file' => '2eme Qz Juillet 2026 P.L.xlsm', 'sheet' => 'IRR',
        'enterprise' => 'PERSEALAND NON DECLARE', 'year' => 2026, 'month' => 7, 'period' => '2',
        'parser' => HeaderMappedPointageParser::class,
    ],
    [
        'file' => '2eme Qz Juillet 2026 P.L.xlsm', 'sheet' => 'UNITE',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 7, 'period' => '2',
        'parser' => HeaderMappedPointageParser::class,
    ],
    [
        'file' => '2eme Qz Juillet 2026 P.L.xlsm', 'sheet' => 'F.B.V',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 7, 'period' => '2',
        'parser' => StackedOperationParser::class,
    ],
    [
        'file' => '2eme Qz Juillet 2026 P.L.xlsm', 'sheet' => 'TRANS',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 7, 'period' => '2',
        'parser' => fn () => new FixedOperationParser('Transport'),
    ],

    // --- 1QZ Aout 2026 (source: master workbook, same sheet set as 2QZ Juillet) ---
    // 'is_closed' => false: unlike every prior period here, this quinzaine is still in progress
    // (today's date falls inside 2026-08-01..08-15) — importEntry() only creates a Quinzaine as
    // closed by default, so this override is required, not just descriptive.
    // 'jf_date' => 14: the "J.F CH" column marks who is owed a paid holiday, but not which
    // calendar day it falls on — confirmed against the source file that every J.F CH=1 employee's
    // holiday is day-of-month 14 this period (some worked it, most didn't). Without this, the
    // holiday pay silently lands on whatever day happens to be the employee's last worked day
    // instead of the real Aug 14, which is wrong both in amount (doubles a non-holiday day instead
    // of the actual holiday) and in the day itself shown in the grid.
    [
        'file' => '1r Qz Aout 2026 P.L.xlsm', 'sheet' => 'A.I',
        'enterprise' => 'AGRI INTERIM', 'year' => 2026, 'month' => 8, 'period' => '1',
        'parser' => HeaderMappedPointageParser::class, 'is_closed' => false, 'jf_date' => 14,
    ],
    [
        'file' => '1r Qz Aout 2026 P.L.xlsm', 'sheet' => 'P.L',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 8, 'period' => '1',
        'parser' => HeaderMappedPointageParser::class, 'is_closed' => false,
    ],
    [
        'file' => '1r Qz Aout 2026 P.L.xlsm', 'sheet' => 'IRR',
        'enterprise' => 'PERSEALAND NON DECLARE', 'year' => 2026, 'month' => 8, 'period' => '1',
        'parser' => HeaderMappedPointageParser::class, 'is_closed' => false,
    ],
    [
        'file' => '1r Qz Aout 2026 P.L.xlsm', 'sheet' => 'UNITE',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 8, 'period' => '1',
        'parser' => HeaderMappedPointageParser::class, 'is_closed' => false,
    ],
    [
        'file' => '1r Qz Aout 2026 P.L.xlsm', 'sheet' => 'F.B.V',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 8, 'period' => '1',
        'parser' => StackedOperationParser::class, 'is_closed' => false,
    ],
    [
        'file' => '1r Qz Aout 2026 P.L.xlsm', 'sheet' => 'TRANS',
        'enterprise' => 'PERSEALAND', 'year' => 2026, 'month' => 8, 'period' => '1',
        'parser' => fn () => new FixedOperationParser('Transport'), 'is_closed' => false,
    ],
];
