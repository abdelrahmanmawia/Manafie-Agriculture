import { openDB } from 'idb';

const DB_NAME = 'pointage-scan-station';
const DB_VERSION = 1;
const DUPLICATE_WINDOW_MS = 8000;
const BANNER_HIDE_MS = 3000;

function dbPromise() {
    return openDB(DB_NAME, DB_VERSION, {
        upgrade(db) {
            if (!db.objectStoreNames.contains('stationData')) {
                db.createObjectStore('stationData');
            }
            if (!db.objectStoreNames.contains('scanQueue')) {
                db.createObjectStore('scanQueue', { keyPath: 'scan_uuid' });
            }
            if (!db.objectStoreNames.contains('syncedLog')) {
                db.createObjectStore('syncedLog', { keyPath: 'scan_uuid' });
            }
        },
    });
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

let audioCtx;
function beep(freq, durationMs, delayMs = 0) {
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        const startAt = audioCtx.currentTime + delayMs / 1000;
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.frequency.value = freq;
        osc.type = 'sine';
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        gain.gain.setValueAtTime(0.2, startAt);
        osc.start(startAt);
        osc.stop(startAt + durationMs / 1000);
    } catch (e) {
        // Web Audio unavailable/blocked — scanning still works, just silently.
    }
}
const beepSuccess = () => beep(880, 120);
const beepError = () => beep(220, 320);
// Distinct two-tap tone so a rejected re-scan (same badge, same worker) doesn't
// sound identical to a real error (unknown badge, missing bloc/opération) —
// the operator needs to tell "already logged" from "something's wrong" by ear.
const beepDuplicate = () => { beep(440, 90); beep(440, 90, 140); };

