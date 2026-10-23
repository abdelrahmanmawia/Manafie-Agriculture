import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

const emptyForm = { name: '', chef_employee_id: '', transport_vehicle_id: '' };

// Chef d'équipe teams — the unit of the first pointage step (Grid "Par équipe"). Members can
// come from any division; the vehicle an équipe rides is how its members travel (its riders,
// whose residences price the vehicle's auto daily cost). Members are picked per équipe with a
// checklist (EquipeController::syncMembers()); a worker is in one équipe at a time.
export default function Equipes({ auth, equipes, employees, vehicles, locations, canManage }) {
    const [editingId, setEditingId] = useState(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState(null);
    const [managingMembersId, setManagingMembersId] = useState(null);
    const [memberSearch, setMemberSearch] = useState('');

    const createForm = useForm(emptyForm);
    const editForm = useForm(emptyForm);
    const deleteForm = useForm();
    const membersForm = useForm({ employee_ids: [] });

    const membersOf = (equipe) => employees.filter(e => e.equipe_id === equipe.id);
    const withoutEquipe = employees.filter(e => !e.equipe_id).length;

    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('equipes.store'), { preserveScroll: true, onSuccess: () => createForm.reset() });
    };

    const startEdit = (equipe) => {
        setConfirmingDeleteId(null);
        setManagingMembersId(null);
        editForm.clearErrors();
        editForm.setData({
            name: equipe.name,
            chef_employee_id: equipe.chef_employee_id ?? '',
            transport_vehicle_id: equipe.transport_vehicle_id ?? '',
        });
        setEditingId(equipe.id);
    };

    const submitEdit = (e, equipeId) => {
        e.preventDefault();
        editForm.put(route('equipes.update', equipeId), { preserveScroll: true, onSuccess: () => setEditingId(null) });
    };

    const toggleActive = (equipe) => {
        router.put(route('equipes.update', equipe.id), { is_active: !equipe.is_active }, { preserveScroll: true });
    };

    const confirmDelete = (equipeId) => {
        deleteForm.delete(route('equipes.destroy', equipeId), { preserveScroll: true, onSuccess: () => setConfirmingDeleteId(null) });
    };

    const openMembers = (equipe) => {
        setEditingId(null);
        setConfirmingDeleteId(null);
        setMemberSearch('');
        membersForm.clearErrors();
        membersForm.setData('employee_ids', membersOf(equipe).map(e => e.id));
        setManagingMembersId(equipe.id);
    };

    const toggleMember = (employeeId) => {
        const ids = membersForm.data.employee_ids;
        membersForm.setData('employee_ids', ids.includes(employeeId) ? ids.filter(id => id !== employeeId) : [...ids, employeeId]);
    };

    const submitMembers = (e, equipeId) => {
        e.preventDefault();
        membersForm.put(route('equipes.members', equipeId), { preserveScroll: true, onSuccess: () => setManagingMembersId(null) });
    };

    // Fires immediately on select — a rider with no residence can't be priced in the vehicle's
    // auto daily cost, so it's fixable right here.
    const quickSetResidence = (employeeId, locationId) => {
        router.put(route('transport.employees.residence', employeeId), {
            residence_location_id: locationId || null,
        }, { preserveScroll: true, preserveState: true });
    };

    const formFields = (form, chefOptions) => (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <InputLabel value="Nom de l'équipe" />
                <TextInput className="block w-full" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                <InputError message={form.errors.name} className="mt-1" />
            </div>
            <div>
                <InputLabel value="Chef d'équipe" />
                <select className="border-gray-300 rounded-md shadow-sm w-full" value={form.data.chef_employee_id} onChange={(e) => form.setData('chef_employee_id', e.target.value)}>
                    <option value="">— Aucun —</option>
                    {chefOptions.map(emp => <option key={emp.id} value={emp.id}>{emp.full_name} ({emp.matricule})</option>)}
                </select>
                <InputError message={form.errors.chef_employee_id} className="mt-1" />
            </div>
            <div>
                <InputLabel value="Véhicule (transport)" />
                <select className="border-gray-300 rounded-md shadow-sm w-full" value={form.data.transport_vehicle_id} onChange={(e) => form.setData('transport_vehicle_id', e.target.value)}>
                    <option value="">— Sans véhicule —</option>
                    {vehicles.map(v => <option key={v.id} value={v.id}>{v.code}</option>)}
                </select>
                <InputError message={form.errors.transport_vehicle_id} className="mt-1" />
            </div>
        </div>
    );

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div>
                    <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Équipes</h2>
                    <p className="text-sm text-gray-500 mt-1">
                        Une équipe = un chef d'équipe et ses ouvriers (toutes divisions). Le pointage « Par équipe » se fait depuis la grille de pointage.
                    </p>
                </div>
            }
        >
            <Head title="Équipes" />

            <div className="py-8">
                <div className="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {withoutEquipe > 0 && (
                        <div className="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-700">
                            {withoutEquipe} ouvrier(s) actif(s) sans équipe — ils apparaissent sous « Sans équipe » dans la grille.
                        </div>
                    )}

                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div className="space-y-2">
                            {equipes.length === 0 && <p className="text-sm text-gray-500 italic">Aucune équipe pour le moment.</p>}
                            {equipes.map((equipe) => (
                                <div key={equipe.id} className={`border rounded-xl p-4 ${equipe.is_active ? 'border-gray-200' : 'border-gray-100 bg-gray-50 opacity-60'}`}>
                                    {editingId === equipe.id ? (
                                        <form onSubmit={(e) => submitEdit(e, equipe.id)} className="space-y-3">
                                            {formFields(editForm, membersOf(equipe).length ? membersOf(equipe) : employees)}
                                            <div className="flex justify-end gap-2">
                                                <SecondaryButton type="button" onClick={() => setEditingId(null)}>Annuler</SecondaryButton>
                                                <PrimaryButton disabled={editForm.processing}>Enregistrer</PrimaryButton>
                                            </div>
                                        </form>
                                    ) : (
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="font-bold text-gray-800 truncate">{equipe.name}</p>
                                                <p className="text-xs text-gray-500 mt-0.5">
                                                    Chef : {equipe.chef?.full_name || <span className="text-amber-600">non défini</span>}
                                                    {' · '}
                                                    {equipe.transport_vehicle ? `Véhicule : ${equipe.transport_vehicle.code}` : 'Sans véhicule'}
                                                </p>
                                            </div>
                                            {confirmingDeleteId === equipe.id ? (
                                                <div className="flex items-center gap-2 shrink-0">
                                                    <span className="text-xs text-gray-500">Supprimer ?</span>
                                                    <DangerButton type="button" disabled={deleteForm.processing} onClick={() => confirmDelete(equipe.id)}>Oui</DangerButton>
                                                    <SecondaryButton type="button" onClick={() => { setConfirmingDeleteId(null); deleteForm.clearErrors(); }}>Non</SecondaryButton>
                                                </div>
                                            ) : (
                                                <div className="flex items-center gap-3 shrink-0">
                                                    <button type="button" onClick={() => canManage && openMembers(equipe)} disabled={!canManage} className="text-xs font-bold text-primary-600 hover:text-primary-700 uppercase tracking-wide disabled:text-gray-400">
                                                        {equipe.employees_count} ouvrier(s)
                                                    </button>
                                                    {canManage && (
                                                        <>
                                                            <button type="button" onClick={() => toggleActive(equipe)} className="text-xs font-medium text-gray-500 hover:text-gray-700">
                                                                {equipe.is_active ? 'Active' : 'Inactive'}
                                                            </button>
                                                            <button type="button" onClick={() => startEdit(equipe)} className="text-gray-400 hover:text-gray-700" title="Modifier">
                                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                                            </button>
                                                            <button type="button" onClick={() => setConfirmingDeleteId(equipe.id)} className="text-gray-400 hover:text-red-600" title="Supprimer">
                                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    {confirmingDeleteId === equipe.id && <InputError message={deleteForm.errors.equipe} className="mt-2" />}
                                    {managingMembersId === equipe.id && (
                                        <form onSubmit={(e) => submitMembers(e, equipe.id)} className="border-t mt-3 pt-3 space-y-3">
                                            <TextInput className="block w-full" value={memberSearch} onChange={(e) => setMemberSearch(e.target.value)} placeholder="Rechercher un ouvrier..." autoFocus />
                                            <div className="max-h-72 overflow-y-auto border border-gray-100 rounded-lg divide-y divide-gray-50">
                                                {employees
                                                    .filter(emp => {
                                                        const q = memberSearch.trim().toLowerCase();
                                                        return !q || emp.full_name.toLowerCase().includes(q) || (emp.matricule || '').toLowerCase().includes(q);
                                                    })
                                                    .map(emp => {
                                                        const checked = membersForm.data.employee_ids.includes(emp.id);
                                                        const elsewhere = emp.equipe_id && emp.equipe_id !== equipe.id ? equipes.find(e => e.id === emp.equipe_id) : null;
                                                        return (
                                                            <div key={emp.id} className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50">
                                                                <label className="flex items-center gap-3 flex-1 min-w-0 cursor-pointer">
                                                                    <input type="checkbox" checked={checked} onChange={() => toggleMember(emp.id)} className="rounded border-gray-300 text-primary-600 focus:ring-primary-500 shrink-0" />
                                                                    <span className="flex-1 min-w-0 truncate">
                                                                        {emp.full_name} <span className="text-gray-400">({emp.matricule})</span>
                                                                        <span className="ml-1 text-[10px] uppercase text-gray-400">{emp.enterprise?.name}</span>
                                                                    </span>
                                                                </label>
                                                                {elsewhere && !checked && <span className="text-xs text-amber-600 shrink-0">déjà dans {elsewhere.name}</span>}
                                                                {equipe.transport_vehicle_id && (
                                                                    <select
                                                                        value={emp.residence_location_id || ''}
                                                                        onChange={(e) => quickSetResidence(emp.id, e.target.value)}
                                                                        className={`text-xs rounded-md shrink-0 max-w-[9.5rem] py-1 ${emp.residence_location_id ? 'border-gray-200 text-gray-500' : 'border-amber-300 text-amber-700 bg-amber-50'}`}
                                                                    >
                                                                        <option value="">résidence ?</option>
                                                                        {locations.map(loc => <option key={loc.id} value={loc.id}>{loc.name}</option>)}
                                                                    </select>
                                                                )}
                                                            </div>
                                                        );
                                                    })}
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs text-gray-500">{membersForm.data.employee_ids.length} sélectionné(s)</span>
                                                <div className="flex gap-2">
                                                    <SecondaryButton type="button" onClick={() => setManagingMembersId(null)}>Annuler</SecondaryButton>
                                                    <PrimaryButton disabled={membersForm.processing}>Enregistrer</PrimaryButton>
                                                </div>
                                            </div>
                                        </form>
                                    )}
                                </div>
                            ))}
                        </div>

                        {canManage && (
                            <form onSubmit={submitCreate} className="border-t mt-6 pt-6 space-y-3">
                                <InputLabel value="Nouvelle équipe" />
                                {formFields(createForm, employees)}
                                <div className="flex justify-end">
                                    <PrimaryButton disabled={createForm.processing}>Ajouter</PrimaryButton>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
