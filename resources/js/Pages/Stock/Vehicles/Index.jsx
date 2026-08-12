import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import ToggleSwitch from '@/Components/ToggleSwitch';
import { formatInt } from '@/utils/number';
import {
    VEHICLE_TYPE_LABELS as TYPE_LABELS,
    FUEL_TYPE_LABELS,
    ASSET_TYPE_LABELS,
    ASSET_STATUS_LABELS,
    EQUIPMENT_TYPE_LABELS,
} from '@/utils/stockLabels';

const TYPE_ICON = {
    tractor: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0zM5 17H3v-4l1-5h9l4 4h2a1 1 0 011 1v4h-2m-4 0H9m0 0H5" />
    ),
    truck: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 7h10v9H3V7zm10 3h4l3 3v3h-7v-6z M6 19a1.5 1.5 0 100-3 1.5 1.5 0 000 3zm11 0a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" />
    ),
    van: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 13l1-5h13l3 5v4H3v-4zm4 4a1.5 1.5 0 100-3 1.5 1.5 0 000 3zm11 0a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" />
    ),
    car: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l1.5-4.5A2 2 0 018.4 7h7.2a2 2 0 011.9 1.5L19 13m-14 0h14m-14 0v4a1 1 0 001 1h1a1 1 0 001-1v-1h8v1a1 1 0 001 1h1a1 1 0 001-1v-4" />
    ),
    other: (
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
    ),
};

// Equipment (pumps, generators, sprayers...) shares one wrench icon instead of a bespoke
// icon per kind — the vehicle types above are the only ones common enough to earn their own.
const EQUIPMENT_ICON = (
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z" />
);

const getIcon = (vehicle) => (vehicle.asset_type === 'equipment' ? EQUIPMENT_ICON : (TYPE_ICON[vehicle.type] || TYPE_ICON.other));

