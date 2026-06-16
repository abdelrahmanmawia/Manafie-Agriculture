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
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">RIB</th>
        @foreach($days as $day)
            <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: center;">{{ date('d', strtotime($day)) }}</th>
        @endforeach
        <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">SAL NET / J</th>
        <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">COMP</th>
        <th style="font-weight: bold; background-color: #d1fae5; border: 1px solid #000;">SAL BRUT / J</th>
        <th style="font-weight: bold; background-color: #fef3c7; border: 1px solid #000;">MARGE</th>
        <th style="font-weight: bold; background-color: #fef3c7; border: 1px solid #000;">TOTAL J</th>
        <th style="font-weight: bold; background-color: #fef3c7; border: 1px solid #000;">J.F CH</th>
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
            $totalHs = $employeeRecords->sum('hours');
            $hasJf = $employeeRecords->where('is_jf', true)->count() > 0;
            
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
            <td style="border: 1px solid #000;">{{ $emp->rib }}</td>
            @foreach($days as $day)
                @php $rec = $employeeRecords[$day][0] ?? null; @endphp
                <td style="border: 1px solid #000; text-align: center; font-size: 8px; @if($rec) background-color: #d1fae5; @endif">
                    @if($rec)
                        {{ $rec->operation->name }} | {{ $rec->bloc->name }} {{ $rec->hours > 0 ? '+'.$rec->hours.'h' : '' }}
                    @endif
                </td>
            @endforeach
            <td style="border: 1px solid #000; text-align: right;">{{ number_format($calc['sal_net_j'], 2) }}</td>
            <td style="border: 1px solid #000; text-align: right;">{{ number_format($emp->complement, 2) }}</td>
            <td style="border: 1px solid #000; text-align: right;">{{ number_format($quinzaine->enterprise->default_brut_rate, 2) }}</td>
            <td style="border: 1px solid #000; text-align: right;">0.00</td>
            <td style="border: 1px solid #000; text-align: center;">{{ $totalJours }}</td>
            <td style="border: 1px solid #000; text-align: center;">{{ $hasJf ? '1' : '0' }}</td>
            <td style="border: 1px solid #000; text-align: center;">{{ $totalHs }}</td>
            <td style="border: 1px solid #000; text-align: right; font-weight: bold;">{{ number_format($quinzaineTotalNet, 2) }}</td>
            <td style="border: 1px solid #000; text-align: right;">{{ number_format($quinzaineNetFacturJ, 2) }}</td>
            <td style="border: 1px solid #000; text-align: right; font-weight: bold;">{{ number_format($quinzaineTotalTtc, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
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
                $dayValues = $matrix[$op->name] ?? [];
                $opTotal = array_sum($dayValues);
            @endphp
            @if($opTotal > 0)
                <tr>
                    <td style="border: 1px solid #000; font-weight: bold;">{{ $op->name }}</td>
                    @foreach($days as $day)
                        @php $val = $dayValues[$day] ?? 0; @endphp
                        <td style="border: 1px solid #000; text-align: center; @if($val > 0) background-color: #eff6ff; @endif">
                            {{ $val > 0 ? number_format($val, 1) : '-' }}
                        </td>
                    @endforeach
                    <td style="border: 1px solid #000; text-align: right; font-weight: bold; background-color: #eff6ff;">
                        {{ number_format($opTotal, 2) }}
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
                    foreach($operations as $o) { $colTotal += ($matrix[$o->name][$day] ?? 0); }
                @endphp
                <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: center;">
                    {{ $colTotal > 0 ? number_format($colTotal, 1) : '-' }}
                </td>
            @endforeach
            <td style="font-weight: bold; background-color: #10b981; color: #ffffff; border: 1px solid #000; text-align: right;">
                @php
                    $blocGrandTotal = 0;
                    foreach($matrix as $oTotal) { $blocGrandTotal += array_sum($oTotal); }
                @endphp
                {{ number_format($blocGrandTotal, 2) }}
            </td>
        </tr>
        </tfoot>
    </table>
    <table><tr><td></td></tr></table>
    @endif
@endforeach
