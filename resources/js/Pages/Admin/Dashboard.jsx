import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { t } from '@/Helpers/i18n';
import StatCard from '@/Components/StatCard';
import DashboardHeader from '@/Components/DashboardHeader';
import QuickActions from '@/Components/QuickActions';

export default function Dashboard({ auth, enterprise, stats, error = null }) {
    // A user with no enterprise_id assigned yet has nothing to render a dashboard for —
    // show the error message instead of crashing on enterprise.name below.
    if (!enterprise) {
        return (
            <AuthenticatedLayout
                user={auth.user}
                header={<DashboardHeader title={t('admin_dashboard')} />}
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
            header={<DashboardHeader title={`${t('admin_dashboard')} - ${enterprise.name}`} />}
        >
            <Head title={t('admin_dashboard')} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <StatCard
                            label={t('total_employees')}
                            value={stats.employees_count}
                            tone="blue"
                            href={route('employees.index')}
                            trend={stats.new_employees_30d > 0 ? { direction: 'up', label: `+${stats.new_employees_30d} ce mois` } : { direction: 'flat', label: 'Aucun nouveau' }}
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />}
                        />
                        <StatCard
                            label={t('open_quinzaines')}
                            value={stats.open_quinzaines}
                            tone="green"
                            href={route('settings.index')}
                            trend={stats.overdue_quinzaines > 0 ? { direction: 'down', label: `${stats.overdue_quinzaines} à clôturer` } : { direction: 'calm', label: 'À jour' }}
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />}
                        />
                    </div>

                    <QuickActions
                        title={t('quick_actions')}
                        items={[
                            {
                                href: route('pointage.quinzaines'),
                                label: t('enter_daily_pointage_btn'),
                                color: 'blue',
                                icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
                            },
                            {
                                href: route('employees.index'),
                                label: t('manage_personnel'),
                                color: 'green',
                                icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
                            },
                            {
                                href: route('settings.index'),
                                label: t('settings'),
                                color: 'orange',
                                icon: [
                                    'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
                                    'M15 12a3 3 0 11-6 0 3 3 0 016 0z',
                                ],
                            },
                        ]}
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
