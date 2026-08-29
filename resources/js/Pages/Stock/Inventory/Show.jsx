import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { formatNumber, formatMAD } from '@/utils/number';
import { UNIT_TYPE_LABELS, MOVEMENT_TYPE_LABELS as MOVEMENT_LABELS } from '@/utils/stockLabels';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

function destinationOf(movement) {
    const ref = movement.reference;
    if (!ref) return 'N/A';
    return ref.bloc?.name || ref.sector?.name || ref.parcelle?.name || ref.vehicle?.name || 'N/A';
}

export default function Show({ auth, stockInventory }) {
    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const [isCounting, setIsCounting] = useState(false);
    const countForm = useForm({
        product_id: stockInventory.product.id,
        counted_quantity: stockInventory.quantity_on_hand,
    });

    const openCount = () => {
        countForm.setData('counted_quantity', stockInventory.quantity_on_hand);
        countForm.clearErrors();
        setIsCounting(true);
    };
    const closeCount = () => {
        setIsCounting(false);
        countForm.reset();
    };
    const submitCount = (e) => {
        e.preventDefault();
        countForm.post(route('stock.inventory.count'), {
            preserveScroll: true,
            onSuccess: () => setIsCounting(false),
        });
    };

    const currentStock = parseFloat(stockInventory.quantity_on_hand || 0);
    const minStock = parseFloat(stockInventory.product.min_stock_level || 0);
    // Without a configured threshold there's no basis to call this stock "Bon" — a product
    // nobody has ever set a minimum for shouldn't look safer than one that has.
    const stockStatus = currentStock <= 0
        ? { status: 'Épuisé', bgColor: 'bg-red-50', textColor: 'text-red-600', color: 'bg-red-500' }
        : minStock <= 0
            ? { status: 'Seuil non défini', bgColor: 'bg-gray-50', textColor: 'text-gray-500', color: 'bg-gray-400' }
            : currentStock <= minStock
                ? { status: 'Faible', bgColor: 'bg-orange-50', textColor: 'text-orange-600', color: 'bg-orange-500' }
                : currentStock <= minStock * 1.5
                    ? { status: 'Normal', bgColor: 'bg-yellow-50', textColor: 'text-yellow-600', color: 'bg-yellow-500' }
                    : { status: 'Bon', bgColor: 'bg-green-50', textColor: 'text-green-600', color: 'bg-green-500' };

    const movements = stockInventory.product.stock_movements ?? [];

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div className="flex items-center gap-4">
                        <Link href={route('stock.inventory.index')} className="text-gray-500 hover:text-gray-700 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Détails de l'Inventaire</h2>
                            <p className="text-sm text-gray-500 mt-1">{stockInventory.product.name}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <button
                            onClick={openCount}
                            className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-xl font-black uppercase tracking-widest shadow-md transition-all"
                        >
                            Compter le Stock
                        </button>
                        <Link
                            href={route('stock.products.show', stockInventory.product.id)}
                            className="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-xl font-black uppercase tracking-widest shadow-md transition-all"
                        >
                            Voir le Produit
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title={`Inventaire: ${stockInventory.product.name}`} />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Header Card */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6">
                            <div className="flex items-start justify-between">
                                <div>
                                    <h3 className="text-2xl font-black text-gray-900 uppercase tracking-tighter">{stockInventory.product.name}</h3>
                                    <div className="flex items-center gap-3 mt-2">
                                        <span className="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-indigo-100 text-indigo-800">
                                            {stockInventory.product.category?.name ?? 'Sans catégorie'}
                                        </span>
                                        {stockInventory.batch_number && (
                                            <span className="text-sm text-gray-500">Lot: {stockInventory.batch_number}</span>
                                        )}
                                    </div>
                                </div>
                                <div className={`px-4 py-2 rounded-lg ${stockStatus.bgColor}`}>
                                    <div className="flex items-center gap-2">
                                        <span className={`h-3 w-3 rounded-full ${stockStatus.color}`}></span>
                                        <span className={`font-semibold ${stockStatus.textColor}`}>{stockStatus.status}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Quantité en Stock</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatNumber(stockInventory.quantity_on_hand)}</p>
                            <p className="text-xs text-gray-500 mt-1">{UNIT_TYPE_LABELS[stockInventory.product.unit_type] || stockInventory.product.unit_type}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Stock Minimum</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatNumber(stockInventory.product.min_stock_level)}</p>
                            <p className="text-xs text-gray-500 mt-1">{UNIT_TYPE_LABELS[stockInventory.product.unit_type] || stockInventory.product.unit_type}</p>
                        </div>
                    </div>

                    {/* Info */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Informations de Stock</h4>
                        </div>
                        <div className="p-6 grid grid-cols-1 md:grid-cols-2 gap-x-8">
                            <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                <span className="text-gray-500">Coût Unitaire Moyen</span>
                                <span className="font-medium text-gray-900">{stockInventory.average_cost ? formatMAD(stockInventory.average_cost) : 'N/A'}</span>
                            </div>
                            <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                <span className="text-gray-500">Numéro de Lot</span>
                                <span className="font-medium text-gray-900">{stockInventory.batch_number || 'N/A'}</span>
                            </div>
                            <div className="flex justify-between items-center py-2 md:border-b-0 border-b border-gray-50">
                                <span className="text-gray-500">Dernier Réapprovisionnement</span>
                                <span className="font-medium text-gray-900">{formatDate(stockInventory.last_restock_date)}</span>
                            </div>
                            <div className="flex justify-between items-center py-2">
                                <span className="text-gray-500">Dernier Inventaire</span>
                                <span className="font-medium text-gray-900">{formatDate(stockInventory.last_count_date)}</span>
                            </div>
                        </div>
                    </div>

                    {/* Movements */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Historique des Mouvements de Stock</h4>
                        </div>
                        {movements.length === 0 ? (
                            <div className="text-center py-12">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                                <p className="mt-4 text-gray-500">Aucun mouvement de stock enregistré pour ce produit</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Quantité</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Coût Total</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Destination</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Effectué par</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {movements.map((movement) => {
                                            const meta = MOVEMENT_LABELS[movement.movement_type] ?? { label: movement.movement_type, className: 'bg-gray-100 text-gray-700' };
                                            return (
                                                <tr key={movement.id} className="hover:bg-gray-50 transition-colors">
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{formatDate(movement.date)}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className={`text-xs font-medium px-2.5 py-1 rounded-full ${meta.className}`}>{meta.label}</span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{formatNumber(movement.quantity)} {UNIT_TYPE_LABELS[stockInventory.product.unit_type] || stockInventory.product.unit_type}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{movement.total_cost ? formatMAD(movement.total_cost) : 'N/A'}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{destinationOf(movement)}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.performed_by?.name || 'N/A'}</td>
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

            <Modal show={isCounting} onClose={closeCount}>
                <div className="p-8">
                    <div className="flex justify-between items-center mb-6">
                        <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">Compter le Stock</h3>
                        <button onClick={closeCount} className="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <form onSubmit={submitCount} className="space-y-6">
                        <p className="text-sm text-gray-500">
                            Quantité système actuelle pour <span className="font-semibold text-gray-700">{stockInventory.product.name}</span> :{' '}
                            <span className="font-semibold text-gray-700">{formatNumber(stockInventory.quantity_on_hand)} {UNIT_TYPE_LABELS[stockInventory.product.unit_type] || stockInventory.product.unit_type}</span>.
                            Entrez ce qui a été réellement compté — un mouvement d'ajustement sera enregistré pour la différence.
                        </p>

                        <div>
                            <InputLabel htmlFor="counted_quantity" value="Quantité Comptée *" />
                            <TextInput
                                id="counted_quantity"
                                type="number"
                                step="0.01"
                                min="0"
                                className="mt-1 block w-full"
                                value={countForm.data.counted_quantity}
                                onChange={(e) => countForm.setData('counted_quantity', e.target.value)}
                                required
                                autoFocus
                            />
                            <InputError message={countForm.errors.counted_quantity} className="mt-2" />
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t">
                            <SecondaryButton onClick={closeCount}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={countForm.processing} className="bg-green-600 hover:bg-green-700">
                                {countForm.processing ? 'Enregistrement...' : 'Enregistrer le Comptage'}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
