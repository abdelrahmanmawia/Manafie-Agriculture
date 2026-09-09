import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

const emptyForm = { transport_company_id: '', code: '', driver_name: '', driver_phone: '', capacity: '', fixed_net_per_day: '' };

// Farm-scoped list of transport vehicles (the "CODE"/matricule) — each belongs to one
// TransportCompany and has its own driver. Riders are picked per-vehicle here (a multi-select
// checklist, see syncEmployees()) rather than one at a time on Admin/Employees.jsx — much
// faster when assigning a whole van's worth of people at once.
export default function Vehicles({ auth, vehicles, companies, employees, locations }) {
    const [editingId, setEditingId] = useState(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState(null);
    const [managingRidersId, setManagingRidersId] = useState(null);
    const [riderSearch, setRiderSearch] = useState('');

    const createForm = useForm(emptyForm);
    const editForm = useForm(emptyForm);
    const deleteForm = useForm();
    const ridersForm = useForm({ employee_ids: [] });

    const submitCreate = (e) => {
        e.preventDefault();
        createForm.post(route('transport.vehicles.store'), {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
    };

    const startEdit = (vehicle) => {
        setConfirmingDeleteId(null);
        editForm.clearErrors();
        editForm.setData({
            transport_company_id: vehicle.transport_company_id,
            code: vehicle.code,
            driver_name: vehicle.driver_name || '',
            driver_phone: vehicle.driver_phone || '',
            capacity: vehicle.capacity ?? '',
            fixed_net_per_day: vehicle.fixed_net_per_day ?? '',
        });
        setEditingId(vehicle.id);
    };

    const cancelEdit = () => {
        setEditingId(null);
        editForm.reset();
    };

    const submitEdit = (e, vehicleId) => {
        e.preventDefault();
        editForm.put(route('transport.vehicles.update', vehicleId), {
            preserveScroll: true,
            onSuccess: () => setEditingId(null),
        });
    };

    const toggleActive = (vehicle) => {
        router.put(route('transport.vehicles.update', vehicle.id), {
            is_active: !vehicle.is_active,
        }, { preserveScroll: true });
    };

    const resetToAutoPrice = (vehicle) => {
        router.put(route('transport.vehicles.update', vehicle.id), {
            fixed_net_per_day: null,
        }, { preserveScroll: true });
    };

    const confirmDelete = (vehicleId) => {
        deleteForm.delete(route('transport.vehicles.destroy', vehicleId), {
            preserveScroll: true,
            onSuccess: () => setConfirmingDeleteId(null),
        });
    };

    const openRiders = (vehicle) => {
        setEditingId(null);
        setConfirmingDeleteId(null);
        setRiderSearch('');
        ridersForm.clearErrors();
        ridersForm.setData('employee_ids', employees.filter(e => e.transport_vehicle_id === vehicle.id).map(e => e.id));
        setManagingRidersId(vehicle.id);
    };

    const closeRiders = () => {
        setManagingRidersId(null);
        ridersForm.reset();
    };

    const toggleRider = (employeeId) => {
        const ids = ridersForm.data.employee_ids;
        ridersForm.setData('employee_ids', ids.includes(employeeId) ? ids.filter(id => id !== employeeId) : [...ids, employeeId]);
    };

    const submitRiders = (e, vehicleId) => {
        e.preventDefault();
        ridersForm.put(route('transport.vehicles.employees', vehicleId), {
            preserveScroll: true,
            onSuccess: () => setManagingRidersId(null),
        });
    };

    // Fires immediately on select — an employee with no residence can't be priced by
    // TransportService, so fixing it right here (instead of a separate save step) matters.
    const quickSetResidence = (employeeId, locationId) => {
        router.put(route('transport.employees.residence', employeeId), {
            residence_location_id: locationId || null,
        }, { preserveScroll: true, preserveState: true });
    };

    const CompanySelect = ({ form }) => (
        <select
            className="border-gray-300 focus:border-primary-500 focus:ring-primary-500 rounded-md shadow-sm w-full"
            value={form.data.transport_company_id}
            onChange={(e) => form.setData('transport_company_id', e.target.value)}
            required
        >
            <option value="">Sélectionner une société</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
    );

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div>
                    <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Véhicules de Transport</h2>
                    <p className="text-sm text-gray-500 mt-1">Véhicules (matricule), chauffeur et société associée</p>
                </div>
            }
        >
            <Head title="Véhicules de Transport" />

            <div className="py-8">
                <div className="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {companies.length === 0 && (
                        <div className="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-700">
                            Aucune société de transport active. <a href={route('transport.companies.index')} className="underline font-semibold">Créez-en une d'abord</a>.
                        </div>
                    )}

                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <div className="space-y-2">
                            {vehicles.length === 0 && (
                                <p className="text-sm text-gray-500 italic">Aucun véhicule pour le moment.</p>
                            )}
                            {vehicles.map((vehicle) => (
                                <div key={vehicle.id} className={`border rounded-xl p-4 ${vehicle.is_active ? 'border-gray-200' : 'border-gray-100 bg-gray-50 opacity-60'}`}>
                                    {editingId === vehicle.id ? (
                                        <form onSubmit={(e) => submitEdit(e, vehicle.id)} className="space-y-3">
                                            <div className="grid grid-cols-2 gap-3">
                                                <div>
                                                    <InputLabel value="Code / Matricule" />
                                                    <TextInput className="block w-full" value={editForm.data.code} onChange={(e) => editForm.setData('code', e.target.value)} required autoFocus />
                                                    <InputError message={editForm.errors.code} className="mt-1" />
                                                </div>
                                                <div>
                                                    <InputLabel value="Société" />
                                                    <CompanySelect form={editForm} />
                                                    <InputError message={editForm.errors.transport_company_id} className="mt-1" />
                                                </div>
                                                <div>
                                                    <InputLabel value="Chauffeur" />
                                                    <TextInput className="block w-full" value={editForm.data.driver_name} onChange={(e) => editForm.setData('driver_name', e.target.value)} />
                                                </div>
                                                <div>
                                                    <InputLabel value="Téléphone (GSM)" />
                                                    <TextInput className="block w-full" value={editForm.data.driver_phone} onChange={(e) => editForm.setData('driver_phone', e.target.value)} />
                                                </div>
                                                <div>
                                                    <InputLabel value="Capacité (places)" />
                                                    <TextInput type="number" min="0" className="block w-full" value={editForm.data.capacity} onChange={(e) => editForm.setData('capacity', e.target.value)} />
                                                </div>
                                                <div className="col-span-2">
                                                    <InputLabel value="Salaire Net/Jour" />
                                                    <div className="flex items-center gap-2">
                                                        <TextInput
                                                            type="number" step="0.01" min="0"
                                                            className="block w-full"
                                                            value={editForm.data.fixed_net_per_day}
                                                            onChange={(e) => editForm.setData('fixed_net_per_day', e.target.value)}
                                                            placeholder={`Auto : ${vehicle.net_per_day} dh (somme des résidences des employés)`}
                                                        />
                                                        {vehicle.fixed_net_per_day !== null && (
                                                            <button type="button" onClick={() => { editForm.setData('fixed_net_per_day', ''); resetToAutoPrice(vehicle); }} className="text-xs text-primary-600 hover:text-primary-700 shrink-0 whitespace-nowrap">
                                                                Auto (calculé)
                                                            </button>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-gray-400 mt-1">Laisser vide pour calculer automatiquement selon les résidences des employés assignés.</p>
                                                    <InputError message={editForm.errors.fixed_net_per_day} className="mt-1" />
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
                                                <p className="font-bold text-gray-800 truncate">
                                                    {vehicle.code}
                                                    <span className="text-gray-400 font-normal"> — {vehicle.transport_company?.name || '—'}</span>
                                                </p>
                                                <p className="text-xs text-gray-500 mt-0.5">
                                                    {vehicle.driver_name || 'Chauffeur non renseigné'}
                                                    {vehicle.driver_phone && ` · ${vehicle.driver_phone}`}
                                                    {vehicle.capacity && ` · ${vehicle.capacity} places`}
                                                    {' · '}
                                                    <span className="font-semibold text-primary-600">{vehicle.net_per_day} dh/jour</span>
                                                    <span className={`ml-1 text-[10px] uppercase font-bold ${vehicle.fixed_net_per_day !== null ? 'text-amber-600' : 'text-gray-400'}`}>
                                                        ({vehicle.fixed_net_per_day !== null ? 'fixe' : 'auto'})
                                                    </span>
                                                </p>
                                            </div>
                                            {confirmingDeleteId === vehicle.id ? (
                                                <div className="flex items-center gap-2 shrink-0">
                                                    <span className="text-xs text-gray-500">Supprimer ?</span>
                                                    <DangerButton type="button" disabled={deleteForm.processing} onClick={() => confirmDelete(vehicle.id)}>Oui</DangerButton>
                                                    <SecondaryButton type="button" onClick={() => { setConfirmingDeleteId(null); deleteForm.clearErrors(); }}>Non</SecondaryButton>
                                                </div>
                                            ) : (
                                                <div className="flex items-center gap-3 shrink-0">
                                                    <button type="button" onClick={() => openRiders(vehicle)} className="text-xs font-bold text-primary-600 hover:text-primary-700 uppercase tracking-wide">
                                                        {employees.filter(e => e.transport_vehicle_id === vehicle.id).length} employé(s)
                                                    </button>
                                                    <button type="button" onClick={() => toggleActive(vehicle)} className="text-xs font-medium text-gray-500 hover:text-gray-700">
                                                        {vehicle.is_active ? 'Actif' : 'Inactif'}
                                                    </button>
                                                    <button type="button" onClick={() => startEdit(vehicle)} className="text-gray-400 hover:text-gray-700 transition-colors" title="Modifier">
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                        </svg>
                                                    </button>
                                                    <button type="button" onClick={() => setConfirmingDeleteId(vehicle.id)} className="text-gray-400 hover:text-red-600 transition-colors" title="Supprimer">
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    {confirmingDeleteId === vehicle.id && (
                                        <InputError message={deleteForm.errors.transport_vehicle} className="mt-2" />
                                    )}
                                    {managingRidersId === vehicle.id && (
                                        <form onSubmit={(e) => submitRiders(e, vehicle.id)} className="border-t mt-3 pt-3 space-y-3">
                                            <TextInput
                                                className="block w-full"
                                                value={riderSearch}
                                                onChange={(e) => setRiderSearch(e.target.value)}
                                                placeholder="Rechercher un employé..."
                                                autoFocus
                                            />
                                            <div className="max-h-56 overflow-y-auto border border-gray-100 rounded-lg divide-y divide-gray-50">
                                                {employees
                                                    .filter(emp => {
                                                        const q = riderSearch.trim().toLowerCase();
                                                        return !q || emp.full_name.toLowerCase().includes(q) || (emp.matricule || '').toLowerCase().includes(q);
                                                    })
                                                    .map(emp => {
                                                        const checked = ridersForm.data.employee_ids.includes(emp.id);
                                                        const elsewhere = emp.transport_vehicle_id && emp.transport_vehicle_id !== vehicle.id
                                                            ? vehicles.find(v => v.id === emp.transport_vehicle_id)
                                                            : null;
                                                        return (
                                                            <div key={emp.id} className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50">
                                                                <label className="flex items-center gap-3 flex-1 min-w-0 cursor-pointer">
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={checked}
                                                                        onChange={() => toggleRider(emp.id)}
                                                                        className="rounded border-gray-300 text-primary-600 focus:ring-primary-500 shrink-0"
                                                                    />
                                                                    <span className="flex-1 min-w-0 truncate">{emp.full_name} <span className="text-gray-400">({emp.matricule})</span></span>
                                                                </label>
                                                                {elsewhere && !checked && (
                                                                    <span className="text-xs text-amber-600 shrink-0">déjà sur {elsewhere.code}</span>
                                                                )}
                                                                {/* Quick residence picker — an employee with none set can't be priced by
                                                                    TransportService, so it's flagged (amber) and fixable right here. */}
                                                                <select
                                                                    value={emp.residence_location_id || ''}
                                                                    onChange={(e) => quickSetResidence(emp.id, e.target.value)}
                                                                    onClick={(e) => e.stopPropagation()}
                                                                    className={`text-xs rounded-md shrink-0 max-w-[9.5rem] py-1 ${emp.residence_location_id ? 'border-gray-200 text-gray-500' : 'border-amber-300 text-amber-700 bg-amber-50'}`}
                                                                >
                                                                    <option value="">résidence ?</option>
                                                                    {locations.map(loc => <option key={loc.id} value={loc.id}>{loc.name}</option>)}
                                                                </select>
                                                            </div>
                                                        );
                                                    })}
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs text-gray-500">{ridersForm.data.employee_ids.length} sélectionné(s)</span>
                                                <div className="flex gap-2">
                                                    <SecondaryButton type="button" onClick={closeRiders}>Annuler</SecondaryButton>
                                                    <PrimaryButton disabled={ridersForm.processing}>Enregistrer</PrimaryButton>
                                                </div>
                                            </div>
                                        </form>
                                    )}
                                </div>
                            ))}
                        </div>

                        <form onSubmit={submitCreate} className="border-t mt-6 pt-6 space-y-3">
                            <InputLabel value="Nouveau Véhicule" />
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <TextInput className="block w-full" value={createForm.data.code} onChange={(e) => createForm.setData('code', e.target.value)} placeholder="Code / Matricule" required />
                                    <InputError message={createForm.errors.code} />
                                </div>
                                <div>
                                    <CompanySelect form={createForm} />
                                    <InputError message={createForm.errors.transport_company_id} />
                                </div>
                                <div>
                                    <TextInput className="block w-full" value={createForm.data.driver_name} onChange={(e) => createForm.setData('driver_name', e.target.value)} placeholder="Chauffeur" />
                                </div>
                                <div>
                                    <TextInput className="block w-full" value={createForm.data.driver_phone} onChange={(e) => createForm.setData('driver_phone', e.target.value)} placeholder="Téléphone (GSM)" />
                                </div>
                                <div>
                                    <TextInput type="number" min="0" className="block w-full" value={createForm.data.capacity} onChange={(e) => createForm.setData('capacity', e.target.value)} placeholder="Capacité (places)" />
                                </div>
                                <div className="flex items-start">
                                    <PrimaryButton disabled={createForm.processing || companies.length === 0}>Ajouter</PrimaryButton>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
