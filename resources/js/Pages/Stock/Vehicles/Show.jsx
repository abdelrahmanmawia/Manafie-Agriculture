import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { useState } from 'react';
import Modal from '@/Components/Modal';

export default function Show({ auth, vehicle }) {
    const [confirmingVehicleDeletion, setConfirmingVehicleDeletion] = useState(false);
    const { delete: destroy, processing } = useForm();

    const confirmVehicleDeletion = () => {
        setConfirmingVehicleDeletion(true);
    };

    const deleteVehicle = (e) => {
        e.preventDefault();
        destroy(route('stock.vehicles.destroy', vehicle.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => closeModal(),
            onFinish: () => closeModal(),
        });
    };

    const closeModal = () => {
        setConfirmingVehicleDeletion(false);
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Détails du Véhicule</h2>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('stock.vehicles.edit', vehicle.id)}
                            className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                        >
                            Modifier
                        </Link>
                        {auth.user.role !== 'data_entry' && (
                            <DangerButton onClick={confirmVehicleDeletion}>Supprimer</DangerButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Véhicule: ${vehicle.name}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-xl font-bold mb-4 border-b pb-2">{vehicle.name}</h3>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <p className="text-gray-600"><strong>Plaque d'Immatriculation:</strong> {vehicle.plate_number}</p>
                                <p className="text-gray-600"><strong>Type:</strong> {vehicle.type}</p>
                                <p className="text-gray-600"><strong>Marque:</strong> {vehicle.brand || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Modèle:</strong> {vehicle.model || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Année:</strong> {vehicle.year || 'N/A'}</p>
                            </div>
                            <div>
                                <p className="text-gray-600"><strong>Type de Carburant:</strong> {vehicle.fuel_type}</p>
                                <p className="text-gray-600"><strong>Capacité du Réservoir:</strong> {vehicle.fuel_capacity_liters ? `${vehicle.fuel_capacity_liters} Litres` : 'N/A'}</p>
                                <p className="text-gray-600"><strong>Conducteur par Défaut:</strong> {vehicle.default_driver?.full_name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Localisation Actuelle:</strong> {vehicle.current_location || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Actif:</strong> {vehicle.is_active ? 'Oui' : 'Non'}</p>
                            </div>
                        </div>

                        {vehicle.notes && (
                            <div className="mt-6">
                                <h4 className="text-lg font-bold mb-2">Notes</h4>
                                <p className="bg-gray-100 p-4 rounded-md text-sm">{vehicle.notes}</p>
                            </div>
                        )}

                        <div className="mt-6">
                            <h4 className="text-lg font-bold mb-2">Transactions de Carburant Récents</h4>
                            {vehicle.fuel_transactions && vehicle.fuel_transactions.length > 0 ? (
                                <ul className="list-disc list-inside">
                                    {vehicle.fuel_transactions.map(transaction => (
                                        <li key={transaction.id} className="text-gray-600">
                                            {transaction.date}: {transaction.transaction_type} {transaction.quantity_liters} Litres (Coût: {transaction.total_cost} MAD)
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-gray-500 italic">Aucune transaction de carburant récente.</p>
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
