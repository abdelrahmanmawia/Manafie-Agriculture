import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function ExpiryAlerts({ auth, expiryAlerts }) {
    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const getAlertTypeColor = (type) => {
        switch (type) {
            case 'expired': return 'bg-red-100 text-red-800 border-red-200';
            case 'expiring_soon': return 'bg-orange-100 text-orange-800 border-orange-200';
            default: return 'bg-gray-100 text-gray-800 border-gray-200';
        }
    };

    const getAlertTypeLabel = (type) => {
        switch (type) {
            case 'expired': return 'Expiré';
            case 'expiring_soon': return 'Expiration Proche';
            default: return type;
        }
    };

    const expiredCount = expiryAlerts.filter(a => a.alert_type === 'expired').length;
    const expiringSoonCount = expiryAlerts.filter(a => a.alert_type === 'expiring_soon').length;

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
                            <h2 className="font-bold text-2xl text-gray-800 leading-tight">Alertes d'Expiration</h2>
                            <p className="text-sm text-gray-500 mt-1">Liste des produits expirés ou proches de l'expiration</p>
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Alertes d'Expiration" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Stats Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-gradient-to-r from-red-500 to-red-600 rounded-xl shadow-lg p-6">
                            <div className="flex items-center gap-4">
                                <div className="h-12 w-12 bg-white/20 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                </div>
                                <div>
                                    <p className="text-red-100 text-sm font-medium">Produits Expirés</p>
                                    <p className="text-white text-3xl font-bold mt-1">{expiredCount}</p>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Expiration Proche</p>
                                    <p className="text-2xl font-bold text-orange-600 mt-1">{expiringSoonCount}</p>
                                </div>
                                <div className="h-12 w-12 bg-orange-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Total Alertes</p>
                                    <p className="text-2xl font-bold text-gray-900 mt-1">{expiryAlerts.length}</p>
                                </div>
                                <div className="h-12 w-12 bg-blue-100 rounded-xl flex items-center justify-center">
                                    <svg className="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Expiry Alerts Table */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h3 className="text-lg font-bold text-gray-800">Produits Expirés ou Proches de l'Expiration</h3>
                        </div>
                        <div className="p-6">
                            {expiryAlerts.length === 0 ? (
                                <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <p className="mt-4 text-gray-500">Aucune alerte d'expiration active pour le moment.</p>
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
                                                    Type d'Alerte
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Seuil
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Valeur Actuelle
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Date d'Expiration
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                    Notes
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {expiryAlerts.map((alert) => (
                                                <tr key={alert.id} className="hover:bg-gray-50 transition-colors">
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        {alert.product.name}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                        <span className={`px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full border ${getAlertTypeColor(alert.alert_type)}`}>
                                                            {getAlertTypeLabel(alert.alert_type)}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        {alert.threshold_value} {alert.product.unit_type}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        <span className={`inline-flex items-center px-2 py-1 rounded-md ${
                                                            alert.current_value <= alert.threshold_value 
                                                                ? 'bg-red-50 text-red-700' 
                                                                : 'bg-yellow-50 text-yellow-700'
                                                        } text-xs font-medium`}>
                                                            {alert.current_value} {alert.product.unit_type}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        {formatDate(alert.product.expiry_date)}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                        {alert.notes || 'N/A'}
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
