import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { useState } from 'react';
import Modal from '@/Components/Modal';

export default function Show({ auth, manualStockEntry }) {
    const [confirmingEntryDeletion, setConfirmingEntryDeletion] = useState(false);
    const [confirmingVerification, setConfirmingVerification] = useState(false);
    const { delete: destroy, post, processing } = useForm();

    const confirmEntryDeletion = () => {
        setConfirmingEntryDeletion(true);
    };

    const deleteEntry = (e) => {
        e.preventDefault();
        destroy(route('stock.manual-entries.destroy', manualStockEntry.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => closeModal(),
            onFinish: () => closeModal(),
        });
    };

    const confirmVerification = () => {
        setConfirmingVerification(true);
    };

    const verifyEntry = (e) => {
        e.preventDefault();
        post(route('stock.manual-entries.verify', manualStockEntry.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => closeModal(),
            onFinish: () => closeModal(),
        });
    };

    const closeModal = () => {
        setConfirmingEntryDeletion(false);
        setConfirmingVerification(false);
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const getEntryTypeColor = (type) => {
        switch (type) {
            case 'consumption': return 'bg-red-100 text-red-800';
            case 'transfer': return 'bg-blue-100 text-blue-800';
            case 'loss': return 'bg-orange-100 text-orange-800';
            case 'theft': return 'bg-purple-100 text-purple-800';
            case 'damage': return 'bg-yellow-100 text-yellow-800';
            default: return 'bg-gray-100 text-gray-800';
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Détails de l'Entrée Manuelle de Stock</h2>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('stock.manual-entries.edit', manualStockEntry.id)}
                            className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                        >
                            Modifier
                        </Link>
                        {!manualStockEntry.is_verified && auth.user.role !== 'data_entry' && (
                            <PrimaryButton onClick={confirmVerification}>Vérifier</PrimaryButton>
                        )}
                        {auth.user.role !== 'data_entry' && (
                            <DangerButton onClick={confirmEntryDeletion}>Supprimer</DangerButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Entrée Manuelle: ${manualStockEntry.id}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-xl font-bold mb-4 border-b pb-2">Entrée Manuelle #{manualStockEntry.id}</h3>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <p className="text-gray-600"><strong>Date:</strong> {formatDate(manualStockEntry.date)}</p>
                                <p className="text-gray-600"><strong>Produit:</strong> {manualStockEntry.product?.name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Type d'Entrée:</strong> <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getEntryTypeColor(manualStockEntry.entry_type)}`}>{manualStockEntry.entry_type}</span></p>
                                <p className="text-gray-600"><strong>Quantité:</strong> {manualStockEntry.quantity} {manualStockEntry.product?.unit_type || ''}</p>
                                <p className="text-gray-600"><strong>Employé:</strong> {manualStockEntry.employee?.full_name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Véhicule:</strong> {manualStockEntry.vehicle?.name || 'N/A'}</p>
                            </div>
                            <div>
                                <p className="text-gray-600"><strong>Opération:</strong> {manualStockEntry.operation?.name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Bloc:</strong> {manualStockEntry.bloc?.name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Secteur:</strong> {manualStockEntry.sector?.name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Parcelle:</strong> {manualStockEntry.parcelle?.name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Saisie par:</strong> {manualStockEntry.enteredBy?.name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Vérifié:</strong> {manualStockEntry.is_verified ? 'Oui' : 'Non'}</p>
                                {manualStockEntry.is_verified && (
                                    <>
                                        <p className="text-gray-600"><strong>Vérifié par:</strong> {manualStockEntry.verifiedBy?.name || 'N/A'}</p>
                                        <p className="text-gray-600"><strong>Date de Vérification:</strong> {formatDate(manualStockEntry.verified_at)}</p>
                                    </>
                                )}
                            </div>
                        </div>

                        {manualStockEntry.notes && (
                            <div className="mt-6">
                                <h4 className="text-lg font-bold mb-2">Notes</h4>
                                <p className="bg-gray-100 p-4 rounded-md text-sm">{manualStockEntry.notes}</p>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <Modal show={confirmingEntryDeletion} onClose={closeModal}>
                <form onSubmit={deleteEntry} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Êtes-vous sûr de vouloir supprimer cette entrée manuelle de stock ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Cette action est irréversible et supprimera toutes les données associées à cette entrée.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <DangerButton className="ml-3" disabled={processing}>
                            Supprimer l'Entrée
                        </DangerButton>
                    </div>
                </form>
            </Modal>

            <Modal show={confirmingVerification} onClose={closeModal}>
                <form onSubmit={verifyEntry} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Confirmer la vérification de cette entrée de stock ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Cette action marquera l'entrée comme vérifiée.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <PrimaryButton className="ml-3" disabled={processing}>
                            Vérifier l'Entrée
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
