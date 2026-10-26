import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import ToggleSwitch from '@/Components/ToggleSwitch';
import { peutSaisir } from '@/utils/acces';

const emptyForm = { transport_company_id: '', code: '', driver_name: '', driver_phone: '', capacity: '', price_per_person: '', fixed_net_per_day: '' };

// How the vehicle is paid per day it runs (TransportService::dailyCost()).
const priceLabel = (vehicle) => vehicle.fixed_net_per_day !== null
    ? `Forfait ${vehicle.fixed_net_per_day} dh/jour`
    : vehicle.price_per_person !== null ? `${vehicle.price_per_person} dh/personne` : null;

// Farm-scoped list of transport vehicles (the "CODE"/matricule) — each belongs to one
// TransportCompany and has its own driver. Its riders are the members of the équipes riding
// it: they're assigned on the Équipes page (one équipe, one vehicle), and only summarised here.
// Its daily cost is computed from the pointage: riders who worked that day × price per person
// (or the optional flat daily rate), see the Grille Transport.
export default function Vehicles({ auth, vehicles, companies, equipes = [] }) {
    // Changed by the pointeur (Pointage saisie); everyone else only consults.
    const canWrite = peutSaisir(auth.user, 'pointage');
    const [editingId, setEditingId] = useState(null);
    const [confirmingDeleteId, setConfirmingDeleteId] = useState(null);

    const createForm = useForm(emptyForm);
    const editForm = useForm(emptyForm);
    const deleteForm = useForm();

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
            price_per_person: vehicle.price_per_person ?? '',
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

    // A deactivated vehicle carries nobody from now on: the équipes riding it keep it, but their
    // members' new days are stamped without transport. Days already pointed keep their cost.
    const toggleActive = (vehicle) => {
        if (vehicle.is_active && !window.confirm(`Désactiver le véhicule « ${vehicle.code} » ?

Les jours déjà pointés gardent leur coût. À partir de maintenant, les nouveaux jours des ouvriers de ses équipes (${equipesOf(vehicle).map(e => e.name).join(', ') || 'aucune'}) ne compteront plus dans son transport.`)) return;
        router.put(route('transport.vehicles.update', vehicle.id), {
            is_active: !vehicle.is_active,
        }, { preserveScroll: true });
    };

    const confirmDelete = (vehicleId) => {
        deleteForm.delete(route('transport.vehicles.destroy', vehicleId), {
            preserveScroll: true,
            onSuccess: () => setConfirmingDeleteId(null),
        });
    };

    const equipesOf = (vehicle) => equipes.filter(e => e.transport_vehicle_id === vehicle.id);

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
                                                <div>
                                                    <InputLabel value="Prix par personne (dh)" />
                                                    <TextInput type="number" step="0.01" min="0" className="block w-full" value={editForm.data.price_per_person} onChange={(e) => editForm.setData('price_per_person', e.target.value)} placeholder="ex. 30" />
                                                    <InputError message={editForm.errors.price_per_person} className="mt-1" />
                                                </div>
                                                <div className="col-span-2">
                                                    <InputLabel value="Forfait / jour (optionnel)" />
                                                    <TextInput type="number" step="0.01" min="0" className="block w-full" value={editForm.data.fixed_net_per_day} onChange={(e) => editForm.setData('fixed_net_per_day', e.target.value)} placeholder="Vide = ouvriers présents × prix par personne" />
                                                    <p className="text-xs text-gray-400 mt-1">Si rempli, ce montant fixe remplace le calcul par personne, chaque jour où au moins un ouvrier de ses équipes a travaillé.</p>
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
                                                    {priceLabel(vehicle)
                                                        ? <span className={`font-semibold ${vehicle.fixed_net_per_day !== null ? 'text-amber-600' : 'text-primary-600'}`}>{priceLabel(vehicle)}</span>
                                                        : <span className="font-semibold text-red-600">Prix par personne non défini</span>}
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
                                                    <Link href={route('equipes.index')} title={equipesOf(vehicle).map(e => e.name).join(', ') || 'Aucune équipe'} className="text-xs font-bold text-primary-600 hover:text-primary-700 uppercase tracking-wide">
                                                        {equipesOf(vehicle).length} équipe(s) · {equipesOf(vehicle).reduce((n, e) => n + e.employees_count, 0)} ouvrier(s)
                                                    </Link>
                                                    <span className="flex items-center gap-2" title={vehicle.is_active ? 'Désactiver le véhicule' : 'Réactiver le véhicule'}>
                                                        <span className={`text-xs font-semibold ${vehicle.is_active ? 'text-green-700' : 'text-gray-400'}`}>{vehicle.is_active ? 'Actif' : 'Inactif'}</span>
                                                        <ToggleSwitch checked={vehicle.is_active} onChange={() => toggleActive(vehicle)} disabled={!canWrite} />
                                                    </span>
                                                    {canWrite && (
                                                        <button type="button" onClick={() => startEdit(vehicle)} className="text-gray-400 hover:text-gray-700 transition-colors" title="Modifier">
                                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                        </button>
                                                    )}
                                                    {canWrite && (
                                                        <button type="button" onClick={() => setConfirmingDeleteId(vehicle.id)} className="text-gray-400 hover:text-red-600 transition-colors" title="Supprimer">
                                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                            </svg>
                                                        </button>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                    {confirmingDeleteId === vehicle.id && (
                                        <InputError message={deleteForm.errors.transport_vehicle} className="mt-2" />
                                    )}
                                </div>
                            ))}
                        </div>

                        {canWrite && (
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
                                    <div>
                                        <TextInput type="number" step="0.01" min="0" className="block w-full" value={createForm.data.price_per_person} onChange={(e) => createForm.setData('price_per_person', e.target.value)} placeholder="Prix par personne (dh)" />
                                        <InputError message={createForm.errors.price_per_person} />
                                    </div>
                                    <div className="flex items-start">
                                        <PrimaryButton disabled={createForm.processing || companies.length === 0}>Ajouter</PrimaryButton>
                                    </div>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
