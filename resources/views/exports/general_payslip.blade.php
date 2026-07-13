<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #333; margin: 0; padding: 0; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .title { font-size: 16px; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; }
        .subtitle { font-size: 11px; font-weight: bold; color: #666; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #f0f0f0; border: 1px solid #000; padding: 6px; font-weight: bold; text-transform: uppercase; font-size: 8px; }
        td { border: 1px solid #000; padding: 6px; vertical-align: middle; }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        
        .footer { margin-top: 30px; }
        .totals-box { float: right; width: 250px; border: 2px solid #000; padding: 10px; background-color: #f9fafb; }
        .signature-col { width: 100px; height: 35px; }

        @page { margin: 0.5cm; size: A4; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">État Global des Salaires & Émargement</div>
        <div class="subtitle">{{ $quinzaine->enterprise->name }}</div>
        <div class="subtitle">Période : {{ $quinzaine->label ?: 'Sans Libellé' }} ({{ $quinzaine->start_date->format('d/m/Y') }} au {{ $quinzaine->end_date->format('d/m/Y') }})</div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 4%;">N°</th>
                <th style="width: 8%;">Matricule</th>
                <th style="width: 25%;">Nom & Prénom</th>
                <th style="width: 10%;">CIN</th>
                <th style="width: 6%;">Jours</th>
                <th style="width: 6%;">H.S</th>
                <th style="width: 6%;">J.F</th>
                <th style="width: 25%;">Net à Payer</th>
                <th class="signature-col text-center" style="width: 10%;">Signature / Emargement</th>
            </tr>
        </thead>
        <tbody>
            @php $grandTotal = 0; @endphp
            @foreach($employees as $index => $emp)
                @php
                    $employeeRecords = $records[$emp->id] ?? collect();
                    if($employeeRecords->isEmpty()) continue;

                    $totalNet = 0;
                    $totalJours = $employeeRecords->count();
                    $totalHs = $employeeRecords->sum('hours');
                    $totalJf = $employeeRecords->where('is_jf', true)->count();

                    foreach($employeeRecords as $record) {
                        $calc = $payrollService->calculate(
                            $quinzaine->enterprise->contract_type,
                            $quinzaine->enterprise->default_brut_rate,
                            $record->hours,
                            $emp->complement,
                            $record->is_jf
                        );
                        $totalNet += $calc['total_net'];
                    }
                    $grandTotal += $totalNet;
                @endphp
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td class="text-center font-bold">{{ $emp->matricule }}</td>
                    <td class="font-bold uppercase">{{ $emp->full_name }}</td>
                    <td class="text-center">{{ $emp->cin ?: '-' }}</td>
                    <td class="text-center font-bold">{{ $totalJours }}</td>
                    <td class="text-center">{{ $totalHs > 0 ? $totalHs : '-' }}</td>
                    <td class="text-center">{{ $totalJf > 0 ? $totalJf : '-' }}</td>
                    <td class="text-right font-bold" style="background-color: #f0fdf4;">{{ number_format($totalNet, 2) }} DH</td>
                    <td class="signature-col"></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <div class="totals-box">
            <table style="margin:0; border:none;">
                <tr style="border:none;">
                    <td style="border:none;" class="font-bold">Nombre de Salariés :</td>
                    <td style="border:none;" class="text-right font-bold">{{ $employees->count() }}</td>
                </tr>
                <tr style="border:none;">
                    <td style="border:none; font-size: 14px;" class="font-bold uppercase">TOTAL NET GLOBAL :</td>
                    <td style="border:none; font-size: 14px; color: #b91c1c;" class="text-right font-bold">{{ number_format($grandTotal, 2) }} DH</td>
                </tr>
            </table>
        </div>
        
        <div style="margin-top: 40px; clear:both;">
            <div style="width: 300px; display: inline-block;">
                <strong>Cachet de l'Entreprise :</strong><br/><br/><br/><br/>
                ..........................................
            </div>
            <div style="width: 300px; display: inline-block; text-align: right; float: right;">
                <strong>Signature de la Direction :</strong><br/><br/><br/><br/>
                ..........................................
            </div>
        </div>
    </div>
</body>
</html>
