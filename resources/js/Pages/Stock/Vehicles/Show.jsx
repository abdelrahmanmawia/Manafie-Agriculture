import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import { formatNumber, formatInt, formatMAD } from '@/utils/number';
import { VEHICLE_TYPE_LABELS as TYPE_LABELS, FUEL_TYPE_LABELS, ENTRY_TYPE_LABELS, UNIT_TYPE_LABELS } from '@/utils/stockLabels';

export default function Show({ auth, vehicle }) {
    const [confirmingVehicleDeletion, setConfirmingVehicleDeletion] = useState(false);
    const { delete: destroy, processing } = useForm();

    const confirmVehicleDeletion = () => setConfirmingVehicleDeletion(true);
    const closeModal = () => setConfirmingVehicleDeletion(false);

    const deleteVehicle = (e) => {
        e.preventDefault();
        destroy(route('stock.vehicles.destroy', vehicle.id), {
            preserveScroll: true,
            onSuccess: closeModal,
            onError: closeModal,
            onFinish: closeModal,
        });
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const fuelTransactions = vehicle.fuel_transactions ?? [];
    const manualStockEntries = vehicle.manual_stock_entries ?? [];

    const totalFuelLiters = fuelTransactions.reduce((sum, t) => sum + parseFloat(t.quantity_liters || 0), 0);
    const totalFuelCost = fuelTransactions.reduce((sum, t) => sum + parseFloat(t.total_cost || 0), 0);

    const activity = [
        ...fuelTransactions.map((t) => ({
            key: `ft-${t.id}`,
            date: t.date,
            label: 'Ravitaillement',
            badgeClass: 'bg-orange-100 text-orange-800',
            product_name: t.product?.name ?? 'Carburant',
            quantity: t.quantity_liters,
            unit_type: 'L',
            cost: t.total_cost,
            person: t.driver?.full_name,
            href: route('stock.fuel-transactions.show', t.id),
        })),
        ...manualStockEntries.map((e) => ({
            key: `mse-${e.id}`,
            date: e.date,
            label: ENTRY_TYPE_LABELS[e.entry_type] ?? e.entry_type,
            badgeClass: 'bg-indigo-100 text-indigo-800',
            product_name: e.product?.name ?? 'N/A',
            quantity: e.quantity,
            unit_type: e.product?.unit_type ?? '',
            cost: e.stock_movement?.total_cost ?? null,
            person: e.employee?.full_name,
            href: route('stock.manual-entries.show', e.id),
        })),
    ].sort((a, b) => new Date(b.date) - new Date(a.date));

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div className="flex items-center gap-4">
                        <Link href={route('stock.vehicles.index')} className="text-gray-500 hover:text-gray-700 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-bold text-2xl text-gray-800 leading-tight">Détails du Véhicule</h2>
                            <p className="text-sm text-gray-500 mt-1">Informations et historique de consommation</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('stock.vehicles.edit', vehicle.id)}
                            className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl font-semibold shadow-lg hover:shadow-xl transition-all flex items-center gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            Modifier
                        </Link>
                        {auth.user.role !== 'data_entry' && (
                            <DangerButton onClick={confirmVehicleDeletion} className="rounded-xl">Supprimer</DangerButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Véhicule: ${vehicle.name}`} />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Vehicle Header Card */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6">
                            <div className="flex items-start justify-between">
                                <div className="flex items-center gap-4">
                                    <div className="flex-shrink-0 h-16 w-16 bg-gray-100 rounded-xl flex items-center justify-center">
                                        <svg className="h-8 w-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l1.5-4.5A2 2 0 018.4 7h7.2a2 2 0 011.9 1.5L19 13m-14 0h14m-14 0v4a1 1 0 001 1h1a1 1 0 001-1v-1h8v1a1 1 0 001 1h1a1 1 0 001-1v-4" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-bold text-gray-900">{vehicle.name}</h3>
                                        <div className="flex items-center gap-3 mt-2">
                                            <span className="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                {TYPE_LABELS[vehicle.type] || vehicle.type}
                                            </span>
                                            <span className="text-sm text-gray-500">{vehicle.plate_number}</span>
                                        </div>
                                    </div>
                                </div>
                                <span className={`px-4 py-2 rounded-lg font-semibold text-sm ${vehicle.is_active ? 'bg-green-50 text-green-600' : 'bg-gray-50 text-gray-500'}`}>
                                    {vehicle.is_active ? 'Actif' : 'Inactif'}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Pleins Enregistrés</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatInt(fuelTransactions.length)}</p>
                        </div>
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Carburant Total</p>
                            <p className="text-2xl font-bold text-orange-600 mt-1">{formatNumber(totalFuelLiters, 0)} L</p>
                        </div>
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Coût Carburant Total</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatMAD(totalFuelCost)}</p>
                        </div>
                    </div>

                    {/* Vehicle Information */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-bold text-gray-800">Informations</h4>
                        </div>
                        <div className="p-6 grid grid-cols-1 md:grid-cols-3 gap-x-8">
                            <div className="flex justify-between items-center py-2 border-b md:border-b-0 border-gray-50">
                                <span className="text-gray-500">Modèle</span>
                                <span className="font-medium text-gray-900">{vehicle.model || 'N/A'}</span>
                            </div>
                            <div className="flex justify-between items-center py-2 border-b md:border-b-0 border-gray-50">
                                <span className="text-gray-500">Type de Carburant</span>
                                <span className="font-medium text-gray-900">{FUEL_TYPE_LABELS[vehicle.fuel_type] || vehicle.fuel_type}</span>
                            </div>
                            <div className="flex justify-between items-center py-2">
                                <span className="text-gray-500">Conducteur par Défaut</span>
                                <span className="font-medium text-gray-900">{vehicle.default_driver?.full_name || 'N/A'}</span>
                            </div>
                        </div>
                    </div>

                    {vehicle.notes && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-bold text-gray-800">Notes</h4>
                            </div>
                            <div className="p-6">
                                <p className="bg-gray-50 p-4 rounded-lg text-sm text-gray-700">{vehicle.notes}</p>
                            </div>
                        </div>
                    )}

                    {/* Activity History */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-bold text-gray-800">Historique de Consommation</h4>
                            <p className="text-sm text-gray-500 mt-1">Pleins de carburant et sorties de stock liées à ce véhicule</p>
                        </div>
                        <div className="p-6">
                            {activity.length === 0 ? (
                                <div className="text-center py-8">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <p className="mt-4 text-gray-500">Aucune activité enregistrée pour ce véhicule</p>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {activity.slice(0, 10).map((item) => (
                                        <Link
                                            key={item.key}
                                            href={item.href}
                                            className="flex items-center justify-between p-4 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors"
                                        >
                                            <div className="flex items-center gap-4">
                                                <span className={`text-xs font-medium px-2.5 py-1 rounded-full ${item.badgeClass}`}>{item.label}</span>
                                                <div>
                                                    <p className="font-medium text-gray-900">{item.product_name}</p>
                                                    <p className="text-sm text-gray-500">{formatDate(item.date)}{item.person ? ` · ${item.person}` : ''}</p>
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <p className="font-semibold text-gray-700">{formatNumber(item.quantity)} {UNIT_TYPE_LABELS[item.unit_type] || item.unit_type}</p>
                                                {item.cost != null && <p className="text-sm text-gray-500">{formatMAD(item.cost)}</p>}
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={confirmingVehicleDeletion} onClose={closeModal}>
                <form onSubmit={deleteVehicle} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Êtes-vous sûr de vouloir supprimer ce véhicule ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Une fois le véhicule supprimé, toutes ses ressources et données associées seront définitivement effacées.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <DangerButton className="ml-3" disabled={processing}>
                            Supprimer le Véhicule
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
