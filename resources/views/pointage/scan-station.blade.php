<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="scan-station-token-url" content="{{ route('pointage.scan-station.token') }}">
    <title>Station de Scan — Pointage</title>
    @vite(['resources/js/scan-station.js'])
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            margin: 0;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
        }
        .wrap { max-width: 900px; margin: 0 auto; padding: 16px; }
        h1 { font-size: 18px; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 12px; }
        .card { background: #1e293b; border-radius: 12px; padding: 16px; margin-bottom: 14px; border: 1px solid #334155; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        select, button, input[type=text] {
            font-size: 14px; padding: 10px 12px; border-radius: 8px; border: 1px solid #475569;
            background: #0f172a; color: #e2e8f0;
        }
        button { cursor: pointer; font-weight: bold; background: #2563eb; border-color: #2563eb; color: white; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        button.secondary { background: #334155; border-color: #334155; }
        button.danger { background: #b91c1c; border-color: #b91c1c; }
        #scanInput { width: 100%; font-size: 20px; padding: 16px; text-align: center; letter-spacing: 1px; }
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
        .status-tile { background: #0f172a; border-radius: 8px; padding: 10px; text-align: center; border: 1px solid #334155; }
        .status-tile .num { font-size: 22px; font-weight: 900; }
        .status-tile .lbl { font-size: 10px; text-transform: uppercase; color: #94a3b8; letter-spacing: 1px; }
        #connState.online { color: #4ade80; }
        #connState.offline { color: #f87171; }
        #confirmBanner {
            text-align: center; font-size: 22px; font-weight: 900; padding: 18px; border-radius: 10px;
            margin-bottom: 14px; display: none;
        }
        #confirmBanner.ok { background: #14532d; color: #86efac; display: block; }
        #confirmBanner.err { background: #7f1d1d; color: #fca5a5; display: block; }
        #confirmBanner.dup { background: #78350f; color: #fde68a; display: block; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; text-transform: uppercase; font-size: 10px; }
        .reason { color: #f87171; font-size: 11px; }
        label { font-size: 11px; text-transform: uppercase; color: #94a3b8; display: block; margin-bottom: 4px; }
        .field { flex: 1; min-width: 160px; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>📡 Station de Scan — Pointage</h1>

        <div id="setupCard" class="card">
            <p>Avant de commencer, connectez-vous une fois en ligne pour télécharger les données de la station (employés, blocs, opérations) et un jeton d'accès.</p>
            <button id="setupBtn">Configurer la Station (en ligne)</button>
            <span id="setupStatus" style="margin-left:10px; font-size:12px;"></span>
        </div>

        <div class="card">
            <div class="status-grid">
                <div class="status-tile"><div class="num"><span id="connState" class="offline">…</span></div><div class="lbl">Connexion</div></div>
                <div class="status-tile"><div class="num" id="queuedCount">0</div><div class="lbl">En attente</div></div>
                <div class="status-tile"><div class="num" id="syncedCount">0</div><div class="lbl">Synchronisés</div></div>
                <div class="status-tile"><div class="num" id="failedCount">0</div><div class="lbl">Échecs</div></div>
                <div class="status-tile"><div class="num" id="lastSync" style="font-size:12px;">Jamais</div><div class="lbl">Dernière Sync</div></div>
            </div>
        </div>

        <div class="card">
            <div class="row">
                <div class="field">
                    <label>Bloc (aujourd'hui)</label>
                    <select id="blocSelect" style="width:100%"></select>
                </div>
                <div class="field">
                    <label>Opération (aujourd'hui)</label>
                    <select id="operationSelect" style="width:100%"></select>
                </div>
            </div>
        </div>

        <div id="confirmBanner"></div>

        <div class="card">
            <label for="scanInput">Scannez un badge</label>
            <input type="text" id="scanInput" autocomplete="off" placeholder="En attente du scan…">
        </div>

        <div class="card">
            <div class="row" style="justify-content: space-between; align-items:center; margin-bottom:10px;">
                <strong>File d'attente (avant synchronisation)</strong>
                <div class="row">
                    <button class="secondary" id="exportBtn">Exporter la File (JSON)</button>
                    <button id="syncBtn">Synchroniser</button>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>Employé</th><th>Bloc / Opération</th><th>Heure</th><th>Statut</th><th></th></tr>
                </thead>
                <tbody id="queueTableBody"></tbody>
            </table>
            <p id="emptyQueueMsg" style="color:#64748b; font-size:12px;">Aucun scan en attente.</p>
        </div>
    </div>
</body>
</html>
