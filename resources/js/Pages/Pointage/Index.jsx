import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { t } from '@/Helpers/i18n';
import DashboardHeader from '@/Components/DashboardHeader';

// The farm's divisions: create one, and each one's quinzaines, analysis and settings. The day's
// work starts on "Aujourd'hui"; this is setup, so it sits lower in the sidebar.
export default function Index({ auth, enterprises, farm }) {
    const [showEntForm, setShowEntForm] = useState(false);

    const entForm = useForm({
        farm_id: farm?.id,
        name: '',
        contract_type: 'avec_contrat',
        default_brut_rate: 97.44,
        invoiced_to_client: false,
        mo_declaree: true,
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
            header={<DashboardHeader title={`Divisions${farm ? ` — ${farm.name}` : ''}`} />}
        >
            <Head title="Divisions" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-10">

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
                                    {!entForm.data.invoiced_to_client && (
                                        <div className="md:col-span-3 flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                id="mo_non_declaree"
                                                className="rounded border-gray-300"
                                                checked={!entForm.data.mo_declaree}
                                                onChange={e => entForm.setData('mo_declaree', !e.target.checked)}
                                            />
                                            <label htmlFor="mo_non_declaree" className="text-xs text-gray-600">
                                                Main d'œuvre non déclarée — l'export Excel utilise la fiche « MO NON DECLARE » (sans RIB, total avec marge de 4%)
                                            </label>
                                        </div>
                                    )}
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
