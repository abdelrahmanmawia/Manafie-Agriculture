import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Modal from '@/Components/Modal';
import FarmFilter from '@/Components/FarmFilter';

export default function Index({ auth, stockAlerts, farms, selectedFarmId }) {
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
                onSuccess: () => closeModal(),
                onError: () => closeModal(),
                onFinish: () => closeModal(),
            });
        }
    };

    const closeModal = () => {
        setConfirmingResolve(false);
        setSelectedAlert(null);
    };

    const getStatusColor = (alert) => {
        if (alert.is_resolved) {
            return 'bg-gray-200 text-gray-700';
        }
        switch (alert.alert_type) {
            case 'low_stock':
                return 'bg-red-100 text-red-800';
            case 'overstock':
                return 'bg-yellow-100 text-yellow-800';
            case 'expired':
                return 'bg-red-200 text-red-900';
            case 'expiring_soon':
                return 'bg-orange-100 text-orange-800';
            default:
                return 'bg-blue-100 text-blue-800';
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Gestion des Alertes de Stock</h2>
                </div>
            }
        >
            <Head title="Alertes de Stock" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <FarmFilter farms={farms} selectedFarmId={selectedFarmId} routeName="stock.alerts.index" />

                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <h3 className="text-lg font-bold mb-6 border-b pb-2">Liste des Alertes de Stock</h3>

                            {stockAlerts.length === 0 ? (
                                <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                                    <p className="text-gray-500 italic">Aucune alerte de stock active pour le moment.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Produit
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Type d'Alerte
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Seuil
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Valeur Actuelle
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Notes
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Statut
                                                </th>
                                                <th scope="col" className="relative px-6 py-3">
                                                    <span className="sr-only">Actions</span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {stockAlerts.map((alert) => (
                                                <tr key={alert.id}>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        {alert.product.name}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {alert.alert_type.replace('_', ' ')}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {alert.threshold_value} {alert.product.unit_type}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {alert.current_value} {alert.product.unit_type}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {alert.notes || 'N/A'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusColor(alert)}`}>
                                                            {alert.is_resolved ? 'Résolue' : 'Active'}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        {!alert.is_resolved && auth.user.role !== 'data_entry' && (
                                                            <PrimaryButton onClick={() => confirmResolveAlert(alert)}>
                                                                Résoudre
                                                            </PrimaryButton>
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
