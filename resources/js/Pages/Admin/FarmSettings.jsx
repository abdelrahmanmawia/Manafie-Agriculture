import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { t } from '@/Helpers/i18n';

export default function FarmSettings({ auth, farm, operations, blocs }) {
    const opForm = useForm({ name: '' });
    const blocForm = useForm({ name: '' });

    const submitOp = (e) => {
        e.preventDefault();
        opForm.post(route('farms.operations.store', farm.id), {
            onSuccess: () => opForm.reset(),
        });
    };

    const submitBloc = (e) => {
        e.preventDefault();
        blocForm.post(route('farms.blocs.store', farm.id), {
            onSuccess: () => blocForm.reset(),
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center w-full">
                    <div className="flex flex-col">
                        <h2 className="font-semibold text-xl text-gray-800 leading-tight">{t('settings')} - {farm.name}</h2>
                        <Link
                            href={route('farms.destroy', farm.id)}
                            method="delete"
                            as="button"
                            onBefore={() => confirm('ATTENTION: Cette action supprimera DÉFINITIVEMENT la ferme, toutes ses divisions, salariés, pointages et quinzaines. Voulez-vous continuer ?')}
                            className="text-[10px] text-red-500 hover:text-red-700 font-bold uppercase text-left mt-1"
                        >
                            Supprimer cette Ferme
                        </Link>
                    </div>
                    <Link
                        href={route('dashboard', { farm_id: farm.id })}
                        className="text-xs bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded font-bold transition-colors"
                    >
                        {t('back')} {t('dashboard')}
                    </Link>
                </div>
            }
        >
            <Head title={`${t('settings')} ${farm.name}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 grid grid-cols-1 md:grid-cols-2 gap-6">

                    {/* OPERATIONS MANAGEMENT */}
                    <div className="bg-white p-6 shadow sm:rounded-lg border-t-4 border-blue-500">
                        <div className="mb-4">
                            <h3 className="text-lg font-bold leading-none">{t('manage_operations')}</h3>
                            <span className="text-[10px] font-black uppercase text-blue-600 tracking-widest block mt-1">Partagé par toute la ferme</span>
                        </div>
                        <form onSubmit={submitOp} className="flex gap-2 mb-4">
                            <input 
                                type="text" 
                                placeholder="Ex: Récolte, Taille..." 
                                className="flex-1 rounded border-gray-300 text-sm" 
                                value={opForm.data.name} 
                                onChange={e => opForm.setData('name', e.target.value)} 
                                required
                            />
                            <button type="submit" className="bg-blue-600 text-white px-4 py-2 rounded font-bold" disabled={opForm.processing}>+</button>
                        </form>
                        <ul className="divide-y divide-gray-100 max-h-80 overflow-y-auto">
                            {operations.map(op => (
                                <li key={op.id} className="py-3 flex justify-between text-sm items-center hover:bg-gray-50 px-2 rounded">
                                    <span className="font-medium text-gray-700">{op.name}</span>
                                    <Link
                                        href={route('farms.operations.destroy', op.id)}
                                        method="delete"
                                        as="button"
                                        className="text-red-400 hover:text-red-600 text-xs font-bold"
                                    >
                                        {t('delete')}
                                    </Link>
                                </li>
                            ))}
                            {operations.length === 0 && <p className="text-center text-gray-400 py-4 text-xs italic">{t('no_data')}</p>}
                        </ul>
                    </div>

                    {/* BLOCS MANAGEMENT */}
                    <div className="bg-white p-6 shadow sm:rounded-lg border-t-4 border-green-500">
                        <div className="mb-4">
                            <h3 className="text-lg font-bold leading-none">{t('manage_blocs')}</h3>
                            <span className="text-[10px] font-black uppercase text-green-600 tracking-widest block mt-1">Partagé par toute la ferme</span>
                        </div>
                        <form onSubmit={submitBloc} className="flex gap-2 mb-4">
                            <input 
                                type="text" 
                                placeholder="Ex: Bloc A, Secteur 1..." 
                                className="flex-1 rounded border-gray-300 text-sm" 
                                value={blocForm.data.name} 
                                onChange={e => blocForm.setData('name', e.target.value)} 
                                required
                            />
                            <button type="submit" className="bg-green-600 text-white px-4 py-2 rounded font-bold" disabled={blocForm.processing}>+</button>
                        </form>
                        <ul className="divide-y divide-gray-100 max-h-80 overflow-y-auto">
                            {blocs.map(b => (
                                <li key={b.id} className="py-3 flex justify-between text-sm items-center hover:bg-gray-50 px-2 rounded">
                                    <span className="font-medium text-gray-700">{b.name}</span>
                                    <Link
                                        href={route('farms.blocs.destroy', b.id)}
                                        method="delete"
                                        as="button"
                                        className="text-red-400 hover:text-red-600 text-xs font-bold"
                                    >
                                        {t('delete')}
                                    </Link>
                                </li>
                            ))}
                            {blocs.length === 0 && <p className="text-center text-gray-400 py-4 text-xs italic">{t('no_data')}</p>}
                        </ul>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
