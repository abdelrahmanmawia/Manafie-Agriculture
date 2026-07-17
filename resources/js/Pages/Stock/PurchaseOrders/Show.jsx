import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { useState } from 'react';
import Modal from '@/Components/Modal';

export default function Show({ auth, purchaseOrder }) {
    const [confirmingOrderDeletion, setConfirmingOrderDeletion] = useState(false);
    const { delete: destroy, processing } = useForm();

    const confirmOrderDeletion = () => {
        setConfirmingOrderDeletion(true);
    };

    const deleteOrder = (e) => {
        e.preventDefault();
        destroy(route('stock.purchase-orders.destroy', purchaseOrder.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => closeModal(),
            onFinish: () => closeModal(),
        });
    };

    const closeModal = () => {
        setConfirmingOrderDeletion(false);
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
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Détails de la Commande d'Achat</h2>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('stock.purchase-orders.edit', purchaseOrder.id)}
                            className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                        >
                            Modifier
                        </Link>
                        {auth.user.role !== 'data_entry' && (
                            <DangerButton onClick={confirmOrderDeletion}>Supprimer</DangerButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Commande: ${purchaseOrder.order_number}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-xl font-bold mb-4 border-b pb-2">Commande #{purchaseOrder.order_number}</h3>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <p className="text-gray-600"><strong>Fournisseur:</strong> {purchaseOrder.supplier_name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Date de Commande:</strong> {formatDate(purchaseOrder.order_date)}</p>
                                <p className="text-gray-600"><strong>Date Prévue:</strong> {formatDate(purchaseOrder.expected_date)}</p>
                                <p className="text-gray-600"><strong>Statut:</strong> {purchaseOrder.status}</p>
                                <p className="text-gray-600"><strong>Montant Total:</strong> {purchaseOrder.total_amount} MAD</p>
                            </div>
                            <div>
                                <p className="text-gray-600"><strong>Reçue par:</strong> {purchaseOrder.received_by?.name || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Reçue le:</strong> {formatDate(purchaseOrder.received_at)}</p>
                                <p className="text-gray-600"><strong>Notes:</strong> {purchaseOrder.notes || 'N/A'}</p>
                            </div>
                        </div>

                        <div className="mt-6">
                            <h4 className="text-lg font-bold mb-2">Articles de la Commande</h4>
                            {purchaseOrder.items && purchaseOrder.items.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Produit
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Quantité Commandée
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Quantité Reçue
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Prix Unitaire
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Prix Total
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Lot
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {purchaseOrder.items.map((item) => (
                                                <tr key={item.id}>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        {item.product.name}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {item.quantity_ordered} {item.product.unit_type}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {item.quantity_received} {item.product.unit_type}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {item.unit_price} MAD
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {item.total_price} MAD
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {item.batch_number || 'N/A'}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <p className="text-gray-500 italic">Aucun article dans cette commande.</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={confirmingOrderDeletion} onClose={closeModal}>
                <form onSubmit={deleteOrder} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Êtes-vous sûr de vouloir supprimer cette commande d'achat ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Une fois la commande supprimée, toutes ses ressources et données associées seront définitivement effacées.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <DangerButton className="ml-3" disabled={processing}>
                            Supprimer la Commande
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
