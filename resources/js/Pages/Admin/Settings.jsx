import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { t } from '@/Helpers/i18n';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import PrimaryButton from '@/Components/PrimaryButton';

export default function Settings({ auth, enterprise, quinzaines }) {
    const [confirmingDivisionDeletion, setConfirmingDivisionDeletion] = useState(false);
    const [confirmingQuinzaineClosure, setConfirmingQuinzaineClosure] = useState(null);

    const editForm = useForm({
        name: enterprise.name,
        default_brut_rate: enterprise.default_brut_rate,
        contract_type: enterprise.contract_type,
        invoiced_to_client: enterprise.invoiced_to_client,
    });

    const qForm = useForm({ start_date: '', end_date: '', enterprise_id: enterprise.id });
    const { delete: destroyDivision, processing: divisionProcessing } = useForm();
    const { post: closeQuinzaine, processing: closureProcessing } = useForm();

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.patch(route('enterprises.update', enterprise.id));
    };

    const confirmDivisionDeletion = () => {
        setConfirmingDivisionDeletion(true);
    };

    const deleteDivision = (e) => {
        e.preventDefault();
        destroyDivision(route('enterprises.destroy', enterprise.id), {
            onSuccess: () => closeDivisionModal(),
            onError: () => closeDivisionModal(),
            onFinish: () => closeDivisionModal(),
        });
    };

    const closeDivisionModal = () => {
        setConfirmingDivisionDeletion(false);
    };

    const confirmQuinzaineClosure = (q) => {
        setConfirmingQuinzaineClosure(q);
    };

    const closeQuinzaineModal = () => {
        setConfirmingQuinzaineClosure(null);
    };

    const submitQuinzaineClosure = (e) => {
        e.preventDefault();
        closeQuinzaine(route('settings.quinzaine.close', confirmingQuinzaineClosure.id), {
            onSuccess: () => closeQuinzaineModal(),
            onError: () => closeQuinzaineModal(),
            onFinish: () => closeQuinzaineModal(),
        });
    };

    const formatDate = (dateString) => {
        if (!dateString) return '';
        return new Date(dateString).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">{t('settings')} - {enterprise.name}</h2>}
        >
            <Head title={t('settings')} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                    {/* ENTERPRISE DETAILS — editing name/rate/contract_type (enterprises.update) is a
                        structural, payroll-formula-affecting change kept farm_manager/super_admin-only
                        (see EnterpriseController::assertEnterpriseStructuralAccess); a data_entry
                        granted Pointage access only gets a read-only summary here. */}
                    <div className="bg-white p-6 shadow-sm sm:rounded-2xl border border-gray-100 border-t-4 border-t-blue-600">
                        <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter mb-4">Détails de la Division</h3>
                        {auth.user.role === 'data_entry' ? (
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 text-sm">
                                <div>
                                    <div className="text-xs font-black uppercase text-gray-400 mb-1">Nom de la Division</div>
                                    <div className="font-bold text-gray-800">{enterprise.name}</div>
                                </div>
                                <div>
                                    <div className="text-xs font-black uppercase text-gray-400 mb-1">Salaire Brut (DH)</div>
                                    <div className="font-bold text-gray-800">{enterprise.default_brut_rate}</div>
                                </div>
                                <div>
                                    <div className="text-xs font-black uppercase text-gray-400 mb-1">Type Contrat</div>
                                    <div className="font-bold text-gray-800">{enterprise.contract_type === 'avec_contrat' ? 'Avec Contrat' : 'Sans Contrat'}</div>
                                </div>
                            </div>
                        ) : (
                        <form onSubmit={submitEdit} className="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">Nom de la Division</label>
                                <input
                                    type="text"
                                    className="w-full rounded border-gray-300 text-sm"
                                    value={editForm.data.name}
                                    onChange={e => editForm.setData('name', e.target.value)}
                                    required
                                />
                                {editForm.errors.name && <div className="text-red-500 text-xs mt-1">{editForm.errors.name}</div>}
                            </div>
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">Salaire Brut (DH)</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    className="w-full rounded border-gray-300 text-sm"
                                    value={editForm.data.default_brut_rate}
                                    onChange={e => editForm.setData('default_brut_rate', e.target.value)}
                                    required
                                />
                                {editForm.errors.default_brut_rate && <div className="text-red-500 text-xs mt-1">{editForm.errors.default_brut_rate}</div>}
                            </div>
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">Type Contrat</label>
                                <select
                                    className="w-full rounded border-gray-300 text-sm"
                                    value={editForm.data.contract_type}
                                    onChange={e => editForm.setData('contract_type', e.target.value)}
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
                                    checked={editForm.data.invoiced_to_client}
                                    onChange={e => editForm.setData('invoiced_to_client', e.target.checked)}
                                />
                                <label htmlFor="invoiced_to_client" className="text-xs text-gray-600">
                                    Cette division facture un client (ex: agence d'intérim) — à ne cocher que si elle émet une facture (net à facturer/TTC), pas juste le salaire des ouvriers
                                </label>
                            </div>
                            <div className="md:col-span-3 flex flex-wrap items-center justify-between gap-4">
                                <div>
                                    <button
                                        type="submit"
                                        disabled={editForm.processing}
                                        className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-xl font-black uppercase text-xs tracking-widest shadow-md transition-colors"
                                    >
                                        Enregistrer les modifications
                                    </button>
                                    {editForm.wasSuccessful && <span className="ml-4 text-green-600 text-xs font-bold">Enregistré !</span>}
                                </div>
                                {auth.user.role !== 'data_entry' && (
                                    <button
                                        type="button"
                                        onClick={confirmDivisionDeletion}
                                        className="text-red-600 hover:text-red-700 font-black uppercase text-xs tracking-widest"
                                    >
                                        Supprimer cette Division
                                    </button>
                                )}
                            </div>
                        </form>
                        )}
                    </div>

                    {/* QUINZAINE MANAGEMENT */}
                    <div className="bg-white p-6 shadow-sm sm:rounded-2xl border border-gray-100 border-t-4 border-t-green-500">
                        <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter mb-4">{t('manage_periods')}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); qForm.post(route('settings.quinzaine')); }} className="flex flex-wrap gap-4 items-end mb-6 bg-gray-50 p-4 rounded-xl">
                            <div>
                                <label className="block text-sm font-bold text-gray-700">{t('start_date')}</label>
                                <input type="date" className="rounded border-gray-300" value={qForm.data.start_date} onChange={e => qForm.setData('start_date', e.target.value)} />
                                {qForm.errors.start_date && <div className="text-red-500 text-xs mt-1">{qForm.errors.start_date}</div>}
                            </div>
                            <div>
                                <label className="block text-sm font-bold text-gray-700">{t('end_date')}</label>
                                <input type="date" className="rounded border-gray-300" value={qForm.data.end_date} onChange={e => qForm.setData('end_date', e.target.value)} />
                                {qForm.errors.end_date && <div className="text-red-500 text-xs mt-1">{qForm.errors.end_date}</div>}
                            </div>
                            <button type="submit" className="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-xl font-black uppercase text-xs tracking-widest shadow-md transition-colors">{t('open_new_period')}</button>
                        </form>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead>
                                    <tr className="text-left font-bold text-gray-500 uppercase tracking-wider text-xs">
                                        <th className="px-4 py-2">{t('period')}</th>
                                        <th className="px-4 py-2">{t('status')}</th>
                                        <th className="px-4 py-2 text-right">{t('actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {quinzaines.map(q => (
                                        <tr key={q.id}>
                                            <td className="px-4 py-3">{formatDate(q.start_date)} {t('to')} {formatDate(q.end_date)}</td>
                                            <td className="px-4 py-3">
                                                {q.is_closed ?
                                                    <span className="bg-gray-100 text-gray-600 px-2 py-1 rounded text-[10px] font-bold">{t('closed')}</span> :
                                                    <span className="bg-green-100 text-green-700 px-2 py-1 rounded text-[10px] font-bold">{t('open')}</span>
                                                }
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {!q.is_closed && (
                                                    <button
                                                        type="button"
                                                        onClick={() => confirmQuinzaineClosure(q)}
                                                        className="text-red-600 font-bold hover:underline"
                                                    >
                                                        {t('close_period')}
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>

            <Modal show={confirmingDivisionDeletion} onClose={closeDivisionModal}>
                <form onSubmit={deleteDivision} className="p-8">
                    <div className="flex items-center gap-4 mb-4">
                        <div className="h-12 w-12 bg-red-100 rounded-full flex items-center justify-center">
                            <svg className="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter">
                            Supprimer la division
                        </h2>
                    </div>
                    <p className="text-gray-600 mb-6">
                        ATTENTION : cette action supprimera <strong>définitivement</strong> la division <strong>{enterprise.name}</strong>, toutes ses quinzaines et pointages. Les salariés rattachés seront conservés mais détachés de cette division.
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={closeDivisionModal}>Annuler</SecondaryButton>
                        <DangerButton className="rounded-xl" disabled={divisionProcessing}>
                            {divisionProcessing ? 'Suppression...' : 'Supprimer'}
                        </DangerButton>
                    </div>
                </form>
            </Modal>

            <Modal show={confirmingQuinzaineClosure !== null} onClose={closeQuinzaineModal}>
                {confirmingQuinzaineClosure && (
                    <form onSubmit={submitQuinzaineClosure} className="p-8">
                        <div className="flex items-center gap-4 mb-4">
                            <div className="h-12 w-12 bg-amber-100 rounded-full flex items-center justify-center">
                                <svg className="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </div>
                            <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter">
                                Clôturer la période
                            </h2>
                        </div>
                        <p className="text-gray-600 mb-6">
                            Clôturer la période <strong>{formatDate(confirmingQuinzaineClosure.start_date)} {t('to')} {formatDate(confirmingQuinzaineClosure.end_date)}</strong> ? Le pointage ne pourra plus être modifié une fois clôturée.
                        </p>
                        <div className="flex justify-end gap-3">
                            <SecondaryButton type="button" onClick={closeQuinzaineModal}>Annuler</SecondaryButton>
                            <PrimaryButton className="rounded-xl !bg-amber-600 hover:!bg-amber-700 focus:!bg-amber-700 active:!bg-amber-900" disabled={closureProcessing}>
                                {closureProcessing ? 'Clôture...' : 'Clôturer'}
                            </PrimaryButton>
                        </div>
                    </form>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
