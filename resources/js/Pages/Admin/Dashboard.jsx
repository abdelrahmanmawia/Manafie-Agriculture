import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { t } from '@/Helpers/i18n';
import StatCard from '@/Components/StatCard';

export default function Dashboard({ auth, enterprise, stats, error = null }) {
    // A user with no enterprise_id assigned yet has nothing to render a dashboard for —
    // show the error message instead of crashing on enterprise.name below.
    if (!enterprise) {
        return (
            <AuthenticatedLayout
                user={auth.user}
                header={<h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">{t('admin_dashboard')}</h2>}
            >
                <Head title={t('admin_dashboard')} />
                <div className="py-12">
                    <div className="max-w-3xl mx-auto sm:px-6 lg:px-8">
                        <div className="bg-white rounded-2xl shadow-sm border border-amber-200 p-10 text-center">
                            <svg className="w-12 h-12 text-amber-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <p className="text-gray-700 font-bold">{error || 'Aucune division ne vous est assignée.'}</p>
                            <p className="text-sm text-gray-500 mt-2">Contactez un administrateur pour qu'une division vous soit assignée.</p>
                        </div>
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">{t('admin_dashboard')} - {enterprise.name}</h2>}
        >
            <Head title={t('admin_dashboard')} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <StatCard
                                label={t('total_employees')}
                                value={stats.employees_count}
                                tone="blue"
                                icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />}
                            />
                            <Link href={route('employees.index')} className="text-blue-600 text-sm hover:underline mt-2 inline-block font-bold">{t('manage_employees')} &rarr;</Link>
                        </div>

                        <div>
                            <StatCard
                                label={t('open_quinzaines')}
                                value={stats.open_quinzaines}
                                tone="green"
                                icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />}
                            />
                            <Link href={route('settings.index')} className="text-green-600 text-sm hover:underline mt-2 inline-block font-bold">{t('manage_periods')} &rarr;</Link>
                        </div>
                    </div>

                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-2xl border border-gray-100 p-8">
                        <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter mb-4">{t('quick_actions')}</h3>
                        <div className="flex flex-wrap gap-4">
                            <Link href={route('pointage.quinzaines')} className="bg-blue-600 text-white px-8 py-4 rounded-xl font-black uppercase tracking-widest text-sm shadow-md hover:bg-blue-700 transition-all flex items-center gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                </svg>
                                {t('enter_daily_pointage_btn')}
                            </Link>
                            <Link href={route('employees.index')} className="bg-gray-800 text-white px-6 py-4 rounded-xl font-black uppercase tracking-widest text-sm shadow-md hover:bg-gray-900 transition-all flex items-center gap-2">
                                {t('manage_personnel')}
                            </Link>
                            <Link href={route('settings.index')} className="bg-green-600 text-white px-6 py-4 rounded-xl font-black uppercase tracking-widest text-sm shadow-md hover:bg-green-700 transition-all flex items-center gap-2">
                                {t('settings')}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
