import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Index({ auth }) {
    const reports = [
        { 
            name: 'Valeur de l\'Inventaire', 
            description: 'Vue d\'ensemble de la valeur actuelle de votre stock.', 
            route: 'stock.reports.inventory-value',
            icon: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            // Was bg-primary-500 — now that primary is a brand green too, it read as a near
            // duplicate of the "Historique des Mouvements" card right next to it. Blue keeps
            // this picker's six cards visually distinct from each other.
            color: 'bg-blue-500'
        },
        { 
            name: 'Historique des Mouvements', 
            description: 'Suivi détaillé de toutes les entrées et sorties de stock.', 
            route: 'stock.reports.movement-history',
            icon: 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
            color: 'bg-green-500'
        },
        { 
            name: 'Consommation par Opération', 
            description: 'Analyse des produits consommés par type d\'opération.', 
            route: 'stock.reports.consumption-by-operation',
            icon: 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10',
            color: 'bg-purple-500'
        },
        {
            name: 'Coût par Hectare',
            description: 'Calcul du coût des intrants par hectare pour les blocs.',
            route: 'stock.reports.cost-per-hectare',
            icon: 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7',
            color: 'bg-orange-500'
        },
        {
            name: 'Coût par Véhicule',
            description: 'Carburant, pièces et maintenance cumulés par véhicule et matériel.',
            route: 'stock.reports.cost-per-vehicle',
            icon: 'M5 13l1.5-4.5A2 2 0 018.4 7h7.2a2 2 0 011.9 1.5L19 13m-14 0h14m-14 0v4a1 1 0 001 1h1a1 1 0 001-1v-1h8v1a1 1 0 001 1h1a1 1 0 001-1v-4',
            color: 'bg-teal-500'
        },
        {
            name: 'Rotation des Stocks',
            description: 'Mesure de la fréquence de renouvellement des stocks.',
            route: 'stock.reports.stock-turnover',
            icon: 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
            color: 'bg-indigo-500'
        },
    ];

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center gap-4">
                    <Link
                        href={route('stock.dashboard')}
                        className="text-gray-500 hover:text-gray-700 transition-colors"
                    >
                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                    </Link>
                    <div>
                        <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Rapports et Analyses de Stock</h2>
                        <p className="text-sm text-gray-500 mt-1">Visualisez et analysez vos données de stock</p>
                    </div>
                </div>
            }
        >
            <Head title="Rapports de Stock" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6">
                            <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter mb-6">Sélectionnez un Rapport</h3>

                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                {reports.map((report, index) => (
                                    <Link
                                        key={index}
                                        href={route(report.route)}
                                        className="group block p-6 bg-gray-50 rounded-xl border border-gray-200 hover:border-primary-300 hover:shadow-lg transition-all duration-300"
                                    >
                                        <div className={`h-12 w-12 ${report.color} rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition-transform duration-300`}>
                                            <svg className="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={report.icon} />
                                            </svg>
                                        </div>
                                        <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter mb-2 group-hover:text-primary-600 transition-colors">{report.name}</h4>
                                        <p className="text-sm text-gray-600">{report.description}</p>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
