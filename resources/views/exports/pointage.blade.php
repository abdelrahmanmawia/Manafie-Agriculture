@if (isset($isEmptySheet) && $isEmptySheet)
    <table>
        <thead>
            <tr>
                <th colspan="5" style="font-weight: bold; text-align: center; font-size: 18px; color: #ef4444;">
                    AUCUNE DONNÉE DE POINTAGE
                </th>
            </tr>
            <tr>
                <th colspan="5" style="text-align: center; font-size: 12px; color: #6b7280;">
                    Période du {{ \Carbon\Carbon::parse($quinzaine->start_date)->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($quinzaine->end_date)->format('d/m/Y') }} pour {{ $quinzaine->enterprise->name }}
                </th>
            </tr>
            <tr>
                <th colspan="5" style="text-align: center; font-size: 12px; color: #6b7280;">
                    Aucune quinzaine correspondante ou aucune donnée de pointage pour cette période et cette division.
                </th>
            </tr>
        </thead>
    </table>
@else
    <table>
        <thead>
        <tr>
            <th colspan="32" style="font-weight: bold; text-align: center; font-size: 18px; color: #1e40af;">
                {{ $quinzaine->label ?: 'SITUATION DE POINTAGE' }}
            </th>
        </tr>
        <tr>
            <th colspan="32" style="text-align: center; font-size: 12px; color: #6b7280;">
                Période du {{ $quinzaine->start_date->format('d/m/Y') }} au {{ $quinzaine->end_date->format('d/m/Y') }} - {{ $quinzaine->enterprise->name }}
            </th>
        </tr>
        <tr><td></td></tr> {{-- SPACING --}}
        <tr>
            <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">N° (Matricule)</th>
            <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">NOM</th>
            <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">PRENOM</th>
            <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">CIN</th>
            <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; min-width: 150px;">RIB</th>
            @foreach($days as $day)
                <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: center;">{{ date('d', strtotime($day)) }}</th>
            @endforeach
            <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">SAL NET / J</th>
            <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">COMP</th>
            <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">SAL BRUT / J</th>
            <th style="font-weight: bold; background-color: #fef3c7; border: 1px solid #000;">MARGE</th>
            <th style="font-weight: bold; background-color: #fef3c7; border: 1px solid #000;">TOTAL J</th>
            <th style="font-weight: bold; background-color: #fef3c7; border: 1px solid #000;">J.F CH (DH)</th>
            <th style="font-weight: bold; background-color: #fef3c7; border: 1px solid #000;">H.S</th>
            <th style="font-weight: bold; background-color: #3b82f6; color: #ffffff; border: 1px solid #000;">TOTAL NET</th>
            <th style="font-weight: bold; background-color: #10b981; color: #ffffff; border: 1px solid #000;">NET FACTUR J</th>
            <th style="font-weight: bold; background-color: #10b981; color: #ffffff; border: 1px solid #000;">TOTAL TTC</th>
        </tr>
        </thead>
        <tbody>
        @foreach($employees as $emp)
            @php
                // Piece-rate (quantity) days are pulled out entirely and shown in their own
                // "Pointage à la Quantité" table below — they don't have HS/JF and their net
                // isn't derived from the enterprise rate/complement, so mixing them into this
                // table's per-day cells and totals would misrepresent both tables.
                $allEmployeeRecords = $records[$emp->id] ?? collect();
                $employeeRecords = $allEmployeeRecords->filter(fn($dayList) => is_null($dayList[0]->quantity ?? null));
                $totalJours = $employeeRecords->count();
                $totalHs = 0;
                $totalJf = 0;
                foreach($employeeRecords as $recordList) {
                    $record = $recordList[0];
                    if(isset($record->hours) && $record->hours > 0) {
                        $totalHs += $record->hours;
                    }
                    if(isset($record->is_jf) && $record->is_jf) {
                        $totalJf++;
                    }
                }

                // SAL NET/J and SAL BRUT/J are a per-employee "typical rate" display, not a sum.
                // Prefer this quinzaine's own records' rate (historically accurate for THIS
                // specific period) over emp->base_rate, which reflects whichever period was
                // chronologically latest across ALL of this employee's imported periods and can
                // differ from the one being exported here.
                $employeeRate = $employeeRecords->isNotEmpty()
                    ? $employeeRecords->first()[0]->rate
                    : ($emp->base_rate ?: $quinzaine->enterprise->default_brut_rate);
                $calc = $payrollService->calculate(
                    $quinzaine->enterprise->contract_type,
                    $employeeRate,
                    0,
                    $emp->complement,
                    false,
                    $quinzaine->enterprise->invoiced_to_client
                );

                // TOTAL NET is summed directly from each PointageRecord's own stored net — that
                // value was computed and frozen at entry/import time and must not be silently
                // recomputed from today's rate. NET FACTUR J / TOTAL TTC (client invoicing) are
                // period-level (see PayrollService::calculateInvoicing()) — computed once per
                // employee from this quinzaine's own totals, not per day.
                $quinzaineTotalNet = 0;
                foreach($employeeRecords as $recordList) {
                    $quinzaineTotalNet += $recordList[0]->net;
                }

                $invoicing = $payrollService->calculateInvoicing(
                    $quinzaine->enterprise->contract_type,
                    $employeeRate,
                    $emp->complement,
                    $totalJours,
                    $totalJf,
                    $totalHs,
                    $quinzaine->enterprise->invoiced_to_client
                );
                $quinzaineNetFacturJ = $invoicing['net_factur_j'];
                $quinzaineTotalTtc = $invoicing['total_ttc'];
                $totalJfAmount = $totalJf * $calc['sal_net_j'];

                // last_name/first_name are set directly from the source file's own NOM/PRENOM
                // columns wherever available (the PRS import). For employees created manually
                // (no separate fields), fall back to a best-effort split of full_name — imperfect
                // for multi-word names, but at least NOM/PRENOM land in the right column now.
                if ($emp->last_name || $emp->first_name) {
                    $nomDisplay = $emp->last_name ?? '';
                    $prenomDisplay = $emp->first_name ?? '';
                } else {
                    $nameParts = explode(' ', $emp->full_name, 2);
                    $nomDisplay = $nameParts[0] ?? $emp->full_name;
                    $prenomDisplay = $nameParts[1] ?? '';
                }
            @endphp
            <tr>
                <td style="border: 1px solid #000;">{{ $emp->matricule }}</td>
                <td style="border: 1px solid #000;">{{ $nomDisplay }}</td>
                <td style="border: 1px solid #000;">{{ $prenomDisplay }}</td>
                <td style="border: 1px solid #000;">{{ $emp->cin }}</td>
                <td style="border: 1px solid #000; white-space: nowrap; overflow: visible; min-width: 150px;">{{ $emp->rib ?? '-' }}</td>
                @foreach($days as $day)
                    @php $rec = $employeeRecords[$day][0] ?? null; @endphp
                    <td style="border: 1px solid #000; text-align: center; font-size: 8px; @if($rec) background-color: #d1fae5; @endif">
                        @if($rec)
                            {{ $rec->operation->abbreviation ?? $rec->operation->name }} | {{ $rec->bloc->name }} {{ $rec->hours > 0 ? '+'.$rec->hours.'h' : '' }}
                        @endif
                    </td>
                @endforeach
                <td style="border: 1px solid #000; text-align: right;">{{ number_format($calc['sal_net_j'], 2, ',', ' ') }}</td>
                <td style="border: 1px solid #000; text-align: right;">{{ number_format($emp->complement, 2, ',', ' ') }}</td>
                <td style="border: 1px solid #000; text-align: right;">{{ number_format($employeeRate, 2, ',', ' ') }}</td>
                <td style="border: 1px solid #000; text-align: right;">0,00</td>
                <td style="border: 1px solid #000; text-align: center;">{{ $totalJours }}</td>
                <td style="border: 1px solid #000; text-align: right;">{{ number_format($totalJfAmount, 2, ',', ' ') }}</td>
                <td style="border: 1px solid #000; text-align: center;">{{ $totalHs }}</td>
                <td style="border: 1px solid #000; text-align: right; font-weight: bold;">{{ number_format($quinzaineTotalNet, 2, ',', ' ') }}</td>
                <td style="border: 1px solid #000; text-align: right;">{{ number_format($quinzaineNetFacturJ, 2, ',', ' ') }}</td>
                <td style="border: 1px solid #000; text-align: right; font-weight: bold;">{{ number_format($quinzaineTotalTtc, 2, ',', ' ') }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000;">TOTAL GÉNÉRAL</td>
                @foreach($days as $day)
                    @php
                        $dayEmployeeCount = 0;
                        foreach($employees as $emp) {
                            $empRecords = ($records[$emp->id] ?? collect())->filter(fn($dayList) => is_null($dayList[0]->quantity ?? null));
                            if(isset($empRecords[$day])) {
                                $dayEmployeeCount++;
                            }
                        }
                    @endphp
                    <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: center;">
                        {{ $dayEmployeeCount > 0 ? $dayEmployeeCount : '-' }}
                    </td>
                @endforeach
                @php
                    $grandTotalSalNetJ = 0;
                    $grandTotalComp = 0;
                    $grandTotalBrut = 0;
                    $grandTotalJours = 0;
                    $grandTotalJf = 0;
                    $grandTotalJfAmount = 0;
                    $grandTotalHs = 0;
                    $grandTotalNet = 0;
                    $grandTotalFacturJ = 0;
                    $grandTotalTtc = 0;

                    foreach($employees as $emp) {
                        $empRecords = ($records[$emp->id] ?? collect())->filter(fn($dayList) => is_null($dayList[0]->quantity ?? null));
                        $grandTotalJours += $empRecords->count();
                        $empJfCount = 0;
                        foreach($empRecords as $recordList) {
                            $record = $recordList[0];
                            if(isset($record->hours) && $record->hours > 0) {
                                $grandTotalHs += $record->hours;
                            }
                            if(isset($record->is_jf) && $record->is_jf) {
                                $empJfCount++;
                            }
                        }
                        $grandTotalJf += $empJfCount;
                        $grandTotalComp += $emp->complement;

                        // Same fix as the per-employee row above: use this quinzaine's own record
                        // rate (historically accurate for THIS period), and compute NET FACTUR J /
                        // TOTAL TTC once per employee from their period totals via
                        // calculateInvoicing(), not per day.
                        $empRate = $empRecords->isNotEmpty()
                            ? $empRecords->first()[0]->rate
                            : ($emp->base_rate ?: $quinzaine->enterprise->default_brut_rate);
                        $calc = $payrollService->calculate(
                            $quinzaine->enterprise->contract_type,
                            $empRate,
                            0,
                            $emp->complement,
                            false,
                            $quinzaine->enterprise->invoiced_to_client
                        );
                        $grandTotalSalNetJ += $calc['sal_net_j'];
                        $grandTotalBrut += $empRate;
                        $grandTotalJfAmount += $empJfCount * $calc['sal_net_j'];

                        foreach($empRecords as $recordList) {
                            $grandTotalNet += $recordList[0]->net;
                        }

                        $empInvoicing = $payrollService->calculateInvoicing(
                            $quinzaine->enterprise->contract_type,
                            $empRate,
                            $emp->complement,
                            $empRecords->count(),
                            $empJfCount,
                            $empRecords->sum(fn($r) => $r[0]->hours),
                            $quinzaine->enterprise->invoiced_to_client
                        );
                        $grandTotalTtc += $empInvoicing['total_ttc'];
                        $grandTotalFacturJ = $empInvoicing['net_factur_j'];
                    }
                @endphp
                <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: right;">{{ number_format($grandTotalSalNetJ, 2, ',', ' ') }}</td>
                <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: right;">{{ number_format($grandTotalComp, 2, ',', ' ') }}</td>
                <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: right;">{{ number_format($grandTotalBrut, 2, ',', ' ') }}</td>
                <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: right;">0,00</td>
                <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: center;">{{ $grandTotalJours }}</td>
                <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: right;">{{ number_format($grandTotalJfAmount, 2, ',', ' ') }}</td>
                <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: center;">{{ $grandTotalHs }}</td>
                <td style="font-weight: bold; background-color: #10b981; color: #ffffff; border: 1px solid #000; text-align: right;">{{ number_format($grandTotalNet, 2, ',', ' ') }}</td>
                <td style="font-weight: bold; background-color: #10b981; color: #ffffff; border: 1px solid #000; text-align: right;">{{ number_format($grandTotalFacturJ, 2, ',', ' ') }}</td>
                <td style="font-weight: bold; background-color: #10b981; color: #ffffff; border: 1px solid #000; text-align: right;">{{ number_format($grandTotalTtc, 2, ',', ' ') }}</td>
            </tr>
        </tfoot>
    </table>

    {{-- SPACE BETWEEN TABLES --}}
    <table><tr><td></td></tr><tr><td></td></tr></table>

    {{-- POINTAGE À LA QUANTITÉ (piece-rate: quantity × the operation's own rate — e.g. meterage
         for Fixation Brise Vent) — kept entirely separate from the normal table above since these
         days have no HS/JF and their net isn't derived from the enterprise rate/complement. --}}
    @php
        $quantityByEmployee = [];
        foreach ($employees as $emp) {
            $qRecords = ($records[$emp->id] ?? collect())->filter(fn($dayList) => !is_null($dayList[0]->quantity ?? null));
            if ($qRecords->isNotEmpty()) {
                $quantityByEmployee[$emp->id] = $qRecords;
            }
        }
    @endphp
    @if(count($quantityByEmployee) > 0)
        <table>
            <thead>
            <tr>
                <th colspan="32" style="font-weight: bold; text-align: center; font-size: 16px; color: #047857;">
                    POINTAGE À LA QUANTITÉ
                </th>
            </tr>
            <tr><td></td></tr> {{-- SPACING --}}
            <tr>
                <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">N° (Matricule)</th>
                <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">NOM</th>
                <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">PRENOM</th>
                <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">CIN</th>
                @foreach($days as $day)
                    <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000; text-align: center;">{{ date('d', strtotime($day)) }}</th>
                @endforeach
                <th style="font-weight: bold; background-color: #10b981; color: #ffffff; border: 1px solid #000;">TOTAL QUANTITÉ</th>
                <th style="font-weight: bold; background-color: #059669; color: #ffffff; border: 1px solid #000;">TOTAL NET</th>
            </tr>
            </thead>
            <tbody>
            @foreach($quantityByEmployee as $empId => $qRecords)
                @php
                    $emp = $employees->firstWhere('id', $empId);
                    $totalQuantity = 0;
                    $totalNetQty = 0;
                    // A day can hold MORE than one entry — the same employee can genuinely do
                    // piece-rate meterage in more than one Bloc on the same calendar day (confirmed
                    // against real source data) — so every entry for the day must be summed/shown,
                    // not just the first.
                    foreach ($qRecords as $dayEntries) {
                        foreach ($dayEntries as $entry) {
                            $totalQuantity += $entry->quantity;
                            $totalNetQty += $entry->net;
                        }
                    }
                    if ($emp->last_name || $emp->first_name) {
                        $nomDisplay = $emp->last_name ?? '';
                        $prenomDisplay = $emp->first_name ?? '';
                    } else {
                        $nameParts = explode(' ', $emp->full_name, 2);
                        $nomDisplay = $nameParts[0] ?? $emp->full_name;
                        $prenomDisplay = $nameParts[1] ?? '';
                    }
                @endphp
                <tr>
                    <td style="border: 1px solid #000;">{{ $emp->matricule }}</td>
                    <td style="border: 1px solid #000;">{{ $nomDisplay }}</td>
                    <td style="border: 1px solid #000;">{{ $prenomDisplay }}</td>
                    <td style="border: 1px solid #000;">{{ $emp->cin }}</td>
                    @foreach($days as $day)
                        @php $dayEntries = $qRecords[$day] ?? collect(); @endphp
                        <td style="border: 1px solid #000; text-align: center; font-size: 8px; @if($dayEntries->isNotEmpty()) background-color: #d1fae5; @endif">
                            @foreach($dayEntries as $entry)
                                {{ $entry->quantity }} ({{ $entry->operation->abbreviation ?? $entry->operation->name }}) {{ $entry->bloc->name }}@if(!$loop->last)<br>@endif
                            @endforeach
                        </td>
                    @endforeach
                    <td style="border: 1px solid #000; text-align: center; font-weight: bold;">{{ number_format($totalQuantity, 2, ',', ' ') }}</td>
                    <td style="border: 1px solid #000; text-align: right; font-weight: bold;">{{ number_format($totalNetQty, 2, ',', ' ') }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000;">TOTAL GÉNÉRAL</td>
                    @foreach($days as $day)
                        @php
                            $dayNetTotal = 0;
                            foreach ($quantityByEmployee as $qRecords) {
                                foreach (($qRecords[$day] ?? collect()) as $entry) {
                                    $dayNetTotal += $entry->net;
                                }
                            }
                        @endphp
                        <td style="border: 1px solid #000; text-align: center; font-size: 8px; font-weight: bold; background-color: #1e293b; color: #ffffff;">
                            {{ $dayNetTotal > 0 ? number_format($dayNetTotal, 1, ',', ' ') : '-' }}
                        </td>
                    @endforeach
                    @php
                        $grandTotalQty = 0;
                        $grandTotalNetQty = 0;
                        foreach ($quantityByEmployee as $qRecords) {
                            foreach ($qRecords as $dayEntries) {
                                foreach ($dayEntries as $entry) {
                                    $grandTotalQty += $entry->quantity;
                                    $grandTotalNetQty += $entry->net;
                                }
                            }
                        }
                    @endphp
                    <td style="border: 1px solid #000; text-align: center; font-weight: bold; background-color: #10b981; color: #ffffff;">{{ number_format($grandTotalQty, 2, ',', ' ') }}</td>
                    <td style="border: 1px solid #000; text-align: right; font-weight: bold; background-color: #059669; color: #ffffff;">{{ number_format($grandTotalNetQty, 2, ',', ' ') }}</td>
                </tr>
            </tfoot>
        </table>
        <table><tr><td></td></tr><tr><td></td></tr></table>
    @endif

    @foreach($blocs as $bloc)
        @php
            $matrix = $blocMatrices[$bloc->name] ?? [];
            $hasData = false;
            foreach($matrix as $opDays) { if(array_sum($opDays) > 0) { $hasData = true; break; } }
        @endphp

        @if($hasData)
        <table>
            <thead>
            <tr>
                <th colspan="20" style="font-weight: bold; font-size: 14px; background-color: #dcfce7; text-align: center; border: 2px solid #000;">
                    SITUATION DES SALAIRES : BLOC {{ $bloc->name }}
                </th>
            </tr>
            <tr>
                <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">OPÉRATION</th>
                @foreach($days as $day)
                    <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: center;">{{ date('d', strtotime($day)) }}</th>
                @endforeach
                <th style="font-weight: bold; background-color: #3b82f6; color: #ffffff; border: 1px solid #000;">TOTAL NET OP</th>
            </tr>
            </thead>
            <tbody>
            @foreach($operations as $op)
                @php
                    $opKey = $op->abbreviation ?? $op->name;
                    $dayValues = $matrix[$opKey] ?? [];
                    $opTotal = array_sum($dayValues);
                @endphp
                @if($opTotal > 0)
                    <tr>
                        <td style="border: 1px solid #000; font-weight: bold;">{{ $op->name }} ({{ $op->abbreviation ?? '-' }})</td>
                        @foreach($days as $day)
                            @php $val = $dayValues[$day] ?? 0; @endphp
                            <td style="border: 1px solid #000; text-align: center; @if($val > 0) background-color: #eff6ff; @endif">
                                {{ $val > 0 ? number_format($val, 1, ',', ' ') : '-' }}
                            </td>
                        @endforeach
                        <td style="border: 1px solid #000; text-align: right; font-weight: bold; background-color: #eff6ff;">
                            {{ number_format($opTotal, 2, ',', ' ') }}
                        </td>
                    </tr>
                @endif
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000;">TOTAL NET / JOUR</td>
                @foreach($days as $day)
                    @php
                        $colTotal = 0;
                        foreach($operations as $o) {
                            $opKey = $o->abbreviation ?? $o->name;
                            $colTotal += ($matrix[$opKey][$day] ?? 0);
                        }
                    @endphp
                    <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: center;">
                        {{ $colTotal > 0 ? number_format($colTotal, 1, ',', ' ') : '-' }}
                    </td>
                @endforeach
                <td style="font-weight: bold; background-color: #10b981; color: #ffffff; border: 1px solid #000; text-align: right;">
                    @php
                        $blocGrandTotal = 0;
                        foreach($matrix as $oTotal) { $blocGrandTotal += array_sum($oTotal); }
                    @endphp
                    {{ number_format($blocGrandTotal, 2, ',', ' ') }}
                </td>
            </tr>
            </tfoot>
        </table>
        <table><tr><td></td></tr></table>
        @endif
    @endforeach
@endif
