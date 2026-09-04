import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { formatNumber, formatInt, formatMAD } from '@/utils/number';
import {
    ASSET_TYPE_LABELS,
    VEHICLE_TYPE_LABELS,
    EQUIPMENT_TYPE_LABELS,
} from '@/utils/stockLabels';

export default function CostPerVehicle({ auth, costPerVehicleData }) {
    const [searchTerm, setSearchTerm] = useState('');
    const [assetTypeFilter, setAssetTypeFilter] = useState('');
    const [selectedType, setSelectedType] = useState('');
    const [hideZeroCost, setHideZeroCost] = useState(false);

    const typeLabel = (row) => {
        const labels = row.asset_type === 'equipment' ? EQUIPMENT_TYPE_LABELS : VEHICLE_TYPE_LABELS;
        return labels[row.type] || row.type;
    };

    // Options derive from the data itself rather than a separate props list — only types
    // actually present in this farm's fleet show up, and no controller change is needed.
    const typeOptions = [...new Set(
        costPerVehicleData
            .filter((row) => !assetTypeFilter || row.asset_type === assetTypeFilter)
            .map((row) => row.type)
    )].sort();

    const filteredData = costPerVehicleData.filter((row) => {
        const term = searchTerm.toLowerCase();
        const matchesSearch = row.vehicle_name.toLowerCase().includes(term) ||
            (row.plate_number || '').toLowerCase().includes(term) ||
            (row.serial_number || '').toLowerCase().includes(term);
        const matchesAssetType = !assetTypeFilter || row.asset_type === assetTypeFilter;
        const matchesType = !selectedType || row.type === selectedType;
        const matchesZeroCost = !hideZeroCost || row.total_cost > 0;
        return matchesSearch && matchesAssetType && matchesType && matchesZeroCost;
    });

    const totalFleetCost = filteredData.reduce((sum, row) => sum + row.total_cost, 0);
    const totalFuelCost = filteredData.reduce((sum, row) => sum + row.fuel_cost, 0);
    const totalPartsCost = filteredData.reduce((sum, row) => sum + row.parts_cost, 0);
    const totalMaintenanceCost = filteredData.reduce((sum, row) => sum + row.maintenance_cost, 0);
    const topSpender = filteredData.find((row) => row.total_cost > 0);
    const untracked = filteredData.filter((row) => row.total_cost === 0).length;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center gap-4">
                    <Link
                        href={route('stock.reports.index')}
                        className="text-gray-500 hover:text-gray-700 transition-colors"
                    >
                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                    </Link>
                    <div>
                        <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Coût par Véhicule</h2>
                        <p className="text-sm text-gray-500 mt-1">Carburant, pièces sorties du stock et maintenance cumulés par véhicule et matériel</p>
                    </div>
                </div>
            }
        >
            <Head title="Coût par Véhicule" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Stats Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div className="bg-gradient-to-r from-teal-500 to-teal-600 rounded-xl shadow-lg p-6">
                            <div className="flex items-center gap-4">
                                <div className="h-12 w-12 bg-white/20 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <p className="text-teal-100 text-sm font-medium">Coût Total du Parc</p>
                                    <p className="text-white text-2xl font-bold mt-1">{formatMAD(totalFleetCost)}</p>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Carburant</p>
                            <p className="text-2xl font-bold text-orange-600 mt-1">{formatMAD(totalFuelCost)}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Pièces</p>
                            <p className="text-2xl font-bold text-indigo-600 mt-1">{formatMAD(totalPartsCost)}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Main d'œuvre</p>
                            <p className="text-2xl font-bold text-gray-800 mt-1">{formatMAD(totalMaintenanceCost)}</p>
                        </div>
                    </div>

                    {topSpender && (
                        <div className="flex items-center gap-3 bg-teal-50 border border-teal-200 rounded-xl px-5 py-4">
                            <svg className="w-5 h-5 text-teal-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            </svg>
                            <p className="text-sm font-medium text-teal-900">
                                Le plus coûteux : <strong>{topSpender.vehicle_name}</strong> ({formatMAD(topSpender.total_cost)})
                            </p>
                            {untracked > 0 && (
                                <p className="text-sm text-teal-700 ml-auto">{untracked} actif(s) sans aucun coût enregistré</p>
                            )}
                        </div>
                    )}

                    {/* Filters */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        placeholder="Nom, plaque ou n° de série..."
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
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
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
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
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                                >
                                    <option value="">Tous les types</option>
                                    {typeOptions.map((type) => (
                                        <option key={type} value={type}>{VEHICLE_TYPE_LABELS[type] || EQUIPMENT_TYPE_LABELS[type] || type}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex items-end pb-2">
                                <label className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={hideZeroCost}
                                        onChange={(e) => setHideZeroCost(e.target.checked)}
                                        className="rounded border-gray-300 text-teal-600 focus:ring-teal-500"
                                    />
                                    Masquer les actifs sans coût
                                </label>
                            </div>
                        </div>
                    </div>

                    {/* Cost per Vehicle Table */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <div className="flex justify-between items-center">
                                <div>
                                    <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Détail par Véhicule / Matériel</h3>
                                    <p className="text-sm text-gray-500 mt-1">Trié du coût total le plus élevé au plus faible.</p>
                                </div>
                                <span className="text-sm text-gray-500">{filteredData.length} élément(s)</span>
                            </div>
                        </div>

                        {filteredData.length === 0 ? (
                            <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200 m-6">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l1.5-4.5A2 2 0 018.4 7h7.2a2 2 0 011.9 1.5L19 13m-14 0h14m-14 0v4a1 1 0 001 1h1a1 1 0 001-1v-1h8v1a1 1 0 001 1h1a1 1 0 001-1v-4" />
                                </svg>
                                <p className="mt-4 text-gray-500">
                                    {costPerVehicleData.length === 0 ? 'Aucun véhicule ou matériel enregistré.' : 'Aucun résultat pour ces filtres.'}
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Véhicule / Matériel</th>
                                            <th className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                                            <th className="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Carburant</th>
                                            <th className="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Pièces</th>
                                            <th className="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Maintenance</th>
                                            <th className="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Coût Total</th>
                                            <th className="relative px-6 py-3"><span className="sr-only">Actions</span></th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {filteredData.map((row) => (
                                            <tr key={row.vehicle_id} className={`hover:bg-gray-50 transition-colors ${!row.is_active ? 'opacity-60' : ''}`}>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="text-sm font-medium text-gray-900">{row.vehicle_name}</div>
                                                    <div className="text-xs text-gray-500">{row.plate_number || row.serial_number || '—'}</div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${row.asset_type === 'equipment' ? 'bg-purple-100 text-purple-800' : 'bg-primary-100 text-primary-800'}`}>
                                                        {typeLabel(row)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600">
                                                    {row.fuel_cost > 0 ? (
                                                        <>
                                                            {formatMAD(row.fuel_cost)}
                                                            <div className="text-xs text-gray-400">{formatNumber(row.fuel_liters, 0)} L</div>
                                                        </>
                                                    ) : <span className="text-gray-300">—</span>}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600">
                                                    {row.parts_cost > 0 ? (
                                                        <>
                                                            {formatMAD(row.parts_cost)}
                                                            <div className="text-xs text-gray-400">{formatInt(row.parts_count)} sortie(s)</div>
                                                        </>
                                                    ) : <span className="text-gray-300">—</span>}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-600">
                                                    {row.maintenance_cost > 0 ? (
                                                        <>
                                                            {formatMAD(row.maintenance_cost)}
                                                            <div className="text-xs text-gray-400">{formatInt(row.maintenance_count)} intervention(s)</div>
                                                        </>
                                                    ) : <span className="text-gray-300">—</span>}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                                                    {formatMAD(row.total_cost)}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <Link
                                                        href={route('stock.vehicles.show', row.vehicle_id)}
                                                        className="text-gray-400 hover:text-teal-600 transition-colors"
                                                        title="Voir le véhicule"
                                                    >
                                                        <svg className="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                        </svg>
                                                    </Link>
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
        </AuthenticatedLayout>
    );
}