document.addEventListener('DOMContentLoaded', () => {
    const scanInput = document.getElementById('scanInput');
    const blocSelect = document.getElementById('blocSelect');
    const operationSelect = document.getElementById('operationSelect');
    const setupBtn = document.getElementById('setupBtn');
    const setupStatus = document.getElementById('setupStatus');
    const syncBtn = document.getElementById('syncBtn');
    const exportBtn = document.getElementById('exportBtn');
    const confirmBanner = document.getElementById('confirmBanner');
    const connState = document.getElementById('connState');
    const queueTableBody = document.getElementById('queueTableBody');
    const emptyQueueMsg = document.getElementById('emptyQueueMsg');
    const syncStatus = document.getElementById('syncStatus');

    function refocus() {
        scanInput.focus();
    }

    let bannerTimer;
    function showBanner(type, message) {
        confirmBanner.className = type;
        confirmBanner.textContent = message;
        confirmBanner.style.display = 'block';
        clearTimeout(bannerTimer);
        bannerTimer = setTimeout(() => {
            confirmBanner.className = '';
            confirmBanner.style.display = 'none';
        }, BANNER_HIDE_MS);
    }

    function updateConnState() {
        if (navigator.onLine) {
            connState.textContent = 'En ligne';
            connState.className = 'online';
        } else {
            connState.textContent = 'Hors ligne';
            connState.className = 'offline';
        }
    }

    async function loadStationData() {
        const db = await dbPromise();
        const snapshot = await db.get('stationData', 'snapshot');
        if (!snapshot) {
            setupStatus.textContent = 'Station non configurée — cliquez "Configurer" en ligne.';
            scanInput.disabled = true;
            return;
        }
        scanInput.disabled = false;

        blocSelect.innerHTML = '<option value="">-- Bloc --</option>' +
            (snapshot.blocs || []).map((b) => `<option value="${b.id}">${escapeHtml(b.name)}</option>`).join('');
        operationSelect.innerHTML = '<option value="">-- Opération --</option>' +
            (snapshot.operations || []).map((o) => `<option value="${o.id}">${escapeHtml(o.name)}</option>`).join('');

        const lastBloc = await db.get('stationData', 'lastBlocId');
        const lastOp = await db.get('stationData', 'lastOperationId');
        if (lastBloc) blocSelect.value = lastBloc;
        if (lastOp) operationSelect.value = lastOp;

        setupStatus.textContent = `✓ Configuré (${(snapshot.employees || []).length} employés) — chargé le ${new Date(snapshot.cachedAt).toLocaleString()}`;
    }

    async function refreshUI() {
        const db = await dbPromise();
        const queue = await db.getAll('scanQueue');
        const synced = await db.getAll('syncedLog');
        const todayStr = new Date().toISOString().slice(0, 10);
        const syncedToday = synced.filter((s) => (s.synced_at_iso || '').slice(0, 10) === todayStr).length;
        const failed = queue.filter((q) => q.status === 'failed').length;
        const lastSync = await db.get('stationData', 'lastSync');

        document.getElementById('queuedCount').textContent = queue.length;
        document.getElementById('syncedCount').textContent = syncedToday;
        document.getElementById('failedCount').textContent = failed;
        document.getElementById('lastSync').textContent = lastSync ? new Date(lastSync).toLocaleTimeString() : 'Jamais';

        queueTableBody.innerHTML = '';
        queue.sort((a, b) => b.client_ts - a.client_ts).forEach((entry) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escapeHtml(entry.employee_name)}<br><small>${escapeHtml(entry.employee_matricule || '')}</small></td>
                <td>${escapeHtml(entry.bloc_name || '')} / ${escapeHtml(entry.operation_name || '')}</td>
                <td>${new Date(entry.scanned_at).toLocaleTimeString()}</td>
                <td>${entry.status === 'failed' ? `<span class="reason">${escapeHtml(entry.reason || 'Échec')}</span>` : 'En attente'}</td>
                <td><button class="danger" data-uuid="${entry.scan_uuid}">Supprimer</button></td>
            `;
            queueTableBody.appendChild(tr);
        });
        emptyQueueMsg.style.display = queue.length ? 'none' : 'block';

        queueTableBody.querySelectorAll('button[data-uuid]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const db2 = await dbPromise();
                await db2.delete('scanQueue', btn.dataset.uuid);
                await refreshUI();
                refocus();
            });
        });
    }

    async function handleScan(badgeUuid) {
        const db = await dbPromise();
        const snapshot = await db.get('stationData', 'snapshot');
        if (!snapshot) {
            showBanner('err', '✗ Station non configurée — connectez-vous en ligne d\'abord.');
            beepError();
            return;
        }

        const employee = (snapshot.employees || []).find((e) => e.badge_uuid === badgeUuid);
        if (!employee) {
            showBanner('err', `✗ Badge inconnu (${badgeUuid})`);
            beepError();
            refocus();
            return;
        }

        const blocId = blocSelect.value;
        const operationId = operationSelect.value;
        if (!blocId || !operationId) {
            showBanner('err', '✗ Sélectionnez un Bloc et une Opération avant de scanner.');
            beepError();
            refocus();
            return;
        }

        const now = Date.now();
        const [queued, synced] = await Promise.all([db.getAll('scanQueue'), db.getAll('syncedLog')]);
        const recentlyScanned = queued.find((s) => s.badge_uuid === badgeUuid && (now - s.client_ts) < DUPLICATE_WINDOW_MS)
            || synced.find((s) => s.badge_uuid === badgeUuid && (now - s.synced_at) < DUPLICATE_WINDOW_MS);
        if (recentlyScanned) {
            showBanner('dup', `⏱ ${employee.full_name} — déjà scanné il y a quelques secondes`);
            beepDuplicate();
            refocus();
            return;
        }

        const bloc = (snapshot.blocs || []).find((b) => String(b.id) === String(blocId));
        const operation = (snapshot.operations || []).find((o) => String(o.id) === String(operationId));

        await db.add('scanQueue', {
            scan_uuid: crypto.randomUUID(),
            badge_uuid: badgeUuid,
            bloc_id: blocId,
            operation_id: operationId,
            scanned_at: new Date().toISOString(),
            client_ts: now,
            employee_name: employee.full_name,
            employee_matricule: employee.matricule,
            bloc_name: bloc ? bloc.name : '',
            operation_name: operation ? operation.name : '',
            status: 'queued',
        });

        showBanner('ok', `✓ ${employee.full_name}`);
        beepSuccess();
        await refreshUI();
        refocus();
    }

    async function doSync() {
        const db = await dbPromise();
        const snapshot = await db.get('stationData', 'snapshot');
        if (!snapshot || !snapshot.token) {
            showBanner('err', '✗ Configurez la station en ligne avant de synchroniser.');
            return;
        }

        const queue = await db.getAll('scanQueue');
        if (queue.length === 0) {
            syncStatus.textContent = '';
            return;
        }

        syncStatus.textContent = 'Synchronisation…';
        syncStatus.className = '';

        const payload = queue.map((q) => ({
            scan_uuid: q.scan_uuid,
            badge_uuid: q.badge_uuid,
            scanned_at: q.scanned_at,
            bloc_id: q.bloc_id,
            operation_id: q.operation_id,
        }));

        try {
            const res = await fetch('/api/pointage/scan-station/sync', {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${snapshot.token}`,
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ scans: payload }),
            });
            if (!res.ok) {
                throw new Error(`sync-http-${res.status}`);
            }
            const { results } = await res.json();

            for (const result of results) {
                const original = queue.find((q) => q.scan_uuid === result.scan_uuid);
                if (!original) continue;
                if (result.ok) {
                    await db.delete('scanQueue', result.scan_uuid);
                    await db.put('syncedLog', {
                        ...original,
                        status: 'synced',
                        synced_at: Date.now(),
                        synced_at_iso: new Date().toISOString(),
                    });
                } else {
                    await db.put('scanQueue', { ...original, status: 'failed', reason: result.reason });
                }
            }
            await db.put('stationData', Date.now(), 'lastSync');
            const stillFailed = (await db.getAll('scanQueue')).filter((q) => q.status === 'failed').length;
            syncStatus.textContent = stillFailed
                ? `⚠ ${stillFailed} scan(s) rejeté(s) par le serveur — voir la file ci-dessous`
                : `✓ Synchronisé à ${new Date().toLocaleTimeString()}`;
            syncStatus.className = stillFailed ? 'warn' : 'ok';
        } catch (e) {
            // Network/server unreachable — the queue is left untouched so the next manual
            // click, 'online' event, or periodic auto-retry picks it back up, but the
            // operator needs to actually see that it didn't go through.
            syncStatus.textContent = '✗ Synchronisation impossible — nouvelle tentative automatique en cours.';
            syncStatus.className = 'err';
        }
        await refreshUI();
    }

    async function exportQueue() {
        const db = await dbPromise();
        const [queue, synced] = await Promise.all([db.getAll('scanQueue'), db.getAll('syncedLog')]);
        const blob = new Blob([JSON.stringify({
            exported_at: new Date().toISOString(),
            queued_scans: queue,
            synced_scans: synced,
        }, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `scan-station-backup-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.json`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    setupBtn.addEventListener('click', async () => {
        setupStatus.textContent = 'Connexion…';
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const tokenUrl = document.querySelector('meta[name="scan-station-token-url"]').content;

            const tokenRes = await fetch(tokenUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!tokenRes.ok) throw new Error('token-fetch-failed');
            const { token } = await tokenRes.json();

            const dataRes = await fetch('/api/pointage/scan-station/station-data', {
                headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
            });
            if (!dataRes.ok) throw new Error('station-data-fetch-failed');
            const stationData = await dataRes.json();

            const db = await dbPromise();
            await db.put('stationData', { token, ...stationData, cachedAt: Date.now() }, 'snapshot');

            await loadStationData();
        } catch (e) {
            setupStatus.textContent = '✗ Échec de la configuration — réessayez en ligne.';
        }
    });

    scanInput.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const code = scanInput.value.trim();
        scanInput.value = '';
        if (code) handleScan(code);
    });

    scanInput.addEventListener('blur', () => {
        setTimeout(() => {
            const active = document.activeElement;
            const tag = active && active.tagName;
            if (tag !== 'SELECT' && tag !== 'INPUT' && tag !== 'BUTTON' && tag !== 'TEXTAREA') {
                refocus();
            }
        }, 300);
    });
    window.addEventListener('focus', () => setTimeout(refocus, 200));

    blocSelect.addEventListener('change', async () => {
        const db = await dbPromise();
        await db.put('stationData', blocSelect.value, 'lastBlocId');
        refocus();
    });
    operationSelect.addEventListener('change', async () => {
        const db = await dbPromise();
        await db.put('stationData', operationSelect.value, 'lastOperationId');
        refocus();
    });

    syncBtn.addEventListener('click', doSync);
    exportBtn.addEventListener('click', exportQueue);
    window.addEventListener('online', () => { updateConnState(); doSync(); });
    window.addEventListener('offline', updateConnState);

    // navigator.onLine only reflects the network interface, not the server being
    // reachable — a scan queued during a brief server-side hiccup would otherwise sit
    // stuck until someone happens to click "Synchroniser" again. Retry periodically
    // instead of relying solely on the 'online' event or a manual tap.
    setInterval(() => {
        if (navigator.onLine) doSync();
    }, 30000);

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/scan-station-sw.js').catch(() => {});
    }

    loadStationData();
    refreshUI();
    updateConnState();
    refocus();
});
