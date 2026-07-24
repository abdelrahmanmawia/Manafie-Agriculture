import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { formatNumber, formatMAD } from '@/utils/number';
import { FUEL_TRANSACTION_TYPE_LABELS } from '@/utils/stockLabels';

export default function Show({ auth, fuelTransaction }) {
    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div className="flex items-center gap-4">
                        <Link href={route('stock.fuel-transactions.index')} className="text-gray-500 hover:text-gray-700 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-bold text-2xl text-gray-800 leading-tight">Transaction de Carburant</h2>
                            <p className="text-sm text-gray-500 mt-1">Historique en lecture seule</p>
                        </div>
                    </div>
                </div>
            }
        >
            <Head title={`Transaction: ${fuelTransaction.id}`} />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Header Card */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6">
                            <div className="flex items-center gap-4">
                                <div className="h-16 w-16 bg-orange-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-8 w-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <h3 className="text-2xl font-bold text-gray-900">{fuelTransaction.product?.name || 'Carburant'}</h3>
                                    <p className="text-sm text-gray-500 mt-1">{fuelTransaction.vehicle?.name || 'N/A'} · {fuelTransaction.vehicle?.plate_number || 'N/A'}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Quantité</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatNumber(fuelTransaction.quantity_liters)} L</p>
                        </div>
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Prix / Litre</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{fuelTransaction.unit_price_per_liter ? formatMAD(fuelTransaction.unit_price_per_liter) : 'N/A'}</p>
                        </div>
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Coût Total</p>
                            <p className="text-2xl font-bold text-orange-600 mt-1">{fuelTransaction.total_cost ? formatMAD(fuelTransaction.total_cost) : 'N/A'}</p>
                        </div>
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Date</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatDate(fuelTransaction.date)}</p>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-bold text-gray-800">Détails de la Transaction</h4>
                            </div>
                            <div className="p-6 space-y-4">
                                <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                    <span className="text-gray-500">Type de Transaction</span>
                                    <span className="font-medium text-gray-900">{FUEL_TRANSACTION_TYPE_LABELS[fuelTransaction.transaction_type] || fuelTransaction.transaction_type}</span>
                                </div>
                                <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                    <span className="text-gray-500">Conducteur</span>
                                    <span className="font-medium text-gray-900">{fuelTransaction.driver?.full_name || 'N/A'}</span>
                                </div>
                                <div className="flex justify-between items-center py-2">
                                    <span className="text-gray-500">Effectué par</span>
                                    <span className="font-medium text-gray-900">{fuelTransaction.performed_by?.name || 'N/A'}</span>
                                </div>
                            </div>
                        </div>

                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-bold text-gray-800">Suivi Véhicule</h4>
                            </div>
                            <div className="p-6 space-y-4">
                                <div className="flex justify-between items-center py-2">
                                    <span className="text-gray-500">Kilométrage</span>
                                    <span className="font-medium text-gray-900">{fuelTransaction.odometer_km ? `${formatNumber(fuelTransaction.odometer_km)} km` : 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {fuelTransaction.notes && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-bold text-gray-800">Notes</h4>
                            </div>
                            <div className="p-6">
                                <p className="bg-gray-50 p-4 rounded-lg text-sm text-gray-700">{fuelTransaction.notes}</p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
