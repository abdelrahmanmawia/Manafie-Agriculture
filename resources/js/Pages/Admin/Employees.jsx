import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatNumber } from '@/Helpers/formatNumber';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { t } from '@/Helpers/i18n';

export default function Employees({ auth, employees, enterprises, selectedEnterpriseId }) {
    const [isAddingEmployee, setIsAddingEmployee] = useState(false);

    const { data, setData, post, processing, reset, errors } = useForm({
        matricule: '',
        full_name: '',
        cin: '',
        cnss_number: '',
        dob: '',
        hire_date: '',
        phone: '',
        address: '',
        bank_name: '',
        rib: '',
        type: 'persea',
        base_rate: '',
        complement: 0,
        enterprise_id: selectedEnterpriseId || (enterprises?.[0]?.id || ''),
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('employees.store'), {
            onSuccess: () => {
                reset();
                setIsAddingEmployee(false);
            },
        });
    };

    const handleFilterChange = (e) => {
        const id = e.target.value;
        router.get(route('employees.index'), { enterprise_id: id });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">{t('personnel_management')}</h2>
                    <div className="flex gap-4 items-center">
                        {auth.user.role === 'super_admin' && (
                            <select
                                className="rounded-lg border-gray-300 text-sm"
                                value={selectedEnterpriseId || ''}
                                onChange={handleFilterChange}
                            >
                                <option value="">{t('all_fermes')}</option>
                                {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                            </select>
                        )}
                        {auth.user.role !== 'data_entry' && (
                            <button
                                onClick={() => setIsAddingEmployee(true)}
                                className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                            >
                                <span>+</span> {t('add_employee_btn')}
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={t('employees')} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                    {/* EMPLOYEES LIST */}
                    <div className="bg-white p-6 shadow sm:rounded-lg">
                        <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                            <div>
                                <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">
                                    {t('employees_list_title')} {selectedEnterpriseId && `- ${enterprises.find(e => e.id == selectedEnterpriseId)?.name}`}
                                </h3>
                                <p className="text-xs text-gray-400 font-bold uppercase tracking-widest">{employees.length} {t('salaries_recorded')}</p>
                            </div>
                        </div>

                        <div className="overflow-x-auto -mx-6 sm:mx-0">
                            <div className="inline-block min-w-full align-middle">
                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr className="text-left text-xs font-bold uppercase tracking-wider text-gray-500">
                                    <th className="px-4 py-3">{t('matricule')}</th>
                                    <th className="px-4 py-3">{t('full_name')}</th>
                                    {auth.user.role === 'super_admin' && <th className="px-4 py-3 text-blue-600">{t('fermes')}</th>}
                                    <th className="px-4 py-3">{t('cin')}</th>
                                    <th className="px-4 py-3">{t('cnss')}</th>
                                    <th className="px-4 py-3 text-right">{t('daily_rate_brut')}</th>
                                    <th className="px-4 py-3 text-right text-green-600">{t('daily_net')}</th>
                                    <th className="px-4 py-3 text-center">{t('actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {employees.map(emp => {
                                    const salNetJ = emp.enterprise?.contract_type === 'avec_contrat'
                                        ? (parseFloat(emp.base_rate) * (1 - 0.0674)) + parseFloat(emp.complement || 0)
                                        : parseFloat(emp.base_rate);

                                    return (
                                        <tr key={emp.id} className="hover:bg-gray-50 transition-colors">
                                            <td className="px-4 py-3 font-medium text-gray-900">{emp.matricule}</td>
                                            <td className="px-4 py-3">
                                                <div className="font-bold text-gray-800">{emp.full_name}</div>
                                                <div className="text-[9px] text-gray-400 uppercase tracking-widest">{emp.type}</div>
                                            </td>
                                            {auth.user.role === 'super_admin' && (
                                                <td className="px-4 py-3 font-bold text-blue-600 text-xs">{emp.enterprise?.name || 'N/A'}</td>
                                            )}
                                            <td className="px-4 py-3">{emp.cin || '-'}</td>
                                            <td className="px-4 py-3">{emp.cnss_number || '-'}</td>
                                            <td className="px-4 py-3 text-right font-medium text-gray-400">{formatNumber(emp.base_rate)} DH</td>
                                            <td className="px-4 py-3 text-right font-black text-green-700 bg-green-50/30">
                                                {formatNumber(salNetJ)} <small className="text-[10px]">DH</small>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {auth.user.role !== 'data_entry' && (
                                                    <Link
                                                        href={route('employees.destroy', emp.id)}
                                                        method="delete"
                                                        as="button"
                                                        className="text-red-600 hover:text-red-900 font-bold text-xs transition-colors uppercase"
                                                    >
                                                        {t('delete')}
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {employees.length === 0 && (
                                    <tr>
                                        <td colSpan={auth.user.role === 'super_admin' ? 8 : 7} className="px-4 py-12 text-center text-gray-400 italic">
                                            {t('no_employee_found')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* ADD EMPLOYEE MODAL */}
            <Modal show={isAddingEmployee} onClose={() => setIsAddingEmployee(false)} maxWidth="4xl">
                <div className="p-8">
                    <h3 className="text-2xl font-black mb-6 text-gray-900 border-b pb-4 tracking-tighter">{t('register_new_employee')}</h3>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            {/* Identity */}
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('matricule_label')}</label>
                                <input type="text" className="w-full rounded-lg border-gray-200" value={data.matricule} onChange={e => setData('matricule', e.target.value)} />
                                {errors.matricule && <div className="text-red-500 text-xs mt-1">{errors.matricule}</div>}
                            </div>
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('full_name_label')}</label>
                                <input type="text" className="w-full rounded-lg border-gray-200" value={data.full_name} onChange={e => setData('full_name', e.target.value)} />
                                {errors.full_name && <div className="text-red-500 text-xs mt-1">{errors.full_name}</div>}
                            </div>
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('cin')}</label>
                                <input type="text" className="w-full rounded-lg border-gray-200" value={data.cin} onChange={e => setData('cin', e.target.value)} />
                            </div>

                            {/* Dates & CNSS */}
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('cnss_number_label')}</label>
                                <input type="text" className="w-full rounded-lg border-gray-200" value={data.cnss_number} onChange={e => setData('cnss_number', e.target.value)} />
                            </div>
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('dob_label')}</label>
                                <input type="date" className="w-full rounded-lg border-gray-200" value={data.dob} onChange={e => setData('dob', e.target.value)} />
                            </div>
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('hire_date_label')}</label>
                                <input type="date" className="w-full rounded-lg border-gray-200" value={data.hire_date} onChange={e => setData('hire_date', e.target.value)} />
                            </div>

                            {/* Contact */}
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('phone_label')}</label>
                                <input type="text" className="w-full rounded-lg border-gray-200" value={data.phone} onChange={e => setData('phone', e.target.value)} />
                            </div>
                            <div className="md:col-span-2">
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('address')}</label>
                                <input type="text" className="w-full rounded-lg border-gray-200" value={data.address} onChange={e => setData('address', e.target.value)} />
                            </div>

                            {/* Bank */}
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('bank')}</label>
                                <input type="text" className="w-full rounded-lg border-gray-200" value={data.bank_name} onChange={e => setData('bank_name', e.target.value)} />
                            </div>
                            <div className="md:col-span-2">
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('rib')}</label>
                                <input type="text" className="w-full rounded-lg border-gray-200" value={data.rib} onChange={e => setData('rib', e.target.value)} />
                            </div>

                            {/* Ferme Assignment (Super Admin Only) */}
                            {auth.user.role === 'super_admin' && (
                                <div>
                                    <label className="block text-xs font-black uppercase text-blue-600 mb-1">{t('assign_to_ferme')}</label>
                                    <select
                                        className="w-full rounded-lg border-blue-200 bg-blue-50"
                                        value={data.enterprise_id}
                                        onChange={e => setData('enterprise_id', e.target.value)}
                                    >
                                        {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                    </select>
                                    {errors.enterprise_id && <div className="text-red-500 text-xs mt-1">{errors.enterprise_id}</div>}
                                </div>
                            )}

                            {/* Payroll Profile */}
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('contract_type_auto')}</label>
                                <div className="bg-gray-100 p-2.5 rounded-lg text-gray-500 font-black uppercase text-[10px]">
                                    {selectedEnterpriseId ? (enterprises.find(e => e.id == selectedEnterpriseId)?.contract_type.replace('_', ' ')) : t('managed_by_ferme')}
                                </div>
                            </div>
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('daily_rate_dh')}</label>
                                <input type="number" step="0.01" className="w-full rounded-lg border-gray-200 font-bold text-blue-700" value={data.base_rate} onChange={e => setData('base_rate', e.target.value)} />
                            </div>
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">{t('complement_prime')}</label>
                                <input type="number" step="0.01" className="w-full rounded-lg border-gray-200 font-bold text-green-700" value={data.complement} onChange={e => setData('complement', e.target.value)} />
                            </div>
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsAddingEmployee(false)}>{t('cancel')}</SecondaryButton>
                            <PrimaryButton disabled={processing}>{t('save_employee')}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
