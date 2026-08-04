<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; margin: 0; }
        .grid { width: 100%; }
        .badge {
            display: inline-block;
            width: 47%;
            margin: 1.5%;
            border: 2px solid #1e40af;
            border-radius: 10px;
            padding: 10px;
            box-sizing: border-box;
            page-break-inside: avoid;
        }
        .badge-header { text-align: center; border-bottom: 1px solid #dbeafe; padding-bottom: 6px; margin-bottom: 6px; }
        .badge-title { font-size: 10px; font-weight: bold; text-transform: uppercase; color: #1e40af; letter-spacing: 1px; }
        .badge-body { width: 100%; }
        .photo-cell { width: 60px; display: inline-block; vertical-align: top; }
        .photo { width: 55px; height: 55px; object-fit: cover; border-radius: 6px; border: 1px solid #cbd5e1; }
        .photo-placeholder {
            width: 55px; height: 55px; border-radius: 6px; border: 1px dashed #cbd5e1;
            text-align: center; line-height: 55px; font-size: 8px; color: #94a3b8;
        }
        .qr-cell { width: 70px; display: inline-block; vertical-align: top; text-align: center; }
        .info-cell { display: inline-block; vertical-align: top; padding-left: 8px; }
        .full-name { font-size: 13px; font-weight: bold; color: #111827; }
        .matricule { font-size: 11px; color: #6b7280; margin-top: 2px; }
        .matricule b { color: #1e40af; }
    </style>
</head>
<body>
    <div class="grid">
        @foreach ($employees as $employee)
            <div class="badge">
                <div class="badge-header">
                    <span class="badge-title">Badge de Pointage</span>
                </div>
                <div class="badge-body">
                    <div class="photo-cell">
                        @if ($employee->photo_path && file_exists(storage_path('app/public/' . $employee->photo_path)))
                            <img class="photo" src="{{ storage_path('app/public/' . $employee->photo_path) }}">
                        @else
                            <div class="photo-placeholder">Photo</div>
                        @endif
                    </div>
                    <div class="info-cell">
                        <div class="full-name">{{ $employee->full_name }}</div>
                        <div class="matricule">Matricule : <b>{{ $employee->matricule }}</b></div>
                    </div>
                    <div class="qr-cell">
                        {{-- dompdf in this environment doesn't render inline <svg> at all (confirmed
                             even for a trivial static <svg>, unrelated to the QR library) — render a
                             real PNG via GD (no Imagick available) and embed it as a data URI instead. --}}
                        <img src="{{ $qrDataUris[$employee->id] }}" width="65" height="65">
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</body>
</html>
