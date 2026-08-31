import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { t } from '@/Helpers/i18n';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import StatCard from '@/Components/StatCard';
import { formatInt, formatNumber, formatMAD } from '@/utils/number';

export default function FarmDashboard({
    auth,
    farm,
    stats,
    isSuperAdmin = false,
    payrollTrend = [],
    harvestSummary = { total_kg: 0, total_revenue: 0 },
    stockStats = { products: 0, lowStock: 0, vehicles: 0, alerts: 0 },
}) {
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

                    {/* VUE D'ENSEMBLE — headline numbers only; the detail for each lives one click away
                        on that zone's own dashboard, not repeated here. */}
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <StatCard
                            label="Total Salariés"
                            value={formatInt(stats.employees_count)}
                            tone="green"
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />}
                        />
                        <Link href={route('pointage.index')}>
                            <StatCard
                                label="Périodes Ouvertes"
                                value={formatInt(stats.open_quinzaines)}
                                tone="blue"
                                icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />}
                            />
                        </Link>
                        <Link href={route('stock.alerts.index', { alert_type: 'low_stock' })}>
                            <StatCard
                                label="Stock Faible"
                                value={formatInt(stockStats.lowStock)}
                                tone="red"
                                icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />}
                            />
                        </Link>
                        <Link href={route('stock.alerts.index')}>
                            <StatCard
                                label="Alertes Stock"
                                value={formatInt(stockStats.alerts)}
                                tone="orange"
                                icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />}
                            />
                        </Link>
                    </div>

                    {/* POINTAGE & PAIE — farm-wide financial pulse (all divisions combined); this is
                        unique to the Hub, it has no per-zone equivalent. */}
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

                    {/* ACCÈS RAPIDE — a launchpad into each zone's own dashboard, instead of
                        repeating that zone's content here. */}
                    <div className="bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 border border-gray-200">
                        <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter mb-4">Accès Rapide</h3>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <Link
                                href={route('pointage.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-blue-300"
                            >
                                <svg className="w-8 h-8 text-blue-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Divisions &amp; Pointage</span>
                            </Link>
                            <Link
                                href={route('stock.dashboard')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-purple-300"
                            >
                                <svg className="w-8 h-8 text-purple-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Gestion de Stock</span>
                            </Link>
                            <Link
                                href={route('employees.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-green-300"
                            >
                                <svg className="w-8 h-8 text-green-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Employés</span>
                            </Link>
                            <Link
                                href={route('analytics.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-orange-300"
                            >
                                <svg className="w-8 h-8 text-orange-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Analyses &amp; Statistiques</span>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
