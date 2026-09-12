import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

// A simple named list picked when logging an Entrée (réception) — no order/procurement workflow,
// just "who did this delivery come from". Mirrors ManageCategoriesModal.jsx's exact pattern.
export default function ManageSuppliersModal({ show, onClose, suppliers }) {
    const [renamingId, setRenamingId] = useState(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState(null);

    const createForm = useForm({ name: '' });
    const renameForm = useForm({ name: '' });
    const deleteForm = useForm();

    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('stock.suppliers.store'), {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
    };

    const startRename = (supplier) => {
        setConfirmingDeleteId(null);
        renameForm.clearErrors();
        renameForm.setData({ name: supplier.name });
        setRenamingId(supplier.id);
    };

    const cancelRename = () => {
        setRenamingId(null);
        renameForm.reset();
    };

    const submitRename = (e, supplierId) => {
        e.preventDefault();
        renameForm.put(route('stock.suppliers.update', supplierId), {
            preserveScroll: true,
            onSuccess: () => setRenamingId(null),
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

    const handleClose = () => {
        setRenamingId(null);
        setConfirmingDeleteId(null);
        createForm.reset();
        onClose();
    };

    return (
        <Modal show={show} onClose={handleClose}>
            <div className="p-8">
                <div className="flex justify-between items-center mb-6">
                    <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">Gérer les Fournisseurs</h3>
                    <button onClick={handleClose} className="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="space-y-2 max-h-80 overflow-y-auto pr-1">
                    {suppliers.length === 0 && (
                        <p className="text-sm text-gray-500 italic">Aucun fournisseur pour le moment.</p>
                    )}
                    {suppliers.map((supplier) => (
                        <div key={supplier.id} className={`border rounded-xl p-3 ${supplier.is_active ? 'border-gray-200' : 'border-gray-100 bg-gray-50 opacity-60'}`}>
                            {renamingId === supplier.id ? (
                                <form onSubmit={(e) => submitRename(e, supplier.id)} className="space-y-3">
                                    <div>
                                        <TextInput
                                            className="block w-full"
                                            value={renameForm.data.name}
                                            onChange={(e) => renameForm.setData('name', e.target.value)}
                                            required
                                            autoFocus
                                        />
                                        <InputError message={renameForm.errors.name} className="mt-1" />
                                    </div>
                                    <div className="flex justify-end gap-2">
                                        <SecondaryButton type="button" onClick={cancelRename}>Annuler</SecondaryButton>
                                        <PrimaryButton disabled={renameForm.processing}>Enregistrer</PrimaryButton>
                                    </div>
                                </form>
                            ) : (
                                <div>
                                <div className="flex items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="font-medium text-gray-800 truncate">{supplier.name}</p>
                                    </div>
                                    {confirmingDeleteId === supplier.id ? (
                                        <div className="flex items-center gap-2 shrink-0">
                                            <span className="text-xs text-gray-500">Supprimer ?</span>
                                            <DangerButton type="button" disabled={deleteForm.processing} onClick={() => confirmDelete(supplier.id)}>Oui</DangerButton>
                                            <SecondaryButton type="button" onClick={() => { setConfirmingDeleteId(null); deleteForm.clearErrors(); }}>Non</SecondaryButton>
                                        </div>
                                    ) : (
                                        <div className="flex items-center gap-3 shrink-0">
                                            <button
                                                type="button"
                                                onClick={() => toggleActive(supplier)}
                                                className="text-xs font-medium text-gray-500 hover:text-gray-700"
                                                title={supplier.is_active ? 'Désactiver' : 'Activer'}
                                            >
                                                {supplier.is_active ? 'Actif' : 'Inactif'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => startRename(supplier)}
                                                className="text-gray-400 hover:text-gray-700 transition-colors"
                                                title="Renommer"
                                            >
                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setConfirmingDeleteId(supplier.id)}
                                                className="text-gray-400 hover:text-red-600 transition-colors"
                                                title="Supprimer"
                                            >
                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    )}
                                </div>
                                {confirmingDeleteId === supplier.id && (
                                    <InputError message={deleteForm.errors.supplier} className="mt-2" />
                                )}
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                <form onSubmit={submitCreate} className="border-t mt-6 pt-6 space-y-3">
                    <InputLabel htmlFor="new_supplier_name" value="Nouveau Fournisseur" />
                    <div className="flex gap-2">
                        <TextInput
                            id="new_supplier_name"
                            className="block w-full"
                            value={createForm.data.name}
                            onChange={(e) => createForm.setData('name', e.target.value)}
                            placeholder="Ex: GDIRAGRI sarl"
                            required
                        />
                        <PrimaryButton disabled={createForm.processing}>Ajouter</PrimaryButton>
                    </div>
                    <InputError message={createForm.errors.name} />
                </form>
            </div>
        </Modal>
    );
}
