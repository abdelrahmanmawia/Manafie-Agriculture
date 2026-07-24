import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import { formatNumber, formatMAD } from '@/utils/number';
import { CATEGORY_LABELS, UNIT_TYPE_LABELS, ALERT_TYPE_LABELS, MOVEMENT_TYPE_LABELS } from '@/utils/stockLabels';

export default function Show({ auth, product }) {
    const [confirmingProductDeletion, setConfirmingProductDeletion] = useState(false);
    const [showImagePreview, setShowImagePreview] = useState(false);
    const { delete: destroy, processing } = useForm();

    const confirmProductDeletion = () => {
        setConfirmingProductDeletion(true);
    };

    const deleteProduct = (e) => {
        e.preventDefault();
        destroy(route('stock.products.destroy', product.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => closeModal(),
            onFinish: () => closeModal(),
        });
    };

    const closeModal = () => {
        setConfirmingProductDeletion(false);
    };

    // Product.stockInventory() is a hasMany — always exactly one row in practice (no
    // per-batch splitting happens in this system), so take the first.
    const inventory = product.stock_inventory?.[0];
    const currentStock = parseFloat(inventory?.quantity_on_hand || 0);
    const minStock = parseFloat(product.min_stock_level || 0);
    
    const getStockStatus = () => {
        if (currentStock === 0) return { status: 'Épuisé', color: 'bg-red-500', textColor: 'text-red-600', bgColor: 'bg-red-50' };
        if (currentStock <= minStock) return { status: 'Faible', color: 'bg-orange-500', textColor: 'text-orange-600', bgColor: 'bg-orange-50' };
        if (currentStock <= minStock * 1.5) return { status: 'Normal', color: 'bg-yellow-500', textColor: 'text-yellow-600', bgColor: 'bg-yellow-50' };
        return { status: 'Bon', color: 'bg-green-500', textColor: 'text-green-600', bgColor: 'bg-green-50' };
    };

    const stockStatus = getStockStatus();

    const getCategoryColor = (category) => {
        const colors = {
            'seeds': 'bg-green-100 text-green-800',
            'fertilizers': 'bg-blue-100 text-blue-800',
            'pesticides': 'bg-red-100 text-red-800',
            'tools': 'bg-gray-100 text-gray-800',
            'packaging': 'bg-yellow-100 text-yellow-800',
            'equipment': 'bg-purple-100 text-purple-800',
            'fuel': 'bg-orange-100 text-orange-800',
            'other': 'bg-indigo-100 text-indigo-800',
        };
        return colors[category] || 'bg-gray-100 text-gray-800';
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
                    <div className="flex items-center gap-4">
                        <Link
                            href={route('stock.products.index')}
                            className="text-gray-500 hover:text-gray-700 transition-colors"
                        >
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-bold text-2xl text-gray-800 leading-tight">Détails du Produit</h2>
                            <p className="text-sm text-gray-500 mt-1">Informations détaillées et historique</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('stock.products.edit', product.id)}
                            className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl font-semibold shadow-lg hover:shadow-xl transition-all flex items-center gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            Modifier
                        </Link>
                        {auth.user.role !== 'data_entry' && (
                            <DangerButton onClick={confirmProductDeletion} className="rounded-xl">Supprimer</DangerButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Produit: ${product.name}`} />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Product Header Card */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6">
                            <div className="flex items-start justify-between">
                                <div className="flex items-center gap-4">
                                    {product.image_url ? (
                                        <button
                                            type="button"
                                            onClick={() => setShowImagePreview(true)}
                                            className="group relative flex-shrink-0 h-16 w-16 rounded-xl overflow-hidden"
                                            title="Agrandir l'image"
                                        >
                                            <img
                                                src={product.image_url}
                                                alt={product.name}
                                                className="h-16 w-16 object-cover"
                                            />
                                            <span className="absolute inset-0 bg-black/0 group-hover:bg-black/40 flex items-center justify-center transition-colors">
                                                <svg className="h-5 w-5 text-white opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16zM11 8v6m-3-3h6" />
                                                </svg>
                                            </span>
                                        </button>
                                    ) : (
                                        <div className="flex-shrink-0 h-16 w-16 bg-blue-100 rounded-xl flex items-center justify-center">
                                            <svg className="h-8 w-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />
                                            </svg>
                                        </div>
                                    )}
                                    <div>
                                        <h3 className="text-2xl font-bold text-gray-900">{product.name}</h3>
                                        <div className="flex items-center gap-3 mt-2">
                                            <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${getCategoryColor(product.category)}`}>
                                                {CATEGORY_LABELS[product.category] || product.category}
                                            </span>
                                            {product.is_active ? (
                                                <span className="flex items-center text-green-600 text-sm">
                                                    <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                    Actif
                                                </span>
                                            ) : (
                                                <span className="flex items-center text-gray-400 text-sm">
                                                    <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                    Inactif
                                                </span>
                                            )}
                                        </div>
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
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Stock Actuel</p>
                                    <p className="text-2xl font-bold text-gray-900 mt-1">{formatNumber(currentStock)}</p>
                                    <p className="text-xs text-gray-500 mt-1">{UNIT_TYPE_LABELS[product.unit_type] || product.unit_type}</p>
                                </div>
                                <div className="h-12 w-12 bg-blue-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Stock Minimum</p>
                                    <p className="text-2xl font-bold text-gray-900 mt-1">{formatNumber(minStock)}</p>
                                    <p className="text-xs text-gray-500 mt-1">{UNIT_TYPE_LABELS[product.unit_type] || product.unit_type}</p>
                                </div>
                                <div className="h-12 w-12 bg-orange-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Product Information */}
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-bold text-gray-800">Informations Générales</h4>
                            </div>
                            <div className="p-6 space-y-4">
                                <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                    <span className="text-gray-500">Type d'Unité</span>
                                    <span className="font-medium text-gray-900">{UNIT_TYPE_LABELS[product.unit_type] || product.unit_type}</span>
                                </div>
                                <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                    <span className="text-gray-500">Coût Unitaire (catalogue)</span>
                                    <span className="font-medium text-gray-900">{product.unit_cost ? formatMAD(product.unit_cost) : 'N/A'}</span>
                                </div>
                                <div className="flex justify-between items-center py-2">
                                    <span className="text-gray-500">Coût Moyen Pondéré (CUMP)</span>
                                    <span className="font-medium text-gray-900">{inventory?.average_cost ? formatMAD(inventory.average_cost) : 'N/A'}</span>
                                </div>
                            </div>
                        </div>

                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-bold text-gray-800">Informations de Stock</h4>
                            </div>
                            <div className="p-6 space-y-4">
                                <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                    <span className="text-gray-500">Dernier Réapprovisionnement</span>
                                    <span className="font-medium text-gray-900">{formatDate(inventory?.last_restock_date)}</span>
                                </div>
                                <div className="flex justify-between items-center py-2">
                                    <span className="text-gray-500">Dernier Inventaire</span>
                                    <span className="font-medium text-gray-900">{formatDate(inventory?.last_count_date)}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Stock Movements */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-bold text-gray-800">Mouvements de Stock Récents</h4>
                        </div>
                        <div className="p-6">
                            {product.stock_movements && product.stock_movements.length > 0 ? (
                                <div className="space-y-3">
                                    {product.stock_movements.slice(0, 5).map(movement => (
                                        <div key={movement.id} className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                            <div className="flex items-center gap-4">
                                                <div className={`h-10 w-10 rounded-full flex items-center justify-center ${movement.movement_type === 'in' ? 'bg-green-100' : 'bg-red-100'}`}>
                                                    {movement.movement_type === 'in' ? (
                                                        <svg className="h-5 w-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                                                        </svg>
                                                    ) : (
                                                        <svg className="h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                                                        </svg>
                                                    )}
                                                </div>
                                                <div>
                                                    <p className="font-medium text-gray-900">{MOVEMENT_TYPE_LABELS[movement.movement_type]?.label || movement.movement_type}</p>
                                                    <p className="text-sm text-gray-500">{formatDate(movement.date)}</p>
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <p className={`font-semibold ${movement.movement_type === 'in' ? 'text-green-600' : 'text-red-600'}`}>
                                                    {movement.movement_type === 'in' ? '+' : '-'}{formatNumber(movement.quantity)} {UNIT_TYPE_LABELS[product.unit_type] || product.unit_type}
                                                </p>
                                                <p className="text-sm text-gray-500">{movement.total_cost ? formatMAD(movement.total_cost) : 'N/A'}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <p className="mt-4 text-gray-500">Aucun mouvement de stock récent</p>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Stock Alerts */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-bold text-gray-800">Alertes de Stock</h4>
                        </div>
                        <div className="p-6">
                            {product.stock_alerts && product.stock_alerts.length > 0 ? (
                                <div className="space-y-3">
                                    {product.stock_alerts.map(alert => (
                                        <div key={alert.id} className={`p-4 rounded-lg border ${alert.is_resolved ? 'bg-gray-50 border-gray-200' : 'bg-red-50 border-red-200'}`}>
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-3">
                                                    {alert.is_resolved ? (
                                                        <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                        </svg>
                                                    ) : (
                                                        <svg className="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                        </svg>
                                                    )}
                                                    <div>
                                                        <p className={`font-medium ${alert.is_resolved ? 'text-gray-600' : 'text-red-700'}`}>
                                                            {ALERT_TYPE_LABELS[alert.alert_type] || alert.alert_type}
                                                        </p>
                                                        <p className="text-sm text-gray-500">{alert.notes}</p>
                                                    </div>
                                                </div>
                                                <div className="text-right">
                                                    <p className={`text-sm font-medium ${alert.is_resolved ? 'text-gray-600' : 'text-red-600'}`}>
                                                        {alert.is_resolved ? 'Résolue' : 'Non Résolue'}
                                                    </p>
                                                    <p className="text-xs text-gray-500">{formatNumber(alert.threshold_value)} / {formatNumber(alert.current_value)}</p>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <p className="mt-4 text-gray-500">Aucune alerte de stock active</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={confirmingProductDeletion} onClose={closeModal}>
                <form onSubmit={deleteProduct} className="p-8">
                    <div className="flex items-center gap-4 mb-4">
                        <div className="h-12 w-12 bg-red-100 rounded-full flex items-center justify-center">
                            <svg className="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <h2 className="text-xl font-bold text-gray-900">
                            Supprimer le produit
                        </h2>
                    </div>
                    <p className="text-gray-600 mb-6">
                        Êtes-vous sûr de vouloir supprimer <strong>{product.name}</strong> ? Cette action est irréversible et toutes les données associées seront définitivement effacées.
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <DangerButton className="rounded-xl" disabled={processing}>
                            {processing ? 'Suppression...' : 'Supprimer le Produit'}
                        </DangerButton>
                    </div>
                </form>
            </Modal>

            {product.image_url && showImagePreview && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                    onClick={() => setShowImagePreview(false)}
                >
                    <button
                        type="button"
                        onClick={() => setShowImagePreview(false)}
                        className="absolute top-4 right-4 text-white hover:text-gray-300 transition-colors"
                        title="Fermer"
                    >
                        <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                    <img
                        src={product.image_url}
                        alt={product.name}
                        className="max-w-full max-h-full rounded-xl shadow-2xl object-contain"
                        onClick={(e) => e.stopPropagation()}
                    />
                </div>
            )}
        </AuthenticatedLayout>
    );
}
