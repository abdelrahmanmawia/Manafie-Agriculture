import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import ToggleSwitch from '@/Components/ToggleSwitch';
import { formatNumber, formatMAD, formatInt } from '@/utils/number';
import { UNIT_TYPE_LABELS } from '@/utils/stockLabels';

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
}

// A supplier's own delivery history — every "in" stock movement where they're on file as the
// source, so a manager can actually see what this supplier has delivered over time instead of
// just their name. Mirrors Stock/Vehicles/Show.jsx's header/stats/table shape.
export default function Show({ auth, supplier, stats }) {
    const [isRenaming, setIsRenaming] = useState(false);
    const [confirmingDeletion, setConfirmingDeletion] = useState(false);

    const renameForm = useForm({ name: supplier.name });
    const deleteForm = useForm();

    const openRename = () => {
        renameForm.clearErrors();
        renameForm.setData('name', supplier.name);
        setIsRenaming(true);
    };

    const submitRename = (e) => {
        e.preventDefault();
        renameForm.put(route('stock.suppliers.update', supplier.id), {
            preserveScroll: true,
            onSuccess: () => setIsRenaming(false),
        });
    };

    const handleToggleActive = () => {
        router.put(route('stock.suppliers.update', supplier.id), { is_active: !supplier.is_active }, { preserveScroll: true });
    };

    const confirmDelete = () => {
        deleteForm.delete(route('stock.suppliers.destroy', supplier.id), {
            preserveScroll: true,
            onSuccess: () => setConfirmingDeletion(false),
        });
    };

    const movements = supplier.stock_movements ?? [];

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <div className="flex items-center gap-4">
                        <Link href={route('stock.suppliers.index')} className="text-gray-500 hover:text-gray-700 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Détails du Fournisseur</h2>
                            <p className="text-sm text-gray-500 mt-1">Historique des réceptions</p>
                        </div>
                    </div>
                    {auth.user.role !== 'data_entry' && (
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={openRename}
                                className="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                                Modifier
                            </button>
                            <DangerButton onClick={() => setConfirmingDeletion(true)} className="rounded-xl">Supprimer</DangerButton>
                        </div>
                    )}
                </div>
            }
        >
            <Head title={`Fournisseur: ${supplier.name}`} />

            <div className="py-8">
                <div className="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Header Card */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6">
                            <div className="flex items-start justify-between">
                                <div className="flex items-center gap-4">
                                    <div className="flex-shrink-0 h-16 w-16 bg-gray-100 rounded-xl flex items-center justify-center">
                                        <svg className="h-8 w-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                                        </svg>
                                    </div>
                                    <h3 className="text-2xl font-black text-gray-900 uppercase tracking-tighter">{supplier.name}</h3>
                                </div>
                                <div className="px-4 py-2 rounded-lg flex items-center gap-3 bg-gray-50">
                                    <span className={`font-semibold text-sm ${supplier.is_active ? 'text-green-600' : 'text-gray-500'}`}>
                                        {supplier.is_active ? 'Actif' : 'Inactif'}
                                    </span>
                                    <ToggleSwitch
                                        checked={supplier.is_active}
                                        onChange={handleToggleActive}
                                        disabled={auth.user.role === 'data_entry'}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Quick Stats */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Total Livraisons</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatInt(stats.total_deliveries)}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Valeur Totale</p>
                            <p className="text-2xl font-bold text-green-700 mt-1">{formatMAD(stats.total_value)}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Produits Distincts</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatInt(stats.distinct_products)}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Dernière Livraison</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatDate(stats.last_delivery_date)}</p>
                        </div>
                    </div>

                    {/* Delivery history */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Historique des Réceptions</h4>
                        </div>
                        {movements.length === 0 ? (
                            <p className="px-6 py-16 text-center text-gray-500 italic">Aucune réception enregistrée pour ce fournisseur.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-gray-500 uppercase text-xs tracking-wider bg-gray-50">
                                        <th className="px-6 py-3 font-semibold">Date</th>
                                        <th className="px-6 py-3 font-semibold">Produit</th>
                                        <th className="px-6 py-3 font-semibold">N° B.L</th>
                                        <th className="px-6 py-3 font-semibold text-right">Quantité</th>
                                        <th className="px-6 py-3 font-semibold text-right">Coût Unitaire</th>
                                        <th className="px-6 py-3 font-semibold text-right">Coût Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {movements.map((movement) => (
                                        <tr key={movement.id}>
                                            <td className="px-6 py-3 text-gray-600">{formatDate(movement.date)}</td>
                                            <td className="px-6 py-3 font-medium text-gray-800">{movement.product?.name ?? '—'}</td>
                                            <td className="px-6 py-3 text-gray-600">{movement.numero_bl || '—'}</td>
                                            <td className="px-6 py-3 text-right">
                                                {formatNumber(movement.quantity)} {UNIT_TYPE_LABELS[movement.product?.unit_type] || movement.product?.unit_type}
                                            </td>
                                            <td className="px-6 py-3 text-right">{movement.unit_cost ? formatMAD(movement.unit_cost) : '—'}</td>
                                            <td className="px-6 py-3 text-right font-semibold text-gray-800">{movement.total_cost ? formatMAD(movement.total_cost) : '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>

            {/* Rename modal */}
            <Modal show={isRenaming} onClose={() => setIsRenaming(false)}>
                <form onSubmit={submitRename} className="p-8">
                    <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter mb-6">Renommer le Fournisseur</h3>
                    <InputLabel htmlFor="rename_supplier_name" value="Nom" />
                    <TextInput
                        id="rename_supplier_name"
                        className="block w-full mt-1"
                        value={renameForm.data.name}
                        onChange={(e) => renameForm.setData('name', e.target.value)}
                        required
                        autoFocus
                    />
                    <InputError message={renameForm.errors.name} className="mt-2" />
                    <div className="flex justify-end gap-3 mt-6">
                        <SecondaryButton type="button" onClick={() => setIsRenaming(false)}>Annuler</SecondaryButton>
                        <PrimaryButton disabled={renameForm.processing}>Enregistrer</PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* Delete confirmation */}
            <Modal show={confirmingDeletion} onClose={() => setConfirmingDeletion(false)}>
                <div className="p-8">
                    <div className="flex items-center gap-4 mb-4">
                        <div className="h-12 w-12 bg-red-100 rounded-full flex items-center justify-center">
                            <svg className="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter">Supprimer le fournisseur</h2>
                    </div>
                    <p className="text-gray-600 mb-2">
                        Êtes-vous sûr de vouloir supprimer <strong>{supplier.name}</strong> ?
                    </p>
                    <InputError message={deleteForm.errors.supplier} className="mb-4" />
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setConfirmingDeletion(false)}>Annuler</SecondaryButton>
                        <DangerButton className="rounded-xl" disabled={deleteForm.processing} onClick={confirmDelete}>
                            {deleteForm.processing ? 'Suppression...' : 'Supprimer'}
                        </DangerButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
