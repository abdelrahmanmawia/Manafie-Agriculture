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
                $employeeRecords = $records[$emp->id] ?? collect();
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

                $calc = $payrollService->calculate(
                    $quinzaine->enterprise->contract_type,
                    $quinzaine->enterprise->default_brut_rate,
                    0,
                    $emp->complement,
                    false
                );

                $quinzaineTotalNet = 0;
                $quinzaineTotalTtc = 0;
                $quinzaineNetFacturJ = 0;
                $totalJfAmount = $totalJf * $calc['sal_net_j'];

                foreach($employeeRecords as $recordList) {
                    $record = $recordList[0];
                    $dayCalc = $payrollService->calculate(
                        $quinzaine->enterprise->contract_type,
                        $quinzaine->enterprise->default_brut_rate,
                        $record->hours,
                        $emp->complement,
                        $record->is_jf
                    );
                    $quinzaineTotalNet += $dayCalc['total_net'];
                    $quinzaineTotalTtc += $dayCalc['total_ttc'];
                    $quinzaineNetFacturJ = $dayCalc['net_factur_j'];
                }
            @endphp
            <tr>
                <td style="border: 1px solid #000;">{{ $emp->matricule }}</td>
                <td style="border: 1px solid #000;">{{ explode(' ', $emp->full_name)[1] ?? $emp->full_name }}</td>
                <td style="border: 1px solid #000;">{{ explode(' ', $emp->full_name)[0] ?? '' }}</td>
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
                <td style="border: 1px solid #000; text-align: right;">{{ number_format($quinzaine->enterprise->default_brut_rate, 2, ',', ' ') }}</td>
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
                            $empRecords = $records[$emp->id] ?? collect();
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
                        $empRecords = $records[$emp->id] ?? collect();
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

                        $calc = $payrollService->calculate(
                            $quinzaine->enterprise->contract_type,
                            $quinzaine->enterprise->default_brut_rate,
                            0,
                            $emp->complement,
                            false
                        );
                        $grandTotalSalNetJ += $calc['sal_net_j'];
                        $grandTotalBrut += $quinzaine->enterprise->default_brut_rate;
                        $grandTotalJfAmount += $empJfCount * $calc['sal_net_j'];

                        foreach($empRecords as $recordList) {
                            $record = $recordList[0];
                            $dayCalc = $payrollService->calculate(
                                $quinzaine->enterprise->contract_type,
                                $quinzaine->enterprise->default_brut_rate,
                                $record->hours,
                                $emp->complement,
                                $record->is_jf
                            );
                            $grandTotalNet += $dayCalc['total_net'];
                            $grandTotalTtc += $dayCalc['total_ttc'];
                            $grandTotalFacturJ = $dayCalc['net_factur_j'];
                        }
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
