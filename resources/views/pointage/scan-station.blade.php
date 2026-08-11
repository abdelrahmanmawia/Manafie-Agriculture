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
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
        }
        .wrap { max-width: 900px; margin: 0 auto; padding: 16px; }
        h1 { font-size: 22px; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 12px; }
        .card { background: #ffffff; border-radius: 12px; padding: 16px; margin-bottom: 14px; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        select, button, input[type=text] {
            font-size: 16px; padding: 12px 14px; border-radius: 8px; border: 1px solid #cbd5e1;
            background: #ffffff; color: #1e293b;
        }
        button { cursor: pointer; font-weight: bold; background: #2563eb; border-color: #2563eb; color: white; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        button.secondary { background: #e2e8f0; border-color: #cbd5e1; color: #1e293b; }
        button.danger { background: #dc2626; border-color: #dc2626; }
        #scanInput { width: 100%; font-size: 20px; padding: 16px; text-align: center; letter-spacing: 1px; }
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
        .status-tile { background: #f8fafc; border-radius: 8px; padding: 14px 10px; text-align: center; border: 1px solid #e2e8f0; }
        .status-tile .num { font-size: 30px; font-weight: 900; }
        .status-tile .lbl { font-size: 13px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; }
        #connState.online { color: #16a34a; }
        #connState.offline { color: #dc2626; }
        #confirmBanner {
            text-align: center; font-size: 28px; font-weight: 900; padding: 22px; border-radius: 10px;
            margin-bottom: 14px; display: none;
        }
        #confirmBanner.ok { background: #dcfce7; color: #15803d; display: block; }
        #confirmBanner.err { background: #fee2e2; color: #b91c1c; display: block; }
        #confirmBanner.dup { background: #fef3c7; color: #92400e; display: block; }
        #syncStatus { font-size: 15px; font-weight: bold; margin-left: 12px; }
        #syncStatus.ok { color: #16a34a; }
        #syncStatus.err { color: #dc2626; }
        #syncStatus.warn { color: #d97706; }
        table { width: 100%; border-collapse: collapse; font-size: 16px; }
        th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #e2e8f0; }
        th { color: #64748b; text-transform: uppercase; font-size: 13px; }
        .reason { color: #dc2626; font-size: 14px; }
        label { font-size: 14px; text-transform: uppercase; color: #64748b; display: block; margin-bottom: 4px; }
        .field { flex: 1; min-width: 160px; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>📡 Station de Scan — Pointage</h1>

        <div id="setupCard" class="card">
            <p>Avant de commencer, connectez-vous une fois en ligne pour télécharger les données de la station (employés, blocs, opérations) et un jeton d'accès.</p>
            <button id="setupBtn">Configurer la Station (en ligne)</button>
            <span id="setupStatus" style="margin-left:10px; font-size:15px;"></span>
        </div>

        <div class="card">
            <div class="status-grid">
                <div class="status-tile"><div class="num"><span id="connState" class="offline">…</span></div><div class="lbl">Connexion</div></div>
                <div class="status-tile"><div class="num" id="queuedCount">0</div><div class="lbl">En attente</div></div>
                <div class="status-tile"><div class="num" id="syncedCount">0</div><div class="lbl">Synchronisés</div></div>
                <div class="status-tile"><div class="num" id="failedCount">0</div><div class="lbl">Échecs</div></div>
                <div class="status-tile"><div class="num" id="lastSync" style="font-size:16px;">Jamais</div><div class="lbl">Dernière Sync</div></div>
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
                <div class="row" style="align-items:center;">
                    <button class="secondary" id="exportBtn">Exporter la File (JSON)</button>
                    <button id="syncBtn">Synchroniser</button>
                    <span id="syncStatus"></span>
                </div>
            </div>
            <table>
                <thead>
                    <tr><th>Employé</th><th>Bloc / Opération</th><th>Heure</th><th>Statut</th><th></th></tr>
                </thead>
                <tbody id="queueTableBody"></tbody>
            </table>
            <p id="emptyQueueMsg" style="color:#64748b; font-size:14px;">Aucun scan en attente.</p>
        </div>
    </div>
</body>
</html>
