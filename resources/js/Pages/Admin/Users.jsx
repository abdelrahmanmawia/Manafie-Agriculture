import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { t } from '@/Helpers/i18n';

export default function Users({ auth, users, farms = [], enterprises = [], selectedFarmId, selectedEnterpriseId }) {
    const [isAddingUser, setIsAddingUser] = useState(false);

    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        email: '',
        password: '',
        role: 'data_entry',
        farm_id: selectedFarmId || (farms[0]?.id || ''),
        enterprise_id: selectedEnterpriseId || '',
    });

    const filteredEnterprises = data.farm_id 
        ? enterprises.filter(ent => ent.farm_id == data.farm_id)
        : enterprises;

    const submit = (e) => {
        e.preventDefault();
        post(route('users.store'), {
            onSuccess: () => {
                reset();
                setIsAddingUser(false);
            },
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">{t('user_management')}</h2>
                    <button
                        onClick={() => setIsAddingUser(true)}
                        className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-xl font-black text-sm uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                    >
                        <span>+</span> {t('add_user')}
                    </button>
                </div>
            }
        >
            <Head title={t('users')} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white p-6 shadow-sm sm:rounded-2xl border border-gray-100 overflow-x-auto">
                        <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter mb-4">{t('application_users')}</h3>
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr className="text-left text-xs font-bold uppercase tracking-wider text-gray-500">
                                    <th className="px-4 py-3">{t('name')}</th>
                                    <th className="px-4 py-3">{t('email')}</th>
                                    <th className="px-4 py-3">Ferme / Division</th>
                                    <th className="px-4 py-3">{t('role')}</th>
                                    <th className="px-4 py-3 text-center">{t('actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {users.map(user => (
                                    <tr key={user.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3 font-medium text-gray-900">{user.name}</td>
                                        <td className="px-4 py-3">{user.email}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-col">
                                                <span className="font-bold text-gray-700">{user.farm?.name || '-'}</span>
                                                <span className="text-[10px] text-gray-400 uppercase">{user.enterprise?.name || 'Accès Global Ferme'}</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 capitalize">
                                            <span className={`px-2 py-1 rounded-full text-[10px] font-bold ${
                                                user.role === 'super_admin' ? 'bg-purple-100 text-purple-700' : 
                                                user.role === 'farm_manager' ? 'bg-green-100 text-green-700' :
                                                user.role === 'enterprise_admin' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700'
                                            }`}>
                                                {user.role === 'super_admin' ? t('super_admin') : 
                                                 user.role === 'farm_manager' ? 'Manager Ferme' :
                                                 user.role === 'enterprise_admin' ? 'Admin Division' : t('data_entry_personnel')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {user.role !== 'super_admin' && (
                                                <Link
                                                    href={route('users.destroy', user.id)}
                                                    method="delete"
                                                    as="button"
                                                    onBefore={() => confirm(`Supprimer définitivement le compte de ${user.name} ? Cette action est irréversible.`)}
                                                    className="text-red-600 hover:text-red-900 font-bold transition-colors"
                                                >
                                                    {t('delete')}
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={isAddingUser} onClose={() => setIsAddingUser(false)}>
                <div className="p-8">
                    <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">{t('create_user_account_title')}</h3>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-4">
                            <div>
                                <label htmlFor="user_name" className="block text-sm font-bold text-gray-700 mb-1">{t('full_name')}</label>
                                <input id="user_name" type="text" className="w-full rounded border-gray-300" value={data.name} onChange={e => setData('name', e.target.value)} />
                                {errors.name && <div className="text-red-500 text-xs mt-1">{errors.name}</div>}
                            </div>
                            <div>
                                <label htmlFor="user_email" className="block text-sm font-bold text-gray-700 mb-1">{t('email')}</label>
                                <input id="user_email" type="email" className="w-full rounded border-gray-300" value={data.email} onChange={e => setData('email', e.target.value)} />
                                {errors.email && <div className="text-red-500 text-xs mt-1">{errors.email}</div>}
                            </div>
                            <div>
                                <label htmlFor="user_password" className="block text-sm font-bold text-gray-700 mb-1">{t('temp_password')}</label>
                                <input id="user_password" type="password" title="password" className="w-full rounded border-gray-300" value={data.password} onChange={e => setData('password', e.target.value)} />
                                {errors.password && <div className="text-red-500 text-xs mt-1">{errors.password}</div>}
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label htmlFor="user_farm_id" className="block text-sm font-bold text-gray-700 mb-1">Ferme</label>
                                    <select
                                        id="user_farm_id"
                                        className="w-full rounded border-gray-300"
                                        value={data.farm_id}
                                        onChange={e => setData('farm_id', e.target.value)}
                                    >
                                        <option value="">-- Choisir Ferme --</option>
                                        {farms.map(f => <option key={f.id} value={f.id}>{f.name}</option>)}
                                    </select>
                                    {errors.farm_id && <div className="text-red-500 text-xs mt-1">{errors.farm_id}</div>}
                                </div>
                                <div>
                                    <label htmlFor="user_role" className="block text-sm font-bold text-gray-700 mb-1">Rôle</label>
                                    <select id="user_role" className="w-full rounded border-gray-300" value={data.role} onChange={e => setData('role', e.target.value)}>
                                        <option value="farm_manager">Manager Ferme (Tous accès)</option>
                                        <option value="enterprise_admin">Admin Division</option>
                                        <option value="data_entry">{t('data_entry_personnel')}</option>
                                    </select>
                                    {errors.role && <div className="text-red-500 text-xs mt-1">{errors.role}</div>}
                                </div>
                                <div>
                                    <label htmlFor="user_enterprise_id" className="block text-sm font-bold text-gray-700 mb-1">Division (Optionnel)</label>
                                    <select
                                        id="user_enterprise_id"
                                        className="w-full rounded border-gray-300"
                                        value={data.enterprise_id}
                                        onChange={e => setData('enterprise_id', e.target.value)}
                                        disabled={data.role === 'farm_manager'}
                                    >
                                        <option value="">Accès Global à la Ferme</option>
                                        {filteredEnterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                    </select>
                                    {errors.enterprise_id && <div className="text-red-500 text-xs mt-1">{errors.enterprise_id}</div>}
                                </div>
                            </div>
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsAddingUser(false)}>{t('cancel')}</SecondaryButton>
                            <PrimaryButton disabled={processing}>{t('create_user_button')}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
