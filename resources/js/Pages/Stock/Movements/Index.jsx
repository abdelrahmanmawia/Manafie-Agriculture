import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { formatNumber, formatMAD } from '@/utils/number';
import { MOVEMENT_TYPE_LABELS as MOVEMENT_LABELS, UNIT_TYPE_LABELS } from '@/utils/stockLabels';

export default function Index({ auth, stockMovements, products, suppliers }) {
    const [isReceiving, setIsReceiving] = useState(false);

    const { data, setData, post, processing, reset, errors } = useForm({
        product_id: products.length > 0 ? products[0].id : '',
        quantity: '',
        unit_cost: '',
        batch_number: '',
        supplier_id: '',
        numero_bl: '',
        date: new Date().toISOString().slice(0, 10),
        notes: '',
    });

    // Support being deep-linked from the Inventory page with ?action=receive
    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('action') === 'receive') {
            setIsReceiving(true);
        }
    }, []);

    const selectedProduct = products.find((p) => String(p.id) === String(data.product_id));

    const submit = (e) => {
        e.preventDefault();
        post(route('stock.movements.in'), {
            onSuccess: () => {
                reset();
                setIsReceiving(false);
            },
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <div>
                        <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Mouvements de Stock</h2>
                        <p className="text-sm text-gray-500 mt-1">Historique des entrées et sorties du magasin</p>
                    </div>
                    <button
                        onClick={() => setIsReceiving(true)}
                        className="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Nouvelle Réception
                    </button>
                </div>
            }
        >
            <Head title="Mouvements de Stock" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Tous les Mouvements de Stock</h3>
                        </div>

                        {stockMovements.length === 0 ? (
                            <div className="text-center py-16">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                                <p className="mt-4 text-gray-500">Aucun mouvement de stock enregistré pour le moment.</p>
                                <button onClick={() => setIsReceiving(true)} className="mt-4 text-green-600 hover:text-green-700 font-medium">
                                    Enregistrer une réception
                                </button>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Produit</th>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Quantité</th>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Coût Total</th>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Effectué par</th>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Pris par</th>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Destination</th>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {stockMovements.map((movement) => {
                                            const meta = MOVEMENT_LABELS[movement.movement_type] ?? { label: movement.movement_type, className: 'bg-gray-100 text-gray-700' };
                                            return (
                                                <tr key={movement.id} className="hover:bg-gray-50 transition-colors">
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{new Date(movement.date).toLocaleDateString('fr-FR')}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{movement.product?.name ?? 'Produit supprimé'}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className={`text-xs font-medium px-2.5 py-1 rounded-full ${meta.className}`}>{meta.label}</span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{formatNumber(movement.quantity)} {UNIT_TYPE_LABELS[movement.product?.unit_type] || movement.product?.unit_type}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.total_cost ? formatMAD(movement.total_cost) : 'N/A'}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.performed_by?.name || 'N/A'}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.reference?.employee?.full_name || 'N/A'}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {[movement.reference?.bloc?.name, movement.reference?.sector?.name, movement.reference?.parcelle?.name].filter(Boolean).join(' / ') || movement.reference?.vehicle?.name || 'N/A'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.notes || 'N/A'}</td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* RECEPTION MODAL */}
            <Modal show={isReceiving} onClose={() => setIsReceiving(false)}>
                <div className="p-8">
                    <div className="flex justify-between items-center mb-6">
                        <div className="flex items-center gap-3">
                            <div className="h-10 w-10 bg-green-100 rounded-xl flex items-center justify-center">
                                <svg className="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                            </div>
                            <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">Nouvelle Réception de Stock</h3>
                        </div>
                        <button onClick={() => setIsReceiving(false)} className="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {products.length === 0 ? (
                        <p className="text-sm text-gray-500">
                            Aucun produit actif. <Link href={route('stock.products.index')} className="text-primary-600 hover:underline">Créez d'abord un produit</Link>.
                        </p>
                    ) : (
                        <form onSubmit={submit} className="space-y-6">
                            <div className="bg-gray-50 rounded-xl p-4">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div className="md:col-span-2">
                                        <InputLabel htmlFor="product_id" value="Produit *" />
                                        <select
                                            id="product_id"
                                            className="mt-1 block w-full border-gray-300 focus:border-green-500 focus:ring-green-500 rounded-lg shadow-sm"
                                            value={data.product_id}
                                            onChange={(e) => setData('product_id', e.target.value)}
                                            required
                                        >
                                            {products.map((product) => (
                                                <option key={product.id} value={product.id}>{product.name}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.product_id} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="quantity" value={`Quantité reçue${selectedProduct ? ` (${UNIT_TYPE_LABELS[selectedProduct.unit_type] || selectedProduct.unit_type})` : ''} *`} />
                                        <TextInput
                                            id="quantity"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            className="mt-1 block w-full"
                                            value={data.quantity}
                                            onChange={(e) => setData('quantity', e.target.value)}
                                            required
                                            autoFocus
                                        />
                                        <InputError message={errors.quantity} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="unit_cost" value="Coût Unitaire (MAD)" />
                                        <TextInput
                                            id="unit_cost"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            className="mt-1 block w-full"
                                            value={data.unit_cost}
                                            onChange={(e) => setData('unit_cost', e.target.value)}
                                            placeholder={selectedProduct?.unit_cost ?? 'Ex: 15.50'}
                                        />
                                        <InputError message={errors.unit_cost} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="date" value="Date de Réception *" />
                                        <TextInput
                                            id="date"
                                            type="date"
                                            className="mt-1 block w-full"
                                            value={data.date}
                                            onChange={(e) => setData('date', e.target.value)}
                                            required
                                        />
                                        <InputError message={errors.date} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="batch_number" value="N° de Lot" />
                                        <TextInput
                                            id="batch_number"
                                            type="text"
                                            className="mt-1 block w-full"
                                            value={data.batch_number}
                                            onChange={(e) => setData('batch_number', e.target.value)}
                                            placeholder="Optionnel"
                                        />
                                        <InputError message={errors.batch_number} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="supplier_id" value="Fournisseur" />
                                        <select
                                            id="supplier_id"
                                            className="mt-1 block w-full border-gray-300 focus:border-green-500 focus:ring-green-500 rounded-lg shadow-sm"
                                            value={data.supplier_id}
                                            onChange={(e) => setData('supplier_id', e.target.value)}
                                        >
                                            <option value="">-- Aucun --</option>
                                            {suppliers.filter((s) => s.is_active || String(s.id) === String(data.supplier_id)).map((s) => (
                                                <option key={s.id} value={s.id}>{s.name}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.supplier_id} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="numero_bl" value="N° B.L" />
                                        <TextInput
                                            id="numero_bl"
                                            type="text"
                                            className="mt-1 block w-full"
                                            value={data.numero_bl}
                                            onChange={(e) => setData('numero_bl', e.target.value)}
                                            placeholder="Optionnel"
                                        />
                                        <InputError message={errors.numero_bl} className="mt-2" />
                                    </div>

                                    <div className="md:col-span-2">
                                        <InputLabel htmlFor="notes" value="Notes" />
                                        <textarea
                                            id="notes"
                                            rows={2}
                                            className="mt-1 block w-full border-gray-300 focus:border-green-500 focus:ring-green-500 rounded-lg shadow-sm"
                                            value={data.notes}
                                            onChange={(e) => setData('notes', e.target.value)}
                                            placeholder="Optionnel"
                                        />
                                        <InputError message={errors.notes} className="mt-2" />
                                    </div>
                                </div>
                            </div>

                            <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                                <SecondaryButton onClick={() => setIsReceiving(false)}>Annuler</SecondaryButton>
                                <PrimaryButton disabled={processing} className="bg-green-600 hover:bg-green-700">
                                    {processing ? 'Enregistrement...' : 'Enregistrer la Réception'}
                                </PrimaryButton>
                            </div>
                        </form>
                    )}
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
