@php
    // Same French labels as resources/js/utils/stockLabels.js's UNIT_TYPE_LABELS.
    $unitTypeLabels = [
        'kg' => 'kg', 'tonnes' => 'tonnes', 'liters' => 'litres', 'meters' => 'mètres',
        'units' => 'unités', 'boxes' => 'boîtes', 'bags' => 'sacs', 'rolls' => 'rouleaux',
    ];
    $cell = 'padding: 6px; border: 1px solid #000;';
    $columns = $aveugle ? 4 : 5;
@endphp
{{-- The sheet the magasinier fills by hand, then types into Inventaire > Comptage. "Aveugle"
     leaves out the book quantity so the count isn't steered by it. --}}
<table style="width: 100%; border-collapse: collapse; font-size: 12px;">
    <colgroup>
        <col style="width: {{ $aveugle ? 50 : 42 }}%;">
        <col style="width: 10%;">
        @unless ($aveugle)<col style="width: 14%;">@endunless
        <col style="width: 16%;">
        <col style="width: {{ $aveugle ? 24 : 18 }}%;">
    </colgroup>
    <thead>
    <tr>
        <th colspan="{{ $columns - 2 }}" style="text-align: left; font-size: 15px; {{ $cell }}">{{ $farm->name ?? '' }}</th>
        <th colspan="2" style="text-align: right; font-size: 10px; {{ $cell }}">Imprimée le {{ $generatedAt->format('d/m/Y H:i') }}</th>
    </tr>
    <tr>
        <th colspan="{{ $columns }}" style="text-align: center; font-size: 18px; font-weight: bold; {{ $cell }}">
            Fiche de comptage{{ $categoryName ? ' — '.mb_strtoupper($categoryName) : '' }}
        </th>
    </tr>
    <tr>
        <th colspan="{{ $columns }}" style="text-align: left; font-size: 12px; font-weight: normal; padding: 10px 6px; border: 1px solid #000;">
            Date du comptage : ____ / ____ / ________ &nbsp;&nbsp;&nbsp; Compté par : ______________________ &nbsp;&nbsp;&nbsp; Signature : ______________
        </th>
    </tr>
    <tr>
        <th style="text-align: left; background-color: #f3f4f6; {{ $cell }}">Désignation</th>
        <th style="background-color: #f3f4f6; {{ $cell }}">Unité</th>
        @unless ($aveugle)<th style="background-color: #f3f4f6; {{ $cell }}">Stock système</th>@endunless
        <th style="background-color: #f3f4f6; {{ $cell }}">Quantité comptée</th>
        <th style="background-color: #f3f4f6; {{ $cell }}">Observations</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($groups as $groupName => $lignes)
        @if (! $categoryName)
            <tr>
                <td colspan="{{ $columns }}" style="font-weight: bold; background-color: #1e293b; color: #ffffff; {{ $cell }}">{{ mb_strtoupper($groupName) }}</td>
            </tr>
        @endif
        @foreach ($lignes as $ligne)
            <tr>
                <td style="{{ $cell }}">{{ $ligne['name'] }}</td>
                <td style="text-align: center; {{ $cell }}">{{ $unitTypeLabels[$ligne['unit_type']] ?? $ligne['unit_type'] }}</td>
                @unless ($aveugle)
                    <td style="text-align: right; {{ $cell }}">{{ number_format($ligne['quantity'], 2, ',', ' ') }}</td>
                @endunless
                <td style="height: 22px; {{ $cell }}"></td>
                <td style="{{ $cell }}"></td>
            </tr>
        @endforeach
    @endforeach
    </tbody>
</table>
