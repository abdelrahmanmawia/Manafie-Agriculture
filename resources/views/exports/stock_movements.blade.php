<table>
    <thead>
    <tr>
        <th colspan="3" style="text-align: left; font-size: 16px; border: 1px solid #000;">{{ $farm->name ?? '' }}</th>
        <th colspan="3" style="text-align: center; font-size: 20px; font-weight: bold; border: 1px solid #000;">Fiche de Stock</th>
        <th colspan="2" style="text-align: right; font-size: 12px; border: 1px solid #000;">Page : 1/1</th>
    </tr>
    <tr>
        <th colspan="8" style="text-align: center; font-size: 12px; font-style: italic; border: 1px solid #000;">Liste de distribution : Magasinier</th>
    </tr>
    <tr>
        <th colspan="8" style="text-align: left; font-size: 14px; color: #1d4ed8; border: 1px solid #000;">
            Article : {{ strtoupper($inventory->product->name) }} ({{ $inventory->product->unit_type }})
        </th>
    </tr>
    <tr><td colspan="8"></td></tr> {{-- SPACING --}}
    <tr>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Date</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Fournisseur</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">N° B.L</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: center;">Entrée</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: center;">Sorties</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: center;">Stock</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Observations</th>
        <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Bloc</th>
    </tr>
    </thead>
    <tbody>
    @php $runningStock = 0; @endphp
    @forelse ($movements as $movement)
        @php
            $ref = $movement->reference;
            $bloc = $ref?->bloc?->name;
            $isEntry = $movement->movement_type === 'in';
            // 'in'/'out' always store a positive magnitude (direction comes from movement_type);
            // 'adjustment' (from a stock count) already stores a SIGNED delta — treating it the
            // same as 'out' here would silently subtract a count INCREASE from the running
            // balance instead of adding it. Normalizing to one signed $delta fixes both the
            // math and which column (Entrée/Sorties) an adjustment correctly falls into.
            $delta = match ($movement->movement_type) {
                'in' => (float) $movement->quantity,
                'out' => -(float) $movement->quantity,
                default => (float) $movement->quantity,
            };
            $runningStock += $delta;
        @endphp
        <tr>
            <td style="border: 1px solid #000;">{{ \Carbon\Carbon::parse($movement->date)->format('d/m/Y') }}</td>
            <td style="border: 1px solid #000;">{{ $isEntry ? ($movement->supplier->name ?? '') : '' }}</td>
            <td style="border: 1px solid #000;">{{ $isEntry ? $movement->numero_bl : '' }}</td>
            <td style="border: 1px solid #000; text-align: center;">{{ $delta > 0 ? number_format($delta, 2, ',', ' ') : '' }}</td>
            <td style="border: 1px solid #000; text-align: center;">{{ $delta < 0 ? number_format(abs($delta), 2, ',', ' ') : '' }}</td>
            <td style="border: 1px solid #000; text-align: center; font-weight: bold;">{{ number_format($runningStock, 2, ',', ' ') }}</td>
            <td style="border: 1px solid #000;">{{ $movement->notes }}</td>
            <td style="border: 1px solid #000; text-align: center;">{{ $bloc }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="8" style="border: 1px solid #000; text-align: center; color: #6b7280;">Aucun mouvement enregistré pour ce produit.</td>
        </tr>
    @endforelse
    </tbody>
</table>
