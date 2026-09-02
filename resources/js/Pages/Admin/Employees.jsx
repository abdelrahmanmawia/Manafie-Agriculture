import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatNumber } from '@/Helpers/formatNumber';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import { t } from '@/Helpers/i18n';

export default function Employees({ auth, employees, enterprises, selectedEnterpriseId, searchQuery }) {
    const [isAddingEmployee, setIsAddingEmployee] = useState(false);
    const [isEditingEmployee, setIsEditingEmployee] = useState(false);
    const [editingEmployee, setEditingEmployee] = useState(null);
    const [localSearch, setLocalSearch] = useState(searchQuery || '');
    const [confirmingEmployeeDeletion, setConfirmingEmployeeDeletion] = useState(null);
    const { delete: destroy, processing: deleteProcessing } = useForm();

    const { data, setData, post, transform, processing, reset, errors } = useForm({
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
        is_active: true,
        photo: null,
    });

    const submit = (e) => {
        e.preventDefault();
        if (editingEmployee) {
            // PHP only auto-parses multipart/form-data bodies into $_POST/$_FILES for a genuine
            // POST request, never for PUT — a real PUT with a file attached arrives at Laravel
            // completely empty. Route it as POST with a spoofed _method field instead (Inertia's
            // documented workaround for file uploads on put()/patch()); Laravel's method-override
            // middleware still treats it as PUT for routing/authorization.
            transform((data) => ({ ...data, _method: 'put' }));
            post(route('employees.update', editingEmployee.id), {
                onSuccess: () => {
                    reset();
                    setIsEditingEmployee(false);
                    setEditingEmployee(null);
                },
            });
        } else {
            transform((data) => data);
            post(route('employees.store'), {
                onSuccess: () => {
                    reset();
                    setIsAddingEmployee(false);
                },
            });
        }
    };

    const handleEdit = (employee) => {
        setEditingEmployee(employee);
        setData({
            matricule: employee.matricule,
            full_name: employee.full_name,
            cin: employee.cin || '',
            cnss_number: employee.cnss_number || '',
            dob: employee.dob || '',
            hire_date: employee.hire_date || '',
            phone: employee.phone || '',
            address: employee.address || '',
            bank_name: employee.bank_name || '',
            rib: employee.rib || '',
            type: employee.type,
            base_rate: employee.base_rate,
            complement: employee.complement || 0,
            enterprise_id: employee.enterprise_id,
            is_active: employee.is_active,
            photo: null,
        });

        setIsEditingEmployee(true);
    };

    const handleToggleActive = (employee) => {
        router.post(route('employees.toggle-active', employee.id));
    };

    const confirmEmployeeDeletion = (employee) => setConfirmingEmployeeDeletion(employee);
    const closeDeleteModal = () => setConfirmingEmployeeDeletion(null);
    const deleteEmployee = (e) => {
        e.preventDefault();
        destroy(route('employees.destroy', confirmingEmployeeDeletion.id), {
            preserveScroll: true,
            onSuccess: closeDeleteModal,
            onError: closeDeleteModal,
            onFinish: closeDeleteModal,
        });
    };

    const handleFilterChange = (e) => {
        const id = e.target.value;
        router.get(route('employees.index'), { enterprise_id: id, search: localSearch });
    };

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('employees.index'), { enterprise_id: selectedEnterpriseId, search: localSearch });
    };

    const clearSearch = () => {
        setLocalSearch('');
        router.get(route('employees.index'), { enterprise_id: selectedEnterpriseId, search: '' });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">{t('personnel_management')}</h2>
                    <div className="flex gap-4 items-center">
                        {auth.user.role !== 'data_entry' && (
                            <button
                                onClick={() => setIsAddingEmployee(true)}
                                className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-xl font-black text-sm uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
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
                    <div className="bg-white p-6 shadow-sm sm:rounded-2xl border border-gray-100">
                        <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                            <div>
                                <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">
                                    {t('employees_list_title')} {selectedEnterpriseId && `- ${enterprises.find(e => e.id == selectedEnterpriseId)?.name}`}
                                </h3>
                                <p className="text-xs text-gray-400 font-bold uppercase tracking-widest">{employees.length} {t('salaries_recorded')}</p>
                            </div>
                            <div className="flex gap-3 items-center flex-wrap">
                                {enterprises && enterprises.length > 0 && (
                                    <select
                                        className="rounded-xl border-gray-200 bg-gray-50 text-sm px-4 py-2"
                                        value={selectedEnterpriseId || ''}
                                        onChange={handleFilterChange}
                                    >
                                        <option value="">{t('all_fermes')}</option>
                                        {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                    </select>
                                )}
                                <form onSubmit={handleSearch} className="flex gap-2">
                                    <input
                                        type="text"
                                        placeholder="Rechercher..."
                                        value={localSearch}
                                        onChange={(e) => setLocalSearch(e.target.value)}
                                        className="rounded-xl border-gray-200 bg-gray-50 text-sm px-4 py-2 w-64"
                                    />
                                    {localSearch && (
                                        <button
                                            type="button"
                                            onClick={clearSearch}
                                            className="text-gray-500 hover:text-gray-700 font-bold text-xs"
                                        >
                                            ✕
                                        </button>
                                    )}
                                </form>
                            </div>
                        </div>

                        {/* MOBILE CARD VIEW — the 17-column table below is unusable on a phone even with
                            horizontal scroll, so screens below md get a stacked card per employee
                            showing the essentials instead, with the full table reserved for md+. */}
                        <div className="md:hidden space-y-3">
                            {employees.map(emp => {
                                const salNetJ = emp.enterprise?.contract_type === 'avec_contrat'
                                    ? (parseFloat(emp.base_rate) * (1 - 0.0674)) + parseFloat(emp.complement || 0)
                                    : parseFloat(emp.base_rate);

                                return (
                                    <div key={emp.id} className={`p-4 rounded-2xl border border-gray-100 ${!emp.is_active ? 'opacity-50 bg-gray-50' : 'bg-white'} shadow-sm`}>
                                        <div className="flex justify-between items-start mb-2">
                                            <div>
                                                <div className="font-bold text-gray-800">{emp.full_name}</div>
                                                <div className="text-xs text-gray-400">{emp.matricule}{auth.user.role === 'super_admin' && emp.enterprise?.name ? ` • ${emp.enterprise.name}` : ''}</div>
                                            </div>
                                            <span className={`inline-block px-2 py-1 rounded-lg font-black text-[10px] uppercase shrink-0 ${
                                                emp.type === 'persea' ? 'bg-blue-100 text-blue-800' :
                                                emp.type === 'hafila' ? 'bg-green-100 text-green-800' :
                                                'bg-orange-100 text-orange-800'
                                            }`}>
                                                {emp.type}
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-3 gap-2 my-3 text-center">
                                            <div className="bg-gray-50 rounded-lg py-1.5">
                                                <div className="text-[8px] font-bold text-gray-400 uppercase">{t('daily_rate_brut')}</div>
                                                <div className="text-xs font-bold text-gray-600">{formatNumber(emp.base_rate)} DH</div>
                                            </div>
                                            <div className="bg-gray-50 rounded-lg py-1.5">
                                                <div className="text-[8px] font-bold text-gray-400 uppercase">{t('complement')}</div>
                                                <div className="text-xs font-bold text-gray-600">{formatNumber(emp.complement || 0)} DH</div>
                                            </div>
                                            <div className="bg-green-50 rounded-lg py-1.5">
                                                <div className="text-[8px] font-bold text-green-600 uppercase">{t('daily_net')}</div>
                                                <div className="text-xs font-black text-green-700">{formatNumber(salNetJ)} DH</div>
                                            </div>
                                        </div>
                                        <div className="text-xs text-gray-500 space-y-0.5 mb-3">
                                            {emp.cin && <div>{t('cin')}: {emp.cin}</div>}
                                            {emp.phone && <div>{t('phone')}: {emp.phone}</div>}
                                            {emp.hire_date && <div>{t('hire_date')}: {emp.hire_date}</div>}
                                        </div>
                                        <div className="flex items-center justify-between pt-2 border-t border-gray-100">
                                            <label className="relative inline-flex items-center cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={emp.is_active}
                                                    onChange={() => handleToggleActive(emp)}
                                                    className="sr-only peer"
                                                />
                                                <div className="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600"></div>
                                            </label>
                                            {auth.user.role !== 'data_entry' && (
                                                <div className="flex items-center justify-end gap-4">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleEdit(emp)}
                                                        title="Modifier"
                                                        className="text-gray-400 hover:text-gray-700 transition-colors"
                                                    >
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                        </svg>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => confirmEmployeeDeletion(emp)}
                                                        title="Supprimer"
                                                        className="text-gray-400 hover:text-red-600 transition-colors"
                                                    >
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                            {employees.length === 0 && (
                                <div className="py-12 text-center text-gray-400 italic">{t('no_employee_found')}</div>
                            )}
                        </div>

                        <div className="hidden md:block overflow-x-auto -mx-6 sm:mx-0">
                            <div className="inline-block min-w-full align-middle">
                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr className="text-left text-xs font-bold uppercase tracking-wider text-gray-500">
                                    <th className="px-4 py-3">{t('matricule')}</th>
                                    <th className="px-4 py-3">{t('full_name')}</th>
                                    {auth.user.role === 'super_admin' && <th className="px-4 py-3 text-blue-600">{t('fermes')}</th>}
                                    <th className="px-4 py-3">{t('cin')}</th>
                                    <th className="px-4 py-3">{t('cnss')}</th>
                                    <th className="px-4 py-3">{t('phone')}</th>
                                    <th className="px-4 py-3">{t('address')}</th>
                                    <th className="px-4 py-3">{t('bank')}</th>
                                    <th className="px-4 py-3">{t('rib')}</th>
                                    <th className="px-4 py-3">{t('dob')}</th>
                                    <th className="px-4 py-3">{t('hire_date')}</th>
                                    <th className="px-4 py-3">{t('type')}</th>
                                    <th className="px-4 py-3 text-right">{t('daily_rate_brut')}</th>
                                    <th className="px-4 py-3 text-right">{t('complement')}</th>
                                    <th className="px-4 py-3 text-right text-green-600">{t('daily_net')}</th>
                                    <th className="px-4 py-3 text-center">{t('status')}</th>
                                    <th className="px-4 py-3 text-center">{t('actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {employees.map(emp => {
                                    const salNetJ = emp.enterprise?.contract_type === 'avec_contrat'
                                        ? (parseFloat(emp.base_rate) * (1 - 0.0674)) + parseFloat(emp.complement || 0)
                                        : parseFloat(emp.base_rate);

                                    return (
                                        <tr key={emp.id} className={`hover:bg-gray-50 transition-colors ${!emp.is_active ? 'opacity-50 bg-gray-100' : ''}`}>
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
                                            <td className="px-4 py-3">{emp.phone || '-'}</td>
                                            <td className="px-4 py-3 text-xs text-gray-600 max-w-[150px] truncate" title={emp.address}>{emp.address || '-'}</td>
                                            <td className="px-4 py-3">{emp.bank_name || '-'}</td>
                                            <td className="px-4 py-3 text-xs font-mono">{emp.rib || '-'}</td>
                                            <td className="px-4 py-3">{emp.dob || '-'}</td>
                                            <td className="px-4 py-3">{emp.hire_date || '-'}</td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-block px-2 py-1 rounded-lg font-black text-[10px] uppercase ${
                                                    emp.type === 'persea' ? 'bg-blue-100 text-blue-800' :
                                                    emp.type === 'hafila' ? 'bg-green-100 text-green-800' :
                                                    'bg-orange-100 text-orange-800'
                                                }`}>
                                                    {emp.type}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right font-medium text-gray-400">{formatNumber(emp.base_rate)} DH</td>
                                            <td className="px-4 py-3 text-right font-medium text-gray-400">{formatNumber(emp.complement || 0)} DH</td>
                                            <td className="px-4 py-3 text-right font-black text-green-700 bg-green-50/30">
                                                {formatNumber(salNetJ)} <small className="text-[10px]">DH</small>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <label className="relative inline-flex items-center cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={emp.is_active}
                                                        onChange={() => handleToggleActive(emp)}
                                                        className="sr-only peer"
                                                    />
                                                    <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                                                </label>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                {auth.user.role !== 'data_entry' && (
                                                    <div className="flex items-center justify-center gap-3">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleEdit(emp)}
                                                            title="Modifier"
                                                            className="text-gray-400 hover:text-gray-700 transition-colors"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => confirmEmployeeDeletion(emp)}
                                                            title="Supprimer"
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
                                    );
                                })}
                                {employees.length === 0 && (
                                    <tr>
                                        <td colSpan={auth.user.role === 'super_admin' ? 17 : 16} className="px-4 py-12 text-center text-gray-400 italic">
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

            {/* ADD/EDIT EMPLOYEE MODAL */}
            <Modal show={isAddingEmployee || isEditingEmployee} onClose={() => { setIsAddingEmployee(false); setIsEditingEmployee(false); setEditingEmployee(null); reset(); }} maxWidth="4xl">
                <div className="p-8">
                    <h3 className="text-2xl font-black mb-6 text-gray-900 border-b pb-4 tracking-tighter">
                        {isEditingEmployee ? 'Modifier Employé' : t('register_new_employee')}
                    </h3>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            {/* Identity */}
                            <div>
                                <label htmlFor="emp_matricule" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('matricule_label')}</label>
                                <input id="emp_matricule" type="text" className="w-full rounded-lg border-gray-200" value={data.matricule} onChange={e => setData('matricule', e.target.value)} />
                                {errors.matricule && <div className="text-red-500 text-xs mt-1">{errors.matricule}</div>}
                            </div>
                            <div>
                                <label htmlFor="emp_full_name" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('full_name_label')}</label>
                                <input id="emp_full_name" type="text" className="w-full rounded-lg border-gray-200" value={data.full_name} onChange={e => setData('full_name', e.target.value)} />
                                {errors.full_name && <div className="text-red-500 text-xs mt-1">{errors.full_name}</div>}
                            </div>
                            <div>
                                <label htmlFor="emp_cin" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('cin')}</label>
                                <input id="emp_cin" type="text" className="w-full rounded-lg border-gray-200" value={data.cin} onChange={e => setData('cin', e.target.value)} />
                            </div>

                            {/* Dates & CNSS */}
                            <div>
                                <label htmlFor="emp_cnss" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('cnss_number_label')}</label>
                                <input id="emp_cnss" type="text" className="w-full rounded-lg border-gray-200" value={data.cnss_number} onChange={e => setData('cnss_number', e.target.value)} />
                            </div>
                            <div>
                                <label htmlFor="emp_dob" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('dob_label')}</label>
                                <input id="emp_dob" type="date" className="w-full rounded-lg border-gray-200" value={data.dob} onChange={e => setData('dob', e.target.value)} />
                            </div>
                            <div>
                                <label htmlFor="emp_hire_date" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('hire_date_label')}</label>
                                <input id="emp_hire_date" type="date" className="w-full rounded-lg border-gray-200" value={data.hire_date} onChange={e => setData('hire_date', e.target.value)} />
                            </div>

                            {/* Contact */}
                            <div>
                                <label htmlFor="emp_phone" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('phone_label')}</label>
                                <input id="emp_phone" type="text" className="w-full rounded-lg border-gray-200" value={data.phone} onChange={e => setData('phone', e.target.value)} />
                            </div>
                            <div className="md:col-span-2">
                                <label htmlFor="emp_address" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('address')}</label>
                                <input id="emp_address" type="text" className="w-full rounded-lg border-gray-200" value={data.address} onChange={e => setData('address', e.target.value)} />
                            </div>

                            {/* Bank */}
                            <div>
                                <label htmlFor="emp_bank_name" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('bank')}</label>
                                <input id="emp_bank_name" type="text" className="w-full rounded-lg border-gray-200" value={data.bank_name} onChange={e => setData('bank_name', e.target.value)} />
                            </div>
                            <div className="md:col-span-2">
                                <label htmlFor="emp_rib" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('rib')}</label>
                                <input id="emp_rib" type="text" className="w-full rounded-lg border-gray-200" value={data.rib} onChange={e => setData('rib', e.target.value)} />
                            </div>

                            {/* Enterprise Assignment */}
                            {(auth.user.role === 'super_admin' || auth.user.role === 'farm_manager') && (
                                <div>
                                    <label htmlFor="emp_enterprise_id" className="block text-xs font-black uppercase text-blue-600 mb-1">{t('assign_to_ferme')}</label>
                                    <select
                                        id="emp_enterprise_id"
                                        className="w-full rounded-lg border-blue-200 bg-blue-50"
                                        value={data.enterprise_id}
                                        onChange={e => setData('enterprise_id', e.target.value)}
                                    >
                                        <option value="">Sélectionner une division</option>
                                        {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                    </select>
                                    {errors.enterprise_id && <div className="text-red-500 text-xs mt-1">{errors.enterprise_id}</div>}
                                </div>
                            )}

                            {/* Payroll Profile */}
                            <div>
                                <label htmlFor="emp_type" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('type')}</label>
                                <select
                                    id="emp_type"
                                    className="w-full rounded-lg border-gray-200"
                                    value={data.type}
                                    onChange={e => setData('type', e.target.value)}
                                >
                                    <option value="persea">PERSEA</option>
                                    <option value="hafila">HAFILA</option>
                                    <option value="interim">INTERIM</option>
                                </select>
                                {errors.type && <div className="text-red-500 text-xs mt-1">{errors.type}</div>}
                            </div>
                            <div>
                                <label id="emp_contract_type_label" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('contract_type_auto')}</label>
                                <div aria-labelledby="emp_contract_type_label" className="bg-gray-100 p-2.5 rounded-lg text-gray-500 font-black uppercase text-[10px]">
                                    {selectedEnterpriseId ? (enterprises.find(e => e.id == selectedEnterpriseId)?.contract_type.replace('_', ' ')) : t('managed_by_ferme')}
                                </div>
                            </div>
                            <div>
                                <label htmlFor="emp_base_rate" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('daily_rate_dh')}</label>
                                <input id="emp_base_rate" type="number" step="0.01" className="w-full rounded-lg border-gray-200 font-bold text-blue-700" value={data.base_rate} onChange={e => setData('base_rate', e.target.value)} />
                                {errors.base_rate && <div className="text-red-500 text-xs mt-1">{errors.base_rate}</div>}
                            </div>
                            <div>
                                <label htmlFor="emp_complement" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('complement_prime')}</label>
                                <input id="emp_complement" type="number" step="0.01" className="w-full rounded-lg border-gray-200 font-bold text-green-700" value={data.complement} onChange={e => setData('complement', e.target.value)} />
                                {errors.complement && <div className="text-red-500 text-xs mt-1">{errors.complement}</div>}
                            </div>

                            {/* Status (Edit Only) */}
                            {isEditingEmployee && (
                                <div>
                                    <label htmlFor="emp_is_active" className="block text-xs font-black uppercase text-gray-400 mb-1">Statut</label>
                                    <select
                                        id="emp_is_active"
                                        className="w-full rounded-lg border-gray-200"
                                        value={data.is_active ? 'true' : 'false'}
                                        onChange={e => setData('is_active', e.target.value === 'true')}
                                    >
                                        <option value="true">Actif</option>
                                        <option value="false">Inactif</option>
                                    </select>
                                </div>
                            )}

                            {/* Badge photo (Edit Only) — optional, printed on the pointage badge alongside the QR code */}
                            {isEditingEmployee && (
                                <div className="md:col-span-3 flex items-center gap-4 border-t pt-4 mt-2">
                                    {editingEmployee?.photo_path && (
                                        <img
                                            src={`/storage/${editingEmployee.photo_path}`}
                                            alt=""
                                            className="w-14 h-14 rounded-lg object-cover border border-gray-200"
                                        />
                                    )}
                                    <div className="flex-1">
                                        <label htmlFor="emp_photo" className="block text-xs font-black uppercase text-gray-400 mb-1">Photo du Badge (optionnel)</label>
                                        <input
                                            id="emp_photo"
                                            type="file"
                                            accept="image/*"
                                            className="w-full text-xs"
                                            onChange={e => setData('photo', e.target.files[0] || null)}
                                        />
                                        {errors.photo && <div className="text-red-500 text-xs mt-1">{errors.photo}</div>}
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => { setIsAddingEmployee(false); setIsEditingEmployee(false); setEditingEmployee(null); reset(); }}>{t('cancel')}</SecondaryButton>
                            <PrimaryButton disabled={processing}>{isEditingEmployee ? 'Mettre à jour' : t('save_employee')}</PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>

            {/* DELETE EMPLOYEE MODAL */}
            <Modal show={confirmingEmployeeDeletion !== null} onClose={closeDeleteModal}>
                <form onSubmit={deleteEmployee} className="p-8">
                    <div className="flex items-center gap-4 mb-4">
                        <div className="h-12 w-12 bg-red-100 rounded-full flex items-center justify-center">
                            <svg className="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter">
                            Supprimer l'employé
                        </h2>
                    </div>
                    <p className="text-gray-600 mb-6">
                        Êtes-vous sûr de vouloir supprimer définitivement <strong>{confirmingEmployeeDeletion?.full_name}</strong> ? Cette action est irréversible.
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={closeDeleteModal}>Annuler</SecondaryButton>
                        <DangerButton className="rounded-xl" disabled={deleteProcessing}>
                            {deleteProcessing ? 'Suppression...' : "Supprimer l'Employé"}
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
