<table style="width: 100%; border-collapse: collapse; font-size: 11px;">
    <colgroup>
        <col style="width: 10%;">
        <col style="width: 14%;">
        <col style="width: 10%;">
        <col style="width: 9%;">
        <col style="width: 9%;">
        <col style="width: 10%;">
        <col style="width: 26%;">
        <col style="width: 12%;">
    </colgroup>
    <thead>
    <tr>
        <th colspan="3" style="text-align: left; font-size: 16px; padding: 8px; border: 1px solid #000;">{{ $farm->name ?? '' }}</th>
        <th colspan="3" style="text-align: center; font-size: 20px; font-weight: bold; padding: 8px; border: 1px solid #000;">Fiche de Stock</th>
        <th colspan="2" style="text-align: right; font-size: 12px; padding: 8px; border: 1px solid #000;">Page : 1/1</th>
    </tr>
    <tr>
        <th colspan="8" style="text-align: center; font-size: 12px; font-style: italic; padding: 8px; border: 1px solid #000;">Liste de distribution : Magasinier</th>
    </tr>
    <tr>
        <th colspan="8" style="text-align: left; font-size: 14px; color: #1d4ed8; padding: 8px; border: 1px solid #000;">
            Article : {{ strtoupper($inventory->product->name) }} ({{ $inventory->product->unit_type }})
        </th>
    </tr>
    <tr><td colspan="8" style="padding: 4px;"></td></tr> {{-- SPACING --}}
    <tr>
        <th style="font-weight: bold; background-color: #f3f4f6; padding: 6px; border: 1px solid #000;">Date</th>
        <th style="font-weight: bold; background-color: #f3f4f6; padding: 6px; border: 1px solid #000;">Fournisseur</th>
        <th style="font-weight: bold; background-color: #f3f4f6; padding: 6px; border: 1px solid #000;">N° B.L</th>
        <th style="font-weight: bold; background-color: #f3f4f6; padding: 6px; border: 1px solid #000; text-align: center;">Entrée</th>
        <th style="font-weight: bold; background-color: #f3f4f6; padding: 6px; border: 1px solid #000; text-align: center;">Sorties</th>
        <th style="font-weight: bold; background-color: #f3f4f6; padding: 6px; border: 1px solid #000; text-align: center;">Stock</th>
        <th style="font-weight: bold; background-color: #f3f4f6; padding: 6px; border: 1px solid #000;">Observations</th>
        <th style="font-weight: bold; background-color: #f3f4f6; padding: 6px; border: 1px solid #000;">Bloc</th>
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
            // A sortie left with no note shows its own type (Consommation/Perte/...) instead of
            // a blank cell — same label the "Type de Sortie" dropdown uses for that key. Only
            // 'manual_entry'-referenced movements (sorties) have an entry_type at all.
            $entryType = $ref?->entry_type ?? null;
            $observations = trim((string) $movement->notes) !== ''
                ? $movement->notes
                : ($entryType ? ($exitTypesByKey[$entryType] ?? $entryType) : '');
        @endphp
        <tr>
            <td style="padding: 6px; border: 1px solid #000;">{{ \Carbon\Carbon::parse($movement->date)->format('d/m/Y') }}</td>
            <td style="padding: 6px; border: 1px solid #000;">{{ $isEntry ? ($movement->supplier->name ?? '') : '' }}</td>
            <td style="padding: 6px; border: 1px solid #000;">{{ $isEntry ? $movement->numero_bl : '' }}</td>
            <td style="padding: 6px; text-align: center; border: 1px solid #000;">{{ $delta > 0 ? number_format($delta, 2, ',', ' ') : '' }}</td>
            <td style="padding: 6px; text-align: center; border: 1px solid #000;">{{ $delta < 0 ? number_format(abs($delta), 2, ',', ' ') : '' }}</td>
            <td style="padding: 6px; text-align: center; font-weight: bold; border: 1px solid #000;">{{ number_format($runningStock, 2, ',', ' ') }}</td>
            <td style="padding: 6px; border: 1px solid #000;">{{ $observations }}</td>
            <td style="padding: 6px; text-align: center; border: 1px solid #000;">{{ $bloc }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="8" style="padding: 8px; text-align: center; color: #6b7280; border: 1px solid #000;">Aucun mouvement enregistré pour ce produit.</td>
        </tr>
    @endforelse
    </tbody>
</table>
