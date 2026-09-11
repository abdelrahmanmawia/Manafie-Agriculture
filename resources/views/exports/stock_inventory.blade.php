@php
    // Same French labels as resources/js/utils/stockLabels.js's UNIT_TYPE_LABELS, kept in sync
    // by hand since a Blade export can't import the JS module.
    $unitTypeLabels = [
        'kg' => 'kg', 'tonnes' => 'tonnes', 'liters' => 'litres', 'meters' => 'mètres',
        'units' => 'unités', 'boxes' => 'boîtes', 'bags' => 'sacs', 'rolls' => 'rouleaux',
    ];

    // Filtered to one category: a flat list, no grouping needed (the title already says which
    // category this is). Otherwise: grouped by category, each as its own section header row —
    // mirrors the reference inventory sheet (category name as a row, not a repeated column).
    $groups = $categoryName
        ? ['' => $stockInventory]
        : $stockInventory->groupBy(fn ($item) => $item->product->category->name ?? 'Sans catégorie');
@endphp
<table>
    <thead>
    <tr>
        <th colspan="2" style="font-weight: bold; text-align: center; font-size: 18px; color: #15803d;">
            {{ $categoryName ? strtoupper($categoryName) : 'INVENTAIRE DU STOCK' }}
        </th>
    </tr>
    <tr>
        <th colspan="2" style="text-align: center; font-size: 12px; color: #6b7280;">
            Généré le {{ $generatedAt->format('d/m/Y H:i') }}
        </th>
    </tr>
    </thead>
    <tbody>
    @foreach ($groups as $groupName => $items)
        <tr><td colspan="2"></td></tr> {{-- SPACING --}}
        @if ($groupName !== '')
            <tr>
                <td colspan="2" style="font-weight: bold; background-color: #1e293b; color: #ffffff; border: 1px solid #000;">
                    {{ strtoupper($groupName) }}
                </td>
            </tr>
            <tr><td colspan="2"></td></tr> {{-- SPACING --}}
        @endif
        <tr>
            <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000;">Désignation</th>
            <th style="font-weight: bold; background-color: #f3f4f6; border: 1px solid #000; text-align: center;">Quantité</th>
        </tr>
        @foreach ($items as $item)
            <tr>
                <td style="border: 1px solid #000;">{{ $item->product->name }}</td>
                <td style="border: 1px solid #000; text-align: center;">
                    {{ number_format((float) $item->quantity_on_hand, 2, ',', ' ') }}
                    {{ $unitTypeLabels[$item->product->unit_type] ?? $item->product->unit_type }}
                </td>
            </tr>
        @endforeach
    @endforeach
    </tbody>
</table>
