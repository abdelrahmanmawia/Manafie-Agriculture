import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import Pagination from '@/Components/Pagination';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { formatNumber, formatMAD } from '@/utils/number';
import { FUEL_TRANSACTION_TYPE_LABELS } from '@/utils/stockLabels';
import useListFilters from '@/utils/useListFilters';

export default function Index({ auth, fuelTransactions, vehicles, filters = {}, totals, canDeleteAny = false }) {
    const { values, set, reset, active } = useListFilters('stock.fuel-transactions.index', filters);
    const [deleting, setDeleting] = useState(null);
    const rows = fuelTransactions.data;
    const today = new Date().toISOString().slice(0, 10);
    // Same rule as FuelTransactionController::destroy(): a manager any time, the author the same day.
    const canDelete = (t) => canDeleteAny || (t.performed_by === auth.user.id && t.created_at?.slice(0, 10) === today);
    const confirmDelete = () => router.delete(route('stock.fuel-transactions.destroy', deleting.id), {
        preserveScroll: true,
        onFinish: () => setDeleting(null),
    });

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const getTransactionTypeColor = (type) => {
        const colors = {
            'fueling': 'bg-green-100 text-green-800',
            'transfer': 'bg-primary-100 text-primary-800',
            'adjustment': 'bg-yellow-100 text-yellow-800',
        };
        return colors[type] || 'bg-gray-100 text-gray-800';
    };

    const getTransactionTypeLabel = (type) => FUEL_TRANSACTION_TYPE_LABELS[type] || type;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div>
                    <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Transactions de Carburant</h2>
                    <p className="text-sm text-gray-500 mt-1">
                        Les pleins des véhicules et du matériel. Pour en enregistrer un, faites une sortie de carburant (Consommation) dans{' '}
                        <Link href={route('stock.manual-entries.index')} className="text-orange-600 hover:underline font-medium">
                            Sorties de Stock
                        </Link>{' '}: elle arrive ici.
                    </p>
                </div>
            }
        >
            <Head title="Transactions de Carburant" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Filters */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        placeholder="Véhicule, matricule, conducteur..."
                                        value={values.search || ''}
                                        onChange={(e) => set('search', e.target.value)}
                                        className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                                    />
                                    <svg className="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Véhicule</label>
                                <select
                                    value={values.vehicle_id || ''}
                                    onChange={(e) => set('vehicle_id', e.target.value)}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                                >
                                    <option value="">Tous les véhicules</option>
                                    {vehicles.map((vehicle) => (
                                        <option key={vehicle.id} value={vehicle.id}>{vehicle.name}{(vehicle.plate_number || vehicle.serial_number) ? ` (${vehicle.plate_number || vehicle.serial_number})` : ''}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Du</label>
                                <input type="date" value={values.from || ''} onChange={(e) => set('from', e.target.value)} className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500" />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Au</label>
                                <input type="date" value={values.to || ''} onChange={(e) => set('to', e.target.value)} className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-orange-500 focus:border-orange-500" />
                            </div>
                        </div>
                        {active && (
                            <button type="button" onClick={reset} className="mt-3 text-xs font-medium text-gray-500 hover:text-orange-600">
                                Effacer les filtres
                            </button>
                        )}
                    </div>

                    {/* Transactions Table */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <div className="flex justify-between items-center">
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Historique des Transactions</h3>
                                <span className="text-sm text-gray-500">
                                    {totals.count} plein(s) · {formatNumber(totals.liters)} L · {formatMAD(totals.cost)}
                                </span>
                            </div>
                        </div>

                        {rows.length === 0 ? (
                            <div className="text-center py-16">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p className="mt-4 text-gray-500">Aucune transaction trouvée</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Date
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Véhicule
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Type
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Quantité
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Coût
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Conducteur
                                            </th>
                                            <th scope="col" className="relative px-6 py-3">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {rows.map((transaction) => (
                                            <tr key={transaction.id} className="hover:bg-gray-50 transition-colors">
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {formatDate(transaction.date)}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="flex items-center">
                                                        <div className="flex-shrink-0 h-8 w-8 bg-orange-100 rounded-full flex items-center justify-center">
                                                            <svg className="h-4 w-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                                            </svg>
                                                        </div>
                                                        <div className="ml-3">
                                                            <div className="text-sm font-medium text-gray-900">{transaction.vehicle?.name || 'N/A'}</div>
                                                            <div className="text-xs text-gray-500">{transaction.vehicle?.plate_number || ''}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${getTransactionTypeColor(transaction.transaction_type)}`}>
                                                        {getTransactionTypeLabel(transaction.transaction_type)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="text-sm font-medium text-gray-900">{formatNumber(transaction.quantity_liters)} L</div>
                                                    <div className="text-xs text-gray-500">{transaction.product?.name || ''}</div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    {transaction.total_cost ? formatMAD(transaction.total_cost) : 'N/A'}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {transaction.driver?.full_name || 'N/A'}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                                                    <Link
                                                        href={route('stock.fuel-transactions.show', transaction.id)}
                                                        className="text-gray-400 hover:text-orange-600 transition-colors"
                                                        title="Voir"
                                                    >
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                        </svg>
                                                    </Link>
                                                    {canDelete(transaction) && (
                                                        <button
                                                            type="button"
                                                            onClick={() => setDeleting(transaction)}
                                                            className="text-gray-400 hover:text-red-600 transition-colors"
                                                            title="Supprimer"
                                                        >
                                                            <svg className="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                            </svg>
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <Pagination paginator={fuelTransactions} />
                    </div>
                </div>
            </div>

            <Modal show={Boolean(deleting)} onClose={() => setDeleting(null)} maxWidth="md">
                {deleting && (
                    <div className="p-6">
                        <h3 className="text-lg font-black text-gray-800">Supprimer ce ravitaillement ?</h3>
                        <p className="mt-2 text-sm text-gray-600">
                            {formatNumber(deleting.quantity_liters)} L pour {deleting.vehicle?.name || 'N/A'} le {formatDate(deleting.date)}.
                            Les litres reviennent au magasin et le coût sort du carburant du véhicule.
                        </p>
                        <div className="mt-6 flex justify-end gap-3">
                            <SecondaryButton onClick={() => setDeleting(null)}>Annuler</SecondaryButton>
                            <DangerButton onClick={confirmDelete}>Supprimer</DangerButton>
                        </div>
                    </div>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
