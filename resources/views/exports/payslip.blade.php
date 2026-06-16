<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 20px; }
        .enterprise-name { font-size: 20px; font-weight: bold; color: #1e40af; }
        .title { font-size: 16px; font-weight: bold; margin-top: 5px; text-transform: uppercase; }
        
        .info-section { width: 100%; margin-bottom: 20px; }
        .info-box { width: 48%; display: inline-block; vertical-align: top; border: 1px solid #e5e7eb; padding: 10px; border-radius: 8px; }
        .label { font-weight: bold; color: #6b7280; font-size: 10px; text-transform: uppercase; }
        .value { font-size: 13px; font-weight: bold; margin-top: 2px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background-color: #f3f4f6; padding: 10px; border: 1px solid #e5e7eb; text-align: left; font-size: 10px; text-transform: uppercase; }
        td { padding: 10px; border: 1px solid #e5e7eb; }
        
        .total-row { background-color: #eff6ff; font-weight: bold; }
        .net-pay { font-size: 18px; color: #1d4ed8; text-align: right; }
        
        .footer { margin-top: 50px; border-top: 1px solid #e5e7eb; padding-top: 10px; font-size: 10px; color: #9ca3af; text-align: center; }
        .signature { margin-top: 40px; }
        .sig-box { width: 45%; display: inline-block; text-align: center; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <div class="enterprise-name">{{ $quinzaine->enterprise->name }}</div>
        <div class="title">Bulletin de Paie</div>
        <div>Période : {{ $quinzaine->label ?: 'Quinzaine' }} ({{ $quinzaine->start_date->format('d/m/Y') }} - {{ $quinzaine->end_date->format('d/m/Y') }})</div>
    </div>

    <div class="info-section">
        <div class="info-box">
            <div class="label">Salarié</div>
            <div class="value">{{ $employee->full_name }}</div>
            <div class="label" style="margin-top:8px;">Matricule</div>
            <div class="value">{{ $employee->matricule }}</div>
            <div class="label" style="margin-top:8px;">CIN</div>
            <div class="value">{{ $employee->cin ?: '---' }}</div>
        </div>
        <div class="info-box" style="margin-left: 2%;">
            <div class="label">Contrat</div>
            <div class="value uppercase">{{ str_replace('_', ' ', $quinzaine->enterprise->contract_type) }}</div>
            <div class="label" style="margin-top:8px;">RIB</div>
            <div class="value">{{ $employee->rib ?: 'Paiement Espèces' }}</div>
            <div class="label" style="margin-top:8px;">Banque</div>
            <div class="value">{{ $employee->bank_name ?: '---' }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Désignation</th>
                <th style="text-align: center;">Base / Qte</th>
                <th style="text-align: right;">Taux</th>
                <th style="text-align: right;">Gains</th>
                <th style="text-align: right;">Retenues</th>
            </tr>
        </thead>
        <tbody>
            {{-- 1. Salaire de Base --}}
            <tr>
                <td>Salaire de base (Jours de présence)</td>
                <td style="text-align: center;">{{ $records->count() }} J</td>
                <td style="text-align: right;">{{ number_format($quinzaine->enterprise->default_brut_rate, 2) }}</td>
                <td style="text-align: right;">{{ number_format($records->count() * $quinzaine->enterprise->default_brut_rate, 2) }}</td>
                <td></td>
            </tr>

            {{-- 2. Heures Supplémentaires --}}
            @if($records->sum('hours') > 0)
            <tr>
                <td>Heures Supplémentaires (H.S)</td>
                <td style="text-align: center;">{{ $records->sum('hours') }} H</td>
                <td style="text-align: right;">11.36</td>
                <td style="text-align: right;">{{ number_format($records->sum('hours') * 11.36, 2) }}</td>
                <td></td>
            </tr>
            @endif

            {{-- 3. Jours Fériés --}}
            @if($records->where('is_jf', true)->count() > 0)
            <tr>
                <td>Primes Jour Férié (JF)</td>
                <td style="text-align: center;">{{ $records->where('is_jf', true)->count() }} J</td>
                <td style="text-align: right;">{{ number_format($baseNetJ, 2) }}</td>
                <td style="text-align: right;">{{ number_format($records->where('is_jf', true)->count() * $baseNetJ, 2) }}</td>
                <td></td>
            </tr>
            @endif

            {{-- 4. Complément / Primes --}}
            @if($employee->complement > 0)
            <tr>
                <td>Complément / Prime de rendement</td>
                <td style="text-align: center;">{{ $records->count() }} J</td>
                <td style="text-align: right;">{{ number_format($employee->complement, 2) }}</td>
                <td style="text-align: right;">{{ number_format($records->count() * $employee->complement, 2) }}</td>
                <td></td>
            </tr>
            @endif

            {{-- 5. Deductions (CNSS / AMO) --}}
            @if($quinzaine->enterprise->contract_type === 'avec_contrat')
                @php
                    $totalBrut = ($records->count() * $quinzaine->enterprise->default_brut_rate) + ($records->sum('hours') * 11.36);
                    $cnss = min($totalBrut, 6000) * 0.0448;
                    $amo = $totalBrut * 0.0226;
                @endphp
                <tr>
                    <td>Cotisation CNSS (4.48%)</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td style="text-align: right;">{{ number_format($cnss, 2) }}</td>
                </tr>
                <tr>
                    <td>Cotisation AMO (2.26%)</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td style="text-align: right;">{{ number_format($amo, 2) }}</td>
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3" style="text-align: right; text-transform: uppercase;">Total Général</td>
                <td style="text-align: right;">{{ number_format($totalGains, 2) }}</td>
                <td style="text-align: right;">{{ number_format($totalRetenues, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div style="text-align: right; margin-top: 10px;">
        <div class="label">Net à Payer</div>
        <div class="net-pay">{{ number_format($totalNet, 2) }} DH</div>
    </div>

    <div class="signature">
        <div class="sig-box">Le Salarié<br/><br/><br/>(Signature)</div>
        <div class="sig-box" style="float: right;">L'Employeur<br/><br/><br/>(Cachet et Signature)</div>
    </div>

    <div class="footer">
        Document généré automatiquement par PointageApp - {{ date('d/m/Y H:i') }}
    </div>
</body>
</html>
