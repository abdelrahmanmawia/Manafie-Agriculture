import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import FarmFilter from '@/Components/FarmFilter';

export default function ConsumptionByOperation({ auth, consumptionByOperation, farms, selectedFarmId }) {
    const totalConsumption = consumptionByOperation.reduce((sum, op) => sum + op.total_quantity_consumed, 0);

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
                            <h2 className="font-bold text-2xl text-gray-800 leading-tight">Consommation par Opération</h2>
                            <p className="text-sm text-gray-500 mt-1">Analyse des produits consommés par type d'opération</p>
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Consommation par Opération" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <FarmFilter farms={farms} selectedFarmId={selectedFarmId} routeName="stock.reports.consumption-by-operation" />

                    {/* Stats Card */}
                    <div className="bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl shadow-lg p-6">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <div className="h-16 w-16 bg-white/20 rounded-xl flex items-center justify-center">
                                    <svg className="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                </div>
                                <div>
                                    <p className="text-purple-100 text-sm font-medium">Consommation Totale</p>
                                    <p className="text-white text-3xl font-bold mt-1">{totalConsumption.toFixed(2)} unités</p>
                                </div>
                            </div>
                            <div className="text-right">
                                <p className="text-purple-100 text-sm">Nombre d'Opérations</p>
                                <p className="text-white text-xl font-semibold">{consumptionByOperation.length}</p>
                            </div>
                        </div>
                    </div>

                    {/* Consumption by Operation */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h3 className="text-lg font-bold text-gray-800">Analyse de la Consommation par Opération</h3>
                        </div>
                        <div className="p-6">
                            {consumptionByOperation.length === 0 ? (
                                <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                    <p className="mt-4 text-gray-500">Aucune donnée de consommation par opération disponible.</p>
                                </div>
                            ) : (
                                <div className="space-y-6">
                                    {consumptionByOperation.map((operationData, index) => (
                                        <div key={index} className="border border-gray-200 rounded-xl overflow-hidden">
                                            <div className="bg-gray-50 p-4 border-b border-gray-200">
                                                <div className="flex items-center justify-between">
                                                    <h4 className="text-lg font-bold text-gray-800">{operationData.operation_name}</h4>
                                                    <span className="inline-flex items-center px-3 py-1 rounded-full bg-purple-100 text-purple-800 text-sm font-semibold">
                                                        {operationData.total_quantity_consumed.toFixed(2)} unités
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="p-4">
                                                <h5 className="font-semibold text-gray-700 mb-3">Détail par Produit:</h5>
                                                <div className="overflow-x-auto">
                                                    <table className="min-w-full divide-y divide-gray-200">
                                                        <thead className="bg-gray-50">
                                                            <tr>
                                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                                    Produit
                                                                </th>
                                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                                    Quantité
                                                                </th>
                                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                                    Pourcentage
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="bg-white divide-y divide-gray-200">
                                                            {operationData.products_consumed.map((product, pIndex) => (
                                                                <tr key={pIndex} className="hover:bg-gray-50 transition-colors">
                                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                                        {product.product_name}
                                                                    </td>
                                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                                        <span className="inline-flex items-center px-2 py-1 rounded-md bg-purple-50 text-purple-700 text-xs font-medium">
                                                                            {product.quantity.toFixed(2)} {product.unit_type}
                                                                        </span>
                                                                    </td>
                                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                                        {((product.quantity / operationData.total_quantity_consumed) * 100).toFixed(1)}%
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
