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
{{-- Only 2 real columns (Désignation/Quantité) — without an explicit width dompdf shrinks the
     table to its content instead of spanning the page, which is what made this look "tiny". --}}
<table style="width: 100%; border-collapse: collapse; font-size: 14px;">
    <colgroup>
        <col style="width: 70%;">
        <col style="width: 30%;">
    </colgroup>
    <thead>
    <tr>
        <th style="text-align: left; font-size: 16px; padding: 8px; border: 1px solid #000;">{{ $farm->name ?? '' }}</th>
        <th style="text-align: right; font-size: 12px; padding: 8px; border: 1px solid #000;">Page : 1/1</th>
    </tr>
    <tr>
        <th colspan="2" style="text-align: center; font-size: 20px; font-weight: bold; padding: 10px; border: 1px solid #000;">Fiche de Stock</th>
    </tr>
    <tr>
        <th colspan="2" style="text-align: center; font-size: 12px; font-style: italic; padding: 8px; border: 1px solid #000;">Liste de distribution : Magasinier</th>
    </tr>
    <tr>
        <th colspan="2" style="text-align: left; font-size: 14px; color: #1d4ed8; padding: 8px; border: 1px solid #000;">
            {{ $categoryName ? 'Catégorie : ' . strtoupper($categoryName) : 'Inventaire Général' }}
        </th>
    </tr>
    <tr>
        <th colspan="2" style="text-align: center; font-size: 11px; color: #6b7280; padding: 6px; border: 1px solid #000;">
            Généré le {{ $generatedAt->format('d/m/Y H:i') }}
        </th>
    </tr>
    </thead>
    <tbody>
    @foreach ($groups as $groupName => $items)
        <tr><td colspan="2" style="padding: 4px;"></td></tr> {{-- SPACING --}}
        @if ($groupName !== '')
            <tr>
                <td colspan="2" style="font-weight: bold; font-size: 15px; background-color: #1e293b; color: #ffffff; padding: 8px; border: 1px solid #000;">
                    {{ strtoupper($groupName) }}
                </td>
            </tr>
            <tr><td colspan="2" style="padding: 4px;"></td></tr> {{-- SPACING --}}
        @endif
        <tr>
            <th style="font-weight: bold; background-color: #f3f4f6; padding: 8px; border: 1px solid #000;">Désignation</th>
            <th style="font-weight: bold; background-color: #f3f4f6; text-align: center; padding: 8px; border: 1px solid #000;">Quantité</th>
        </tr>
        @foreach ($items as $item)
            <tr>
                <td style="padding: 8px; border: 1px solid #000;">{{ $item->product->name }}</td>
                <td style="padding: 8px; text-align: center; border: 1px solid #000;">
                    {{ number_format((float) $item->quantity_on_hand, 2, ',', ' ') }}
                    {{ $unitTypeLabels[$item->product->unit_type] ?? $item->product->unit_type }}
                </td>
            </tr>
        @endforeach
    @endforeach
    </tbody>
</table>
