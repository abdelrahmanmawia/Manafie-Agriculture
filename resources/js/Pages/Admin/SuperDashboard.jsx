import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { t } from '@/Helpers/i18n';
import DashboardHeader from '@/Components/DashboardHeader';

export default function SuperDashboard({ auth, farms = [], enterprises = [] }) {
    const [showFarmForm, setShowFarmForm] = useState(false);
    const [showEntForm, setShowEntForm] = useState(false);

    const farmForm = useForm({
        name: '',
    });

    const entForm = useForm({
        farm_id: '',
        name: '',
        contract_type: 'avec_contrat',
        default_brut_rate: 97.44,
    });

    const submitFarm = (e) => {
        e.preventDefault();
        farmForm.post(route('farms.store'), {
            onSuccess: () => {
                farmForm.reset();
                setShowFarmForm(false);
            },
        });
    };

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
            header={<DashboardHeader title="Tableau de Bord - Fermes" />}
        >
            <Head title="Super Admin Dashboard" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

                    {/* CREATE FARM TOGGLE */}
                    <div className="flex justify-center">
                        {!showFarmForm ? (
                            <button
                                onClick={() => setShowFarmForm(true)}
                                className="bg-primary-600 hover:bg-primary-700 text-white px-8 py-3 rounded-full font-black uppercase tracking-widest shadow-sm transition-colors"
                            >
                                + Créer une Nouvelle Ferme
                            </button>
                        ) : (
                            <div className="bg-white p-8 shadow-xl rounded-2xl border border-gray-200 w-full max-w-md">
                                <div className="flex justify-between items-center mb-6">
                                    <h3 className="text-xl font-black uppercase tracking-tight">Nouvelle Ferme</h3>
                                    <button onClick={() => setShowFarmForm(false)} className="text-gray-400 hover:text-gray-600 font-bold">X</button>
                                </div>
                                <form onSubmit={submitFarm} className="space-y-6">
                                    <div>
                                        <label className="block text-[10px] font-black uppercase text-gray-400 mb-1 tracking-widest">Nom de la Ferme</label>
                                        <input
                                            type="text"
                                            className="w-full rounded-xl border-gray-200 text-sm focus:border-primary-500 focus:ring-primary-500 py-3"
                                            placeholder="Ex: Ferme Souss"
                                            value={farmForm.data.name}
                                            onChange={e => farmForm.setData('name', e.target.value)}
                                            required
                                        />
                                        {farmForm.errors.name && <div className="text-red-500 text-xs mt-1">{farmForm.errors.name}</div>}
                                    </div>
                                    <button
                                        type="submit"
                                        disabled={farmForm.processing}
                                        className="w-full bg-primary-600 hover:bg-primary-700 text-white py-4 rounded-xl font-black uppercase tracking-widest transition-colors shadow-md"
                                    >
                                        Enregistrer la Ferme
                                    </button>
                                </form>
                            </div>
                        )}
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        {farms.map(farm => (
                            <div key={farm.id} className="bg-white overflow-hidden shadow-sm sm:rounded-2xl p-8 border border-gray-100 hover:border-primary-300 hover:shadow-lg transition-all group">
                                <div className="flex justify-between items-start mb-6">
                                    <div>
                                        <h4 className="text-3xl font-black text-gray-900 leading-tight uppercase group-hover:text-primary-600 transition-colors">{farm.name}</h4>
                                        <div className="flex items-center gap-2 mt-1">
                                            <span className="w-2 h-2 rounded-full bg-green-500"></span>
                                            <span className="text-[10px] font-black text-gray-400 uppercase tracking-widest">Active</span>
                                        </div>
                                    </div>
                                    <div className="bg-gray-50 px-3 py-2 rounded-xl text-center border border-gray-100">
                                        <p className="text-xl font-black text-gray-900 leading-none">{farm.enterprises_count}</p>
                                        <p className="text-[8px] font-bold text-gray-400 uppercase tracking-tighter">Divisions</p>
                                    </div>
                                </div>
                                
                                <div className="flex flex-col gap-3 mt-8">
                                    <Link
                                        method="post"
                                        as="button"
                                        href={route('farms.activate', farm.id)}
                                        className="w-full text-center bg-gray-900 text-white hover:bg-black py-4 rounded-xl font-black text-xs transition-all uppercase tracking-widest shadow-sm"
                                    >
                                        Travailler sur cette Ferme
                                    </Link>
                                    <Link
                                        href={route('farms.settings', farm.id)}
                                        className="w-full text-center bg-gray-50 hover:bg-gray-100 text-gray-600 py-3 rounded-xl font-bold text-[10px] transition-all uppercase tracking-widest border border-gray-100"
                                    >
                                        {t('settings')} {t('fermes')}
                                    </Link>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
