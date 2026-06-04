import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Dashboard({ auth, enterprise, stats }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Admin Dashboard - {enterprise.name}</h2>}
        >
            <Head title="Admin Dashboard" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 border-l-4 border-blue-500">
                            <h3 className="text-gray-500 text-sm font-bold uppercase tracking-wider">Total Employees</h3>
                            <p className="text-3xl font-black text-gray-900">{stats.employees_count}</p>
                            <Link href={route('employees.index')} className="text-blue-600 text-sm hover:underline mt-2 inline-block font-bold">Manage Employees &rarr;</Link>
                        </div>

                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 border-l-4 border-green-500">
                            <h3 className="text-gray-500 text-sm font-bold uppercase tracking-wider">Open Quinzaines</h3>
                            <p className="text-3xl font-black text-gray-900">{stats.open_quinzaines}</p>
                            <Link href={route('settings.index')} className="text-green-600 text-sm hover:underline mt-2 inline-block font-bold">Manage Periods &rarr;</Link>
                        </div>
                    </div>

                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-8">
                        <h3 className="text-xl font-bold mb-4">Quick Actions</h3>
                        <div className="flex flex-wrap gap-4">
                            <Link href={route('pointage.index')} className="bg-blue-600 text-white px-8 py-4 rounded-lg font-black shadow-lg hover:bg-blue-700 transition-all flex items-center gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                </svg>
                                ENTER DAILY POINTAGE
                            </Link>
                            <Link href={route('employees.index')} className="bg-gray-800 text-white px-6 py-4 rounded-lg font-bold shadow hover:bg-gray-900 transition-all flex items-center gap-2">
                                Manage Personnel
                            </Link>
                            <Link href={route('settings.index')} className="bg-green-600 text-white px-6 py-4 rounded-lg font-bold shadow hover:bg-green-700 transition-all flex items-center gap-2">
                                Settings
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
