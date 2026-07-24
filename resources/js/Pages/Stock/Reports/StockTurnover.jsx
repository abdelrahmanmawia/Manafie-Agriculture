import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { formatNumber, formatInt } from '@/utils/number';

export default function StockTurnover({ auth, stockTurnoverData }) {
    const { data, setData, get } = useForm({
        period: '365', // Default to 365 days (1 year)
    });

    const handleFilterChange = (e) => {
        e.preventDefault();
        get(route('stock.reports.stock-turnover', { period: data.period }));
    };

    const averageTurnover = stockTurnoverData.length > 0 
        ? stockTurnoverData.reduce((sum, data) => sum + (data.stock_turnover_ratio || 0), 0) / stockTurnoverData.length 
        : 0;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
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
                            <h2 className="font-bold text-2xl text-gray-800 leading-tight">Rotation des Stocks</h2>
                            <p className="text-sm text-gray-500 mt-1">Mesure de la fréquence de renouvellement des stocks</p>
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Rotation des Stocks" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Filter Card */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <form onSubmit={handleFilterChange} className="flex items-end gap-4">
                            <div className="flex-1">
                                <InputLabel htmlFor="period" value="Période (jours)" />
                                <TextInput
                                    id="period"
                                    type="number"
                                    min="1"
                                    className="mt-1 block w-full"
                                    value={data.period}
                                    onChange={(e) => setData('period', e.target.value)}
                                    placeholder="Ex: 365"
                                />
                            </div>
                            <PrimaryButton type="submit" className="bg-indigo-600 hover:bg-indigo-700">
                                Appliquer le Filtre
                            </PrimaryButton>
                        </form>
                    </div>

                    {/* Stats Card */}
                    <div className="bg-gradient-to-r from-indigo-500 to-indigo-600 rounded-xl shadow-lg p-6">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <div className="h-16 w-16 bg-white/20 rounded-xl flex items-center justify-center">
                                    <svg className="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                </div>
                                <div>
                                    <p className="text-indigo-100 text-sm font-medium">Rotation Moyenne</p>
                                    <p className="text-white text-3xl font-bold mt-1">{formatNumber(averageTurnover)}x</p>
                                </div>
                            </div>
                            <div className="text-right">
                                <p className="text-indigo-100 text-sm">Nombre de Produits</p>
                                <p className="text-white text-xl font-semibold">{formatInt(stockTurnoverData.length)}</p>
                            </div>
                        </div>
                    </div>

                    {/* Stock Turnover Table */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h3 className="text-lg font-bold text-gray-800">Analyse de la Rotation des Stocks</h3>
                        </div>
                        <div className="p-6">
                            {stockTurnoverData.length === 0 ? (
                                <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    <p className="mt-4 text-gray-500">Aucune donnée de rotation des stocks disponible pour la période sélectionnée.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Produit
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Inventaire Début
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Achats
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Consommation/Ventes
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Inventaire Fin
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Rotation
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Jours d'Inventaire
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {stockTurnoverData.map((data, index) => (
                                                <tr key={index} className="hover:bg-gray-50 transition-colors">
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        {data.product_name}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        {formatNumber(data.beginning_inventory)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        <span className="inline-flex items-center px-2 py-1 rounded-md bg-green-50 text-green-700 text-xs font-medium">
                                                            +{formatNumber(data.purchases)}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        <span className="inline-flex items-center px-2 py-1 rounded-md bg-red-50 text-red-700 text-xs font-medium">
                                                            -{formatNumber(data.sales_consumption)}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        {formatNumber(data.ending_inventory)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-semibold">
                                                        <span className={`inline-flex items-center px-2 py-1 rounded-md ${
                                                            data.stock_turnover_ratio > averageTurnover
                                                                ? 'bg-green-50 text-green-700'
                                                                : 'bg-red-50 text-red-700'
                                                        } text-xs font-medium`}>
                                                            {formatNumber(data.stock_turnover_ratio)}x
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        {formatInt(data.days_inventory_outstanding)}
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
            </div>
        </AuthenticatedLayout>
    );
}
