import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';

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
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Personnel Management</h2>
                    <div className="flex gap-4 items-center">
                        {auth.user.role === 'super_admin' && (
                            <select 
                                className="rounded-lg border-gray-300 text-sm"
                                value={selectedEnterpriseId || ''}
                                onChange={handleFilterChange}
                            >
                                <option value="">-- All Fermes --</option>
                                {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                            </select>
                        )}
                        {auth.user.role !== 'data_entry' && (
                            <button 
                                onClick={() => setIsAddingEmployee(true)}
                                className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                            >
                                <span>+</span> Add Employee
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Employees" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    
                    {/* EMPLOYEES LIST */}
                    <div className="bg-white p-6 shadow sm:rounded-lg overflow-x-auto">
                        <div className="flex justify-between items-center mb-4">
                            <h3 className="text-lg font-bold text-gray-800">
                                Liste du Personnel {selectedEnterpriseId && `- ${enterprises.find(e => e.id == selectedEnterpriseId)?.name}`}
                            </h3>
                            <p className="text-sm text-gray-500 font-bold">{employees.length} Employees found</p>
                        </div>
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr className="text-left text-xs font-bold uppercase tracking-wider text-gray-500">
                                    <th className="px-4 py-3">Matricule</th>
                                    <th className="px-4 py-3">Nom & Prénom</th>
                                    {auth.user.role === 'super_admin' && <th className="px-4 py-3 text-blue-600">Ferme</th>}
                                    <th className="px-4 py-3">CIN</th>
                                    <th className="px-4 py-3">CNSS</th>
                                    <th className="px-4 py-3">Type</th>
                                    <th className="px-4 py-3 text-right">Taux</th>
                                    <th className="px-4 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {employees.map(emp => (
                                    <tr key={emp.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-3 font-medium text-gray-900">{emp.matricule}</td>
                                        <td className="px-4 py-3">{emp.full_name}</td>
                                        {auth.user.role === 'super_admin' && (
                                            <td className="px-4 py-3 font-bold text-blue-600">{emp.enterprise?.name || 'N/A'}</td>
                                        )}
                                        <td className="px-4 py-3">{emp.cin || '-'}</td>
                                        <td className="px-4 py-3">{emp.cnss_number || '-'}</td>
                                        <td className="px-4 py-3 capitalize">
                                            <span className={`px-2 py-1 rounded-full text-[10px] font-bold ${emp.type === 'hafila' ? 'bg-orange-100 text-orange-700' : 'bg-green-100 text-green-700'}`}>
                                                {emp.type}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right font-bold text-blue-600">{emp.base_rate} DH</td>
                                        <td className="px-4 py-3 text-center">
                                            {auth.user.role !== 'data_entry' && (
                                                <Link 
                                                    href={route('employees.destroy', emp.id)} 
                                                    method="delete" 
                                                    as="button"
                                                    className="text-red-600 hover:text-red-900 font-bold transition-colors"
                                                >
                                                    Supprimer
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {employees.length === 0 && (
                                    <tr>
                                        <td colSpan={auth.user.role === 'super_admin' ? 8 : 7} className="px-4 py-8 text-center text-gray-500 italic">
                                            No employees found for this selection.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* ADD EMPLOYEE MODAL */}
            <Modal show={isAddingEmployee} onClose={() => setIsAddingEmployee(false)} maxWidth="4xl">
                <div className="p-8">
                    <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">Register New Employee</h3>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            {/* Identity */}
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">Matricule *</label>
                                <input type="text" className="w-full rounded border-gray-300" value={data.matricule} onChange={e => setData('matricule', e.target.value)} />
                                {errors.matricule && <div className="text-red-500 text-xs mt-1">{errors.matricule}</div>}
                            </div>
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">Nom & Prénom *</label>
                                <input type="text" className="w-full rounded border-gray-300" value={data.full_name} onChange={e => setData('full_name', e.target.value)} />
                                {errors.full_name && <div className="text-red-500 text-xs mt-1">{errors.full_name}</div>}
                            </div>
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">CIN</label>
                                <input type="text" className="w-full rounded border-gray-300" value={data.cin} onChange={e => setData('cin', e.target.value)} />
                            </div>

                            {/* Dates & CNSS */}
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">N° CNSS</label>
                                <input type="text" className="w-full rounded border-gray-300" value={data.cnss_number} onChange={e => setData('cnss_number', e.target.value)} />
                            </div>
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">Date de Naissance</label>
                                <input type="date" className="w-full rounded border-gray-300" value={data.dob} onChange={e => setData('dob', e.target.value)} />
                            </div>
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">Date d'Embauche</label>
                                <input type="date" className="w-full rounded border-gray-300" value={data.hire_date} onChange={e => setData('hire_date', e.target.value)} />
                            </div>

                            {/* Contact */}
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">GSM / Téléphone</label>
                                <input type="text" className="w-full rounded border-gray-300" value={data.phone} onChange={e => setData('phone', e.target.value)} />
                            </div>
                            <div className="md:col-span-2">
                                <label className="block text-sm font-bold text-gray-700 mb-1">Adresse</label>
                                <input type="text" className="w-full rounded border-gray-300" value={data.address} onChange={e => setData('address', e.target.value)} />
                            </div>

                            {/* Bank */}
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">Banque</label>
                                <input type="text" className="w-full rounded border-gray-300" value={data.bank_name} onChange={e => setData('bank_name', e.target.value)} />
                            </div>
                            <div className="md:col-span-2">
                                <label className="block text-sm font-bold text-gray-700 mb-1">RIB (24 chiffres)</label>
                                <input type="text" className="w-full rounded border-gray-300" value={data.rib} onChange={e => setData('rib', e.target.value)} />
                            </div>

                            {/* Ferme Assignment (Super Admin Only) */}
                            {auth.user.role === 'super_admin' && (
                                <div>
                                    <label className="block text-sm font-bold text-blue-700 mb-1">Assign to Ferme *</label>
                                    <select 
                                        className="w-full rounded border-blue-300 bg-blue-50" 
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
                                <label className="block text-sm font-bold text-gray-700 mb-1">Type de Contrat (Auto)</label>
                                <div className="bg-gray-100 p-2 rounded text-gray-600 font-bold uppercase text-xs">
                                    {selectedEnterpriseId ? (enterprises.find(e => e.id == selectedEnterpriseId)?.contract_type.replace('_', ' ')) : 'Géré par la Ferme'}
                                </div>
                            </div>
                            <div>
                                <label className="block text-sm font-bold text-gray-700 mb-1">Taux Journalier (DH) *</label>
                                <input type="number" step="0.01" className="w-full rounded border-gray-300 font-bold text-blue-700" value={data.base_rate} onChange={e => setData('base_rate', e.target.value)} />
                            </div>
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsAddingEmployee(false)}>
                                Cancel
                            </SecondaryButton>
                            <PrimaryButton disabled={processing}>
                                {processing ? 'Enregistrement...' : 'Enregistrer le Salarié'}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
