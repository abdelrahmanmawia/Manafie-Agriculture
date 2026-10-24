import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { formatNumber } from '@/Helpers/formatNumber';

const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

// Dates arrive as "2026-10-16T00:00:00.000000Z"; only the calendar day matters, so it's read
// from the string itself (never through a Date, which would shift it by the time zone).
const day = (d) => (d || '').slice(0, 10);
const ymd = (y, m, dd) => `${y}-${String(m).padStart(2, '0')}-${String(dd).padStart(2, '0')}`;
const formatDate = (d) => {
    const [y, m, dd] = day(d).split('-').map(Number);
    return y ? `${String(dd).padStart(2, '0')} ${MONTHS[m - 1].slice(0, 4)}. ${y}` : '';
};
// The farm's own way of naming a period: "1ERE Qz octobre 2026" / "2EME Qz octobre 2026".
const periodLabel = (start) => {
    const [y, m, dd] = day(start).split('-').map(Number);
    return `${dd <= 15 ? '1ERE' : '2EME'} Qz ${MONTHS[m - 1]} ${y}`;
};
// The period after the latest one: 1–15, then 16–end of month.
const nextPeriod = (quinzaines) => {
    const latest = quinzaines.map(q => day(q.end_date)).sort().pop();
    if (!latest) return { start_date: '', end_date: '', label: '' };
    let [y, m, dd] = latest.split('-').map(Number);
    let start;
    let end;
    if (dd <= 15) {
        start = ymd(y, m, 16);
        end = ymd(y, m, new Date(y, m, 0).getDate());
    } else {
        if (++m > 12) { m = 1; y++; }
        start = ymd(y, m, 1);
        end = ymd(y, m, 15);
    }
    return { start_date: start, end_date: end, label: periodLabel(start) };
};

function Stat({ label, value, tone = 'gray' }) {
    const tones = { gray: 'text-gray-800', green: 'text-green-700', red: 'text-red-600' };
    return (
        <div className="min-w-[64px]">
            <div className="text-[9px] font-black uppercase tracking-widest text-gray-400">{label}</div>
            <div className={`text-sm font-black ${tones[tone]}`}>{value}</div>
        </div>
    );
}

