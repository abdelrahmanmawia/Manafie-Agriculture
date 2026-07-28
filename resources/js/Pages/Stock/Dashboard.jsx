import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { formatInt, formatNumber } from '@/utils/number';
import StatCard from '@/Components/StatCard';

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

export default function Dashboard({ auth, stats, recentAlerts, recentMovements, pendingManualEntries }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-bold text-2xl text-gray-800 leading-tight">Tableau de Bord de Gestion de Stock</h2>
                    <div className="flex items-center space-x-2 text-sm text-gray-500">
                        <span className="flex items-center">
                            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {new Date().toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                        </span>
                    </div>
                </div>
            }
        >
            <Head title="Gestion de Stock" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Pending manual entries callout */}
                    {pendingManualEntries > 0 && (
                        <Link
                            href={route('stock.manual-entries.index')}
                            className="flex items-center justify-between bg-indigo-50 border border-indigo-200 rounded-xl px-5 py-4 hover:bg-indigo-100 transition-colors"
                        >
                            <div className="flex items-center gap-3">
                                <svg className="w-5 h-5 text-indigo-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p className="text-sm font-medium text-indigo-900">
                                    {pendingManualEntries} entrée{pendingManualEntries > 1 ? 's' : ''} manuelle{pendingManualEntries > 1 ? 's' : ''} en attente de vérification
                                </p>
                            </div>
                            <span className="text-sm font-medium text-indigo-700">Vérifier →</span>
                        </Link>
                    )}

                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <StatCard
                            label="Produits Actifs"
                            value={formatInt(stats.products)}
                            tone="blue"
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />}
                        />
                        <Link href={route('stock.alerts.index', { alert_type: 'low_stock' })}>
                            <StatCard
                                label="Stock Faible"
                                value={formatInt(stats.lowStock)}
                                tone="red"
                                icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />}
                            />
                        </Link>
                        <Link href={route('stock.vehicles.index')}>
                            <StatCard
                                label="Véhicules"
                                value={formatInt(stats.vehicles)}
                                tone="gray"
                                icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />}
                            />
                        </Link>
                        <Link href={route('stock.alerts.index')}>
                            <StatCard
                                label="Alertes"
                                value={formatInt(stats.alerts)}
                                tone="orange"
                                icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />}
                            />
                        </Link>
                    </div>

                    {/* Alerts + Recent Movements */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Recent Alerts */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-5 border-b border-gray-100 flex items-center justify-between">
                                <h3 className="text-base font-bold text-gray-800">Alertes récentes</h3>
                                <Link href={route('stock.alerts.index')} className="text-sm font-medium text-blue-600 hover:text-blue-800">
                                    Tout voir
                                </Link>
                            </div>
                            <div className="divide-y divide-gray-100">
                                {recentAlerts.length === 0 && (
                                    <p className="p-5 text-sm text-gray-500">Aucune alerte non résolue.</p>
                                )}
                                {recentAlerts.map((alert) => {
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

                        {/* Recent Movements */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-5 border-b border-gray-100 flex items-center justify-between">
                                <h3 className="text-base font-bold text-gray-800">Mouvements récents</h3>
                                <Link href={route('stock.movements.index')} className="text-sm font-medium text-blue-600 hover:text-blue-800">
                                    Tout voir
                                </Link>
                            </div>
                            <div className="divide-y divide-gray-100">
                                {recentMovements.length === 0 && (
                                    <p className="p-5 text-sm text-gray-500">Aucun mouvement enregistré.</p>
                                )}
                                {recentMovements.map((movement) => {
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

                    {/* Quick Actions */}
                    <div className="bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 border border-gray-200">
                        <h3 className="text-lg font-bold text-gray-800 mb-4">Actions Rapides</h3>
                        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                            <Link
                                href={route('stock.products.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-blue-300"
                            >
                                <svg className="w-8 h-8 text-blue-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Nouveau Produit</span>
                            </Link>
                            <Link
                                href={route('stock.movements.index') + '?action=receive'}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-green-300"
                            >
                                <svg className="w-8 h-8 text-green-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Réception Stock</span>
                            </Link>
                            <Link
                                href={route('stock.manual-entries.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-indigo-300"
                            >
                                <svg className="w-8 h-8 text-indigo-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Sorties de Stock</span>
                            </Link>
                            <Link
                                href={route('stock.fuel-transactions.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-orange-300"
                            >
                                <svg className="w-8 h-8 text-orange-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Carburant</span>
                            </Link>
                            <Link
                                href={route('stock.reports.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-teal-300"
                            >
                                <svg className="w-8 h-8 text-teal-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Rapports</span>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
