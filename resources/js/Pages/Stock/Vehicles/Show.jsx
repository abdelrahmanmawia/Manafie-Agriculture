import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import ToggleSwitch from '@/Components/ToggleSwitch';
import { formatNumber, formatInt, formatMAD } from '@/utils/number';
import {
    VEHICLE_TYPE_LABELS as TYPE_LABELS,
    FUEL_TYPE_LABELS,
    ENTRY_TYPE_LABELS,
    UNIT_TYPE_LABELS,
    ASSET_TYPE_LABELS,
    ASSET_STATUS_LABELS,
    EQUIPMENT_TYPE_LABELS,
} from '@/utils/stockLabels';

export default function Show({ auth, vehicle, vehicleTypes, equipmentTypes, fuelTypes, employees }) {
    const types = vehicleTypes.map((t) => t.key);
    const vehicleTypeLabelMap = Object.fromEntries(vehicleTypes.map((t) => [t.key, t.label]));
    const [confirmingVehicleDeletion, setConfirmingVehicleDeletion] = useState(false);
    const [confirmingMaintenanceLogDeletion, setConfirmingMaintenanceLogDeletion] = useState(null);
    const [isEditing, setIsEditing] = useState(false);
    const [isAddingMaintenance, setIsAddingMaintenance] = useState(false);
    const [editingMaintenanceLog, setEditingMaintenanceLog] = useState(null);
    const { delete: destroy, processing, errors } = useForm();

    const typeOptionsFor = (assetType) => (assetType === 'equipment' ? equipmentTypes : types);
    const typeLabelsFor = (assetType) => (assetType === 'equipment' ? EQUIPMENT_TYPE_LABELS : { ...TYPE_LABELS, ...vehicleTypeLabelMap });

    const editForm = useForm({
        asset_type: vehicle.asset_type,
        name: vehicle.name,
        plate_number: vehicle.plate_number || '',
        serial_number: vehicle.serial_number || '',
        type: vehicle.type,
        model: vehicle.model || '',
        fuel_type: vehicle.fuel_type,
        capacity_liters: vehicle.capacity_liters ?? '',
        status: vehicle.status || 'operational',
        quantity: vehicle.quantity || 1,
        default_driver_id: vehicle.default_driver_id || '',
        is_active: vehicle.is_active,
        is_location: vehicle.is_location,
        default_daily_rate: vehicle.default_daily_rate ?? '',
        notes: vehicle.notes || '',
    });

    const maintenanceForm = useForm({
        description: '',
        performed_at: new Date().toISOString().slice(0, 10),
        cost: '',
        performed_by_id: '',
        next_due_date: '',
    });

    const confirmVehicleDeletion = () => setConfirmingVehicleDeletion(true);
    const closeModal = () => setConfirmingVehicleDeletion(false);

    const deleteVehicle = (e) => {
        e.preventDefault();
        destroy(route('stock.vehicles.destroy', vehicle.id), {
            preserveScroll: true,
            onSuccess: closeModal,
        });
    };

    const openEdit = () => {
        editForm.clearErrors();
        editForm.setData({
            asset_type: vehicle.asset_type,
            name: vehicle.name,
            plate_number: vehicle.plate_number || '',
            serial_number: vehicle.serial_number || '',
            type: vehicle.type,
            model: vehicle.model || '',
            fuel_type: vehicle.fuel_type,
            capacity_liters: vehicle.capacity_liters ?? '',
            status: vehicle.status || 'operational',
            quantity: vehicle.quantity || 1,
            default_driver_id: vehicle.default_driver_id || '',
            is_active: vehicle.is_active,
            is_location: vehicle.is_location,
            default_daily_rate: vehicle.default_daily_rate ?? '',
            notes: vehicle.notes || '',
        });
        setIsEditing(true);
    };

    const handleEditAssetTypeChange = (assetType) => {
        const options = typeOptionsFor(assetType);
        editForm.setData((prev) => ({
            ...prev,
            asset_type: assetType,
            type: options.length > 0 ? options[0] : '',
            plate_number: assetType === 'equipment' ? '' : prev.plate_number,
        }));
    };

    const closeEdit = () => setIsEditing(false);

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('stock.vehicles.update', vehicle.id), {
            onSuccess: () => closeEdit(),
        });
    };

    const openAddMaintenance = () => {
        setEditingMaintenanceLog(null);
        maintenanceForm.clearErrors();
        maintenanceForm.reset();
        setIsAddingMaintenance(true);
    };

    const openEditMaintenance = (log) => {
        setEditingMaintenanceLog(log);
        maintenanceForm.clearErrors();
        maintenanceForm.setData({
            description: log.description,
            performed_at: log.performed_at ? String(log.performed_at).slice(0, 10) : '',
            cost: log.cost ?? '',
            performed_by_id: log.performed_by_id || '',
            next_due_date: log.next_due_date ? String(log.next_due_date).slice(0, 10) : '',
        });
        setIsAddingMaintenance(true);
    };

    const closeAddMaintenance = () => {
        setIsAddingMaintenance(false);
        setEditingMaintenanceLog(null);
    };

    const submitMaintenance = (e) => {
        e.preventDefault();
        if (editingMaintenanceLog) {
            maintenanceForm.put(route('stock.vehicles.maintenance-logs.update', editingMaintenanceLog.id), {
                onSuccess: () => closeAddMaintenance(),
            });
        } else {
            maintenanceForm.post(route('stock.vehicles.maintenance-logs.store', vehicle.id), {
                onSuccess: () => closeAddMaintenance(),
            });
        }
    };

    const confirmMaintenanceLogDeletion = (log) => setConfirmingMaintenanceLogDeletion(log);
    const closeMaintenanceLogDeleteModal = () => setConfirmingMaintenanceLogDeletion(null);
    const deleteMaintenanceLog = (e) => {
        e.preventDefault();
        destroy(route('stock.vehicles.maintenance-logs.destroy', confirmingMaintenanceLogDeletion.id), {
            preserveScroll: true,
            onSuccess: closeMaintenanceLogDeleteModal,
            onError: closeMaintenanceLogDeleteModal,
            onFinish: closeMaintenanceLogDeleteModal,
        });
    };

    const handleToggleActive = () => {
        router.post(route('stock.vehicles.toggle-active', vehicle.id), {}, { preserveScroll: true });
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const fuelTransactions = vehicle.fuel_transactions ?? [];
    const manualStockEntries = vehicle.manual_stock_entries ?? [];
    const locationUsages = vehicle.usages ?? [];
    const maintenanceLogs = vehicle.maintenance_logs ?? [];

    // Flagged client-side from already-loaded data — no separate reminder job needed.
    const dueSoonCutoff = new Date();
    dueSoonCutoff.setDate(dueSoonCutoff.getDate() + 7);
    const upcomingMaintenance = maintenanceLogs.find((log) => log.next_due_date && new Date(log.next_due_date) <= dueSoonCutoff);
    const totalLocationDays = locationUsages.length;
    const totalLocationAmount = locationUsages.reduce((sum, u) => sum + parseFloat(u.daily_rate || 0), 0);

    const totalFuelLiters = fuelTransactions.reduce((sum, t) => sum + parseFloat(t.quantity_liters || 0), 0);
    const totalFuelCost = fuelTransactions.reduce((sum, t) => sum + parseFloat(t.total_cost || 0), 0);

    const activity = [
        ...fuelTransactions.map((t) => ({
            key: `ft-${t.id}`,
            date: t.date,
            label: 'Ravitaillement',
            badgeClass: 'bg-orange-100 text-orange-800',
            product_name: t.product?.name ?? 'Carburant',
            quantity: t.quantity_liters,
            unit_type: 'L',
            cost: t.total_cost,
            person: t.driver?.full_name,
            href: route('stock.fuel-transactions.show', t.id),
        })),
        ...manualStockEntries.map((e) => ({
            key: `mse-${e.id}`,
            date: e.date,
            label: ENTRY_TYPE_LABELS[e.entry_type] ?? e.entry_type,
            badgeClass: 'bg-indigo-100 text-indigo-800',
            product_name: e.product?.name ?? 'N/A',
            quantity: e.quantity,
            unit_type: e.product?.unit_type ?? '',
            cost: e.stock_movement?.total_cost ?? null,
            person: e.employee?.full_name,
            href: route('stock.manual-entries.show', e.id),
        })),
    ].sort((a, b) => new Date(b.date) - new Date(a.date));

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <div className="flex items-center gap-4">
                        <Link href={route('stock.vehicles.index')} className="text-gray-500 hover:text-gray-700 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">
                                {vehicle.asset_type === 'equipment' ? "Détails de l'Équipement" : 'Détails du Véhicule'}
                            </h2>
                            <p className="text-sm text-gray-500 mt-1">Informations et historique d'utilisation</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={openEdit}
                            className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            Modifier
                        </button>
                        {auth.user.role !== 'data_entry' && (
                            <DangerButton onClick={confirmVehicleDeletion} className="rounded-xl">Supprimer</DangerButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Véhicule: ${vehicle.name}`} />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Vehicle Header Card */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6">
                            <div className="flex items-start justify-between">
                                <div className="flex items-center gap-4">
                                    <div className="flex-shrink-0 h-16 w-16 bg-gray-100 rounded-xl flex items-center justify-center">
                                        <svg className="h-8 w-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l1.5-4.5A2 2 0 018.4 7h7.2a2 2 0 011.9 1.5L19 13m-14 0h14m-14 0v4a1 1 0 001 1h1a1 1 0 001-1v-1h8v1a1 1 0 001 1h1a1 1 0 001-1v-4" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-black text-gray-900 uppercase tracking-tighter">
                                            {vehicle.name}
                                            {vehicle.quantity > 1 && (
                                                <span className="ml-2 text-base text-gray-500 normal-case tracking-normal">×{vehicle.quantity}</span>
                                            )}
                                        </h3>
                                        <div className="flex items-center gap-3 mt-2 flex-wrap">
                                            <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${vehicle.asset_type === 'equipment' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'}`}>
                                                {ASSET_TYPE_LABELS[vehicle.asset_type] || vehicle.asset_type}
                                            </span>
                                            <span className="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                {typeLabelsFor(vehicle.asset_type)[vehicle.type] || vehicle.type}
                                            </span>
                                            {(() => {
                                                const statusMeta = ASSET_STATUS_LABELS[vehicle.status] || ASSET_STATUS_LABELS.operational;
                                                return (
                                                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${statusMeta.className}`}>
                                                        {statusMeta.label}
                                                    </span>
                                                );
                                            })()}
                                            <span className="text-sm text-gray-500">{vehicle.plate_number || vehicle.serial_number || '—'}</span>
                                        </div>
                                    </div>
                                </div>
                                <div className={`px-4 py-2 rounded-lg flex items-center gap-3 ${vehicle.is_active ? 'bg-green-50' : 'bg-gray-50'}`}>
                                    <span className={`font-semibold text-sm ${vehicle.is_active ? 'text-green-600' : 'text-gray-500'}`}>
                                        {vehicle.is_active ? 'Actif' : 'Inactif'}
                                    </span>
                                    <ToggleSwitch
                                        checked={vehicle.is_active}
                                        onChange={handleToggleActive}
                                        disabled={auth.user.role === 'data_entry'}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    {upcomingMaintenance && (
                        <div className="flex items-center gap-3 bg-orange-50 border border-orange-200 rounded-xl px-5 py-4">
                            <svg className="w-5 h-5 text-orange-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <p className="text-sm font-medium text-orange-900">
                                Entretien à prévoir — prochaine échéance le {formatDate(upcomingMaintenance.next_due_date)}
                                {upcomingMaintenance.description ? ` (${upcomingMaintenance.description})` : ''}
                            </p>
                        </div>
                    )}

                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Pleins Enregistrés</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatInt(fuelTransactions.length)}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Carburant Total</p>
                            <p className="text-2xl font-bold text-orange-600 mt-1">{formatNumber(totalFuelLiters, 0)} L</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Coût Carburant Total</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatMAD(totalFuelCost)}</p>
                        </div>
                    </div>

                    {/* Vehicle Information */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Informations</h4>
                        </div>
                        <div className="p-6 grid grid-cols-1 md:grid-cols-3 gap-x-8">
                            <div className="flex justify-between items-center py-2 border-b md:border-b-0 border-gray-50">
                                <span className="text-gray-500">Modèle</span>
                                <span className="font-medium text-gray-900">{vehicle.model || 'N/A'}</span>
                            </div>
                            <div className="flex justify-between items-center py-2 border-b md:border-b-0 border-gray-50">
                                <span className="text-gray-500">Type de Carburant</span>
                                <span className="font-medium text-gray-900">{vehicle.fuel_type ? (FUEL_TYPE_LABELS[vehicle.fuel_type] || vehicle.fuel_type) : 'N/A'}</span>
                            </div>
                            {vehicle.asset_type === 'vehicle' ? (
                                <div className="flex justify-between items-center py-2">
                                    <span className="text-gray-500">Conducteur par Défaut</span>
                                    <span className="font-medium text-gray-900">{vehicle.default_driver?.full_name || 'N/A'}</span>
                                </div>
                            ) : (
                                <div className="flex justify-between items-center py-2">
                                    <span className="text-gray-500">N° de Série</span>
                                    <span className="font-medium text-gray-900">{vehicle.serial_number || 'N/A'}</span>
                                </div>
                            )}
                            <div className="flex justify-between items-center py-2 border-b md:border-b-0 border-gray-50 md:border-t md:pt-4 md:mt-2">
                                <span className="text-gray-500">Capacité du Réservoir</span>
                                <span className="font-medium text-gray-900">{vehicle.capacity_liters ? `${formatNumber(vehicle.capacity_liters, 0)} L` : 'N/A'}</span>
                            </div>
                        </div>
                    </div>

                    {/* Maintenance History */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100 flex justify-between items-center">
                            <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Historique de Maintenance</h4>
                            {auth.user.role !== 'data_entry' && (
                                <button
                                    type="button"
                                    onClick={openAddMaintenance}
                                    className="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-xl font-black text-xs uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                                >
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    Ajouter une Intervention
                                </button>
                            )}
                        </div>
                        {maintenanceLogs.length === 0 ? (
                            <div className="p-6 text-center text-sm text-gray-500">Aucune intervention de maintenance enregistrée.</div>
                        ) : (
                            <div className="divide-y divide-gray-100">
                                {maintenanceLogs.map((log) => {
                                    const parts = log.manual_stock_entries ?? [];
                                    const partsCost = parts.reduce((sum, e) => sum + parseFloat(e.stock_movement?.total_cost || 0), 0);
                                    const laborCost = parseFloat(log.cost || 0);
                                    const totalCost = laborCost + partsCost;
                                    return (
                                    <div key={log.id} className="p-4 flex items-center justify-between gap-4">
                                        <div>
                                            <p className="text-sm font-medium text-gray-900">{log.description}</p>
                                            <p className="text-xs text-gray-500 mt-0.5">
                                                {formatDate(log.performed_at)}
                                                {log.performed_by?.full_name ? ` · ${log.performed_by.full_name}` : ''}
                                                {log.next_due_date ? ` · Prochaine échéance : ${formatDate(log.next_due_date)}` : ''}
                                            </p>
                                            {parts.length > 0 && (
                                                <p className="text-xs text-teal-700 mt-1">
                                                    🔧 {parts.map((e) => e.product?.name).filter(Boolean).join(', ')} — {formatMAD(partsCost)}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-4 shrink-0">
                                            {totalCost > 0 && (
                                                <div className="text-right">
                                                    <p className="text-sm font-semibold text-gray-900">{formatMAD(totalCost)}</p>
                                                    {laborCost > 0 && partsCost > 0 && (
                                                        <p className="text-xs text-gray-400">dont {formatMAD(laborCost)} main d'œuvre</p>
                                                    )}
                                                </div>
                                            )}
                                            <button
                                                type="button"
                                                onClick={() => openEditMaintenance(log)}
                                                className="text-gray-400 hover:text-blue-600 transition-colors"
                                                title="Modifier"
                                            >
                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            {auth.user.role !== 'data_entry' && (
                                                <button
                                                    type="button"
                                                    onClick={() => confirmMaintenanceLogDeletion(log)}
                                                    className="text-gray-400 hover:text-red-600 transition-colors"
                                                    title="Supprimer"
                                                >
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    {/* Location (rental tracking) — only for vehicles flagged "Disponible en Location" */}
                    {vehicle.is_location && (
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100 flex justify-between items-center">
                                <div>
                                    <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Location</h4>
                                    <p className="text-sm text-gray-500 mt-1">Jours d'utilisation enregistrés dans la grille Location</p>
                                </div>
                                <Link
                                    href={route('stock.vehicle-usage.index')}
                                    className="text-purple-600 hover:text-purple-800 text-sm font-semibold"
                                >
                                    Voir la grille →
                                </Link>
                            </div>
                            <div className="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div className="bg-purple-50 rounded-xl p-4">
                                    <p className="text-sm text-gray-500">Jours Loués (total)</p>
                                    <p className="text-2xl font-bold text-purple-700 mt-1">{formatInt(totalLocationDays)}</p>
                                </div>
                                <div className="bg-purple-50 rounded-xl p-4">
                                    <p className="text-sm text-gray-500">Montant Total</p>
                                    <p className="text-2xl font-bold text-purple-700 mt-1">{formatMAD(totalLocationAmount)}</p>
                                </div>
                                <div className="bg-gray-50 rounded-xl p-4">
                                    <p className="text-sm text-gray-500">Tarif Journalier par Défaut</p>
                                    <p className="text-2xl font-bold text-gray-800 mt-1">
                                        {vehicle.default_daily_rate ? formatMAD(vehicle.default_daily_rate) : 'Non défini'}
                                    </p>
                                </div>
                            </div>
                            {locationUsages.length > 0 && (
                                <div className="px-6 pb-6">
                                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead>
                                            <tr>
                                                <th className="py-2 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                                <th className="py-2 text-right text-xs font-semibold text-gray-500 uppercase">Tarif ce jour-là</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100">
                                            {locationUsages.slice(0, 10).map((u) => (
                                                <tr key={u.id}>
                                                    <td className="py-2 text-gray-700">{formatDate(u.date)}</td>
                                                    <td className="py-2 text-right font-medium text-gray-900">{formatMAD(u.daily_rate)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {locationUsages.length > 10 && (
                                        <p className="text-xs text-gray-400 mt-2">Affichage des 10 entrées les plus récentes sur {locationUsages.length}.</p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}

                    {vehicle.notes && (
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Notes</h4>
                            </div>
                            <div className="p-6">
                                <p className="bg-gray-50 p-4 rounded-lg text-sm text-gray-700">{vehicle.notes}</p>
                            </div>
                        </div>
                    )}

                    {/* Activity History */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Historique de Consommation</h4>
                            <p className="text-sm text-gray-500 mt-1">Pleins de carburant et sorties de stock liées à ce véhicule</p>
                        </div>
                        <div className="p-6">
                            {activity.length === 0 ? (
                                <div className="text-center py-8">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <p className="mt-4 text-gray-500">Aucune activité enregistrée pour ce véhicule</p>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {activity.slice(0, 10).map((item) => (
                                        <Link
                                            key={item.key}
                                            href={item.href}
                                            className="flex items-center justify-between p-4 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors"
                                        >
                                            <div className="flex items-center gap-4">
                                                <span className={`text-xs font-medium px-2.5 py-1 rounded-full ${item.badgeClass}`}>{item.label}</span>
                                                <div>
                                                    <p className="font-medium text-gray-900">{item.product_name}</p>
                                                    <p className="text-sm text-gray-500">{formatDate(item.date)}{item.person ? ` · ${item.person}` : ''}</p>
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <p className="font-semibold text-gray-700">{formatNumber(item.quantity)} {UNIT_TYPE_LABELS[item.unit_type] || item.unit_type}</p>
                                                {item.cost != null && <p className="text-sm text-gray-500">{formatMAD(item.cost)}</p>}
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={confirmingVehicleDeletion} onClose={closeModal}>
                <form onSubmit={deleteVehicle} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Êtes-vous sûr de vouloir supprimer ce véhicule ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Cette action est irréversible. La suppression n'est possible que si le véhicule n'a aucun historique (carburant ou sorties de stock).
                    </p>
                    <InputError message={errors.vehicle} className="mt-2" />
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <DangerButton className="ml-3" disabled={processing}>
                            Supprimer le Véhicule
                        </DangerButton>
                    </div>
                </form>
            </Modal>

            <Modal show={confirmingMaintenanceLogDeletion !== null} onClose={closeMaintenanceLogDeleteModal}>
                <form onSubmit={deleteMaintenanceLog} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Êtes-vous sûr de vouloir supprimer cette intervention de maintenance ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Cette action est irréversible.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton type="button" onClick={closeMaintenanceLogDeleteModal}>Annuler</SecondaryButton>
                        <DangerButton className="ml-3" disabled={processing}>
                            Supprimer
                        </DangerButton>
                    </div>
                </form>
            </Modal>

            {/* ADD / EDIT MAINTENANCE LOG MODAL */}
            <Modal show={isAddingMaintenance} onClose={closeAddMaintenance}>
                <div className="p-8">
                    <div className="flex justify-between items-center mb-6">
                        <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">{editingMaintenanceLog ? "Modifier l'Intervention" : 'Ajouter une Intervention'}</h3>
                        <button onClick={closeAddMaintenance} className="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <form onSubmit={submitMaintenance} className="space-y-6">
                        <div>
                            <InputLabel htmlFor="maint_description" value="Description *" />
                            <textarea
                                id="maint_description"
                                className="mt-1 block w-full border-gray-300 focus:border-orange-500 focus:ring-orange-500 rounded-lg shadow-sm"
                                value={maintenanceForm.data.description}
                                onChange={(e) => maintenanceForm.setData('description', e.target.value)}
                                rows="3"
                                required
                                autoFocus
                                placeholder="Ex: Remplacement du filtre à huile"
                            ></textarea>
                            <InputError message={maintenanceForm.errors.description} className="mt-2" />
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="maint_performed_at" value="Date *" />
                                <TextInput
                                    id="maint_performed_at"
                                    type="date"
                                    className="mt-1 block w-full"
                                    value={maintenanceForm.data.performed_at}
                                    onChange={(e) => maintenanceForm.setData('performed_at', e.target.value)}
                                    required
                                />
                                <InputError message={maintenanceForm.errors.performed_at} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="maint_cost" value="Coût (MAD)" />
                                <TextInput
                                    id="maint_cost"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    className="mt-1 block w-full"
                                    value={maintenanceForm.data.cost}
                                    onChange={(e) => maintenanceForm.setData('cost', e.target.value)}
                                    placeholder="Ex: 350.00"
                                />
                                <p className="text-xs text-gray-500 mt-1">Main d'œuvre / facture garage — hors pièces sorties du magasin.</p>
                                <InputError message={maintenanceForm.errors.cost} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="maint_performed_by_id" value="Effectué Par" />
                                <select
                                    id="maint_performed_by_id"
                                    className="mt-1 block w-full border-gray-300 focus:border-orange-500 focus:ring-orange-500 rounded-lg shadow-sm"
                                    value={maintenanceForm.data.performed_by_id}
                                    onChange={(e) => maintenanceForm.setData('performed_by_id', e.target.value)}
                                >
                                    <option value="">-- Sélectionner --</option>
                                    {employees.map((employee) => (
                                        <option key={employee.id} value={employee.id}>{employee.full_name}</option>
                                    ))}
                                </select>
                                <InputError message={maintenanceForm.errors.performed_by_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="maint_next_due_date" value="Prochaine Échéance" />
                                <TextInput
                                    id="maint_next_due_date"
                                    type="date"
                                    className="mt-1 block w-full"
                                    value={maintenanceForm.data.next_due_date}
                                    onChange={(e) => maintenanceForm.setData('next_due_date', e.target.value)}
                                />
                                <p className="text-xs text-gray-500 mt-1">Optionnel — affiche une alerte quand la date approche.</p>
                                <InputError message={maintenanceForm.errors.next_due_date} className="mt-2" />
                            </div>
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={closeAddMaintenance}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={maintenanceForm.processing} className="bg-orange-600 hover:bg-orange-700">
                                {maintenanceForm.processing
                                    ? 'Enregistrement...'
                                    : (editingMaintenanceLog ? "Mettre à Jour l'Intervention" : "Enregistrer l'Intervention")}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>

            {/* EDIT VEHICLE MODAL */}
            <Modal show={isEditing} onClose={closeEdit}>
                <div className="p-8">
                    <div className="flex justify-between items-center mb-6">
                        <div className="flex items-center gap-3">
                            <div className="h-10 w-10 bg-gray-100 rounded-xl flex items-center justify-center">
                                <svg className="h-6 w-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </div>
                            <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">Modifier l'Actif</h3>
                        </div>
                        <button onClick={closeEdit} className="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <form onSubmit={submitEdit} className="space-y-6">
                        <div className="grid grid-cols-2 gap-3">
                            {['vehicle', 'equipment'].map((assetType) => (
                                <button
                                    key={assetType}
                                    type="button"
                                    onClick={() => handleEditAssetTypeChange(assetType)}
                                    className={`px-4 py-3 rounded-xl border-2 font-bold text-sm uppercase tracking-wide transition-colors ${
                                        editForm.data.asset_type === assetType
                                            ? 'border-gray-700 bg-gray-700 text-white'
                                            : 'border-gray-200 text-gray-500 hover:border-gray-400'
                                    }`}
                                >
                                    {ASSET_TYPE_LABELS[assetType]}
                                </button>
                            ))}
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="edit_name" value="Nom *" />
                                <TextInput
                                    id="edit_name"
                                    type="text"
                                    className="mt-1 block w-full"
                                    value={editForm.data.name}
                                    onChange={(e) => editForm.setData('name', e.target.value)}
                                    required
                                    autoFocus
                                />
                                <InputError message={editForm.errors.name} className="mt-2" />
                            </div>

                            {editForm.data.asset_type === 'vehicle' ? (
                                <div>
                                    <InputLabel htmlFor="edit_plate_number" value="Plaque d'Immatriculation *" />
                                    <TextInput
                                        id="edit_plate_number"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={editForm.data.plate_number}
                                        onChange={(e) => editForm.setData('plate_number', e.target.value)}
                                        required
                                    />
                                    <InputError message={editForm.errors.plate_number} className="mt-2" />
                                </div>
                            ) : (
                                <div>
                                    <InputLabel htmlFor="edit_serial_number" value="N° de Série" />
                                    <TextInput
                                        id="edit_serial_number"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={editForm.data.serial_number}
                                        onChange={(e) => editForm.setData('serial_number', e.target.value)}
                                        placeholder="Optionnel"
                                    />
                                    <InputError message={editForm.errors.serial_number} className="mt-2" />
                                </div>
                            )}

                            <div>
                                <InputLabel htmlFor="edit_type" value="Type *" />
                                <select
                                    id="edit_type"
                                    className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                    value={editForm.data.type}
                                    onChange={(e) => editForm.setData('type', e.target.value)}
                                    required
                                >
                                    {typeOptionsFor(editForm.data.asset_type).map((type) => (
                                        <option key={type} value={type}>{typeLabelsFor(editForm.data.asset_type)[type] || type}</option>
                                    ))}
                                </select>
                                <InputError message={editForm.errors.type} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="edit_status" value="État" />
                                <select
                                    id="edit_status"
                                    className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                    value={editForm.data.status}
                                    onChange={(e) => editForm.setData('status', e.target.value)}
                                >
                                    {Object.entries(ASSET_STATUS_LABELS).map(([value, meta]) => (
                                        <option key={value} value={value}>{meta.label}</option>
                                    ))}
                                </select>
                                <InputError message={editForm.errors.status} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="edit_quantity" value="Quantité" />
                                <TextInput
                                    id="edit_quantity"
                                    type="number"
                                    min="1"
                                    step="1"
                                    className="mt-1 block w-full"
                                    value={editForm.data.quantity}
                                    onChange={(e) => editForm.setData('quantity', e.target.value)}
                                />
                                <InputError message={editForm.errors.quantity} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="edit_fuel_type" value="Type de Carburant" />
                                <select
                                    id="edit_fuel_type"
                                    className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                    value={editForm.data.fuel_type}
                                    onChange={(e) => editForm.setData('fuel_type', e.target.value)}
                                >
                                    {fuelTypes.map((fuel) => (
                                        <option key={fuel} value={fuel}>{FUEL_TYPE_LABELS[fuel] || fuel}</option>
                                    ))}
                                </select>
                                <InputError message={editForm.errors.fuel_type} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="edit_capacity_liters" value="Capacité du Réservoir (L)" />
                                <TextInput
                                    id="edit_capacity_liters"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    className="mt-1 block w-full"
                                    value={editForm.data.capacity_liters}
                                    onChange={(e) => editForm.setData('capacity_liters', e.target.value)}
                                />
                                <InputError message={editForm.errors.capacity_liters} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="edit_model" value="Modèle" />
                                <TextInput
                                    id="edit_model"
                                    type="text"
                                    className="mt-1 block w-full"
                                    value={editForm.data.model}
                                    onChange={(e) => editForm.setData('model', e.target.value)}
                                />
                                <InputError message={editForm.errors.model} className="mt-2" />
                            </div>

                            {editForm.data.asset_type === 'vehicle' && (
                            <div>
                                <InputLabel htmlFor="edit_default_driver_id" value="Conducteur par Défaut" />
                                <select
                                    id="edit_default_driver_id"
                                    className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                    value={editForm.data.default_driver_id}
                                    onChange={(e) => editForm.setData('default_driver_id', e.target.value)}
                                >
                                    <option value="">-- Sélectionner un conducteur --</option>
                                    {employees.map((employee) => (
                                        <option key={employee.id} value={employee.id}>{employee.full_name}</option>
                                    ))}
                                </select>
                                <InputError message={editForm.errors.default_driver_id} className="mt-2" />
                            </div>
                            )}

                            <div className="md:col-span-2">
                                <InputLabel htmlFor="edit_notes" value="Notes" />
                                <textarea
                                    id="edit_notes"
                                    className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                    value={editForm.data.notes}
                                    onChange={(e) => editForm.setData('notes', e.target.value)}
                                    rows="3"
                                ></textarea>
                                <InputError message={editForm.errors.notes} className="mt-2" />
                            </div>
                        </div>

                        <div className="flex items-center justify-between bg-gray-50 rounded-xl p-4">
                            <div>
                                <InputLabel htmlFor="edit_is_active" value="Actif" className="mb-0" />
                                <p className="text-xs text-gray-500">Les éléments inactifs ne sont plus proposés dans les sélections</p>
                            </div>
                            <ToggleSwitch
                                checked={editForm.data.is_active}
                                onChange={(e) => editForm.setData('is_active', e.target.checked)}
                            />
                        </div>

                        {editForm.data.asset_type === 'vehicle' && (
                        <div className="bg-purple-50 rounded-xl p-4 space-y-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <InputLabel htmlFor="edit_is_location" value="Disponible en Location" className="mb-0" />
                                    <p className="text-xs text-gray-500">Seuls les véhicules marqués ici apparaissent dans la grille "Location"</p>
                                </div>
                                <ToggleSwitch
                                    checked={editForm.data.is_location}
                                    onChange={(e) => editForm.setData('is_location', e.target.checked)}
                                />
                            </div>
                            {editForm.data.is_location && (
                                <div>
                                    <InputLabel htmlFor="edit_default_daily_rate" value="Tarif Journalier par Défaut (DH)" />
                                    <TextInput
                                        id="edit_default_daily_rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className="mt-1 block w-full"
                                        value={editForm.data.default_daily_rate}
                                        onChange={(e) => editForm.setData('default_daily_rate', e.target.value)}
                                    />
                                    <InputError message={editForm.errors.default_daily_rate} className="mt-2" />
                                </div>
                            )}
                        </div>
                        )}

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={closeEdit}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={editForm.processing} className="bg-gray-700 hover:bg-gray-800">
                                {editForm.processing ? 'Mise à jour...' : "Mettre à Jour l'Actif"}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
