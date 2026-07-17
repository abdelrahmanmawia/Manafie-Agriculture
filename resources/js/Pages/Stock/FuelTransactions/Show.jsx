import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { useState } from 'react';
import Modal from '@/Components/Modal';

export default function Show({ auth, fuelTransaction }) {
    const [confirmingTransactionDeletion, setConfirmingTransactionDeletion] = useState(false);
    const { delete: destroy, processing } = useForm();

    const confirmTransactionDeletion = () => {
        setConfirmingTransactionDeletion(true);
    };

    const deleteTransaction = (e) => {
        e.preventDefault();
        destroy(route('stock.fuel-transactions.destroy', fuelTransaction.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => closeModal(),
            onFinish: () => closeModal(),
        });
    };

    const closeModal = () => {
        setConfirmingTransactionDeletion(false);
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Détails de la Transaction de Carburant</h2>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('stock.fuel-transactions.edit', fuelTransaction.id)}
                            className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                        >
                            Modifier
                        </Link>
                        {auth.user.role !== 'data_entry' && (
                            <DangerButton onClick={confirmTransactionDeletion}>Supprimer</DangerButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Transaction: ${fuelTransaction.id}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-xl font-bold mb-4 border-b pb-2">Transaction #{fuelTransaction.id}</h3>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <p className="text-gray-600"><strong>Date:</strong> {formatDate(fuelTransaction.date)}</p>
                                <p className="text-gray-600"><strong>Véhicule:</strong> {fuelTransaction.vehicle?.name || 'N/A'} ({fuelTransaction.vehicle?.plate_number || 'N/A'})</p>
                                <p className="text-gray-600"><strong>Produit Carburant:</strong> {fuelTransaction.product?.name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Type de Transaction:</strong> {fuelTransaction.transaction_type}</p>
                                <p className="text-gray-600"><strong>Quantité (Litres):</strong> {fuelTransaction.quantity_liters}</p>
                            </div>
                            <div>
                                <p className="text-gray-600"><strong>Prix Unitaire par Litre:</strong> {fuelTransaction.unit_price_per_liter ? `${fuelTransaction.unit_price_per_liter} MAD` : 'N/A'}</p>
                                <p className="text-gray-600"><strong>Coût Total:</strong> {fuelTransaction.total_cost ? `${fuelTransaction.total_cost} MAD` : 'N/A'}</p>
                                <p className="text-gray-600"><strong>Conducteur:</strong> {fuelTransaction.driver?.full_name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Effectué par:</strong> {fuelTransaction.performedBy?.name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Kilométrage (km):</strong> {fuelTransaction.odometer_km || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Heures Travaillées:</strong> {fuelTransaction.hours_worked || 'N/A'}</p>
                            </div>
                        </div>

                        {fuelTransaction.notes && (
                            <div className="mt-6">
                                <h4 className="text-lg font-bold mb-2">Notes</h4>
                                <p className="bg-gray-100 p-4 rounded-md text-sm">{fuelTransaction.notes}</p>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={confirmingTransactionDeletion} onClose={closeModal}>
                <form onSubmit={deleteTransaction} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Êtes-vous sûr de vouloir supprimer cette transaction de carburant ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Cette action est irréversible et supprimera toutes les données associées à cette transaction.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <DangerButton className="ml-3" disabled={processing}>
                            Supprimer la Transaction
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
