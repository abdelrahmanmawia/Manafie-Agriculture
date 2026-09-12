import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

// A simple named list picked when logging an Entrée (réception) — no order/procurement workflow.
// Was a small modal (ManageSuppliersModal) launched from the Produits page; now its own page so
// a supplier can have a real Show/detail view (its delivery history), matching how
// Véhicules/Sorties already work.
export default function Index({ auth, suppliers }) {
    const [searchTerm, setSearchTerm] = useState('');
    const [isCreating, setIsCreating] = useState(false);
    const [renamingSupplier, setRenamingSupplier] = useState(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState(null);

    const createForm = useForm({ name: '' });
    const renameForm = useForm({ name: '' });
    const deleteForm = useForm();

    const filtered = suppliers.filter((s) => s.name.toLowerCase().includes(searchTerm.toLowerCase()));

    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('stock.suppliers.store'), {
            preserveScroll: true,
            onSuccess: () => { createForm.reset(); setIsCreating(false); },
        });
    };

    const startRename = (supplier) => {
        renameForm.clearErrors();
        renameForm.setData({ name: supplier.name });
        setRenamingSupplier(supplier);
    };

    const submitRename = (e) => {
        e.preventDefault();
        renameForm.put(route('stock.suppliers.update', renamingSupplier.id), {
            preserveScroll: true,
            onSuccess: () => setRenamingSupplier(null),
        });
    };

    const toggleActive = (supplier) => {
        router.put(route('stock.suppliers.update', supplier.id), {
            is_active: !supplier.is_active,
        }, { preserveScroll: true });
    };

    const confirmDelete = (supplierId) => {
        deleteForm.delete(route('stock.suppliers.destroy', supplierId), {
            preserveScroll: true,
            onSuccess: () => setConfirmingDeleteId(null),
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <div>
                        <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Fournisseurs</h2>
                        <p className="text-sm text-gray-500 mt-1">Gérez la liste des fournisseurs utilisée à la réception de stock</p>
                    </div>
                    {auth.user.role !== 'data_entry' && (
                        <button
                            onClick={() => { createForm.reset(); createForm.clearErrors(); setIsCreating(true); }}
                            className="bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                        >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Nouveau Fournisseur
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Fournisseurs" />

            <div className="py-8">
                <div className="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                        <label className="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                        <div className="relative max-w-md">
                            <input
                                type="text"
                                placeholder="Rechercher un fournisseur..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                            />
                            <svg className="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>

                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100 flex justify-between items-center">
                            <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Liste des Fournisseurs</h3>
                            <span className="text-sm text-gray-500">{filtered.length} fournisseur(s)</span>
                        </div>

                        {filtered.length === 0 ? (
                            <p className="px-6 py-16 text-center text-gray-500 italic">Aucun fournisseur trouvé.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-gray-500 uppercase text-xs tracking-wider bg-gray-50">
                                        <th className="px-6 py-3 font-semibold">Nom</th>
                                        <th className="px-6 py-3 font-semibold text-center">Statut</th>
                                        <th className="px-6 py-3 font-semibold text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {filtered.map((supplier) => (
                                        <tr key={supplier.id} className={supplier.is_active ? '' : 'opacity-60 bg-gray-50'}>
                                            <td className="px-6 py-4 font-medium text-gray-800">{supplier.name}</td>
                                            <td className="px-6 py-4 text-center">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleActive(supplier)}
                                                    disabled={auth.user.role === 'data_entry'}
                                                    className={`text-[10px] font-black uppercase px-2 py-1 rounded ${supplier.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'}`}
                                                >
                                                    {supplier.is_active ? 'Actif' : 'Inactif'}
                                                </button>
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex items-center justify-end gap-3">
                                                    <Link href={route('stock.suppliers.show', supplier.id)} className="text-gray-400 hover:text-gray-700 transition-colors" title="Voir">
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                        </svg>
                                                    </Link>
                                                    {auth.user.role !== 'data_entry' && (
                                                        <>
                                                            <button type="button" onClick={() => startRename(supplier)} className="text-gray-400 hover:text-gray-700 transition-colors" title="Renommer">
                                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                                </svg>
                                                            </button>
                                                            {confirmingDeleteId === supplier.id ? (
                                                                <div className="flex items-center gap-2">
                                                                    <span className="text-xs text-gray-500">Supprimer ?</span>
                                                                    <DangerButton type="button" disabled={deleteForm.processing} onClick={() => confirmDelete(supplier.id)}>Oui</DangerButton>
                                                                    <SecondaryButton type="button" onClick={() => { setConfirmingDeleteId(null); deleteForm.clearErrors(); }}>Non</SecondaryButton>
                                                                </div>
                                                            ) : (
                                                                <button type="button" onClick={() => setConfirmingDeleteId(supplier.id)} className="text-gray-400 hover:text-red-600 transition-colors" title="Supprimer">
                                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                                    </svg>
                                                                </button>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                                {confirmingDeleteId === supplier.id && (
                                                    <InputError message={deleteForm.errors.supplier} className="mt-2 text-right" />
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>

            {/* Create modal */}
            <Modal show={isCreating} onClose={() => setIsCreating(false)}>
                <form onSubmit={submitCreate} className="p-8">
                    <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter mb-6">Nouveau Fournisseur</h3>
                    <InputLabel htmlFor="new_supplier_name" value="Nom" />
                    <TextInput
                        id="new_supplier_name"
                        className="block w-full mt-1"
                        value={createForm.data.name}
                        onChange={(e) => createForm.setData('name', e.target.value)}
                        placeholder="Ex: GDIRAGRI Sarl"
                        required
                        autoFocus
                    />
                    <InputError message={createForm.errors.name} className="mt-2" />
                    <div className="flex justify-end gap-3 mt-6">
                        <SecondaryButton type="button" onClick={() => setIsCreating(false)}>Annuler</SecondaryButton>
                        <PrimaryButton disabled={createForm.processing}>Ajouter</PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* Rename modal */}
            <Modal show={renamingSupplier !== null} onClose={() => setRenamingSupplier(null)}>
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
                        <SecondaryButton type="button" onClick={() => setRenamingSupplier(null)}>Annuler</SecondaryButton>
                        <PrimaryButton disabled={renameForm.processing}>Enregistrer</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
