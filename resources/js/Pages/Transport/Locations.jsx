import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

// Farm-scoped price list: each residence town has a fixed per-person transport price, set by
// distance from the farm. Feeds the Employee "Résidence" select and TransportService's cost
// calculation — same "simple list + inline edit + create form" pattern as ManageCategoriesModal.
export default function Locations({ auth, locations }) {
    const [editingId, setEditingId] = useState(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState(null);

    const createForm = useForm({ name: '', price_per_person: '' });
    const editForm = useForm({ name: '', price_per_person: '' });
    const deleteForm = useForm();

    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('transport.locations.store'), {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
    };

    const startEdit = (location) => {
        setConfirmingDeleteId(null);
        editForm.clearErrors();
        editForm.setData({ name: location.name, price_per_person: location.price_per_person });
        setEditingId(location.id);
    };

    const cancelEdit = () => {
        setEditingId(null);
        editForm.reset();
    };

    const submitEdit = (e, locationId) => {
        e.preventDefault();
        editForm.put(route('transport.locations.update', locationId), {
            preserveScroll: true,
            onSuccess: () => setEditingId(null),
        });
    };

    const toggleActive = (location) => {
        router.put(route('transport.locations.update', location.id), {
            is_active: !location.is_active,
        }, { preserveScroll: true });
    };

    const confirmDelete = (locationId) => {
        deleteForm.delete(route('transport.locations.destroy', locationId), {
            preserveScroll: true,
            onSuccess: () => setConfirmingDeleteId(null),
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div>
                    <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Emplacements Transport</h2>
                    <p className="text-sm text-gray-500 mt-1">Prix de transport par personne selon le lieu de résidence</p>
                </div>
            }
        >
            <Head title="Emplacements Transport" />

            <div className="py-8">
                <div className="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div className="space-y-2">
                            {locations.length === 0 && (
                                <p className="text-sm text-gray-500 italic">Aucun emplacement pour le moment.</p>
                            )}
                            {locations.map((location) => (
                                <div key={location.id} className={`border rounded-xl p-4 ${location.is_active ? 'border-gray-200' : 'border-gray-100 bg-gray-50 opacity-60'}`}>
                                    {editingId === location.id ? (
                                        <form onSubmit={(e) => submitEdit(e, location.id)} className="space-y-3">
                                            <div className="flex gap-3">
                                                <div className="flex-1">
                                                    <InputLabel value="Nom" />
                                                    <TextInput
                                                        className="block w-full"
                                                        value={editForm.data.name}
                                                        onChange={(e) => editForm.setData('name', e.target.value)}
                                                        required
                                                        autoFocus
                                                    />
                                                    <InputError message={editForm.errors.name} className="mt-1" />
                                                </div>
                                                <div className="w-40">
                                                    <InputLabel value="Prix / personne (dh)" />
                                                    <TextInput
                                                        type="number" step="0.01" min="0"
                                                        className="block w-full"
                                                        value={editForm.data.price_per_person}
                                                        onChange={(e) => editForm.setData('price_per_person', e.target.value)}
                                                        required
                                                    />
                                                    <InputError message={editForm.errors.price_per_person} className="mt-1" />
                                                </div>
                                            </div>
                                            <div className="flex justify-end gap-2">
                                                <SecondaryButton type="button" onClick={cancelEdit}>Annuler</SecondaryButton>
                                                <PrimaryButton disabled={editForm.processing}>Enregistrer</PrimaryButton>
                                            </div>
                                        </form>
                                    ) : (
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="font-bold text-gray-800 truncate">{location.name}</p>
                                                <p className="text-sm text-primary-600 font-semibold">{location.price_per_person} dh / personne</p>
                                            </div>
                                            {confirmingDeleteId === location.id ? (
                                                <div className="flex items-center gap-2 shrink-0">
                                                    <span className="text-xs text-gray-500">Supprimer ?</span>
                                                    <DangerButton type="button" disabled={deleteForm.processing} onClick={() => confirmDelete(location.id)}>Oui</DangerButton>
                                                    <SecondaryButton type="button" onClick={() => { setConfirmingDeleteId(null); deleteForm.clearErrors(); }}>Non</SecondaryButton>
                                                </div>
                                            ) : (
                                                <div className="flex items-center gap-3 shrink-0">
                                                    <button type="button" onClick={() => toggleActive(location)} className="text-xs font-medium text-gray-500 hover:text-gray-700">
                                                        {location.is_active ? 'Actif' : 'Inactif'}
                                                    </button>
                                                    <button type="button" onClick={() => startEdit(location)} className="text-gray-400 hover:text-gray-700 transition-colors" title="Modifier">
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                        </svg>
                                                    </button>
                                                    <button type="button" onClick={() => setConfirmingDeleteId(location.id)} className="text-gray-400 hover:text-red-600 transition-colors" title="Supprimer">
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    {confirmingDeleteId === location.id && (
                                        <InputError message={deleteForm.errors.transport_location} className="mt-2" />
                                    )}
                                </div>
                            ))}
                        </div>

                        <form onSubmit={submitCreate} className="border-t mt-6 pt-6 space-y-3">
                            <InputLabel value="Nouvel Emplacement" />
                            <div className="flex gap-2">
                                <TextInput
                                    className="block w-full"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    placeholder="Ex: Sidi Slimane"
                                    required
                                />
                                <TextInput
                                    type="number" step="0.01" min="0"
                                    className="block w-40"
                                    value={createForm.data.price_per_person}
                                    onChange={(e) => createForm.setData('price_per_person', e.target.value)}
                                    placeholder="Prix (dh)"
                                    required
                                />
                                <PrimaryButton disabled={createForm.processing}>Ajouter</PrimaryButton>
                            </div>
                            <InputError message={createForm.errors.name} />
                            <InputError message={createForm.errors.price_per_person} />
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