export default function Index({ auth, vehicles, types, equipmentTypes, fuelTypes, employees }) {
    const [isCreating, setIsCreating] = useState(false);
    const [editingVehicle, setEditingVehicle] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [assetTypeFilter, setAssetTypeFilter] = useState('');
    const [selectedType, setSelectedType] = useState('');

    const { data, setData, post, put, processing, reset, errors, clearErrors } = useForm({
        asset_type: 'vehicle',
        name: '',
        plate_number: '',
        serial_number: '',
        type: types.length > 0 ? types[0] : '',
        model: '',
        fuel_type: fuelTypes.length > 0 ? fuelTypes[0] : '',
        capacity_liters: '',
        status: 'operational',
        quantity: 1,
        default_driver_id: '',
        is_active: true,
        is_location: false,
        default_daily_rate: '',
        purchase_date: '',
        notes: '',
    });

    const typeOptionsFor = (assetType) => (assetType === 'equipment' ? equipmentTypes : types);
    const typeLabelsFor = (assetType) => (assetType === 'equipment' ? EQUIPMENT_TYPE_LABELS : TYPE_LABELS);

    const handleAssetTypeChange = (assetType) => {
        const options = typeOptionsFor(assetType);
        setData((prev) => ({
            ...prev,
            asset_type: assetType,
            type: options.length > 0 ? options[0] : '',
            plate_number: assetType === 'equipment' ? '' : prev.plate_number,
        }));
    };

    const openCreate = () => {
        setEditingVehicle(null);
        clearErrors();
        reset();
        setIsCreating(true);
    };

    const openEdit = (vehicle) => {
        setEditingVehicle(vehicle);
        clearErrors();
        setData({
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
            purchase_date: vehicle.purchase_date ? String(vehicle.purchase_date).slice(0, 10) : '',
            notes: vehicle.notes || '',
        });
        setIsCreating(true);
    };

    const closeVehicleModal = () => {
        setIsCreating(false);
        setEditingVehicle(null);
    };

    const submit = (e) => {
        e.preventDefault();
        if (editingVehicle) {
            put(route('stock.vehicles.update', editingVehicle.id), {
                onSuccess: () => closeVehicleModal(),
            });
        } else {
            post(route('stock.vehicles.store'), {
                onSuccess: () => closeVehicleModal(),
            });
        }
    };

    const handleToggleActive = (vehicle) => {
        router.post(route('stock.vehicles.toggle-active', vehicle.id), {}, { preserveScroll: true });
    };

    const filteredVehicles = vehicles.filter((vehicle) => {
        const term = searchTerm.toLowerCase();
        const matchesSearch = vehicle.name.toLowerCase().includes(term) ||
                             (vehicle.plate_number || '').toLowerCase().includes(term) ||
                             (vehicle.serial_number || '').toLowerCase().includes(term);
        const matchesAssetType = !assetTypeFilter || vehicle.asset_type === assetTypeFilter;
        const matchesType = !selectedType || vehicle.type === selectedType;
        return matchesSearch && matchesAssetType && matchesType;
    });

    const activeCount = vehicles.filter((v) => v.is_active).length;
    const equipmentCount = vehicles.filter((v) => v.asset_type === 'equipment').length;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div>
                        <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Véhicules & Matériel</h2>
                        <p className="text-sm text-gray-500 mt-1">Parc de véhicules et équipement agricole de la ferme</p>
                    </div>
                    {auth.user.role !== 'data_entry' && (
                        <button
                            onClick={openCreate}
                            className="bg-gray-700 hover:bg-gray-800 text-white px-6 py-3 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                        >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Ajouter un Actif
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Véhicules & Matériel" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Total Actifs</p>
                                    <p className="text-2xl font-bold text-gray-900 mt-1">{formatInt(vehicles.length)}</p>
                                </div>
                                <div className="h-12 w-12 bg-gray-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">{TYPE_ICON.other}</svg>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Actifs</p>
                                    <p className="text-2xl font-bold text-green-600 mt-1">{formatInt(activeCount)}</p>
                                </div>
                                <div className="h-12 w-12 bg-green-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Équipement</p>
                                    <p className="text-2xl font-bold text-gray-900 mt-1">{formatInt(equipmentCount)}</p>
                                </div>
                                <div className="h-12 w-12 bg-amber-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">{EQUIPMENT_ICON}</svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        placeholder="Rechercher par nom, plaque ou n° de série..."
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                                    />
                                    <svg className="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Type d'Actif</label>
                                <select
                                    value={assetTypeFilter}
                                    onChange={(e) => { setAssetTypeFilter(e.target.value); setSelectedType(''); }}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                                >
                                    <option value="">Tous</option>
                                    <option value="vehicle">{ASSET_TYPE_LABELS.vehicle}</option>
                                    <option value="equipment">{ASSET_TYPE_LABELS.equipment}</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Type</label>
                                <select
                                    value={selectedType}
                                    onChange={(e) => setSelectedType(e.target.value)}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                                >
                                    <option value="">Tous les types</option>
                                    {(assetTypeFilter === 'equipment' ? equipmentTypes : assetTypeFilter === 'vehicle' ? types : [...new Set([...types, ...equipmentTypes])]).map((type) => (
                                        <option key={type} value={type}>{TYPE_LABELS[type] || EQUIPMENT_TYPE_LABELS[type] || type}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Vehicles Table */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <div className="flex justify-between items-center">
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Liste des Actifs</h3>
                                <span className="text-sm text-gray-500">{filteredVehicles.length} élément(s)</span>
                            </div>
                        </div>

                        {filteredVehicles.length === 0 ? (
                            <div className="text-center py-16">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">{TYPE_ICON.other}</svg>
                                <p className="mt-4 text-gray-500">Aucun élément trouvé</p>
                                {auth.user.role !== 'data_entry' && (
                                    <button onClick={openCreate} className="mt-4 text-gray-700 hover:text-gray-900 font-medium">
                                        Ajouter votre premier véhicule ou équipement
                                    </button>
                                )}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Véhicule</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type d'Actif</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Carburant</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Conducteur</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">État</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Actif</th>
                                            <th scope="col" className="relative px-6 py-3"><span className="sr-only">Actions</span></th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {filteredVehicles.map((vehicle) => {
                                            const statusMeta = ASSET_STATUS_LABELS[vehicle.status] || ASSET_STATUS_LABELS.operational;
                                            return (
                                            <tr key={vehicle.id} className={`hover:bg-gray-50 transition-colors ${!vehicle.is_active ? 'opacity-60 bg-gray-50' : ''}`}>
                                                <td className="px-6 py-4">
                                                    <div className="flex items-center">
                                                        <div className="flex-shrink-0 h-10 w-10 bg-gray-100 rounded-lg flex items-center justify-center">
                                                            <svg className="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                {getIcon(vehicle)}
                                                            </svg>
                                                        </div>
                                                        <div className="ml-4">
                                                            <div className="text-sm font-medium text-gray-900">
                                                                {vehicle.name}
                                                                {vehicle.quantity > 1 && (
                                                                    <span className="ml-1.5 text-xs font-semibold text-gray-500">×{vehicle.quantity}</span>
                                                                )}
                                                            </div>
                                                            <div className="text-xs text-gray-500">{vehicle.plate_number || vehicle.serial_number || '—'}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${vehicle.asset_type === 'equipment' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'}`}>
                                                        {ASSET_TYPE_LABELS[vehicle.asset_type] || vehicle.asset_type}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                    {typeLabelsFor(vehicle.asset_type)[vehicle.type] || vehicle.type}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                    {vehicle.fuel_type ? (FUEL_TYPE_LABELS[vehicle.fuel_type] || vehicle.fuel_type) : '—'}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {vehicle.default_driver?.full_name || 'N/A'}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${statusMeta.className}`}>
                                                        {statusMeta.label}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <ToggleSwitch
                                                        checked={vehicle.is_active}
                                                        onChange={() => handleToggleActive(vehicle)}
                                                        disabled={auth.user.role === 'data_entry'}
                                                    />
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <div className="flex items-center justify-end space-x-2">
                                                        <Link
                                                            href={route('stock.vehicles.show', vehicle.id)}
                                                            className="text-gray-400 hover:text-gray-700 transition-colors"
                                                            title="Voir"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                            </svg>
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() => openEdit(vehicle)}
                                                            className="text-gray-400 hover:text-blue-600 transition-colors"
                                                            title="Modifier"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* CREATE / EDIT VEHICLE MODAL */}
            <Modal show={isCreating} onClose={closeVehicleModal}>
                <div className="p-8">
                    <div className="flex justify-between items-center mb-6">
                        <div className="flex items-center gap-3">
                            <div className="h-10 w-10 bg-gray-100 rounded-xl flex items-center justify-center">
                                <svg className="h-6 w-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                            </div>
                            <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">{editingVehicle ? "Modifier l'Actif" : 'Ajouter un Nouvel Actif'}</h3>
                        </div>
                        <button onClick={closeVehicleModal} className="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid grid-cols-2 gap-3">
                            {['vehicle', 'equipment'].map((assetType) => (
                                <button
                                    key={assetType}
                                    type="button"
                                    onClick={() => handleAssetTypeChange(assetType)}
                                    className={`px-4 py-3 rounded-xl border-2 font-bold text-sm uppercase tracking-wide transition-colors ${
                                        data.asset_type === assetType
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
                                <InputLabel htmlFor="name" value="Nom *" />
                                <TextInput
                                    id="name"
                                    type="text"
                                    className="mt-1 block w-full"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.name} className="mt-2" />
                            </div>

                            {data.asset_type === 'vehicle' ? (
                                <div>
                                    <InputLabel htmlFor="plate_number" value="Plaque d'Immatriculation *" />
                                    <TextInput
                                        id="plate_number"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={data.plate_number}
                                        onChange={(e) => setData('plate_number', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.plate_number} className="mt-2" />
                                </div>
                            ) : (
                                <div>
                                    <InputLabel htmlFor="serial_number" value="N° de Série" />
                                    <TextInput
                                        id="serial_number"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={data.serial_number}
                                        onChange={(e) => setData('serial_number', e.target.value)}
                                        placeholder="Optionnel"
                                    />
                                    <InputError message={errors.serial_number} className="mt-2" />
                                </div>
                            )}

                            <div>
                                <InputLabel htmlFor="type" value="Type *" />
                                <select
                                    id="type"
                                    className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                    value={data.type}
                                    onChange={(e) => setData('type', e.target.value)}
                                    required
                                >
                                    {typeOptionsFor(data.asset_type).map((type) => (
                                        <option key={type} value={type}>{typeLabelsFor(data.asset_type)[type] || type}</option>
                                    ))}
                                </select>
                                <InputError message={errors.type} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="status" value="État" />
                                <select
                                    id="status"
                                    className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                >
                                    {Object.entries(ASSET_STATUS_LABELS).map(([value, meta]) => (
                                        <option key={value} value={value}>{meta.label}</option>
                                    ))}
                                </select>
                                <InputError message={errors.status} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="quantity" value="Quantité" />
                                <TextInput
                                    id="quantity"
                                    type="number"
                                    min="1"
                                    step="1"
                                    className="mt-1 block w-full"
                                    value={data.quantity}
                                    onChange={(e) => setData('quantity', e.target.value)}
                                />
                                <p className="text-xs text-gray-500 mt-1">Pour un lot identique non suivi individuellement (ex : 2 broyeurs).</p>
                                <InputError message={errors.quantity} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="fuel_type" value="Type de Carburant" />
                                <select
                                    id="fuel_type"
                                    className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                    value={data.fuel_type}
                                    onChange={(e) => setData('fuel_type', e.target.value)}
                                >
                                    {fuelTypes.map((fuel) => (
                                        <option key={fuel} value={fuel}>{FUEL_TYPE_LABELS[fuel] || fuel}</option>
                                    ))}
                                </select>
                                <InputError message={errors.fuel_type} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="capacity_liters" value="Capacité du Réservoir (L)" />
                                <TextInput
                                    id="capacity_liters"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    className="mt-1 block w-full"
                                    value={data.capacity_liters}
                                    onChange={(e) => setData('capacity_liters', e.target.value)}
                                    placeholder="Ex: 2000"
                                />
                                <InputError message={errors.capacity_liters} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="model" value="Modèle" />
                                <TextInput
                                    id="model"
                                    type="text"
                                    className="mt-1 block w-full"
                                    value={data.model}
                                    onChange={(e) => setData('model', e.target.value)}
                                />
                                <InputError message={errors.model} className="mt-2" />
                            </div>

                            {data.asset_type === 'vehicle' && (
                                <div>
                                    <InputLabel htmlFor="default_driver_id" value="Conducteur par Défaut" />
                                    <select
                                        id="default_driver_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                        value={data.default_driver_id}
                                        onChange={(e) => setData('default_driver_id', e.target.value)}
                                    >
                                        <option value="">-- Sélectionner un conducteur --</option>
                                        {employees.map((employee) => (
                                            <option key={employee.id} value={employee.id}>{employee.full_name}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.default_driver_id} className="mt-2" />
                                </div>
                            )}

                            <div>
                                <InputLabel htmlFor="purchase_date" value="Date d'Achat" />
                                <TextInput
                                    id="purchase_date"
                                    type="date"
                                    className="mt-1 block w-full"
                                    value={data.purchase_date}
                                    onChange={(e) => setData('purchase_date', e.target.value)}
                                />
                                <InputError message={errors.purchase_date} className="mt-2" />
                            </div>

                            <div className="md:col-span-2">
                                <InputLabel htmlFor="notes" value="Notes" />
                                <textarea
                                    id="notes"
                                    className="mt-1 block w-full border-gray-300 focus:border-gray-500 focus:ring-gray-500 rounded-lg shadow-sm"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    rows="3"
                                ></textarea>
                                <InputError message={errors.notes} className="mt-2" />
                            </div>
                        </div>

                        {editingVehicle && (
                            <div className="flex items-center justify-between bg-gray-50 rounded-xl p-4">
                                <div>
                                    <InputLabel htmlFor="is_active" value="Actif" className="mb-0" />
                                    <p className="text-xs text-gray-500">Les éléments inactifs ne sont plus proposés dans les sélections</p>
                                </div>
                                <ToggleSwitch
                                    checked={data.is_active}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                />
                            </div>
                        )}

                        {data.asset_type === 'vehicle' && (
                        <div className="bg-purple-50 rounded-xl p-4 space-y-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <InputLabel htmlFor="is_location" value="Disponible en Location" className="mb-0" />
                                    <p className="text-xs text-gray-500">Seuls les véhicules marqués ici apparaissent dans la grille "Location"</p>
                                </div>
                                <ToggleSwitch
                                    checked={data.is_location}
                                    onChange={(e) => setData('is_location', e.target.checked)}
                                />
                            </div>
                            {data.is_location && (
                                <div>
                                    <InputLabel htmlFor="default_daily_rate" value="Tarif Journalier par Défaut (DH)" />
                                    <TextInput
                                        id="default_daily_rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className="mt-1 block w-full"
                                        value={data.default_daily_rate}
                                        onChange={(e) => setData('default_daily_rate', e.target.value)}
                                    />
                                    <p className="text-xs text-gray-500 mt-1">Utilisé automatiquement dans la grille Location — modifiable ici à tout moment.</p>
                                    <InputError message={errors.default_daily_rate} className="mt-2" />
                                </div>
                            )}
                        </div>
                        )}

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={closeVehicleModal}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={processing} className="bg-gray-700 hover:bg-gray-800">
                                {processing
                                    ? (editingVehicle ? 'Mise à jour...' : 'Ajout en cours...')
                                    : (editingVehicle ? "Mettre à Jour l'Actif" : "Ajouter l'Actif")}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
