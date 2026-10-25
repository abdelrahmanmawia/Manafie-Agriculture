import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatNumber, formatMAD } from '@/utils/number';
import { UNIT_TYPE_LABELS } from '@/utils/stockLabels';

// The quantities typed so far, kept in this browser until the count is saved — counting a whole
// magasin takes a while, and a reload or a wrong click shouldn't lose it.
const DRAFT_KEY = 'stock-comptage-brouillon';
const loadDraft = () => {
    try {
        return JSON.parse(window.localStorage.getItem(DRAFT_KEY) || '{}');
    } catch {
        return {};
    }
};
const saveDraft = (counts) => {
    try {
        window.localStorage.setItem(DRAFT_KEY, JSON.stringify(counts));
    } catch {
        // storage unavailable: the count still works, just without a draft
    }
};

const filled = (value) => value !== undefined && value !== null && String(value).trim() !== '';

export default function Comptage({ auth, lignes, categories, categoryId, date }) {
    const { errors } = usePage().props;
    const [counts, setCounts] = useState(loadDraft);
    const [search, setSearch] = useState('');
    const [ecartsOnly, setEcartsOnly] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => saveDraft(counts), [counts]);

    const visit = (params) => router.get(route('stock.inventory.comptage'), { date, category_id: categoryId || '', ...params }, {
        preserveState: true, preserveScroll: true, replace: true,
    });

    const setCount = (productId, value) => setCounts((prev) => {
        const next = { ...prev };
        if (value === '') delete next[productId];
        else next[productId] = value;
        return next;
    });

    const rows = useMemo(() => lignes.map((ligne) => {
        const counted = counts[ligne.product_id];
        const ecart = filled(counted) ? Number(counted) - ligne.quantity : null;
        return { ...ligne, counted, ecart, valeur: ecart === null ? null : ecart * ligne.cost };
    }), [lignes, counts]);

    const shown = rows.filter((row) => (!search || row.name.toLowerCase().includes(search.toLowerCase()))
        && (!ecartsOnly || (row.ecart !== null && Math.abs(row.ecart) > 0.001)));
    const groups = shown.reduce((acc, row) => ({ ...acc, [row.category]: [...(acc[row.category] || []), row] }), {});

    const saisis = Object.entries(counts).filter(([, v]) => filled(v));
    const visibleIds = new Set(lignes.map((l) => String(l.product_id)));
    const horsListe = saisis.filter(([id]) => !visibleIds.has(String(id))).length;
    const ecarts = rows.filter((r) => r.ecart !== null && Math.abs(r.ecart) > 0.001);
    const valeurNette = ecarts.reduce((sum, r) => sum + r.valeur, 0);

    const submit = () => {
        setSaving(true);
        router.post(route('stock.inventory.comptage.store'), {
            date,
            lignes: saisis.map(([product_id, counted_quantity]) => ({ product_id: Number(product_id), counted_quantity })),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setCounts({});
                setConfirming(false);
            },
            onError: () => setConfirming(false),
            onFinish: () => setSaving(false),
        });
    };

    const pdfHref = (aveugle) => route('stock.inventory.comptage.pdf', { ...(categoryId ? { category_id: categoryId } : {}), ...(aveugle ? { aveugle: 1 } : {}) });
    const input = 'border border-gray-300 rounded-lg text-sm px-3 py-2 focus:ring-2 focus:ring-green-500 focus:border-green-500';
    const unit = (type) => UNIT_TYPE_LABELS[type] || type;
    const dateFr = new Date(`${date}T00:00:00`).toLocaleDateString('fr-FR');

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <div className="flex items-center gap-4">
                        <Link href={route('stock.inventory.index')} className="text-gray-500 hover:text-gray-700 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Comptage d'inventaire</h2>
                            <p className="text-sm text-gray-500 mt-1">Saisissez les quantités comptées : les écarts deviennent des ajustements datés du jour du comptage.</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <a href={pdfHref(false)} className="bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 px-4 py-2.5 rounded-xl font-black uppercase tracking-widest shadow-sm text-xs">
                            Fiche de comptage (PDF)
                        </a>
                        <a href={pdfHref(true)} className="bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 px-4 py-2.5 rounded-xl font-black uppercase tracking-widest shadow-sm text-xs" title="Sans le stock système, pour ne pas influencer le comptage">
                            Fiche à l'aveugle
                        </a>
                    </div>
                </div>
            }
        >
            <Head title="Comptage d'inventaire" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-4 flex flex-wrap items-end gap-4">
                        <div>
                            <label className="block text-xs font-medium text-gray-600 mb-1">Date du comptage</label>
                            <input type="date" value={date} max={new Date().toISOString().slice(0, 10)} onChange={(e) => e.target.value && visit({ date: e.target.value })} className={input} />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-600 mb-1">Catégorie</label>
                            <select value={categoryId || ''} onChange={(e) => visit({ category_id: e.target.value })} className={input}>
                                <option value="">Tout le magasin</option>
                                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                        <div className="flex-1 min-w-[200px]">
                            <label className="block text-xs font-medium text-gray-600 mb-1">Rechercher</label>
                            <input type="text" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Produit..." className={`${input} w-full`} />
                        </div>
                        <label className="flex items-center gap-2 text-sm text-gray-600 pb-2">
                            <input type="checkbox" checked={ecartsOnly} onChange={(e) => setEcartsOnly(e.target.checked)} className="rounded border-gray-300 text-green-600 focus:ring-green-500" />
                            Seulement les écarts
                        </label>
                    </div>

                    {date !== new Date().toISOString().slice(0, 10) && (
                        <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
                            Comptage du {dateFr} : le stock système est celui de la fin de ce jour-là. Les sorties et réceptions faites depuis sont gardées.
                        </p>
                    )}
                    {errors.lignes && (
                        <p className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3">{errors.lignes}</p>
                    )}
                    {errors.date && (
                        <p className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3">{errors.date}</p>
                    )}

                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr>
                                    {['Produit', 'Stock système', 'Compté', 'Écart', 'Valeur écart'].map((h, i) => (
                                        <th key={h} className={`px-4 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider ${i === 0 ? 'text-left' : 'text-right'}`}>{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {Object.keys(groups).length === 0 && (
                                    <tr><td colSpan={5} className="px-4 py-12 text-center text-gray-500">Aucun produit à afficher.</td></tr>
                                )}
                                {Object.entries(groups).map(([category, items]) => (
                                    <Fragment key={category}>
                                        <tr className="bg-slate-800">
                                            <td colSpan={5} className="px-4 py-2 text-xs font-bold text-white uppercase tracking-wider">{category}</td>
                                        </tr>
                                        {items.map((row) => (
                                            <tr key={row.product_id} className={row.counted !== undefined ? 'bg-green-50/40' : ''}>
                                                <td className="px-4 py-2">
                                                    <div className="font-medium text-gray-900">{row.name}</div>
                                                    {row.last_count_date && (
                                                        <div className="text-xs text-gray-400">Compté le {new Date(`${row.last_count_date}T00:00:00`).toLocaleDateString('fr-FR')}</div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2 text-right whitespace-nowrap text-gray-600">
                                                    <button type="button" onClick={() => setCount(row.product_id, String(row.quantity))} className="hover:text-green-700 hover:underline" title="Compté = stock système">
                                                        {formatNumber(row.quantity)} {unit(row.unit_type)}
                                                    </button>
                                                </td>
                                                <td className="px-4 py-2 text-right">
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        inputMode="decimal"
                                                        value={row.counted ?? ''}
                                                        onChange={(e) => setCount(row.product_id, e.target.value)}
                                                        className="w-28 border border-gray-300 rounded-lg text-sm px-2 py-1.5 text-right focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                                    />
                                                </td>
                                                <td className={`px-4 py-2 text-right whitespace-nowrap font-semibold ${row.ecart === null || Math.abs(row.ecart) < 0.001 ? 'text-gray-400' : row.ecart > 0 ? 'text-green-700' : 'text-red-700'}`}>
                                                    {row.ecart === null ? '—' : `${row.ecart > 0 ? '+' : ''}${formatNumber(row.ecart)}`}
                                                </td>
                                                <td className="px-4 py-2 text-right whitespace-nowrap text-gray-600">
                                                    {row.valeur === null || Math.abs(row.ecart) < 0.001 ? '—' : formatMAD(row.valeur)}
                                                </td>
                                            </tr>
                                        ))}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="sticky bottom-0 z-20 bg-white/95 backdrop-blur border border-gray-200 shadow-lg sm:rounded-2xl">
                        <div className="px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                            <div className="text-sm text-gray-600">
                                <span className="font-semibold text-gray-900">{saisis.length}</span> produit(s) saisi(s)
                                {horsListe > 0 && <span className="text-gray-400"> (dont {horsListe} hors de cette liste)</span>}
                                {' · '}<span className="font-semibold text-gray-900">{ecarts.length}</span> écart(s)
                                {ecarts.length > 0 && <> · valeur nette <span className={`font-semibold ${valeurNette < 0 ? 'text-red-700' : 'text-green-700'}`}>{formatMAD(valeurNette)}</span></>}
                            </div>
                            <div className="flex gap-2">
                                {saisis.length > 0 && (
                                    <SecondaryButton onClick={() => setCounts({})}>Effacer la saisie</SecondaryButton>
                                )}
                                <PrimaryButton disabled={saisis.length === 0} onClick={() => setConfirming(true)} className="bg-green-600 hover:bg-green-700">
                                    Enregistrer le comptage
                                </PrimaryButton>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={confirming} onClose={() => setConfirming(false)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-black text-gray-800">Enregistrer le comptage du {dateFr} ?</h3>
                    <p className="mt-2 text-sm text-gray-600">
                        {saisis.length} produit(s) compté(s), {ecarts.length} écart(s){ecarts.length > 0 ? `, valeur nette ${formatMAD(valeurNette)}` : ''}.
                        Chaque écart devient un ajustement du stock ; les produits non saisis ne changent pas.
                    </p>
                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={() => setConfirming(false)}>Annuler</SecondaryButton>
                        <PrimaryButton disabled={saving} onClick={submit} className="bg-green-600 hover:bg-green-700">
                            {saving ? 'Enregistrement...' : 'Enregistrer'}
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