// One card per pay period (its dates), one line per division inside it: the farm points a
// period for all divisions together — presence per équipe first (one Grid across divisions),
// then the operations division by division — so that's how the page is laid out.
export default function Quinzaines({ auth, quinzaines, enterprises }) {
    const { props } = usePage();
    const pageErrors = props.errors || {};

    // Mirrors PointageController::exportAllDivisions()'s $isFarmScopedPointageDataEntry check —
    // a data_entry locked to one division must stay blocked (it would leak sibling divisions'
    // payroll), but a farm-scoped one with Pointage access is exactly like a farm_manager here.
    const canExportAllDivisions = auth.user.role === 'super_admin' || auth.user.role === 'farm_manager'
        || (auth.user.role === 'data_entry' && auth.user.can_access_pointage && !auth.user.enterprise_id);
    const canManage = auth.user.role !== 'data_entry' || auth.user.can_access_pointage;
    // A division-locked user gets no list (selectableEnterprises()) and only ever opens their own.
    const divisionChoices = enterprises || [];

    const [isCreating, setIsCreating] = useState(false);
    const [editingQuinzaine, setEditingQuinzaine] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [closing, setClosing] = useState(null);
    const [closingPeriod, setClosingPeriod] = useState(null);
    const [busy, setBusy] = useState(false);
    const [filterEnterpriseId, setFilterEnterpriseId] = useState(props.ziggy?.query?.enterprise_id || '');

    const createForm = useForm({ label: '', start_date: '', end_date: '', enterprise_ids: [] });
    const editForm = useForm({ label: '', start_date: '', end_date: '' });

    // Excel/PDF exports are plain <a target="_blank"> links, not Inertia visits — there's no
    // JS-observable "download finished" event to hook into, so this just gives brief visual
    // feedback that the click registered while the file generates server-side.
    const [downloadingKey, setDownloadingKey] = useState(null);
    const triggerDownload = (key) => {
        setDownloadingKey(key);
        setTimeout(() => setDownloadingKey(prev => (prev === key ? null : prev)), 2500);
    };

    const periods = useMemo(() => {
        const map = new Map();
        quinzaines.forEach(q => {
            const key = `${day(q.start_date)}_${day(q.end_date)}`;
            if (!map.has(key)) map.set(key, { key, start_date: q.start_date, end_date: q.end_date, quinzaines: [] });
            map.get(key).quinzaines.push(q);
        });
        return [...map.values()]
            .sort((a, b) => day(b.start_date).localeCompare(day(a.start_date)))
            .map(p => ({ ...p, quinzaines: p.quinzaines.sort((a, b) => (a.enterprise?.name || '').localeCompare(b.enterprise?.name || '')) }));
    }, [quinzaines]);

    // Which periods to show: '' all, 'open' / 'closed', or one period's key (client-side — the
    // page already has them all; the division filter is the server-side one).
    const [periodFilter, setPeriodFilter] = useState('');
    const shownPeriods = periods.filter(p => periodFilter === ''
        || (periodFilter === 'open' ? p.quinzaines.some(q => !q.is_closed)
            : periodFilter === 'closed' ? p.quinzaines.every(q => q.is_closed)
                : p.key === periodFilter));

    const openCreate = () => {
        createForm.clearErrors();
        createForm.setData({ ...nextPeriod(quinzaines), enterprise_ids: divisionChoices.length ? divisionChoices.map(e => e.id) : [auth.user.enterprise_id].filter(Boolean) });
        setIsCreating(true);
    };
    const toggleDivision = (id) => createForm.setData('enterprise_ids', createForm.data.enterprise_ids.includes(id)
        ? createForm.data.enterprise_ids.filter(x => x !== id)
        : [...createForm.data.enterprise_ids, id]);
    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('settings.quinzaine'), { preserveScroll: true, onSuccess: () => setIsCreating(false) });
    };

    const openEdit = (q) => {
        editForm.clearErrors();
        editForm.setData({ label: q.label || '', start_date: day(q.start_date), end_date: day(q.end_date) });
        setEditingQuinzaine(q);
    };
    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('settings.quinzaine.update', editingQuinzaine.id), { preserveScroll: true, onSuccess: () => setEditingQuinzaine(null) });
    };

    // Delete / close: a refusal comes back as errors.quinzaine, shown in the banner above.
    const run = (method, url, close) => {
        setBusy(true);
        const options = { preserveScroll: true, onFinish: () => { setBusy(false); close(); } };
        if (method === 'delete') router.delete(url, options);
        else router.post(url, {}, options);
    };

    const handleFilterChange = (e) => {
        setFilterEnterpriseId(e.target.value);
        router.get(route('pointage.quinzaines'), { enterprise_id: e.target.value || undefined }, { preserveState: true, preserveScroll: true });
    };

    const nameOf = (q) => q.label || `${formatDate(q.start_date)} — ${formatDate(q.end_date)}`;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Gestion des Quinzaines</h2>
                    {canManage && (
                        <button onClick={openCreate} className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2">
                            <span>+</span> Ouvrir une Période
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Quinzaines" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {pageErrors.quinzaine && (
                        <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                            {pageErrors.quinzaine}
                        </div>
                    )}

                    {periods.length > 0 && (
                        <div className="flex flex-wrap items-center justify-end gap-3">
                            <span className="flex items-center gap-2">
                            <label htmlFor="period_filter_select" className="text-sm font-medium text-gray-700 whitespace-nowrap">Période :</label>
                            <select id="period_filter_select" value={periodFilter} onChange={e => setPeriodFilter(e.target.value)}
                                className={`w-auto min-w-[220px] text-sm rounded-md shadow-sm ${periodFilter ? 'border-primary-300 bg-primary-50 font-bold' : 'border-gray-300'}`}>
                                <option value="">Toutes les périodes ({periods.length})</option>
                                <option value="open">Ouvertes ({periods.filter(p => p.quinzaines.some(q => !q.is_closed)).length})</option>
                                <option value="closed">Clôturées ({periods.filter(p => p.quinzaines.every(q => q.is_closed)).length})</option>
                                <optgroup label="Une période">
                                    {periods.map(p => <option key={p.key} value={p.key}>{periodLabel(p.start_date)}</option>)}
                                </optgroup>
                            </select>
                            </span>
                            {(auth.user.role === 'super_admin' || auth.user.role === 'farm_manager') && divisionChoices.length > 1 && (
                                <span className="flex items-center gap-2">
                                    <label htmlFor="enterprise_filter_select" className="text-sm font-medium text-gray-700 whitespace-nowrap">Division :</label>
                                    <select id="enterprise_filter_select" className="w-auto min-w-[200px] text-sm border-gray-300 rounded-md shadow-sm" value={filterEnterpriseId} onChange={handleFilterChange}>
                                        <option value="">Toutes les Divisions</option>
                                        {divisionChoices.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                    </select>
                                </span>
                            )}
                            {periodFilter && (
                                <button type="button" onClick={() => setPeriodFilter('')} className="text-xs font-bold text-gray-500 hover:text-red-600 underline underline-offset-2">Réinitialiser</button>
                            )}
                        </div>
                    )}

                    {periods.length > 0 && shownPeriods.length === 0 && (
                        <div className="text-center py-8 bg-white rounded-xl border-2 border-dashed border-gray-200">
                            <p className="text-gray-500 italic">Aucune période ne correspond à ce filtre.</p>
                        </div>
                    )}

                    {periods.length === 0 && (
                        <div className="text-center py-12 bg-white rounded-xl border-2 border-dashed border-gray-200">
                            <p className="text-gray-500 italic">Aucune période. Cliquez sur « + Ouvrir une Période » pour commencer.</p>
                        </div>
                    )}

                    {shownPeriods.map(period => {
                        const open = period.quinzaines.filter(q => !q.is_closed);
                        const ref = open[0] || period.quinzaines[0];
                        const total = period.quinzaines.reduce((s, q) => ({
                            jours: s.jours + q.stats.jours, net: s.net + q.stats.net, a_preciser: s.a_preciser + q.stats.a_preciser,
                        }), { jours: 0, net: 0, a_preciser: 0 });

                        return (
                            <div key={period.key} className={`bg-white rounded-2xl border-2 shadow-sm overflow-hidden ${open.length ? 'border-primary-100' : 'border-gray-100'}`}>
                                <div className="p-6 flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-gray-100">
                                    <div>
                                        <div className="flex items-center gap-3">
                                            <h3 className="text-2xl font-black uppercase tracking-tighter text-primary-600 leading-none">{periodLabel(period.start_date)}</h3>
                                            {open.length ? (
                                                <span className="bg-green-100 text-green-700 px-2 py-1 rounded text-[9px] font-black uppercase">
                                                    Ouverte{open.length < period.quinzaines.length && ` · ${period.quinzaines.length - open.length}/${period.quinzaines.length} clôturée(s)`}
                                                </span>
                                            ) : (
                                                <span className="bg-gray-200 text-gray-600 px-2 py-1 rounded text-[9px] font-black uppercase">Clôturée</span>
                                            )}
                                        </div>
                                        <p className="text-sm font-bold text-gray-400 mt-1">
                                            {formatDate(period.start_date)} — {formatDate(period.end_date)}
                                            {' · '}{total.jours} jour(s) · {formatNumber(total.net)} DH
                                            {total.a_preciser > 0 && <span className="text-red-600"> · {total.a_preciser} à préciser</span>}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {/* The farm's first step: presence per équipe, across all divisions (Grid "Par équipe"). */}
                                        <Link href={route('pointage.grid', { quinzaine: ref.id, mode: 'equipe' })}
                                            className="inline-flex items-center px-4 py-2 rounded-lg bg-primary-600 text-white text-sm font-black hover:bg-primary-700 shadow-sm">
                                            Pointage par équipe
                                        </Link>
                                        {canExportAllDivisions && (
                                            <>
                                                <a href={route('pointage.exportAllDivisions', ref.id)} target="_blank" onClick={() => triggerDownload(`all-${period.key}`)}
                                                    className="inline-flex items-center px-4 py-2 rounded-lg border border-green-300 bg-green-50 text-green-800 text-sm font-bold hover:bg-green-100">
                                                    {downloadingKey === `all-${period.key}` ? 'Génération…' : 'Excel toutes divisions'}
                                                </a>
                                                {/* Same period, workers grouped by équipe with how each is paid (PaiementEquipesExport). */}
                                                <a href={route('pointage.paiementEquipes', ref.id)} target="_blank" onClick={() => triggerDownload(`pay-${period.key}`)}
                                                    className="inline-flex items-center px-4 py-2 rounded-lg border border-primary-300 bg-white text-primary-700 text-sm font-bold hover:bg-primary-50">
                                                    {downloadingKey === `pay-${period.key}` ? 'Génération…' : 'Paiement par équipe'}
                                                </a>
                                            </>
                                        )}
                                        {canManage && open.length > 1 && (
                                            <button type="button" onClick={() => setClosingPeriod({ ...period, open })}
                                                className="inline-flex items-center px-4 py-2 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 text-sm font-bold hover:bg-amber-100">
                                                Clôturer la période
                                            </button>
                                        )}
                                    </div>
                                </div>

                                <div className="divide-y divide-gray-100">
                                    {period.quinzaines.map(q => (
                                        <div key={q.id} className={`px-6 py-4 flex flex-col xl:flex-row xl:items-center gap-4 ${q.is_closed ? 'bg-gray-50' : ''}`}>
                                            <div className="xl:w-64 shrink-0">
                                                <div className="flex items-center gap-2">
                                                    <p className="font-black text-gray-800">{q.enterprise?.name}</p>
                                                    {q.is_closed && <span className="bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded text-[9px] font-black uppercase">Clôturée</span>}
                                                </div>
                                                {q.label && q.label !== periodLabel(q.start_date) && <p className="text-xs text-gray-400">{q.label}</p>}
                                            </div>

                                            <div className="flex flex-wrap gap-x-6 gap-y-2 flex-1">
                                                <Stat label="Ouvriers" value={q.stats.ouvriers} />
                                                <Stat label="Jours" value={q.stats.jours} />
                                                <Stat label="Net" value={`${formatNumber(q.stats.net)} DH`} tone="green" />
                                                {q.stats.jours > 0 && (q.stats.a_preciser > 0
                                                    ? <Stat label="À préciser" value={`${q.stats.a_preciser} jour(s)`} tone="red" />
                                                    : <Stat label="Opérations" value="✓ complètes" tone="green" />)}
                                            </div>

                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link href={route('pointage.grid', q.id)}
                                                    className={`px-3 py-2 rounded-lg text-xs font-black ${q.is_closed ? 'bg-gray-800 text-white hover:bg-black' : 'bg-primary-50 text-primary-700 border border-primary-200 hover:bg-primary-100'}`}>
                                                    {q.is_closed ? 'Voir' : q.stats.a_preciser > 0 ? 'Préciser les opérations' : 'Grille'}
                                                </Link>
                                                <a href={route('pointage.export', q.id)} target="_blank" onClick={() => triggerDownload(`excel-${q.id}`)}
                                                    className="px-3 py-2 bg-green-50 text-green-700 rounded-lg text-[10px] font-black border border-green-200 hover:bg-green-100">
                                                    {downloadingKey === `excel-${q.id}` ? '…' : 'EXCEL'}
                                                </a>
                                                <a href={route('payroll.general-payslip', q.id)} target="_blank" onClick={() => triggerDownload(`pdf-${q.id}`)}
                                                    className="px-3 py-2 bg-red-50 text-red-700 rounded-lg text-[10px] font-black border border-red-200 hover:bg-red-100">
                                                    {downloadingKey === `pdf-${q.id}` ? '…' : 'PDF'}
                                                </a>
                                                {!q.is_closed && canManage && (
                                                    <>
                                                        <button type="button" onClick={() => openEdit(q)} title="Modifier" className="p-2 text-gray-400 hover:text-primary-600">
                                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                                        </button>
                                                        <button type="button" onClick={() => setDeleting(q)} title="Supprimer" className="p-2 text-gray-400 hover:text-red-600">
                                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                        </button>
                                                        <button type="button" onClick={() => setClosing(q)} className="px-2 py-2 text-[10px] font-black uppercase text-red-500 hover:text-red-700 hover:underline">
                                                            Clôturer
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* OPEN A PERIOD — for all the checked divisions at once */}
            <Modal show={isCreating} onClose={() => setIsCreating(false)}>
                <form onSubmit={submitCreate} className="p-8 space-y-5">
                    <h3 className="text-xl font-bold text-gray-800 border-b pb-4">Ouvrir une Période</h3>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-bold text-gray-700 mb-1">Date de début</label>
                            <input type="date" className="w-full rounded-lg border-gray-300" value={createForm.data.start_date}
                                onChange={e => createForm.setData(d => ({ ...d, start_date: e.target.value, label: e.target.value ? periodLabel(e.target.value) : d.label }))} />
                            {createForm.errors.start_date && <div className="text-red-500 text-xs mt-1">{createForm.errors.start_date}</div>}
                        </div>
                        <div>
                            <label className="block text-sm font-bold text-gray-700 mb-1">Date de fin</label>
                            <input type="date" className="w-full rounded-lg border-gray-300" value={createForm.data.end_date} onChange={e => createForm.setData('end_date', e.target.value)} />
                            {createForm.errors.end_date && <div className="text-red-500 text-xs mt-1">{createForm.errors.end_date}</div>}
                        </div>
                    </div>
                    <div>
                        <label className="block text-xs font-black uppercase text-gray-400 mb-1">Libellé</label>
                        <input type="text" className="w-full rounded-lg border-gray-300" value={createForm.data.label} onChange={e => createForm.setData('label', e.target.value)} placeholder="Ex : 1ERE Qz novembre 2026" />
                    </div>
                    {divisionChoices.length > 0 && (
                        <div>
                            <label className="block text-sm font-bold text-gray-700 mb-2">Divisions</label>
                            <div className="space-y-2">
                                {divisionChoices.map(ent => (
                                    <label key={ent.id} className="flex items-center gap-3 text-sm cursor-pointer">
                                        <input type="checkbox" className="rounded border-gray-300 text-primary-600" checked={createForm.data.enterprise_ids.includes(ent.id)} onChange={() => toggleDivision(ent.id)} />
                                        {ent.name}
                                    </label>
                                ))}
                            </div>
                            {createForm.errors.enterprise_ids && <div className="text-red-500 text-xs mt-1">Cochez au moins une division.</div>}
                        </div>
                    )}
                    {createForm.errors.quinzaine && <div className="rounded-lg bg-red-50 text-red-700 text-sm px-3 py-2">{createForm.errors.quinzaine}</div>}
                    <div className="flex justify-end gap-3 pt-4 border-t">
                        <SecondaryButton type="button" onClick={() => setIsCreating(false)}>Annuler</SecondaryButton>
                        <PrimaryButton disabled={createForm.processing || createForm.data.enterprise_ids.length === 0}>Ouvrir la période</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={editingQuinzaine !== null} onClose={() => setEditingQuinzaine(null)}>
                {editingQuinzaine && (
                    <form onSubmit={submitEdit} className="p-8 space-y-5">
                        <h3 className="text-xl font-bold text-gray-800 border-b pb-4">Modifier — {editingQuinzaine.enterprise?.name}</h3>
                        <div>
                            <label className="block text-xs font-black uppercase text-gray-400 mb-1">Libellé</label>
                            <input type="text" className="w-full rounded-lg border-gray-300" value={editForm.data.label} onChange={e => editForm.setData('label', e.target.value)} />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">Date de début</label>
                                <input type="date" className="w-full rounded-lg border-gray-300" value={editForm.data.start_date} onChange={e => editForm.setData('start_date', e.target.value)} />
                                {editForm.errors.start_date && <div className="text-red-500 text-xs mt-1">{editForm.errors.start_date}</div>}
                            </div>
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">Date de fin</label>
                                <input type="date" className="w-full rounded-lg border-gray-300" value={editForm.data.end_date} onChange={e => editForm.setData('end_date', e.target.value)} />
                                {editForm.errors.end_date && <div className="text-red-500 text-xs mt-1">{editForm.errors.end_date}</div>}
                            </div>
                        </div>
                        <div className="flex justify-end gap-3 pt-4 border-t">
                            <SecondaryButton type="button" onClick={() => setEditingQuinzaine(null)}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={editForm.processing}>Enregistrer</PrimaryButton>
                        </div>
                    </form>
                )}
            </Modal>

            <Modal show={deleting !== null} onClose={() => setDeleting(null)}>
                {deleting && (
                    <div className="p-8">
                        <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter mb-4">Supprimer la période</h2>
                        <p className="text-gray-600 mb-6">
                            Supprimer <strong>{nameOf(deleting)}</strong> de <strong>{deleting.enterprise?.name}</strong> ?
                            {deleting.stats.jours > 0
                                ? <> Ses <strong className="text-red-600">{deleting.stats.jours} jour(s) pointés ({formatNumber(deleting.stats.net)} DH)</strong> seront supprimés avec elle.</>
                                : ' Elle ne contient aucun jour pointé.'} Cette action est irréversible.
                        </p>
                        <div className="flex justify-end gap-3">
                            <SecondaryButton type="button" onClick={() => setDeleting(null)}>Annuler</SecondaryButton>
                            <DangerButton disabled={busy} onClick={() => run('delete', route('settings.quinzaine.delete', deleting.id), () => setDeleting(null))}>
                                {busy ? 'Suppression…' : 'Supprimer'}
                            </DangerButton>
                        </div>
                    </div>
                )}
            </Modal>

            {/* Close every open division of the period at once — all or nothing (closePeriode()). */}
            <Modal show={closingPeriod !== null} onClose={() => setClosingPeriod(null)}>
                {closingPeriod && (() => {
                    const pending = closingPeriod.open.filter(q => q.stats.a_preciser > 0);
                    return (
                        <div className="p-8">
                            <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter mb-4">Clôturer {periodLabel(closingPeriod.start_date)}</h2>
                            {pending.length > 0 ? (
                                <div className="mb-6 rounded-lg bg-red-50 text-red-700 px-4 py-3 text-sm space-y-1">
                                    <p>Des jours de présence n'ont pas encore leur opération (« à préciser ») :</p>
                                    <ul className="list-disc ml-5 font-bold">
                                        {pending.map(q => <li key={q.id}>{q.enterprise?.name} : {q.stats.a_preciser} jour(s)</li>)}
                                    </ul>
                                    <p>Complétez-les dans la grille avant de clôturer.</p>
                                </div>
                            ) : (
                                <p className="text-gray-600 mb-6">
                                    Clôturer la période pour <strong>{closingPeriod.open.map(q => q.enterprise?.name).join(', ')}</strong> ? Le pointage ne pourra plus être modifié.
                                </p>
                            )}
                            <div className="flex justify-end gap-3">
                                <SecondaryButton type="button" onClick={() => setClosingPeriod(null)}>Annuler</SecondaryButton>
                                {pending.length === 0 && (
                                    <PrimaryButton className="!bg-amber-600 hover:!bg-amber-700" disabled={busy}
                                        onClick={() => run('post', route('settings.quinzaine.close-periode', closingPeriod.open[0].id), () => setClosingPeriod(null))}>
                                        {busy ? 'Clôture…' : 'Tout clôturer'}
                                    </PrimaryButton>
                                )}
                            </div>
                        </div>
                    );
                })()}
            </Modal>

            <Modal show={closing !== null} onClose={() => setClosing(null)}>
                {closing && (
                    <div className="p-8">
                        <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter mb-4">Clôturer la période</h2>
                        {closing.stats.a_preciser > 0 ? (
                            <p className="mb-6 rounded-lg bg-red-50 text-red-700 px-4 py-3 text-sm">
                                <strong>{closing.enterprise?.name}</strong> a encore <strong>{closing.stats.a_preciser} jour(s) de présence sans opération</strong> (« à préciser »).
                                Complétez-les dans la grille (« ≡ Répartir » ou ouvrier par ouvrier) avant de clôturer.
                            </p>
                        ) : (
                            <p className="text-gray-600 mb-6">
                                Clôturer <strong>{nameOf(closing)}</strong> de <strong>{closing.enterprise?.name}</strong> ? Le pointage ne pourra plus être modifié.
                            </p>
                        )}
                        <div className="flex justify-end gap-3">
                            <SecondaryButton type="button" onClick={() => setClosing(null)}>Annuler</SecondaryButton>
                            {closing.stats.a_preciser > 0 ? (
                                <Link href={route('pointage.grid', closing.id)} className="inline-flex items-center px-4 py-2 rounded-md bg-primary-600 text-white text-xs font-semibold uppercase tracking-widest hover:bg-primary-700">
                                    Ouvrir la grille
                                </Link>
                            ) : (
                                <PrimaryButton className="!bg-amber-600 hover:!bg-amber-700" disabled={busy} onClick={() => run('post', route('settings.quinzaine.close', closing.id), () => setClosing(null))}>
                                    {busy ? 'Clôture…' : 'Clôturer'}
                                </PrimaryButton>
                            )}
                        </div>
                    </div>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
