import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { formatNumber, formatInt, formatMAD } from '@/utils/number';
import { UNIT_TYPE_LABELS } from '@/utils/stockLabels';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

function getStockStatus(currentStock, minStock) {
    if (currentStock <= 0) return { status: 'Épuisé', color: 'bg-red-500', textColor: 'text-red-600' };
    // Without a configured threshold there's no basis to call this stock "Bon" — a product
    // nobody has ever set a minimum for shouldn't look safer than one that has.
    if (minStock <= 0) return { status: 'Seuil non défini', color: 'bg-gray-400', textColor: 'text-gray-500' };
    if (currentStock <= minStock) return { status: 'Faible', color: 'bg-orange-500', textColor: 'text-orange-600' };
    if (currentStock <= minStock * 1.5) return { status: 'Normal', color: 'bg-yellow-500', textColor: 'text-yellow-600' };
    return { status: 'Bon', color: 'bg-green-500', textColor: 'text-green-600' };
}

export default function Index({ auth, stockInventory }) {
    const [searchTerm, setSearchTerm] = useState('');
    const [countingItem, setCountingItem] = useState(null);
    const countForm = useForm({ product_id: '', counted_quantity: '' });

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const openCount = (item) => {
        countForm.setData({ product_id: item.product.id, counted_quantity: item.quantity_on_hand });
        countForm.clearErrors();
        setCountingItem(item);
    };
    const closeCount = () => {
        setCountingItem(null);
        countForm.reset();
    };
    const submitCount = (e) => {
        e.preventDefault();
        countForm.post(route('stock.inventory.count'), {
            preserveScroll: true,
            onSuccess: () => setCountingItem(null),
        });
    };

    const filtered = stockInventory.filter((item) =>
        item.product.name.toLowerCase().includes(searchTerm.toLowerCase())
    );

    const lowStockCount = stockInventory.filter((item) => {
        const qty = parseFloat(item.quantity_on_hand);
        const min = parseFloat(item.product.min_stock_level || 0);
        return qty <= min;
    }).length;

    const totalValue = stockInventory.reduce((sum, item) => {
        const cost = parseFloat(item.average_cost ?? item.product.unit_cost ?? 0);
        return sum + parseFloat(item.quantity_on_hand) * cost;
    }, 0);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <div>
                        <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Gestion des Stocks</h2>
                        <p className="text-sm text-gray-500 mt-1">Niveaux d'inventaire actuels par produit</p>
                    </div>
                    <Link
                        href={route('stock.movements.index') + '?action=receive'}
                        className="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Nouvelle Réception
                    </Link>
                </div>
            }
        >
            <Head title="Inventaire des Stocks" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Articles en Stock</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatInt(stockInventory.length)}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Stock Faible</p>
                            <p className="text-2xl font-bold text-red-600 mt-1">{formatInt(lowStockCount)}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Valeur Totale</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatMAD(totalValue)}</p>
                        </div>
                    </div>

                    {/* Search */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                        <label className="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                        <div className="relative max-w-md">
                            <input
                                type="text"
                                placeholder="Rechercher un produit..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                            />
                            <svg className="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <div className="flex justify-between items-center">
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Inventaire Actuel des Produits</h3>
                                <span className="text-sm text-gray-500">{filtered.length} article(s)</span>
                            </div>
                        </div>

                        {filtered.length === 0 ? (
                            <div className="text-center py-16">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <p className="mt-4 text-gray-500">Aucun article en stock trouvé</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Produit</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">En Stock</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Statut</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Dernier Inventaire</th>
                                            <th scope="col" className="relative px-6 py-3"><span className="sr-only">Actions</span></th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {filtered.map((item) => {
                                            const stockStatus = getStockStatus(parseFloat(item.quantity_on_hand), parseFloat(item.product.min_stock_level || 0));
                                            return (
                                                <tr key={item.id} className="hover:bg-gray-50 transition-colors">
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        {item.product.name}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        {formatNumber(item.quantity_on_hand)} {UNIT_TYPE_LABELS[item.product.unit_type] || item.product.unit_type}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex items-center">
                                                            <span className={`h-2 w-2 rounded-full ${stockStatus.color} mr-2`}></span>
                                                            <span className={`text-sm font-medium ${stockStatus.textColor}`}>{stockStatus.status}</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {formatDate(item.last_count_date)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <div className="flex items-center justify-end gap-4">
                                                            <button
                                                                type="button"
                                                                onClick={() => openCount(item)}
                                                                className="text-gray-400 hover:text-green-600 transition-colors"
                                                                title="Compter"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
                                                                </svg>
                                                            </button>
                                                            <Link
                                                                href={route('stock.inventory.show', item.id)}
                                                                className="text-gray-400 hover:text-primary-600 transition-colors"
                                                                title="Détails"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                                </svg>
                                                            </Link>
                                                        </div>
                                                    </td>
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

            <Modal show={countingItem !== null} onClose={closeCount}>
                {countingItem && (
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
                                Quantité système actuelle pour <span className="font-semibold text-gray-700">{countingItem.product.name}</span> :{' '}
                                <span className="font-semibold text-gray-700">
                                    {formatNumber(countingItem.quantity_on_hand)} {UNIT_TYPE_LABELS[countingItem.product.unit_type] || countingItem.product.unit_type}
                                </span>.
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
                                <SecondaryButton type="button" onClick={closeCount}>Annuler</SecondaryButton>
                                <PrimaryButton disabled={countForm.processing} className="bg-green-600 hover:bg-green-700">
                                    {countForm.processing ? 'Enregistrement...' : 'Enregistrer le Comptage'}
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
