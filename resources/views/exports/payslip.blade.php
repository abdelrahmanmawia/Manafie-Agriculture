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
            {{-- Every amount below comes from PayrollService::payslipBreakdown(), computed from
                 each record's own frozen rate — not recomputed here, and never from the
                 division's current rate. --}}

            {{-- 1. Salaire de Base --}}
            @if($payslip['days'] > 0)
            <tr>
                <td>Salaire de base (Jours de présence)</td>
                <td style="text-align: center;">{{ $payslip['days'] }} J</td>
                <td style="text-align: right;">{{ number_format($payslip['day_rate'], 2) }}</td>
                <td style="text-align: right;">{{ number_format($payslip['base'], 2) }}</td>
                <td></td>
            </tr>
            @endif

            {{-- 2. Travail à la tâche (piece-rate days: quantity × the operation's own rate) --}}
            @if($payslip['piece_days'] > 0)
            <tr>
                <td>Travail à la tâche</td>
                <td style="text-align: center;">{{ $payslip['piece_days'] }} J</td>
                <td></td>
                <td style="text-align: right;">{{ number_format($payslip['piece_pay'], 2) }}</td>
                <td></td>
            </tr>
            @endif

            {{-- 3. Heures Supplémentaires --}}
            @if($payslip['hs_hours'] > 0)
            <tr>
                <td>Heures Supplémentaires (H.S)</td>
                <td style="text-align: center;">{{ $payslip['hs_hours'] }} H</td>
                <td style="text-align: right;">{{ number_format($payslip['hs_hourly_rate'], 2) }}</td>
                <td style="text-align: right;">{{ number_format($payslip['hs_pay'], 2) }}</td>
                <td></td>
            </tr>
            @endif

            {{-- 4. Jours Fériés — worked JF days only (an unworked JF day is a normal paid day,
                 already in the base line), and avec_contrat only, same as calculate(). --}}
            @if($payslip['jf_days'] > 0)
            <tr>
                <td>Primes Jour Férié (JF)</td>
                <td style="text-align: center;">{{ $payslip['jf_days'] }} J</td>
                <td style="text-align: right;">{{ number_format($payslip['jf_day_rate'], 2) }}</td>
                <td style="text-align: right;">{{ number_format($payslip['jf_pay'], 2) }}</td>
                <td></td>
            </tr>
            @endif

            {{-- 5. Complément / Primes — avec_contrat only (payslipBreakdown() zeroes it otherwise). --}}
            @if($payslip['complement_pay'] > 0)
            <tr>
                <td>Complément / Prime de rendement</td>
                <td style="text-align: center;">{{ $payslip['days'] }} J</td>
                <td style="text-align: right;">{{ number_format($payslip['complement'], 2) }}</td>
                <td style="text-align: right;">{{ number_format($payslip['complement_pay'], 2) }}</td>
                <td></td>
            </tr>
            @endif

            {{-- Fixed monthly salary (SalaireFixeService), paid in the month's second quinzaine. --}}
            @if(($payslip['salaire_fixe'] ?? 0) > 0)
            <tr>
                <td>Salaire mensuel fixe</td>
                <td style="text-align: center;">{{ $payslip['days_present'] }} J</td>
                <td></td>
                <td style="text-align: right;">{{ number_format($payslip['salaire_fixe'], 2) }}</td>
                <td></td>
            </tr>
            @endif

            {{-- 6. Deductions (CNSS / AMO) — together PayrollService::DEDUCTION_RATE of the base. --}}
            @if($payslip['total_retenues'] > 0)
                <tr>
                    <td>Cotisation CNSS ({{ number_format(\App\Services\PayrollService::CNSS_RATE * 100, 2) }}%)</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td style="text-align: right;">{{ number_format($payslip['cnss'], 2) }}</td>
                </tr>
                <tr>
                    <td>Cotisation AMO ({{ number_format(\App\Services\PayrollService::AMO_RATE * 100, 2) }}%)</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td style="text-align: right;">{{ number_format($payslip['amo'], 2) }}</td>
                </tr>
            @endif
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3" style="text-align: right; text-transform: uppercase;">Total Général</td>
                <td style="text-align: right;">{{ number_format($payslip['total_gains'], 2) }}</td>
                <td style="text-align: right;">{{ number_format($payslip['total_retenues'], 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <div style="text-align: right; margin-top: 10px;">
        <div class="label">Net à Payer</div>
        <div class="net-pay">{{ number_format($payslip['total_net'], 2) }} DH</div>
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
