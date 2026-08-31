import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Modal from '@/Components/Modal';
import { formatNumber, formatInt } from '@/utils/number';
import { ALERT_TYPE_LABELS, UNIT_TYPE_LABELS } from '@/utils/stockLabels';

export default function Index({ auth, stockAlerts, resolvedCount }) {
    const [confirmingResolve, setConfirmingResolve] = useState(false);
    const [selectedAlert, setSelectedAlert] = useState(null);
    const { post, processing } = useForm();

    const confirmResolveAlert = (alert) => {
        setSelectedAlert(alert);
        setConfirmingResolve(true);
    };

    const resolveAlert = (e) => {
        e.preventDefault();
        if (selectedAlert) {
            post(route('stock.alerts.resolve', selectedAlert.id), {
                preserveScroll: true,
                onSuccess: closeModal,
                onError: closeModal,
                onFinish: closeModal,
            });
        }
    };

    const closeModal = () => {
        setConfirmingResolve(false);
        setSelectedAlert(null);
    };

    const getStatusColor = (alert) => {
        if (alert.is_resolved) return 'bg-gray-100 text-gray-600';
        switch (alert.alert_type) {
            case 'low_stock': return 'bg-red-100 text-red-800';
            case 'overstock': return 'bg-yellow-100 text-yellow-800';
            default: return 'bg-blue-100 text-blue-800';
        }
    };

    const activeCount = stockAlerts.filter((a) => !a.is_resolved).length;
    const lowStockCount = stockAlerts.filter((a) => !a.is_resolved && a.alert_type === 'low_stock').length;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div>
                    <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Alertes de Stock</h2>
                    <p className="text-sm text-gray-500 mt-1">Suivi des seuils de stock, expirations et anomalies</p>
                </div>
            }
        >
            <Head title="Alertes de Stock" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Alertes Actives</p>
                                    <p className="text-2xl font-bold text-red-600 mt-1">{formatInt(activeCount)}</p>
                                </div>
                                <div className="h-12 w-12 bg-red-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Dont Stock Faible</p>
                                    <p className="text-2xl font-bold text-orange-600 mt-1">{formatInt(lowStockCount)}</p>
                                </div>
                                <div className="h-12 w-12 bg-orange-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Résolues</p>
                                    <p className="text-2xl font-bold text-gray-900 mt-1">{formatInt(resolvedCount)}</p>
                                </div>
                                <div className="h-12 w-12 bg-green-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <div className="flex justify-between items-center">
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Liste des Alertes</h3>
                                <span className="text-sm text-gray-500">{stockAlerts.length} alerte(s)</span>
                            </div>
                        </div>

                        {stockAlerts.length === 0 ? (
                            <div className="text-center py-16">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p className="mt-4 text-gray-500">Aucune alerte de stock active pour le moment</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Produit</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type d'Alerte</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Seuil</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Valeur Actuelle</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Notes</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Statut</th>
                                            <th scope="col" className="relative px-6 py-3"><span className="sr-only">Actions</span></th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {stockAlerts.map((alert) => (
                                            <tr key={alert.id} className="hover:bg-gray-50 transition-colors">
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <Link href={route('stock.products.show', alert.product.id)} className="text-sm font-medium text-gray-900 hover:text-blue-600">
                                                        {alert.product.name}
                                                    </Link>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                    {ALERT_TYPE_LABELS[alert.alert_type] || alert.alert_type}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {formatNumber(alert.threshold_value)} {UNIT_TYPE_LABELS[alert.product.unit_type] || alert.product.unit_type}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {formatNumber(alert.current_value)} {UNIT_TYPE_LABELS[alert.product.unit_type] || alert.product.unit_type}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {alert.notes || 'N/A'}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`px-2.5 py-1 inline-flex text-xs font-semibold rounded-full ${getStatusColor(alert)}`}>
                                                        {alert.is_resolved ? 'Résolue' : 'Active'}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    {!alert.is_resolved && auth.user.role !== 'data_entry' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => confirmResolveAlert(alert)}
                                                            title="Résoudre"
                                                            className="text-gray-400 hover:text-green-600 transition-colors"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
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
                    </div>
                </div>
            </div>

            <Modal show={confirmingResolve} onClose={closeModal}>
                <form onSubmit={resolveAlert} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Êtes-vous sûr de vouloir résoudre cette alerte ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Cette action marquera l'alerte comme résolue et elle n'apparaîtra plus comme active.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <PrimaryButton className="ml-3" disabled={processing}>
                            Résoudre l'Alerte
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
