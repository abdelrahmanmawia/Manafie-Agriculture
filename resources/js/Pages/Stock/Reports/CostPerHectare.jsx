import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { formatNumber, formatInt, formatMAD } from '@/utils/number';

function CostPerHaCell({ cost, area, highlight, baseline }) {
    if (!(area > 0)) {
        return <span className="text-xs text-gray-400">N/A</span>;
    }
    const perHa = cost / area;
    const className = highlight
        ? (perHa > baseline ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700')
        : 'bg-gray-100 text-gray-700';
    return (
        <span className={`inline-flex items-center px-2 py-1 rounded-md ${className} text-xs font-medium`}>
            {formatNumber(perHa)} MAD/Ha
        </span>
    );
}

export default function CostPerHectare({ auth, costPerHectareData }) {
    const [expandedBlocs, setExpandedBlocs] = useState({});
    const [expandedSectors, setExpandedSectors] = useState({});

    const toggleBloc = (blocName) => setExpandedBlocs((prev) => ({ ...prev, [blocName]: !prev[blocName] }));
    const toggleSector = (key) => setExpandedSectors((prev) => ({ ...prev, [key]: !prev[key] }));

    const averageCostPerHectare = costPerHectareData.length > 0
        ? costPerHectareData.reduce((sum, data) => sum + data.cost_per_hectare, 0) / costPerHectareData.length
        : 0;
    const totalArea = costPerHectareData.reduce((sum, data) => sum + data.total_area_hectares, 0);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
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
                            <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Coût par Hectare</h2>
                            <p className="text-sm text-gray-500 mt-1">Calcul du coût des intrants par hectare, par bloc puis détaillé par secteur et parcelle</p>
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Coût par Hectare" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Stats Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-gradient-to-r from-orange-500 to-orange-600 rounded-xl shadow-lg p-6">
                            <div className="flex items-center gap-4">
                                <div className="h-12 w-12 bg-white/20 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                    </svg>
                                </div>
                                <div>
                                    <p className="text-orange-100 text-sm font-medium">Moyenne Coût/Ha</p>
                                    <p className="text-white text-2xl font-bold mt-1">{formatMAD(averageCostPerHectare)}</p>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Superficie Totale</p>
                                    <p className="text-2xl font-bold text-gray-900 mt-1">{formatNumber(totalArea)} Ha</p>
                                </div>
                                <div className="h-12 w-12 bg-green-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Nombre de Blocs</p>
                                    <p className="text-2xl font-bold text-gray-900 mt-1">{formatInt(costPerHectareData.length)}</p>
                                </div>
                                <div className="h-12 w-12 bg-blue-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Cost per Hectare — Bloc > Secteur > Parcelle */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Analyse du Coût par Hectare</h3>
                            <p className="text-sm text-gray-500 mt-1">Cliquez sur un bloc pour voir le détail par secteur, puis sur un secteur pour voir le détail par parcelle.</p>
                        </div>

                        {costPerHectareData.length === 0 ? (
                            <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200 m-6">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                </svg>
                                <p className="mt-4 text-gray-500">Aucune donnée de coût par hectare disponible.</p>
                            </div>
                        ) : (
                            <div className="divide-y divide-gray-100">
                                {costPerHectareData.map((bloc) => {
                                    const isBlocOpen = !!expandedBlocs[bloc.bloc_key];
                                    return (
                                        <div key={bloc.bloc_key}>
                                            <button
                                                type="button"
                                                onClick={() => toggleBloc(bloc.bloc_key)}
                                                className="w-full flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition-colors text-left"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <svg className={`w-4 h-4 text-gray-400 transition-transform ${isBlocOpen ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                                                    </svg>
                                                    <span className="font-semibold text-gray-900">Bloc {bloc.bloc_name}</span>
                                                    <span className="text-xs text-gray-500">{formatNumber(bloc.total_area_hectares)} Ha</span>
                                                </div>
                                                <div className="flex items-center gap-4">
                                                    <span className="text-sm text-gray-600">{formatMAD(bloc.total_cost)}</span>
                                                    <CostPerHaCell cost={bloc.total_cost} area={bloc.total_area_hectares} highlight baseline={averageCostPerHectare} />
                                                </div>
                                            </button>

                                            {isBlocOpen && (
                                                <div className="bg-gray-50 px-6 pb-4">
                                                    <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                                        <table className="min-w-full divide-y divide-gray-200">
                                                            <thead className="bg-gray-50">
                                                                <tr>
                                                                    <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Secteur</th>
                                                                    <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Coût</th>
                                                                    <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Superficie</th>
                                                                    <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Coût/Ha</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody className="divide-y divide-gray-100">
                                                                {bloc.sectors.map((sector) => {
                                                                    const sectorKey = `${bloc.bloc_key}::${sector.sector_key}`;
                                                                    const isSectorOpen = !!expandedSectors[sectorKey];
                                                                    return (
                                                                        <Fragment key={sectorKey}>
                                                                            <tr
                                                                                className="hover:bg-gray-50 transition-colors cursor-pointer"
                                                                                onClick={() => toggleSector(sectorKey)}
                                                                            >
                                                                                <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-800">
                                                                                    <div className="flex items-center gap-2">
                                                                                        <svg className={`w-3.5 h-3.5 text-gray-400 transition-transform ${isSectorOpen ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                                                                                        </svg>
                                                                                        Secteur {sector.sector_name}
                                                                                    </div>
                                                                                </td>
                                                                                <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-600">{formatMAD(sector.total_cost)}</td>
                                                                                <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-600">
                                                                                    {sector.total_area_hectares > 0 ? `${formatNumber(sector.total_area_hectares)} Ha` : 'N/A'}
                                                                                </td>
                                                                                <td className="px-4 py-2 whitespace-nowrap">
                                                                                    <CostPerHaCell cost={sector.total_cost} area={sector.total_area_hectares} />
                                                                                </td>
                                                                            </tr>
                                                                            {isSectorOpen && (
                                                                                <tr key={`${sectorKey}-detail`}>
                                                                                    <td colSpan={4} className="px-4 pb-3 pt-0 bg-gray-50">
                                                                                        <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white ml-6">
                                                                                            <table className="min-w-full divide-y divide-gray-200">
                                                                                                <thead className="bg-gray-50">
                                                                                                    <tr>
                                                                                                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Parcelle</th>
                                                                                                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Coût</th>
                                                                                                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Superficie</th>
                                                                                                        <th className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Coût/Ha</th>
                                                                                                    </tr>
                                                                                                </thead>
                                                                                                <tbody className="divide-y divide-gray-100">
                                                                                                    {sector.parcelles.map((parcelle) => (
                                                                                                        <tr key={`${sectorKey}::${parcelle.parcelle_key}`} className="hover:bg-gray-50 transition-colors">
                                                                                                            <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-700">Parcelle {parcelle.parcelle_name}</td>
                                                                                                            <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-600">{formatMAD(parcelle.total_cost)}</td>
                                                                                                            <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-600">
                                                                                                                {parcelle.total_area_hectares > 0 ? `${formatNumber(parcelle.total_area_hectares)} Ha` : 'N/A'}
                                                                                                            </td>
                                                                                                            <td className="px-4 py-2 whitespace-nowrap">
                                                                                                                <CostPerHaCell cost={parcelle.total_cost} area={parcelle.total_area_hectares} />
                                                                                                            </td>
                                                                                                        </tr>
                                                                                                    ))}
                                                                                                </tbody>
                                                                                            </table>
                                                                                        </div>
                                                                                    </td>
                                                                                </tr>
                                                                            )}
                                                                        </Fragment>
                                                                    );
                                                                })}
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
