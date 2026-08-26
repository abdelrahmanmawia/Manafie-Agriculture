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
import { formatNumber } from '@/utils/number';
import { ENTRY_TYPE_LABELS, UNIT_TYPE_LABELS } from '@/utils/stockLabels';

export default function Index({ auth, manualStockEntries, products, employees, vehicles, blocs, sectors, parcelles, operations, vehicleMaintenanceLogs }) {
    const [isCreating, setIsCreating] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedType, setSelectedType] = useState('');
    const [deletingEntry, setDeletingEntry] = useState(null);
    const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);

    const { data, setData, post, processing, reset, errors } = useForm({
        product_id: products.length > 0 ? products[0].id : '',
        entry_type: 'consumption',
        quantity: '',
        employee_id: '',
        vehicle_id: '',
        maintenance_log_id: '',
        pointage_record_id: '',
        operation_id: '',
        bloc_id: '',
        sector_id: '',
        parcelle_id: '',
        date: new Date().toISOString().slice(0, 10),
        notes: '',
        odometer_km: '',
    });

    const formatLogOption = (log) => {
        const date = new Date(log.performed_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
        return `${date} — ${log.description.slice(0, 60)}`;
    };
    const vehicleLogsFor = (vehicleId) => vehicleMaintenanceLogs.filter((log) => String(log.vehicle_id) === String(vehicleId));

    const filteredSectors = data.bloc_id
        ? sectors.filter((sector) => String(sector.bloc_id) === String(data.bloc_id))
        : [];
    const filteredParcelles = data.sector_id
        ? parcelles.filter((parcelle) => String(parcelle.sector_id) === String(data.sector_id))
        : [];

    const selectedVehicle = vehicles.find((v) => String(v.id) === String(data.vehicle_id));
    // A tractor/truck works a field (bloc/opération apply); a car/van is just transport (they don't).
    const hidesFieldContext = selectedVehicle && ['car', 'van'].includes(selectedVehicle.type);

    const submit = (e) => {
        e.preventDefault();
        post(route('stock.manual-entries.store'), {
            onSuccess: () => {
                reset();
                setIsCreating(false);
            },
        });
    };

    // Only fuel/oil/parts-type products are tied to a specific vehicle when they leave the magasin.
    const selectedProduct = products.find((p) => String(p.id) === String(data.product_id));
    const isVehicleConsumable = ['fuel', 'vehicle_needs'].includes(selectedProduct?.category);

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const filteredEntries = manualStockEntries.filter(entry => {
        const matchesSearch = entry.product?.name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
                             entry.employee?.full_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
                             entry.vehicle?.name?.toLowerCase().includes(searchTerm.toLowerCase());
        const matchesType = !selectedType || entry.entry_type === selectedType;
        return matchesSearch && matchesType;
    });

    const getEntryTypeColor = (type) => {
        const colors = {
            'consumption': 'bg-red-100 text-red-800',
            'transfer': 'bg-blue-100 text-blue-800',
            'loss': 'bg-orange-100 text-orange-800',
            'theft': 'bg-purple-100 text-purple-800',
            'damage': 'bg-yellow-100 text-yellow-800',
            'maintenance': 'bg-teal-100 text-teal-800',
        };
        return colors[type] || 'bg-gray-100 text-gray-800';
    };

    const getEntryTypeLabel = (type) => ENTRY_TYPE_LABELS[type] || type;

    const handleVerify = (entryId) => {
        router.post(route('stock.manual-entries.verify', entryId), {}, {
            onSuccess: () => {
                // Entry will be reloaded from server
            },
        });
    };

    const handleDelete = () => {
        if (deletingEntry) {
            router.delete(route('stock.manual-entries.destroy', deletingEntry.id), {
                onSuccess: () => {
                    setIsDeleteModalOpen(false);
                    setDeletingEntry(null);
                },
            });
        }
    };

    const openDeleteModal = (entry) => {
        setDeletingEntry(entry);
        setIsDeleteModalOpen(true);
    };

    const closeDeleteModal = () => {
        setIsDeleteModalOpen(false);
        setDeletingEntry(null);
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div>
                        <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Sorties de Stock</h2>
                        <p className="text-sm text-gray-500 mt-1">Enregistrez les consommations, transferts et ajustements de stock</p>
                    </div>
                    {auth.user.role !== 'data_entry' && (
                        <button
                            onClick={() => setIsCreating(true)}
                            className="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                        >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Nouvelle Sortie
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Sorties de Stock" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Filters */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        placeholder="Rechercher par produit, employé ou véhicule..."
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    />
                                    <svg className="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Type de Sortie</label>
                                <select
                                    value={selectedType}
                                    onChange={(e) => setSelectedType(e.target.value)}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option value="">Tous les types</option>
                                    <option value="consumption">Consommation</option>
                                    <option value="transfer">Transfert</option>
                                    <option value="loss">Perte</option>
                                    <option value="theft">Vol</option>
                                    <option value="damage">Dommage</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Entries Table */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <div className="flex justify-between items-center">
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Historique des Sorties</h3>
                                <span className="text-sm text-gray-500">{filteredEntries.length} sortie(s)</span>
                            </div>
                        </div>

                        {filteredEntries.length === 0 ? (
                            <div className="text-center py-16">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                <p className="mt-4 text-gray-500">Aucune sortie trouvée</p>
                                {auth.user.role !== 'data_entry' && (
                                    <button
                                        onClick={() => setIsCreating(true)}
                                        className="mt-4 text-indigo-600 hover:text-indigo-700 font-medium"
                                    >
                                        Enregistrer votre première sortie
                                    </button>
                                )}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Date
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Produit
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Type
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Quantité
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Responsable
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Destination
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Statut
                                            </th>
                                            <th scope="col" className="relative px-6 py-3">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {filteredEntries.map((entry) => (
                                            <tr key={entry.id} className="hover:bg-gray-50 transition-colors">
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {formatDate(entry.date)}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="flex items-center">
                                                        <div className="flex-shrink-0 h-8 w-8 bg-indigo-100 rounded-full flex items-center justify-center">
                                                            <svg className="h-4 w-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />
                                                            </svg>
                                                        </div>
                                                        <div className="ml-3">
                                                            <div className="text-sm font-medium text-gray-900">{entry.product?.name || 'N/A'}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${getEntryTypeColor(entry.entry_type)}`}>
                                                        {getEntryTypeLabel(entry.entry_type)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="text-sm font-medium text-gray-900">{formatNumber(entry.quantity)} {UNIT_TYPE_LABELS[entry.product?.unit_type] || entry.product?.unit_type || ''}</div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {entry.employee?.full_name || 'N/A'}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div className="text-xs">
                                                        {entry.vehicle?.name && <div>{entry.vehicle.name}</div>}
                                                        {entry.maintenance_log && <div className="text-teal-600">🔧 {entry.maintenance_log.description.slice(0, 30)}</div>}
                                                        {entry.operation?.name && <div>{entry.operation.name}</div>}
                                                        {entry.bloc?.name && <div>{entry.bloc.name}</div>}
                                                        {!entry.vehicle && !entry.operation && !entry.bloc && <span>-</span>}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    {entry.is_verified ? (
                                                        <span className="flex items-center text-green-600">
                                                            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                            Vérifié
                                                        </span>
                                                    ) : (
                                                        <span className="flex items-center text-gray-400">
                                                            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                            En attente
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <div className="flex items-center justify-end space-x-2">
                                                        {!entry.is_verified && (
                                                            <button
                                                                onClick={() => handleVerify(entry.id)}
                                                                className="text-gray-400 hover:text-green-600 transition-colors"
                                                                title="Vérifier"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                                </svg>
                                                            </button>
                                                        )}
                                                        <Link
                                                            href={route('stock.manual-entries.show', entry.id)}
                                                            className="text-gray-400 hover:text-indigo-600 transition-colors"
                                                            title="Voir"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                            </svg>
                                                        </Link>
                                                        <Link
                                                            href={route('stock.manual-entries.edit', entry.id)}
                                                            className="text-gray-400 hover:text-blue-600 transition-colors"
                                                            title="Modifier"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                        </Link>
                                                        {!entry.is_verified && (
                                                            <button
                                                                onClick={() => openDeleteModal(entry)}
                                                                className="text-gray-400 hover:text-red-600 transition-colors"
                                                                title="Supprimer"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                                </svg>
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* CREATE STOCK SORTIE MODAL */}
            <Modal show={isCreating} onClose={() => setIsCreating(false)}>
                <div className="p-8">
                    <div className="flex justify-between items-center mb-6">
                        <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">Enregistrer une Sortie de Stock</h3>
                        <button
                            onClick={() => setIsCreating(false)}
                            className="text-gray-400 hover:text-gray-600 transition-colors"
                        >
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">

                            <div>
                                <InputLabel htmlFor="product_id" value="Produit *" />
                                <select
                                    id="product_id"
                                    className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                                    value={data.product_id}
                                    onChange={(e) => {
                                        const nextProduct = products.find((p) => String(p.id) === e.target.value);
                                        const nextIsVehicleConsumable = ['fuel', 'vehicle_needs'].includes(nextProduct?.category);
                                        setData((prev) => ({
                                            ...prev,
                                            product_id: e.target.value,
                                            vehicle_id: nextIsVehicleConsumable ? prev.vehicle_id : '',
                                        }));
                                    }}
                                    required
                                >
                                    <option value="">-- Sélectionner un produit --</option>
                                    {products.map((product) => (
                                        <option key={product.id} value={product.id}>{product.name} ({UNIT_TYPE_LABELS[product.unit_type] || product.unit_type})</option>
                                    ))}
                                </select>
                                <InputError message={errors.product_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="entry_type" value="Type de Sortie *" />
                                <select
                                    id="entry_type"
                                    className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                                    value={data.entry_type}
                                    onChange={(e) => setData((prev) => ({
                                        ...prev,
                                        entry_type: e.target.value,
                                        maintenance_log_id: e.target.value === 'maintenance' ? prev.maintenance_log_id : '',
                                    }))}
                                    required
                                >
                                    <option value="consumption">Consommation</option>
                                    <option value="transfer">Transfert</option>
                                    <option value="loss">Perte</option>
                                    <option value="theft">Vol</option>
                                    <option value="damage">Dommage</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                                <InputError message={errors.entry_type} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="quantity" value="Quantité *" />
                                <TextInput
                                    id="quantity"
                                    type="number"
                                    step="0.01"
                                    className="mt-1 block w-full"
                                    value={data.quantity}
                                    onChange={(e) => setData('quantity', e.target.value)}
                                    required
                                    placeholder="Ex: 25.5"
                                />
                                <InputError message={errors.quantity} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="date" value="Date *" />
                                <TextInput
                                    id="date"
                                    type="date"
                                    className="mt-1 block w-full"
                                    value={data.date}
                                    onChange={(e) => setData('date', e.target.value)}
                                    required
                                />
                                <InputError message={errors.date} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="employee_id" value="Employé" />
                                <select
                                    id="employee_id"
                                    className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                                    value={data.employee_id}
                                    onChange={(e) => setData('employee_id', e.target.value)}
                                >
                                    <option value="">-- Sélectionner un employé --</option>
                                    {employees.map((employee) => (
                                        <option key={employee.id} value={employee.id}>{employee.full_name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.employee_id} className="mt-2" />
                            </div>

                            {isVehicleConsumable && (
                                <div>
                                    <InputLabel htmlFor="vehicle_id" value="Véhicule" />
                                    <select
                                        id="vehicle_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                                        value={data.vehicle_id}
                                        onChange={(e) => setData((prev) => ({ ...prev, vehicle_id: e.target.value, maintenance_log_id: '' }))}
                                    >
                                        <option value="">-- Sélectionner un véhicule --</option>
                                        {vehicles.map((vehicle) => (
                                            <option key={vehicle.id} value={vehicle.id}>{vehicle.name}{(vehicle.plate_number || vehicle.serial_number) ? ` (${vehicle.plate_number || vehicle.serial_number})` : ''}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.vehicle_id} className="mt-2" />
                                </div>
                            )}

                            {isVehicleConsumable && data.vehicle_id && (
                                <div>
                                    <InputLabel htmlFor="odometer_km" value="Kilométrage (km)" />
                                    <TextInput
                                        id="odometer_km"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className="mt-1 block w-full"
                                        value={data.odometer_km}
                                        onChange={(e) => setData('odometer_km', e.target.value)}
                                        placeholder="Ex: 12500.5"
                                    />
                                    <InputError message={errors.odometer_km} className="mt-2" />
                                </div>
                            )}

                            {isVehicleConsumable && data.vehicle_id && data.entry_type === 'maintenance' && (
                                <div className="md:col-span-2">
                                    <InputLabel htmlFor="maintenance_log_id" value="Intervention de Maintenance Liée" />
                                    <select
                                        id="maintenance_log_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                                        value={data.maintenance_log_id}
                                        onChange={(e) => setData('maintenance_log_id', e.target.value)}
                                    >
                                        <option value="">-- Aucune (pièce hors intervention) --</option>
                                        {vehicleLogsFor(data.vehicle_id).map((log) => (
                                            <option key={log.id} value={log.id}>{formatLogOption(log)}</option>
                                        ))}
                                    </select>
                                    <p className="text-xs text-gray-500 mt-1">Rattache le coût de cette pièce à une intervention enregistrée sur la fiche du véhicule.</p>
                                    <InputError message={errors.maintenance_log_id} className="mt-2" />
                                </div>
                            )}

                            {!hidesFieldContext && (
                                <>
                                    <div>
                                        <InputLabel htmlFor="operation_id" value="Opération" />
                                        <select
                                            id="operation_id"
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                                            value={data.operation_id}
                                            onChange={(e) => setData('operation_id', e.target.value)}
                                        >
                                            <option value="">-- Sélectionner une opération --</option>
                                            {operations.map((operation) => (
                                                <option key={operation.id} value={operation.id}>{operation.name}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.operation_id} className="mt-2" />
                                    </div>

                                    <div className="grid grid-cols-3 gap-4 md:col-span-2">
                                        <div>
                                            <InputLabel htmlFor="bloc_id" value="Bloc" />
                                            <select
                                                id="bloc_id"
                                                className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                                                value={data.bloc_id}
                                                onChange={(e) => setData((prev) => ({ ...prev, bloc_id: e.target.value, sector_id: '', parcelle_id: '' }))}
                                            >
                                                <option value="">-- Sélectionner --</option>
                                                {blocs.map((bloc) => (
                                                    <option key={bloc.id} value={bloc.id}>{bloc.name}</option>
                                                ))}
                                            </select>
                                            <InputError message={errors.bloc_id} className="mt-2" />
                                        </div>
                                        <div>
                                            <InputLabel htmlFor="sector_id" value="Secteur" />
                                            <select
                                                id="sector_id"
                                                className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm disabled:bg-gray-100 disabled:text-gray-400"
                                                value={data.sector_id}
                                                onChange={(e) => setData((prev) => ({ ...prev, sector_id: e.target.value, parcelle_id: '' }))}
                                                disabled={!data.bloc_id}
                                            >
                                                <option value="">{data.bloc_id ? '-- Sélectionner --' : '-- Choisir un bloc d\'abord --'}</option>
                                                {filteredSectors.map((sector) => (
                                                    <option key={sector.id} value={sector.id}>{sector.name}</option>
                                                ))}
                                            </select>
                                            <InputError message={errors.sector_id} className="mt-2" />
                                        </div>
                                        <div>
                                            <InputLabel htmlFor="parcelle_id" value="Parcelle" />
                                            <select
                                                id="parcelle_id"
                                                className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm disabled:bg-gray-100 disabled:text-gray-400"
                                                value={data.parcelle_id}
                                                onChange={(e) => setData('parcelle_id', e.target.value)}
                                                disabled={!data.sector_id}
                                            >
                                                <option value="">{data.sector_id ? '-- Sélectionner --' : '-- Choisir un secteur d\'abord --'}</option>
                                                {filteredParcelles.map((parcelle) => (
                                                    <option key={parcelle.id} value={parcelle.id}>{parcelle.name}</option>
                                                ))}
                                            </select>
                                            <InputError message={errors.parcelle_id} className="mt-2" />
                                        </div>
                                    </div>
                                </>
                            )}

                            <div className="md:col-span-2">
                                <InputLabel htmlFor="notes" value="Notes" />
                                <textarea
                                    id="notes"
                                    className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    rows="3"
                                    placeholder="Ajoutez des notes supplémentaires..."
                                ></textarea>
                                <InputError message={errors.notes} className="mt-2" />
                            </div>
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsCreating(false)}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={processing} className="bg-indigo-600 hover:bg-indigo-700">
                                {processing ? 'Enregistrement...' : 'Enregistrer la Sortie'}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>

            {/* DELETE CONFIRMATION MODAL */}
            <Modal show={isDeleteModalOpen} onClose={closeDeleteModal}>
                <div className="p-6">
                    <div className="flex items-center gap-4 mb-4">
                        <div className="h-12 w-12 bg-red-100 rounded-full flex items-center justify-center">
                            <svg className="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div>
                            <h3 className="text-lg font-bold text-gray-900">Confirmer la suppression</h3>
                            <p className="text-sm text-gray-500">Cette action est irréversible</p>
                        </div>
                    </div>
                    {deletingEntry && (
                        <div className="bg-gray-50 rounded-lg p-4 mb-4">
                            <p className="text-sm text-gray-600">
                                <span className="font-medium">Produit:</span> {deletingEntry.product?.name}<br />
                                <span className="font-medium">Quantité:</span> {formatNumber(deletingEntry.quantity)} {UNIT_TYPE_LABELS[deletingEntry.product?.unit_type] || deletingEntry.product?.unit_type}<br />
                                <span className="font-medium">Date:</span> {formatDate(deletingEntry.date)}
                            </p>
                        </div>
                    )}
                    <p className="text-sm text-gray-600 mb-6">
                        Êtes-vous sûr de vouloir supprimer cette sortie de stock ? Cette action annulera également le mouvement de stock correspondant.
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton onClick={closeDeleteModal}>Annuler</SecondaryButton>
                        <DangerButton onClick={handleDelete}>Supprimer</DangerButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
