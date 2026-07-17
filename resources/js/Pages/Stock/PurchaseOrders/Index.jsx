import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

export default function Index({ auth, purchaseOrders }) {
    const [isCreating, setIsCreating] = useState(false);

    const { data, setData, post, processing, reset, errors } = useForm({
        order_number: '',
        supplier_name: '',
        order_date: '',
        expected_date: '',
        status: 'pending',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('stock.purchase-orders.store'), {
            onSuccess: () => {
                reset();
                setIsCreating(false);
            },
        });
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
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Gestion des Commandes d'Achat</h2>
                    {auth.user.role !== 'data_entry' && (
                        <button
                            onClick={() => setIsCreating(true)}
                            className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                        >
                            <span>+</span> Créer une Commande
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Commandes d'Achat" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <h3 className="text-lg font-bold mb-6 border-b pb-2">Liste des Commandes d'Achat</h3>

                            {purchaseOrders.length === 0 ? (
                                <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                                    <p className="text-gray-500 italic">Aucune commande d'achat enregistrée pour le moment. Cliquez sur "+ Créer une Commande" pour commencer.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Numéro de Commande
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Fournisseur
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Date de Commande
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Date Prévue
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Statut
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Montant Total
                                                </th>
                                                <th scope="col" className="relative px-6 py-3">
                                                    <span className="sr-only">Actions</span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {purchaseOrders.map((order) => (
                                                <tr key={order.id}>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        {order.order_number}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {order.supplier_name || 'N/A'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {formatDate(order.order_date)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {formatDate(order.expected_date)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {order.status}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {order.total_amount} MAD
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <Link href={route('stock.purchase-orders.show', order.id)} className="text-indigo-600 hover:text-indigo-900 mr-4">
                                                            Voir
                                                        </Link>
                                                        <Link href={route('stock.purchase-orders.edit', order.id)} className="text-blue-600 hover:text-blue-900">
                                                            Modifier
                                                        </Link>
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

            {/* CREATE PURCHASE ORDER MODAL */}
            <Modal show={isCreating} onClose={() => setIsCreating(false)}>
                <div className="p-8">
                    <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">Créer une Nouvelle Commande d'Achat</h3>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-4">
                            <div>
                                <InputLabel htmlFor="order_number" value="Numéro de Commande *" />
                                <TextInput
                                    id="order_number"
                                    type="text"
                                    className="mt-1 block w-full"
                                    value={data.order_number}
                                    onChange={(e) => setData('order_number', e.target.value)}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.order_number} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="supplier_name" value="Nom du Fournisseur" />
                                <TextInput
                                    id="supplier_name"
                                    type="text"
                                    className="mt-1 block w-full"
                                    value={data.supplier_name}
                                    onChange={(e) => setData('supplier_name', e.target.value)}
                                />
                                <InputError message={errors.supplier_name} className="mt-2" />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel htmlFor="order_date" value="Date de Commande *" />
                                    <TextInput
                                        id="order_date"
                                        type="date"
                                        className="mt-1 block w-full"
                                        value={data.order_date}
                                        onChange={(e) => setData('order_date', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.order_date} className="mt-2" />
                                </div>
                                <div>
                                    <InputLabel htmlFor="expected_date" value="Date de Livraison Prévue" />
                                    <TextInput
                                        id="expected_date"
                                        type="date"
                                        className="mt-1 block w-full"
                                        value={data.expected_date}
                                        onChange={(e) => setData('expected_date', e.target.value)}
                                    />
                                    <InputError message={errors.expected_date} className="mt-2" />
                                </div>
                            </div>

                            <div>
                                <InputLabel htmlFor="status" value="Statut *" />
                                <select
                                    id="status"
                                    className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                    required
                                >
                                    <option value="pending">En attente</option>
                                    <option value="ordered">Commandé</option>
                                    <option value="partial">Partiel</option>
                                    <option value="received">Reçu</option>
                                    <option value="cancelled">Annulé</option>
                                </select>
                                <InputError message={errors.status} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="notes" value="Notes" />
                                <textarea
                                    id="notes"
                                    className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    rows="3"
                                ></textarea>
                                <InputError message={errors.notes} className="mt-2" />
                            </div>
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsCreating(false)}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={processing}>Créer la Commande</PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
