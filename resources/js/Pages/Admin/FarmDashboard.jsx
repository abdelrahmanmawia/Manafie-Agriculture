import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { t } from '@/Helpers/i18n';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import StatCard from '@/Components/StatCard';
import { formatInt, formatNumber, formatMAD } from '@/utils/number';

const ALERT_LABELS = {
    low_stock: { label: 'Stock faible', className: 'bg-red-100 text-red-700' },
};

const MOVEMENT_LABELS = {
    in: { label: 'Entrée', className: 'bg-green-100 text-green-700', sign: '+' },
    production: { label: 'Production', className: 'bg-green-100 text-green-700', sign: '+' },
    out: { label: 'Sortie', className: 'bg-red-100 text-red-700', sign: '-' },
    transfer: { label: 'Transfert', className: 'bg-purple-100 text-purple-700', sign: '-' },
    loss: { label: 'Perte', className: 'bg-gray-200 text-gray-700', sign: '-' },
    adjustment: { label: 'Ajustement', className: 'bg-blue-100 text-blue-700', sign: '' },
};

export default function FarmDashboard({
    auth,
    farm,
    enterprises,
    stats,
    isSuperAdmin = false,
    payrollTrend = [],
    harvestSummary = { total_kg: 0, total_revenue: 0 },
    stockSummary = { stats: { products: 0, lowStock: 0, vehicles: 0, alerts: 0 }, recentAlerts: [], recentMovements: [] },
}) {
    const [showEntForm, setShowEntForm] = useState(false);
    
    const entForm = useForm({
        farm_id: farm.id,
        name: '',
        contract_type: 'avec_contrat',
        default_brut_rate: 97.44,
        invoiced_to_client: false,
    });

    const submitEnt = (e) => {
        e.preventDefault();
        entForm.post(route('enterprises.store'), {
            onSuccess: () => {
                entForm.reset();
                setShowEntForm(false);
            },
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center w-full">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight uppercase tracking-widest">{farm.name} - Dashboard</h2>
                    <div className="flex gap-2">
                        {isSuperAdmin && (
                            <Link
                                method="post"
                                as="button"
                                href={route('farms.deactivate')}
                                className="text-xs bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded font-bold transition-colors"
                            >
                                Changer de Ferme
                            </Link>
                        )}
                        <Link
                            href={route('farms.settings', farm.id)}
                            className="text-xs bg-blue-600 text-white hover:bg-blue-700 px-3 py-1 rounded font-bold transition-colors"
                        >
                            {t('settings')} {t('fermes')}
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title="Tableau de Bord Ferme" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-10">
                    
                    {/* FARM STATS */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div className="bg-white p-8 rounded-3xl shadow-sm border-l-8 border-green-500 flex justify-between items-center">
                            <div>
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Total Salariés (Ferme)</span>
                                <p className="text-4xl font-black text-gray-900 leading-none">{stats.employees_count}</p>
                            </div>
                            <div className="bg-green-50 p-3 rounded-2xl">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </div>
                        </div>
                        <div className="bg-white p-8 rounded-3xl shadow-sm border-l-8 border-blue-600 flex justify-between items-center">
                            <div>
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Périodes Ouvertes</span>
                                <p className="text-4xl font-black text-gray-900 leading-none">{stats.open_quinzaines}</p>
                            </div>
                            <div className="bg-blue-50 p-3 rounded-2xl">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    {/* POINTAGE & PAIE */}
                    <div className="space-y-6">
                        <div className="flex justify-between items-center">
                            <h3 className="text-2xl font-black text-gray-900 uppercase tracking-tight">Pointage &amp; Paie</h3>
                            <Link
                                href={route('analytics.index')}
                                className="text-sm font-bold text-blue-600 hover:text-blue-800 uppercase tracking-widest"
                            >
                                Voir l'Analyse Complète →
                            </Link>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <div className="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                                <h4 className="text-sm font-bold text-gray-500 uppercase tracking-widest mb-4">Coût de la Main d'Œuvre par Quinzaine</h4>
                                {payrollTrend.length === 0 ? (
                                    <p className="text-sm text-gray-400 py-12 text-center">Aucune quinzaine enregistrée pour cette ferme.</p>
                                ) : (
                                    <div className="h-64 sm:h-80 w-full">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <LineChart data={payrollTrend}>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                                                <XAxis dataKey="label" tick={{ fontSize: 8, fontWeight: 'bold' }} />
                                                <YAxis tick={{ fontSize: 10 }} />
                                                <Tooltip formatter={(value) => formatMAD(value)} />
                                                <Line type="monotone" dataKey="total_net" stroke="#10b981" strokeWidth={4} dot={{ r: 6 }} />
                                            </LineChart>
                                        </ResponsiveContainer>
                                    </div>
                                )}
                            </div>
                            <div className="grid grid-cols-1 gap-6">
                                <StatCard
                                    label="Récolte (kg)"
                                    value={formatNumber(harvestSummary.total_kg, 0)}
                                    tone="green"
                                    icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />}
                                />
                                <StatCard
                                    label="Revenu de Récolte"
                                    value={formatMAD(harvestSummary.total_revenue)}
                                    tone="blue"
                                    icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />}
                                />
                            </div>
                        </div>
                    </div>

                    {/* GESTION DE STOCK */}
                    <div className="space-y-6">
                        <div className="flex justify-between items-center">
                            <h3 className="text-2xl font-black text-gray-900 uppercase tracking-tight">Gestion de Stock</h3>
                            <Link
                                href={route('stock.dashboard')}
                                className="text-sm font-bold text-blue-600 hover:text-blue-800 uppercase tracking-widest"
                            >
                                Voir la Gestion de Stock →
                            </Link>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <StatCard
                                label="Produits Actifs"
                                value={formatInt(stockSummary.stats.products)}
                                tone="blue"
                                icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />}
                            />
                            <Link href={route('stock.alerts.index', { alert_type: 'low_stock' })}>
                                <StatCard
                                    label="Stock Faible"
                                    value={formatInt(stockSummary.stats.lowStock)}
                                    tone="red"
                                    icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />}
                                />
                            </Link>
                            <Link href={route('stock.vehicles.index')}>
                                <StatCard
                                    label="Véhicules"
                                    value={formatInt(stockSummary.stats.vehicles)}
                                    tone="gray"
                                    icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />}
                                />
                            </Link>
                            <Link href={route('stock.alerts.index')}>
                                <StatCard
                                    label="Alertes"
                                    value={formatInt(stockSummary.stats.alerts)}
                                    tone="orange"
                                    icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />}
                                />
                            </Link>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="p-5 border-b border-gray-100 flex items-center justify-between">
                                    <h4 className="text-base font-bold text-gray-800">Alertes récentes</h4>
                                    <Link href={route('stock.alerts.index')} className="text-sm font-medium text-blue-600 hover:text-blue-800">
                                        Tout voir
                                    </Link>
                                </div>
                                <div className="divide-y divide-gray-100">
                                    {stockSummary.recentAlerts.length === 0 && (
                                        <p className="p-5 text-sm text-gray-500">Aucune alerte non résolue.</p>
                                    )}
                                    {stockSummary.recentAlerts.map((alert) => {
                                        const meta = ALERT_LABELS[alert.alert_type] ?? { label: alert.alert_type, className: 'bg-gray-100 text-gray-700' };
                                        return (
                                            <div key={alert.id} className="p-4 flex items-center justify-between">
                                                <div>
                                                    <p className="text-sm font-medium text-gray-800">{alert.product?.name ?? 'Produit supprimé'}</p>
                                                    <p className="text-xs text-gray-500 mt-0.5">
                                                        {new Date(alert.created_at).toLocaleDateString('fr-FR')}
                                                    </p>
                                                </div>
                                                <span className={`text-xs font-medium px-2.5 py-1 rounded-full ${meta.className}`}>{meta.label}</span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="p-5 border-b border-gray-100 flex items-center justify-between">
                                    <h4 className="text-base font-bold text-gray-800">Mouvements récents</h4>
                                    <Link href={route('stock.movements.index')} className="text-sm font-medium text-blue-600 hover:text-blue-800">
                                        Tout voir
                                    </Link>
                                </div>
                                <div className="divide-y divide-gray-100">
                                    {stockSummary.recentMovements.length === 0 && (
                                        <p className="p-5 text-sm text-gray-500">Aucun mouvement enregistré.</p>
                                    )}
                                    {stockSummary.recentMovements.map((movement) => {
                                        const meta = MOVEMENT_LABELS[movement.movement_type] ?? { label: movement.movement_type, className: 'bg-gray-100 text-gray-700', sign: '' };
                                        return (
                                            <div key={movement.id} className="p-4 flex items-center justify-between">
                                                <div>
                                                    <p className="text-sm font-medium text-gray-800">{movement.product?.name ?? 'Produit supprimé'}</p>
                                                    <p className="text-xs text-gray-500 mt-0.5">
                                                        {new Date(movement.date).toLocaleDateString('fr-FR')}
                                                        {movement.performed_by?.name ? ` · ${movement.performed_by.name}` : ''}
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <span className={`text-xs font-medium px-2.5 py-1 rounded-full ${meta.className}`}>{meta.label}</span>
                                                    <p className="text-sm font-semibold text-gray-700 mt-1">{meta.sign}{formatNumber(movement.quantity)}</p>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* DIVISIONS MANAGEMENT */}
                    <div className="space-y-6">
                        <div className="flex justify-between items-center">
                            <h3 className="text-2xl font-black text-gray-900 uppercase tracking-tight">Divisions de la Ferme</h3>
                            <button 
                                onClick={() => setShowEntForm(!showEntForm)}
                                className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-xl font-bold text-sm uppercase tracking-widest transition-all shadow-md"
                            >
                                {showEntForm ? 'Annuler' : '+ Nouvelle Division'}
                            </button>
                        </div>

                        {showEntForm && (
                            <div className="bg-white p-8 shadow-xl rounded-2xl border-2 border-blue-500 animate-in fade-in slide-in-from-top-4 duration-300">
                                <form onSubmit={submitEnt} className="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                                    <div className="md:col-span-1">
                                        <label className="block text-[10px] font-black uppercase text-gray-400 mb-1 tracking-widest">Nom de la Division</label>
                                        <input
                                            type="text"
                                            className="w-full rounded-xl border-gray-200 text-sm py-3"
                                            placeholder="Ex: Interim Persealand"
                                            value={entForm.data.name}
                                            onChange={e => entForm.setData('name', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-black uppercase text-gray-400 mb-1 tracking-widest">Salaire Brut (DH)</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className="w-full rounded-xl border-gray-200 text-sm py-3"
                                            value={entForm.data.default_brut_rate}
                                            onChange={e => entForm.setData('default_brut_rate', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-black uppercase text-gray-400 mb-1 tracking-widest">Type Contrat</label>
                                        <select
                                            className="w-full rounded-xl border-gray-200 text-sm py-3"
                                            value={entForm.data.contract_type}
                                            onChange={e => entForm.setData('contract_type', e.target.value)}
                                        >
                                            <option value="avec_contrat">Avec Contrat</option>
                                            <option value="sans_contrat">Sans Contrat</option>
                                        </select>
                                    </div>
                                    <div className="md:col-span-3 flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="invoiced_to_client"
                                            className="rounded border-gray-300"
                                            checked={entForm.data.invoiced_to_client}
                                            onChange={e => entForm.setData('invoiced_to_client', e.target.checked)}
                                        />
                                        <label htmlFor="invoiced_to_client" className="text-xs text-gray-600">
                                            Cette division facture un client (ex: agence d'intérim) — à ne cocher que si elle émet une facture (net à facturer/TTC), pas juste le salaire des ouvriers
                                        </label>
                                    </div>
                                    <div className="md:col-span-3">
                                        <button type="submit" disabled={entForm.processing} className="w-full bg-blue-600 hover:bg-blue-700 text-white py-4 rounded-xl font-black uppercase tracking-widest transition-colors shadow-md">
                                            Créer la Division
                                        </button>
                                    </div>
                                </form>
                            </div>
                        )}

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                            {enterprises.map(ent => (
                                <div key={ent.id} className="bg-white overflow-hidden shadow-sm sm:rounded-2xl p-8 border border-gray-100 hover:shadow-lg transition-all border-t-4 border-t-blue-500">
                                    <div className="flex justify-between items-start mb-6">
                                        <h4 className="text-2xl font-black text-gray-900 uppercase leading-tight">{ent.name}</h4>
                                        <span className={`px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest ${ent.contract_type === 'avec_contrat' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'}`}>
                                            {ent.contract_type.replace('_', ' ')}
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4 mb-8">
                                        <div className="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                                            <p className="text-[8px] font-black text-gray-400 uppercase tracking-widest mb-1">{t('workers')}</p>
                                            <p className="text-xl font-black text-gray-900">{ent.employees_count}</p>
                                        </div>
                                        <div className="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                                            <p className="text-[8px] font-black text-gray-400 uppercase tracking-widest mb-1">{t('periods')}</p>
                                            <p className="text-xl font-black text-gray-900">{ent.quinzaines_count}</p>
                                        </div>
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <Link
                                            href={route('pointage.quinzaines', { enterprise_id: ent.id })}
                                            className="w-full text-center bg-blue-600 text-white hover:bg-blue-700 py-3 rounded-xl font-bold text-xs transition-colors uppercase tracking-widest"
                                        >
                                            {t('pointage')}
                                        </Link>
                                        <div className="grid grid-cols-2 gap-2">
                                            <Link
                                                href={route('analytics.index', { enterprise_id: ent.id })}
                                                className="text-center bg-gray-50 hover:bg-gray-100 text-gray-600 py-2 rounded-xl font-bold text-[10px] transition-colors uppercase tracking-widest border border-gray-100"
                                            >
                                                Analyse
                                            </Link>
                                            <Link
                                                href={route('settings.index', { enterprise_id: ent.id })}
                                                className="text-center bg-gray-50 hover:bg-gray-100 text-gray-600 py-2 rounded-xl font-bold text-[10px] transition-colors uppercase tracking-widest border border-gray-100"
                                            >
                                                {t('settings')}
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {enterprises.length === 0 && (
                                <div className="col-span-full bg-gray-50 rounded-3xl p-12 text-center border-2 border-dashed border-gray-200">
                                    <p className="text-gray-400 font-bold uppercase tracking-widest">Aucune division créée pour cette ferme.</p>
                                    <p className="text-xs text-gray-400 mt-2 italic">Commencez par ajouter une division comme "Interim" ou "Direct".</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
