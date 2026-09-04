import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { t } from '@/Helpers/i18n';
import StatCard from '@/Components/StatCard';
import DashboardHeader from '@/Components/DashboardHeader';
import { formatInt } from '@/utils/number';

export default function Index({ auth, enterprises, farm, totals }) {
    const [showEntForm, setShowEntForm] = useState(false);

    const entForm = useForm({
        farm_id: farm?.id,
        name: '',
        contract_type: 'avec_contrat',
        default_brut_rate: 97.44,
        invoiced_to_client: false,
    });

    const submitEnt = (e) => {
        e.preventDefault();
        entForm.post(route('enterprises.store'), {
            onSuccess: () => {
                entForm.reset();
                setShowEntForm(false);
            },
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<DashboardHeader title={`Pointage${farm ? ` — ${farm.name}` : ''}`} />}
        >
            <Head title="Pointage" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-10">

                    {/* Quick Stats — farm-wide totals across every division below */}
                    <div className="grid grid-cols-2 gap-4">
                        <StatCard
                            label="Total Salariés"
                            value={formatInt(totals.employees)}
                            tone="green"
                            href={route('employees.index')}
                            trend={totals.new_employees_30d > 0 ? { direction: 'up', label: `+${totals.new_employees_30d} ce mois` } : { direction: 'flat', label: 'Aucun nouveau' }}
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />}
                        />
                        <StatCard
                            label="Périodes Ouvertes"
                            value={formatInt(totals.open_quinzaines)}
                            tone="blue"
                            href={route('pointage.quinzaines')}
                            trend={totals.overdue_quinzaines > 0 ? { direction: 'down', label: `${totals.overdue_quinzaines} à clôturer` } : { direction: 'calm', label: 'À jour' }}
                            icon={<path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />}
                        />
                    </div>

                    {/* Quick Actions */}
                    <div className="bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 border border-gray-200">
                        <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter mb-4">Actions Rapides</h3>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <Link
                                href={route('analytics.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-primary-300"
                            >
                                <svg className="w-8 h-8 text-primary-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Analyses &amp; Statistiques</span>
                            </Link>
                            <Link
                                href={route('payroll.history')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-green-300"
                            >
                                <svg className="w-8 h-8 text-green-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Historique Salaires</span>
                            </Link>
                            <Link
                                href={route('harvests.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-orange-300"
                            >
                                <svg className="w-8 h-8 text-orange-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Récoltes</span>
                            </Link>
                            <Link
                                href={route('badges.index')}
                                className="flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 hover:border-purple-300"
                            >
                                <svg className="w-8 h-8 text-purple-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                                </svg>
                                <span className="text-sm font-medium text-gray-700">Badges &amp; Scan</span>
                            </Link>
                        </div>
                    </div>

                    {/* Divisions de la Ferme — the actual division picker + management, the one
                        thing this page is for. */}
                    <div className="space-y-6">
                        <div className="flex justify-between items-center">
                            <div>
                                <h3 className="text-2xl font-black text-gray-900 uppercase tracking-tight">Divisions de la Ferme</h3>
                                <p className="text-xs text-gray-400 font-bold uppercase tracking-widest mt-1">
                                    Choisissez une division pour gérer ses quinzaines de pointage.
                                </p>
                            </div>
                            {farm && auth.user.role !== 'data_entry' && (
                                <button
                                    onClick={() => setShowEntForm(!showEntForm)}
                                    className="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-full font-bold text-sm uppercase tracking-widest transition-all shadow-md"
                                >
                                    {showEntForm ? 'Annuler' : '+ Nouvelle Division'}
                                </button>
                            )}
                        </div>

                        {showEntForm && (
                            <div className="bg-white p-8 shadow-xl rounded-2xl border-2 border-primary-500 animate-in fade-in slide-in-from-top-4 duration-300">
                                <form onSubmit={submitEnt} className="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                                    <div className="md:col-span-1">
                                        <label className="block text-[10px] font-black uppercase text-gray-400 mb-1 tracking-widest">Nom de la Division</label>
                                        <input
                                            type="text"
                                            className="w-full rounded-xl border-gray-200 text-sm py-3"
                                            placeholder="Ex: Interim Persealand"
                                            value={entForm.data.name}
                                            onChange={e => entForm.setData('name', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-black uppercase text-gray-400 mb-1 tracking-widest">Salaire Brut (DH)</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className="w-full rounded-xl border-gray-200 text-sm py-3"
                                            value={entForm.data.default_brut_rate}
                                            onChange={e => entForm.setData('default_brut_rate', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-black uppercase text-gray-400 mb-1 tracking-widest">Type Contrat</label>
                                        <select
                                            className="w-full rounded-xl border-gray-200 text-sm py-3"
                                            value={entForm.data.contract_type}
                                            onChange={e => entForm.setData('contract_type', e.target.value)}
                                        >
                                            <option value="avec_contrat">Avec Contrat</option>
                                            <option value="sans_contrat">Sans Contrat</option>
                                        </select>
                                    </div>
                                    <div className="md:col-span-3 flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="invoiced_to_client"
                                            className="rounded border-gray-300"
                                            checked={entForm.data.invoiced_to_client}
                                            onChange={e => entForm.setData('invoiced_to_client', e.target.checked)}
                                        />
                                        <label htmlFor="invoiced_to_client" className="text-xs text-gray-600">
                                            Cette division facture un client (ex: agence d'intérim) — à ne cocher que si elle émet une facture (net à facturer/TTC), pas juste le salaire des ouvriers
                                        </label>
                                    </div>
                                    <div className="md:col-span-3">
                                        <button type="submit" disabled={entForm.processing} className="w-full bg-primary-600 hover:bg-primary-700 text-white py-4 rounded-xl font-black uppercase tracking-widest transition-colors shadow-md">
                                            Créer la Division
                                        </button>
                                    </div>
                                </form>
                            </div>
                        )}

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                            {enterprises.map(ent => (
                                <div key={ent.id} className="bg-white overflow-hidden shadow-sm sm:rounded-2xl p-8 border border-gray-100 hover:shadow-lg transition-all border-t-4 border-t-primary-500">
                                    <div className="flex justify-between items-start mb-6">
                                        <h4 className="text-2xl font-black text-gray-900 uppercase leading-tight">{ent.name}</h4>
                                        <span className={`px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest ${ent.contract_type === 'avec_contrat' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'}`}>
                                            {ent.contract_type.replace('_', ' ')}
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-3 gap-3 mb-8">
                                        <div className="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                                            <p className="text-[8px] font-black text-gray-400 uppercase tracking-widest mb-1">{t('workers')}</p>
                                            <p className="text-xl font-black text-gray-900">{ent.employees_count}</p>
                                        </div>
                                        <div className="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                                            <p className="text-[8px] font-black text-gray-400 uppercase tracking-widest mb-1">{t('periods')}</p>
                                            <p className="text-xl font-black text-gray-900">{ent.quinzaines_count}</p>
                                        </div>
                                        <div className="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                                            <p className="text-[8px] font-black text-gray-400 uppercase tracking-widest mb-1">Ouvertes</p>
                                            <p className="text-xl font-black text-green-600">{ent.open_quinzaines_count}</p>
                                        </div>
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <Link
                                            href={route('pointage.quinzaines', { enterprise_id: ent.id })}
                                            className="w-full text-center bg-primary-600 text-white hover:bg-primary-700 py-3 rounded-xl font-bold text-xs transition-colors uppercase tracking-widest"
                                        >
                                            Voir les Quinzaines
                                        </Link>
                                        <div className="grid grid-cols-2 gap-2">
                                            <Link
                                                href={route('analytics.index', { enterprise_id: ent.id })}
                                                className="text-center bg-gray-50 hover:bg-gray-100 text-gray-600 py-2 rounded-xl font-bold text-[10px] transition-colors uppercase tracking-widest border border-gray-100"
                                            >
                                                Analyse
                                            </Link>
                                            <Link
                                                href={route('settings.index', { enterprise_id: ent.id })}
                                                className="text-center bg-gray-50 hover:bg-gray-100 text-gray-600 py-2 rounded-xl font-bold text-[10px] transition-colors uppercase tracking-widest border border-gray-100"
                                            >
                                                {t('settings')}
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {enterprises.length === 0 && (
                                <div className="col-span-full bg-gray-50 rounded-3xl p-12 text-center border-2 border-dashed border-gray-200">
                                    <p className="text-gray-400 font-bold uppercase tracking-widest">Aucune division disponible.</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
