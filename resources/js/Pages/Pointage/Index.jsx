import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { t } from '@/Helpers/i18n';

export default function Index({ auth, enterprises }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">
                    Pointage
                </h2>
            }
        >
            <Head title="Pointage" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <p className="text-xs text-gray-400 font-bold uppercase tracking-widest">
                        Choisissez une division pour gérer ses quinzaines de pointage.
                    </p>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        {enterprises.map(ent => (
                            <div key={ent.id} className="bg-white overflow-hidden shadow-sm sm:rounded-2xl p-8 border border-gray-100 hover:shadow-lg transition-all border-t-4 border-t-blue-500">
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
                                        className="w-full text-center bg-blue-600 text-white hover:bg-blue-700 py-3 rounded-xl font-bold text-xs transition-colors uppercase tracking-widest"
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
        </AuthenticatedLayout>
    );
}
