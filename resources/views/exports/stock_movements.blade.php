@php
    // Same French labels as resources/js/utils/stockLabels.js's MOVEMENT_TYPE_LABELS, kept in
    // sync by hand since a Blade export can't import the JS module.
    $movementTypeLabels = [
        'in' => 'Entrée',
        'production' => 'Production',
        'out' => 'Sortie',
        'transfer' => 'Transfert',
        'loss' => 'Perte',
        'adjustment' => 'Ajustement',
    ];
@endphp
<table>
    <thead>
    <tr>
        <th colspan="8" style="font-weight: bold; text-align: center; font-size: 18px; color: #15803d;">
            MOUVEMENTS DE STOCK — {{ strtoupper($inventory->product->name) }}
        </th>
    </tr>
    <tr>
        <th colspan="8" style="text-align: center; font-size: 12px; color: #6b7280;">
            Stock actuel : {{ number_format((float) $inventory->quantity_on_hand, 2, ',', ' ') }} {{ $inventory->product->unit_type }}
            — Généré le {{ $generatedAt->format('d/m/Y H:i') }}
        </th>
    </tr>
    <tr><td></td></tr> {{-- SPACING --}}
    <tr>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Date</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Type</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: center;">Quantité</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: right;">Coût Unitaire (DH)</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: right;">Coût Total (DH)</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Effectué par</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Destination</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Notes</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($movements as $movement)
        @php
            $ref = $movement->reference;
            $destination = $ref?->bloc?->name ?? $ref?->sector?->name ?? $ref?->parcelle?->name ?? $ref?->vehicle?->name ?? 'N/A';
        @endphp
        <tr>
            <td style="border: 1px solid #000;">{{ \Carbon\Carbon::parse($movement->date)->format('d/m/Y') }}</td>
            <td style="border: 1px solid #000;">{{ $movementTypeLabels[$movement->movement_type] ?? $movement->movement_type }}</td>
            <td style="border: 1px solid #000; text-align: center;">{{ number_format((float) $movement->quantity, 2, ',', ' ') }}</td>
            <td style="border: 1px solid #000; text-align: right;">{{ number_format((float) $movement->unit_cost, 2, ',', ' ') }}</td>
            <td style="border: 1px solid #000; text-align: right;">{{ number_format((float) $movement->total_cost, 2, ',', ' ') }}</td>
            <td style="border: 1px solid #000;">{{ $movement->performedBy->name ?? 'N/A' }}</td>
            <td style="border: 1px solid #000;">{{ $destination }}</td>
            <td style="border: 1px solid #000;">{{ $movement->notes }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="8" style="border: 1px solid #000; text-align: center; color: #6b7280;">Aucun mouvement enregistré pour ce produit.</td>
        </tr>
    @endforelse
    </tbody>
</table>
