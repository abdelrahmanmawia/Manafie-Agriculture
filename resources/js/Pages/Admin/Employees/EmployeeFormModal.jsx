import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { t } from '@/Helpers/i18n';

const emptyForm = {
    matricule: '', full_name: '', cin: '', cnss_number: '', dob: '', hire_date: '', phone: '',
    address: '', bank_name: '', rib: '', base_rate: '', complement: 0, enterprise_id: '',
    residence_location_id: '', is_active: true, photo: null,
};

// Shared create/edit form — used by both Admin/Employees.jsx (the list) and
// Admin/Employees/Show.jsx (the detail page), so a field added here doesn't need updating in
// two places. `employee` null means "create"; an object means "edit that employee".
export default function EmployeeFormModal({ show, onClose, employee, enterprises, transportLocations, defaultEnterpriseId, onSaved }) {
    const isEditing = !!employee;
    const { data, setData, post, transform, processing, reset, errors, clearErrors } = useForm(emptyForm);

    useEffect(() => {
        if (!show) return;
        clearErrors();
        if (employee) {
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
                base_rate: employee.base_rate,
                complement: employee.complement || 0,
                enterprise_id: employee.enterprise_id,
                residence_location_id: employee.residence_location_id || '',
                is_active: employee.is_active,
                photo: null,
            });
        } else {
            reset();
            setData('enterprise_id', defaultEnterpriseId || (enterprises?.[0]?.id || ''));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, employee]);

    const handleClose = () => {
        reset();
        onClose();
    };

    const submit = (e) => {
        e.preventDefault();
        if (isEditing) {
            // PHP only auto-parses multipart/form-data bodies into $_POST/$_FILES for a genuine
            // POST request, never for PUT — a real PUT with a file attached arrives at Laravel
            // completely empty. Route it as POST with a spoofed _method field instead (Inertia's
            // documented workaround for file uploads on put()/patch()); Laravel's method-override
            // middleware still treats it as PUT for routing/authorization.
            transform((formData) => ({ ...formData, _method: 'put' }));
            post(route('employees.update', employee.id), {
                onSuccess: () => { reset(); onSaved?.(); onClose(); },
            });
        } else {
            transform((formData) => formData);
            post(route('employees.store'), {
                onSuccess: () => { reset(); onSaved?.(); onClose(); },
            });
        }
    };

    // The form's own selected division, not any page-level filter — showing the wrong
    // enterprise's contract type here would be misleading when editing an employee from a
    // different division than whatever the list happens to be filtered to.
    const selectedEnterprise = enterprises?.find(e => e.id == data.enterprise_id);

    return (
        <Modal show={show} onClose={handleClose} maxWidth="4xl">
            <div className="p-8">
                <h3 className="text-2xl font-black mb-6 text-gray-900 border-b pb-4 tracking-tighter">
                    {isEditing ? 'Modifier Employé' : t('register_new_employee')}
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

                        {/* Enterprise Assignment — shown whenever there's more than one division to pick
                            from; EmployeeController::index() only ever populates `enterprises` for
                            super_admin/farm_manager and a farm-scoped (no fixed enterprise_id) data_entry. */}
                        {enterprises && enterprises.length > 0 && (
                            <div>
                                <label htmlFor="emp_enterprise_id" className="block text-xs font-black uppercase text-primary-600 mb-1">{t('assign_to_ferme')}</label>
                                <select
                                    id="emp_enterprise_id"
                                    className="w-full rounded-lg border-primary-200 bg-primary-50"
                                    value={data.enterprise_id}
                                    onChange={e => setData('enterprise_id', e.target.value)}
                                >
                                    <option value="">Sélectionner une division</option>
                                    {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                </select>
                                {errors.enterprise_id && <div className="text-red-500 text-xs mt-1">{errors.enterprise_id}</div>}
                            </div>
                        )}

                        {/* Résidence — distinct from `address` (CIN address) above: drives the
                            per-person transport price (Transport/Locations.jsx). Which vehicle
                            picks them up is assigned from the vehicle's own page instead
                            (Transport/Vehicles.jsx) — picking riders per van there is much
                            faster than opening every employee's form one at a time. */}
                        <div>
                            <label htmlFor="emp_residence_location_id" className="block text-xs font-black uppercase text-gray-400 mb-1">Résidence (Transport)</label>
                            <select
                                id="emp_residence_location_id"
                                className="w-full rounded-lg border-gray-200"
                                value={data.residence_location_id}
                                onChange={e => setData('residence_location_id', e.target.value)}
                            >
                                <option value="">— Aucune —</option>
                                {transportLocations?.map(loc => <option key={loc.id} value={loc.id}>{loc.name} ({loc.price_per_person} dh)</option>)}
                            </select>
                            {errors.residence_location_id && <div className="text-red-500 text-xs mt-1">{errors.residence_location_id}</div>}
                        </div>

                        {/* Payroll Profile */}
                        <div>
                            <label id="emp_contract_type_label" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('contract_type_auto')}</label>
                            <div aria-labelledby="emp_contract_type_label" className="bg-gray-100 p-2.5 rounded-lg text-gray-500 font-black uppercase text-[10px]">
                                {selectedEnterprise ? selectedEnterprise.contract_type.replace('_', ' ') : t('managed_by_ferme')}
                            </div>
                        </div>
                        <div>
                            <label htmlFor="emp_base_rate" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('daily_rate_dh')}</label>
                            <input id="emp_base_rate" type="number" step="0.01" className="w-full rounded-lg border-gray-200 font-bold text-primary-700" value={data.base_rate} onChange={e => setData('base_rate', e.target.value)} />
                            {errors.base_rate && <div className="text-red-500 text-xs mt-1">{errors.base_rate}</div>}
                        </div>
                        <div>
                            <label htmlFor="emp_complement" className="block text-xs font-black uppercase text-gray-400 mb-1">{t('complement_prime')}</label>
                            <input id="emp_complement" type="number" step="0.01" className="w-full rounded-lg border-gray-200 font-bold text-green-700" value={data.complement} onChange={e => setData('complement', e.target.value)} />
                            {errors.complement && <div className="text-red-500 text-xs mt-1">{errors.complement}</div>}
                        </div>

                        {/* Status (Edit Only) */}
                        {isEditing && (
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
                        {isEditing && (
                            <div className="md:col-span-3 flex items-center gap-4 border-t pt-4 mt-2">
                                {employee?.photo_path && (
                                    <img
                                        src={`/storage/${employee.photo_path}`}
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
                        <SecondaryButton type="button" onClick={handleClose}>{t('cancel')}</SecondaryButton>
                        <PrimaryButton disabled={processing}>{isEditing ? 'Mettre à jour' : t('save_employee')}</PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    );
}
