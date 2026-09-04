import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import { t } from '@/Helpers/i18n';

export default function Users({ auth, users, farms = [], enterprises = [], selectedFarmId, selectedEnterpriseId }) {
    const [isAddingUser, setIsAddingUser] = useState(false);
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(null);
    const { delete: destroy, processing: deleteProcessing } = useForm();

    const confirmUserDeletion = (user) => setConfirmingUserDeletion(user);
    const closeDeleteModal = () => setConfirmingUserDeletion(null);
    const deleteUser = (e) => {
        e.preventDefault();
        destroy(route('users.destroy', confirmingUserDeletion.id), {
            preserveScroll: true,
            onSuccess: closeDeleteModal,
            onError: closeDeleteModal,
            onFinish: closeDeleteModal,
        });
    };

    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        email: '',
        password: '',
        role: 'data_entry',
        farm_id: selectedFarmId || (farms[0]?.id || ''),
        enterprise_id: selectedEnterpriseId || '',
        // Both start unchecked — a new data_entry account should fail closed until someone
        // deliberately grants it a domain, not silently see everything by default.
        can_access_pointage: false,
        can_access_stock: false,
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
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">{t('user_management')}</h2>
                    <button
                        onClick={() => setIsAddingUser(true)}
                        className="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-xl font-black text-sm uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
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
                                                user.role === 'farm_manager' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'
                                            }`}>
                                                {user.role === 'super_admin' ? t('super_admin') :
                                                 user.role === 'farm_manager' ? 'Manager Ferme' : t('data_entry_personnel')}
                                            </span>
                                            {user.role === 'data_entry' && (
                                                <div className="flex gap-1 mt-1">
                                                    {user.can_access_pointage && (
                                                        <span className="px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 text-[9px] font-bold uppercase">Pointage</span>
                                                    )}
                                                    {user.can_access_stock && (
                                                        <span className="px-1.5 py-0.5 rounded bg-success-50 text-success-700 text-[9px] font-bold uppercase">Stock</span>
                                                    )}
                                                    {!user.can_access_pointage && !user.can_access_stock && (
                                                        <span className="px-1.5 py-0.5 rounded bg-danger-50 text-danger-700 text-[9px] font-bold uppercase">Aucun accès</span>
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {user.role !== 'super_admin' && (
                                                <div className="flex items-center justify-center gap-2">
                                                    <button
                                                        type="button"
                                                        title="Supprimer"
                                                        onClick={() => confirmUserDeletion(user)}
                                                        className="text-gray-400 hover:text-red-600 transition-colors"
                                                    >
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </div>
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

                            {data.role === 'data_entry' && (
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 mb-1">Accès</label>
                                    <div className="flex gap-4">
                                        <label className="flex items-center gap-2 cursor-pointer select-none">
                                            <input
                                                type="checkbox"
                                                className="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                                                checked={data.can_access_pointage}
                                                onChange={e => setData('can_access_pointage', e.target.checked)}
                                            />
                                            <span className="text-sm font-bold text-gray-700">Accès Pointage</span>
                                        </label>
                                        <label className="flex items-center gap-2 cursor-pointer select-none">
                                            <input
                                                type="checkbox"
                                                className="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                                                checked={data.can_access_stock}
                                                onChange={e => setData('can_access_stock', e.target.checked)}
                                            />
                                            <span className="text-sm font-bold text-gray-700">Accès Stock</span>
                                        </label>
                                    </div>
                                    {!data.can_access_pointage && !data.can_access_stock && (
                                        <p className="text-[11px] font-bold text-amber-600 mt-2 uppercase tracking-wide">
                                            Aucun accès sélectionné — ce compte ne pourra rien voir.
                                        </p>
                                    )}
                                    {(errors.can_access_pointage || errors.can_access_stock) && (
                                        <div className="text-red-500 text-xs mt-1">{errors.can_access_pointage || errors.can_access_stock}</div>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsAddingUser(false)}>{t('cancel')}</SecondaryButton>
                            <PrimaryButton disabled={processing}>{t('create_user_button')}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>

            <Modal show={confirmingUserDeletion !== null} onClose={closeDeleteModal}>
                <form onSubmit={deleteUser} className="p-8">
                    <div className="flex items-center gap-4 mb-4">
                        <div className="h-12 w-12 bg-red-100 rounded-full flex items-center justify-center">
                            <svg className="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter">
                            Supprimer l'utilisateur
                        </h2>
                    </div>
                    <p className="text-gray-600 mb-6">
                        Supprimer définitivement le compte de <strong>{confirmingUserDeletion?.name}</strong> ? Cette action est irréversible.
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={closeDeleteModal}>Annuler</SecondaryButton>
                        <DangerButton className="rounded-xl" disabled={deleteProcessing}>
                            {deleteProcessing ? 'Suppression...' : 'Supprimer'}
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
