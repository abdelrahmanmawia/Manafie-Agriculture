import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { formatInt, formatNumber, formatMAD } from '@/utils/number';
import { UNIT_TYPE_LABELS } from '@/utils/stockLabels';
import StatCard from '@/Components/StatCard';
import DashboardHeader from '@/Components/DashboardHeader';
import { peutSaisir } from '@/utils/acces';

const MOVEMENT_LABELS = {
    in: { label: 'Entrée', className: 'bg-green-100 text-green-700', sign: '+' },
    production: { label: 'Production', className: 'bg-green-100 text-green-700', sign: '+' },
    out: { label: 'Sortie', className: 'bg-red-100 text-red-700', sign: '-' },
    transfer: { label: 'Transfert', className: 'bg-purple-100 text-purple-700', sign: '-' },
    loss: { label: 'Perte', className: 'bg-gray-200 text-gray-700', sign: '-' },
    adjustment: { label: 'Ajustement', className: 'bg-primary-100 text-primary-700', sign: '' },
};

export default function Dashboard({ auth, stats, recentMovements, lowStockList = [] }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <DashboardHeader
                    title="Tableau de Bord de Gestion de Stock"
                    actions={
                        <span className="flex items-center gap-1 text-sm text-gray-500 normal-case tracking-normal font-normal">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {new Date().toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                        </span>
                    }
                />
            }
        >
            <Head title="Gestion de Stock" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* The magasinier's day: what to reorder, what the magasin is worth, what went out
                        this month, the fill-ups that don't add up, the matériel out of service. */}
                    <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                        <StatCard
                            label="Stock Faible"
                            value={formatInt(stats.lowStock)}
                            tone="red"
                            href={route('stock.inventory.index', { low_stock: 1 })}
                            trend={stats.lowStock === 0 ? { direction: 'calm', label: 'Rien à commander' } : { direction: 'down', label: 'À commander' }}
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />}
                        />
                        <StatCard
                            label="Valeur du stock"
                            value={formatMAD(stats.valeur, 0)}
                            tone="blue"
                            href={route('stock.reports.inventory-value')}
                            trend={{ direction: 'flat', label: `${formatInt(stats.products)} produits actifs` }}
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />}
                        />
                        <StatCard
                            label="Sorties du mois"
                            value={formatMAD(stats.sortiesMois, 0)}
                            tone="gray"
                            href={route('stock.manual-entries.index')}
                            trend={{ direction: 'flat', label: 'Consommations et pleins' }}
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />}
                        />
                        <StatCard
                            label="Pleins à vérifier"
                            value={formatInt(stats.pleinsAVerifier)}
                            tone="orange"
                            href={route('stock.fuel-transactions.index', { anomalies: 1 })}
                            trend={stats.pleinsAVerifier === 0 ? { direction: 'calm', label: 'Ce mois : rien' } : { direction: 'down', label: 'Ce mois' }}
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />}
                        />
                        <StatCard
                            label="Matériel en panne"
                            value={formatInt(stats.equipmentDown)}
                            tone="orange"
                            href={route('stock.vehicles.index', { asset_type: 'equipment', status: 'in_repair' })}
                            trend={stats.equipmentDown === 0 ? { direction: 'calm', label: 'Tout tourne' } : { direction: 'down', label: 'En réparation' }}
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z" />}
                        />
                    </div>

                    {/* To reorder + Recent Movements */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* To reorder: the furthest under their minimum first. */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-5 border-b border-gray-100 flex items-center justify-between">
                                <h3 className="text-base font-black text-gray-800 uppercase tracking-tighter">À commander</h3>
                                <Link href={route('stock.inventory.index', { low_stock: 1 })} className="text-sm font-medium text-primary-600 hover:text-primary-800">
                                    Tout voir
                                </Link>
                            </div>
                            <div className="divide-y divide-gray-100">
                                {lowStockList.length === 0 && (
                                    <p className="p-5 text-sm text-gray-500">Aucun produit sous son stock minimum.</p>
                                )}
                                {lowStockList.map((product) => (
                                    <div key={product.id} className="p-4 flex items-center justify-between">
                                        <p className="text-sm font-medium text-gray-800">{product.name}</p>
                                        <p className="text-sm text-right">
                                            <span className="font-semibold text-red-700">{formatNumber(product.quantity)}</span>
                                            <span className="text-gray-400"> / min {formatNumber(product.min)} {UNIT_TYPE_LABELS[product.unit_type] || product.unit_type}</span>
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Recent Movements */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-5 border-b border-gray-100 flex items-center justify-between">
                                <h3 className="text-base font-black text-gray-800 uppercase tracking-tighter">Mouvements récents</h3>
                                <Link href={route('stock.movements.index')} className="text-sm font-medium text-primary-600 hover:text-primary-800">
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
                        <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter mb-4">Actions Rapides</h3>
                        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                            <Link
                                href={route('stock.products.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-primary-300"
                            >
                                <svg className="w-8 h-8 text-primary-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Nouveau Produit</span>
                            </Link>
                            {peutSaisir(auth.user, 'stock') && (
                                <Link
                                    href={route('stock.movements.index') + '?action=receive'}
                                    className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-green-300"
                                >
                                    <svg className="w-8 h-8 text-green-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    <span className="text-sm font-medium text-gray-700">Réception Stock</span>
                                </Link>
                            )}
                            <Link
                                href={route('stock.manual-entries.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-primary-300"
                            >
                                <svg className="w-8 h-8 text-primary-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
