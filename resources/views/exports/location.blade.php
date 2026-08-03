<table>
    <thead>
    <tr>
        <th colspan="20" style="font-weight: bold; text-align: center; font-size: 18px; color: #6d28d9;">
            LOCATION
        </th>
    </tr>
    <tr>
        <th colspan="20" style="text-align: center; font-size: 12px; color: #6b7280;">
            Période du {{ $startDate->format('d/m/Y') }} au {{ $endDate->format('d/m/Y') }} - {{ $farm->name }}
        </th>
    </tr>
    <tr><td></td></tr> {{-- SPACING --}}
    <tr>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; min-width: 180px;">Véhicule / Équipement</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">N° Plaque</th>
        @foreach($days as $day)
            <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: center;">{{ date('d', strtotime($day)) }}</th>
        @endforeach
        <th style="font-weight: bold; background-color: #3b82f6; color: #ffffff; border: 1px solid #000;">TOTAL</th>
    </tr>
    </thead>
    <tbody>
    @foreach($vehicles as $vehicle)
        @php
            $vehicleUsages = $usages[$vehicle->id] ?? collect();
            $vehicleTotal = 0;
            foreach ($vehicleUsages as $dayUsages) {
                $vehicleTotal += (float) ($dayUsages[0]->daily_rate ?? 0);
            }
        @endphp
        @if($vehicleTotal > 0)
            <tr>
                <td style="border: 1px solid #000; font-weight: bold;">{{ $vehicle->name }}</td>
                <td style="border: 1px solid #000;">{{ $vehicle->plate_number }}</td>
                @foreach($days as $day)
                    @php $rate = $vehicleUsages[$day][0]->daily_rate ?? null; @endphp
                    <td style="border: 1px solid #000; text-align: center; @if($rate) background-color: #ede9fe; @endif">
                        {{ $rate ? number_format($rate, 0, ',', ' ') : '-' }}
                    </td>
                @endforeach
                <td style="border: 1px solid #000; text-align: right; font-weight: bold;">
                    {{ number_format($vehicleTotal, 2, ',', ' ') }}
                </td>
            </tr>
        @endif
    @endforeach
    </tbody>
    <tfoot>
    <tr>
        <td colspan="2" style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000;">TOTAL</td>
        @php $grandTotal = 0; @endphp
        @foreach($days as $day)
            @php
                $dayTotal = 0;
                foreach ($vehicles as $vehicle) {
                    $dayTotal += (float) (($usages[$vehicle->id][$day][0] ?? null)->daily_rate ?? 0);
                }
                $grandTotal += $dayTotal;
            @endphp
            <td style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000; text-align: center;">
                {{ $dayTotal > 0 ? number_format($dayTotal, 0, ',', ' ') : '-' }}
            </td>
        @endforeach
        <td style="font-weight: bold; background-color: #6d28d9; color: #ffffff; border: 1px solid #000; text-align: right;">
            {{ number_format($grandTotal, 2, ',', ' ') }}
        </td>
    </tr>
    </tfoot>
</table>
