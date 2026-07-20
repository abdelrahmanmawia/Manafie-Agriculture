import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { t } from '@/Helpers/i18n';

export default function FarmDashboard({ auth, farm, enterprises, stats, isSuperAdmin = false }) {
    const [showEntForm, setShowEntForm] = useState(false);
    
    const entForm = useForm({
        farm_id: farm.id,
        name: '',
        contract_type: 'avec_contrat',
        default_brut_rate: 97.44,
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
            header={
                <div className="flex justify-between items-center w-full">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight uppercase tracking-widest">{farm.name} - Dashboard</h2>
                    <div className="flex gap-2">
                        {isSuperAdmin && (
                            <Link
                                method="post"
                                as="button"
                                href={route('farms.deactivate')}
                                className="text-xs bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded font-bold transition-colors"
                            >
                                Changer de Ferme
                            </Link>
                        )}
                        <Link
                            href={route('farms.settings', farm.id)}
                            className="text-xs bg-blue-600 text-white hover:bg-blue-700 px-3 py-1 rounded font-bold transition-colors"
                        >
                            {t('settings')} {t('fermes')}
                        </Link>
                    </div>
                </div>
            }
        >
            <Head title="Tableau de Bord Ferme" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-10">
                    
                    {/* FARM STATS */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div className="bg-white p-8 rounded-3xl shadow-sm border-l-8 border-green-500 flex justify-between items-center">
                            <div>
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Total Salariés (Ferme)</span>
                                <p className="text-4xl font-black text-gray-900 leading-none">{stats.employees_count}</p>
                            </div>
                            <div className="bg-green-50 p-3 rounded-2xl">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </div>
                        </div>
                        <div className="bg-white p-8 rounded-3xl shadow-sm border-l-8 border-blue-600 flex justify-between items-center">
                            <div>
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Périodes Ouvertes</span>
                                <p className="text-4xl font-black text-gray-900 leading-none">{stats.open_quinzaines}</p>
                            </div>
                            <div className="bg-blue-50 p-3 rounded-2xl">
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    {/* DIVISIONS MANAGEMENT */}
                    <div className="space-y-6">
                        <div className="flex justify-between items-center">
                            <h3 className="text-2xl font-black text-gray-900 uppercase tracking-tight">Divisions de la Ferme</h3>
                            <button 
                                onClick={() => setShowEntForm(!showEntForm)}
                                className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-xl font-bold text-sm uppercase tracking-widest transition-all shadow-md"
                            >
                                {showEntForm ? 'Annuler' : '+ Nouvelle Division'}
                            </button>
                        </div>

                        {showEntForm && (
                            <div className="bg-white p-8 shadow-xl rounded-2xl border-2 border-blue-500 animate-in fade-in slide-in-from-top-4 duration-300">
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
                                    <div className="md:col-span-3">
                                        <button type="submit" disabled={entForm.processing} className="w-full bg-blue-600 hover:bg-blue-700 text-white py-4 rounded-xl font-black uppercase tracking-widest transition-colors shadow-md">
                                            Créer la Division
                                        </button>
                                    </div>
                                </form>
                            </div>
                        )}

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                            {enterprises.map(ent => (
                                <div key={ent.id} className="bg-white overflow-hidden shadow-sm sm:rounded-2xl p-8 border border-gray-100 hover:shadow-lg transition-all border-t-4 border-t-blue-500">
                                    <div className="flex justify-between items-start mb-6">
                                        <h4 className="text-2xl font-black text-gray-900 uppercase leading-tight">{ent.name}</h4>
                                        <span className={`px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest ${ent.contract_type === 'avec_contrat' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'}`}>
                                            {ent.contract_type.replace('_', ' ')}
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4 mb-8">
                                        <div className="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                                            <p className="text-[8px] font-black text-gray-400 uppercase tracking-widest mb-1">{t('workers')}</p>
                                            <p className="text-xl font-black text-gray-900">{ent.employees_count}</p>
                                        </div>
                                        <div className="bg-gray-50 p-4 rounded-2xl border border-gray-100">
                                            <p className="text-[8px] font-black text-gray-400 uppercase tracking-widest mb-1">{t('periods')}</p>
                                            <p className="text-xl font-black text-gray-900">{ent.quinzaines_count}</p>
                                        </div>
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <Link
                                            href={route('pointage.index', { enterprise_id: ent.id })}
                                            className="w-full text-center bg-blue-600 text-white hover:bg-blue-700 py-3 rounded-xl font-bold text-xs transition-colors uppercase tracking-widest"
                                        >
                                            {t('pointage')}
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
                                    <p className="text-gray-400 font-bold uppercase tracking-widest">Aucune division créée pour cette ferme.</p>
                                    <p className="text-xs text-gray-400 mt-2 italic">Commencez par ajouter une division comme "Interim" ou "Direct".</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
