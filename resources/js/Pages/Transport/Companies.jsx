import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

// Farm-scoped list of transport companies (the "STE TRANSPORT") — each owns one or more
// TransportVehicle entries (see Transport/Vehicles.jsx) and gets paid via its own RIB.
export default function Companies({ auth, companies }) {
    const [editingId, setEditingId] = useState(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState(null);

    const createForm = useForm({ name: '', rib: '' });
    const editForm = useForm({ name: '', rib: '' });
    const deleteForm = useForm();

    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('transport.companies.store'), {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
    };

    const startEdit = (company) => {
        setConfirmingDeleteId(null);
        editForm.clearErrors();
        editForm.setData({ name: company.name, rib: company.rib || '' });
        setEditingId(company.id);
    };

    const cancelEdit = () => {
        setEditingId(null);
        editForm.reset();
    };

    const submitEdit = (e, companyId) => {
        e.preventDefault();
        editForm.put(route('transport.companies.update', companyId), {
            preserveScroll: true,
            onSuccess: () => setEditingId(null),
        });
    };

    const toggleActive = (company) => {
        router.put(route('transport.companies.update', company.id), {
            is_active: !company.is_active,
        }, { preserveScroll: true });
    };

    const confirmDelete = (companyId) => {
        deleteForm.delete(route('transport.companies.destroy', companyId), {
            preserveScroll: true,
            onSuccess: () => setConfirmingDeleteId(null),
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div>
                    <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Sociétés de Transport</h2>
                    <p className="text-sm text-gray-500 mt-1">Entreprises de transport à payer, avec leur RIB</p>
                </div>
            }
        >
            <Head title="Sociétés de Transport" />

            <div className="py-8">
                <div className="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div className="space-y-2">
                            {companies.length === 0 && (
                                <p className="text-sm text-gray-500 italic">Aucune société pour le moment.</p>
                            )}
                            {companies.map((company) => (
                                <div key={company.id} className={`border rounded-xl p-4 ${company.is_active ? 'border-gray-200' : 'border-gray-100 bg-gray-50 opacity-60'}`}>
                                    {editingId === company.id ? (
                                        <form onSubmit={(e) => submitEdit(e, company.id)} className="space-y-3">
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
                                                <div className="w-56">
                                                    <InputLabel value="RIB" />
                                                    <TextInput
                                                        className="block w-full"
                                                        value={editForm.data.rib}
                                                        onChange={(e) => editForm.setData('rib', e.target.value)}
                                                    />
                                                    <InputError message={editForm.errors.rib} className="mt-1" />
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
                                                <p className="font-bold text-gray-800 truncate">{company.name}</p>
                                                {company.rib && <p className="text-xs text-gray-500 mt-0.5">RIB : {company.rib}</p>}
                                            </div>
                                            {confirmingDeleteId === company.id ? (
                                                <div className="flex items-center gap-2 shrink-0">
                                                    <span className="text-xs text-gray-500">Supprimer ?</span>
                                                    <DangerButton type="button" disabled={deleteForm.processing} onClick={() => confirmDelete(company.id)}>Oui</DangerButton>
                                                    <SecondaryButton type="button" onClick={() => { setConfirmingDeleteId(null); deleteForm.clearErrors(); }}>Non</SecondaryButton>
                                                </div>
                                            ) : (
                                                <div className="flex items-center gap-3 shrink-0">
                                                    <button type="button" onClick={() => toggleActive(company)} className="text-xs font-medium text-gray-500 hover:text-gray-700">
                                                        {company.is_active ? 'Actif' : 'Inactif'}
                                                    </button>
                                                    <button type="button" onClick={() => startEdit(company)} className="text-gray-400 hover:text-gray-700 transition-colors" title="Modifier">
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                        </svg>
                                                    </button>
                                                    <button type="button" onClick={() => setConfirmingDeleteId(company.id)} className="text-gray-400 hover:text-red-600 transition-colors" title="Supprimer">
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    {confirmingDeleteId === company.id && (
                                        <InputError message={deleteForm.errors.transport_company} className="mt-2" />
                                    )}
                                </div>
                            ))}
                        </div>

                        <form onSubmit={submitCreate} className="border-t mt-6 pt-6 space-y-3">
                            <InputLabel value="Nouvelle Société" />
                            <div className="flex gap-2">
                                <TextInput
                                    className="block w-full"
                                    value={createForm.data.name}
                                    onChange={(e) => createForm.setData('name', e.target.value)}
                                    placeholder="Ex: Transport Al Amal"
                                    required
                                />
                                <TextInput
                                    className="block w-56"
                                    value={createForm.data.rib}
                                    onChange={(e) => createForm.setData('rib', e.target.value)}
                                    placeholder="RIB (optionnel)"
                                />
                                <PrimaryButton disabled={createForm.processing}>Ajouter</PrimaryButton>
                            </div>
                            <InputError message={createForm.errors.name} />
                            <InputError message={createForm.errors.rib} />
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
