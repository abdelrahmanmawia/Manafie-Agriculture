import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

export default function Index({ auth, quinzaines, enterprises }) {
    const [isCreating, setIsCreating] = useState(false);
    
    const { data, setData, post, processing, reset, errors } = useForm({
        start_date: '',
        end_date: '',
        enterprise_id: auth.user.enterprise_id || (enterprises?.[0]?.id || ''),
    });

    const formatDate = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('settings.quinzaine'), {
            onSuccess: () => {
                reset();
                setIsCreating(false);
            },
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Gestion des Quinzaines (Periods)</h2>
                    {auth.user.role !== 'data_entry' && (
                        <button 
                            onClick={() => setIsCreating(true)}
                            className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                        >
                            <span>+</span> Ouvrir une Période
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Pointage" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <h3 className="text-lg font-bold mb-6 border-b pb-2">Liste des Périodes de Pointage</h3>
                            
                            {quinzaines.length === 0 ? (
                                <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                                    <p className="text-gray-500 italic">Aucune quinzaine disponible. Cliquez sur "+ Ouvrir une Période" pour commencer.</p>
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                    {quinzaines.map((q) => (
                                        <div key={q.id} className={`relative flex flex-col p-6 rounded-xl border-2 transition-all ${q.is_closed ? 'bg-gray-50 border-gray-100 opacity-75' : 'bg-white border-blue-50 hover:border-blue-300 shadow-sm'}`}>
                                            <div className="flex justify-between items-start mb-4">
                                                <div className="flex flex-col">
                                                    <span className="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">Période du Pointage</span>
                                                    <h5 className="text-lg font-black text-gray-900 leading-tight">
                                                        {formatDate(q.start_date)} <br/> 
                                                        <span className="text-blue-500">au</span> {formatDate(q.end_date)}
                                                    </h5>
                                                </div>
                                                {q.is_closed ? (
                                                    <span className="bg-gray-200 text-gray-600 px-2 py-1 rounded text-[9px] font-black uppercase tracking-tighter">CLÔTURÉE</span>
                                                ) : (
                                                    <span className="bg-green-100 text-green-700 px-2 py-1 rounded text-[9px] font-black uppercase tracking-tighter animate-pulse">OUVERTE</span>
                                                )}
                                            </div>

                                            <div className="mb-6">
                                                <span className="text-[10px] font-bold text-gray-400 uppercase block mb-1">Ferme / Entreprise</span>
                                                <p className="font-bold text-gray-700">{q.enterprise?.name}</p>
                                            </div>

                                            <div className="mt-auto space-y-2">
                                                <Link
                                                    href={route('pointage.grid', q.id)}
                                                    className={`w-full block text-center py-2.5 rounded-lg font-black text-sm shadow-sm transition-all ${q.is_closed ? 'bg-gray-800 text-white hover:bg-black' : 'bg-blue-600 text-white hover:bg-blue-700'}`}
                                                >
                                                    {q.is_closed ? 'VOIR LES DÉTAILS' : 'REMPLIR LE POINTAGE'}
                                                </Link>

                                                {!q.is_closed && auth.user.role !== 'data_entry' && (
                                                    <Link
                                                        method="post"
                                                        as="button"
                                                        href={route('settings.quinzaine.close', q.id)}
                                                        className="w-full text-center py-2 text-[10px] font-bold text-red-500 hover:text-red-700 hover:underline"
                                                    >
                                                        CLÔTURER CETTE PÉRIODE
                                                    </Link>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* CREATE QUINZAINE MODAL */}
            <Modal show={isCreating} onClose={() => setIsCreating(false)}>
                <div className="p-8">
                    <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">Ouvrir une Nouvelle Quinzaine</h3>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-4">
                            {auth.user.role === 'super_admin' && (
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 mb-1">Ferme / Entreprise *</label>
                                    <select 
                                        className="w-full rounded-lg border-gray-300" 
                                        value={data.enterprise_id} 
                                        onChange={e => setData('enterprise_id', e.target.value)}
                                    >
                                        {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                    </select>
                                    {errors.enterprise_id && <div className="text-red-500 text-xs mt-1">{errors.enterprise_id}</div>}
                                </div>
                            )}
                            
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 mb-1">Date de Début</label>
                                    <input type="date" className="w-full rounded-lg border-gray-300" value={data.start_date} onChange={e => setData('start_date', e.target.value)} />
                                    {errors.start_date && <div className="text-red-500 text-xs mt-1">{errors.start_date}</div>}
                                </div>
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 mb-1">Date de Fin</label>
                                    <input type="date" className="w-full rounded-lg border-gray-300" value={data.end_date} onChange={e => setData('end_date', e.target.value)} />
                                    {errors.end_date && <div className="text-red-500 text-xs mt-1">{errors.end_date}</div>}
                                </div>
                            </div>
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsCreating(false)}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={processing}>Ouvrir la Période</PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
