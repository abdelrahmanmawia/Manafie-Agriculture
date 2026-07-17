import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { useState } from 'react';

export default function Edit({ auth, purchaseOrder, products }) {
    const { data, setData, put, processing, errors } = useForm({
        supplier_name: purchaseOrder.supplier_name || '',
        order_date: purchaseOrder.order_date,
        expected_date: purchaseOrder.expected_date || '',
        status: purchaseOrder.status,
        notes: purchaseOrder.notes || '',
        items: purchaseOrder.items.map(item => ({
            id: item.id,
            product_id: item.product_id,
            product_name: item.product.name,
            quantity_ordered: item.quantity_ordered,
            quantity_received: item.quantity_received,
            unit_price: item.unit_price,
            total_price: item.total_price,
            batch_number: item.batch_number || '',
        })),
        new_item_product_id: '',
        new_item_quantity_ordered: '',
        new_item_unit_price: '',
    });

    const [showAddItemForm, setShowAddItemForm] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        put(route('stock.purchase-orders.update', purchaseOrder.id));
    };

    const addItem = (e) => {
        e.preventDefault();
        const selectedProduct = products.find(p => p.id == data.new_item_product_id);
        if (!selectedProduct) return;

        const newItem = {
            product_id: data.new_item_product_id,
            quantity_ordered: data.new_item_quantity_ordered,
            unit_price: data.new_item_unit_price,
        };

        // Make an Inertia POST request to add the item
        router.post(route('stock.purchase-orders.items', purchaseOrder.id), newItem, {
            onSuccess: () => {
                // Refresh the page to get updated items
                router.reload({ only: ['purchaseOrder'] });
                setData({
                    ...data,
                    new_item_product_id: '',
                    new_item_quantity_ordered: '',
                    new_item_unit_price: '',
                });
                setShowAddItemForm(false);
            },
            onError: (itemErrors) => {
                // Handle errors for new item addition
                console.error("Error adding item:", itemErrors);
            }
        });
    };

    const handleReceive = () => {
        router.post(route('stock.purchase-orders.receive', purchaseOrder.id), {
            items: data.items.map(item => ({
                item_id: item.id,
                quantity_received: item.quantity_received,
                batch_number: item.batch_number,
            })),
            received_date: new Date().toISOString().slice(0, 10), // Current date
        }, {
            onSuccess: () => {
                router.reload({ only: ['purchaseOrder'] });
            },
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Modifier la Commande d'Achat</h2>
                    <Link
                        href={route('stock.purchase-orders.index')}
                        className="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                    >
                        Retour aux Commandes
                    </Link>
                </div>
            }
        >
            <Head title={`Modifier: ${purchaseOrder.order_number}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">Modifier la Commande #{purchaseOrder.order_number}</h3>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="space-y-4">
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
                                            disabled // Order date should not be editable after creation
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
                                <SecondaryButton onClick={() => window.history.back()}>Annuler</SecondaryButton>
                                <PrimaryButton disabled={processing}>Mettre à Jour la Commande</PrimaryButton>
                            </div>
                        </form>

                        <div className="mt-8">
                            <h4 className="text-lg font-bold mb-4 border-b pb-2">Articles de la Commande</h4>
                            <div className="overflow-x-auto mb-4">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Produit
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Commandé
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Reçu
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Prix Unitaire
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Total
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Lot
                                            </th>
                                            <th scope="col" className="relative px-6 py-3">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {data.items.map((item, index) => (
                                            <tr key={item.id}>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    {item.product_name}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {item.quantity_ordered}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <TextInput
                                                        type="number"
                                                        value={item.quantity_received}
                                                        onChange={(e) => {
                                                            const newItems = [...data.items];
                                                            newItems[index].quantity_received = e.target.value;
                                                            setData('items', newItems);
                                                        }}
                                                        className="w-24 text-sm"
                                                    />
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {item.unit_price} MAD
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {item.total_price} MAD
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <TextInput
                                                        type="text"
                                                        value={item.batch_number}
                                                        onChange={(e) => {
                                                            const newItems = [...data.items];
                                                            newItems[index].batch_number = e.target.value;
                                                            setData('items', newItems);
                                                        }}
                                                        className="w-24 text-sm"
                                                    />
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    {/* Add delete item functionality here if needed */}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="flex justify-end">
                                <PrimaryButton onClick={handleReceive} disabled={processing}>
                                    Enregistrer la Réception
                                </PrimaryButton>
                            </div>

                            <div className="mt-6">
                                <button
                                    type="button"
                                    onClick={() => setShowAddItemForm(!showAddItemForm)}
                                    className="text-blue-600 hover:text-blue-800 font-medium"
                                >
                                    {showAddItemForm ? 'Annuler l\'ajout d\'article' : '+ Ajouter un nouvel article'}
                                </button>

                                {showAddItemForm && (
                                    <form onSubmit={addItem} className="mt-4 p-4 border rounded-md bg-gray-50 space-y-4">
                                        <h5 className="font-bold">Ajouter un article à la commande</h5>
                                        <div>
                                            <InputLabel htmlFor="new_item_product_id" value="Produit *" />
                                            <select
                                                id="new_item_product_id"
                                                className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                                value={data.new_item_product_id}
                                                onChange={(e) => {
                                                    const selectedProduct = products.find(p => p.id == e.target.value);
                                                    setData({
                                                        ...data,
                                                        new_item_product_id: e.target.value,
                                                        new_item_unit_price: selectedProduct ? selectedProduct.unit_cost : '',
                                                    });
                                                }}
                                                required
                                            >
                                                <option value="">-- Sélectionner un produit --</option>
                                                {products.map((product) => (
                                                    <option key={product.id} value={product.id}>{product.name} ({product.unit_type})</option>
                                                ))}
                                            </select>
                                            <InputError message={errors.new_item_product_id} className="mt-2" />
                                        </div>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div>
                                                <InputLabel htmlFor="new_item_quantity_ordered" value="Quantité Commandée *" />
                                                <TextInput
                                                    id="new_item_quantity_ordered"
                                                    type="number"
                                                    className="mt-1 block w-full"
                                                    value={data.new_item_quantity_ordered}
                                                    onChange={(e) => setData('new_item_quantity_ordered', e.target.value)}
                                                    required
                                                />
                                                <InputError message={errors.new_item_quantity_ordered} className="mt-2" />
                                            </div>
                                            <div>
                                                <InputLabel htmlFor="new_item_unit_price" value="Prix Unitaire *" />
                                                <TextInput
                                                    id="new_item_unit_price"
                                                    type="number"
                                                    step="0.01"
                                                    className="mt-1 block w-full"
                                                    value={data.new_item_unit_price}
                                                    onChange={(e) => setData('new_item_unit_price', e.target.value)}
                                                    required
                                                />
                                                <InputError message={errors.new_item_unit_price} className="mt-2" />
                                            </div>
                                        </div>
                                        <div className="flex justify-end">
                                            <PrimaryButton type="submit" disabled={processing}>Ajouter l'Article</PrimaryButton>
                                        </div>
                                    </form>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
