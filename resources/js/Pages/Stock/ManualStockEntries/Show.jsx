import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import { formatNumber } from '@/utils/number';
import { ENTRY_TYPE_LABELS as ENTRY_TYPE_TEXT, UNIT_TYPE_LABELS } from '@/utils/stockLabels';

const ENTRY_TYPE_CLASSNAMES = {
    consumption: 'bg-red-100 text-red-800',
    transfer: 'bg-primary-100 text-primary-800',
    loss: 'bg-orange-100 text-orange-800',
    theft: 'bg-purple-100 text-purple-800',
    damage: 'bg-yellow-100 text-yellow-800',
};
const ENTRY_TYPE_LABELS = Object.fromEntries(
    Object.keys(ENTRY_TYPE_TEXT).map((key) => [key, { label: ENTRY_TYPE_TEXT[key], className: ENTRY_TYPE_CLASSNAMES[key] || 'bg-gray-100 text-gray-800' }])
);

export default function Show({ auth, manualStockEntry }) {
    const [confirmingEntryDeletion, setConfirmingEntryDeletion] = useState(false);
    const [confirmingVerification, setConfirmingVerification] = useState(false);
    const { delete: destroy, post, processing } = useForm();

    const confirmEntryDeletion = () => setConfirmingEntryDeletion(true);
    const confirmVerification = () => setConfirmingVerification(true);

    const deleteEntry = (e) => {
        e.preventDefault();
        destroy(route('stock.manual-entries.destroy', manualStockEntry.id), {
            preserveScroll: true,
            onSuccess: closeModal,
            onError: closeModal,
            onFinish: closeModal,
        });
    };

    const verifyEntry = (e) => {
        e.preventDefault();
        post(route('stock.manual-entries.verify', manualStockEntry.id), {
            preserveScroll: true,
            onSuccess: closeModal,
            onError: closeModal,
            onFinish: closeModal,
        });
    };

    const closeModal = () => {
        setConfirmingEntryDeletion(false);
        setConfirmingVerification(false);
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        return new Date(dateString).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const entryType = ENTRY_TYPE_LABELS[manualStockEntry.entry_type] ?? { label: manualStockEntry.entry_type, className: 'bg-gray-100 text-gray-800' };
    const hasVehicleContext = !!manualStockEntry.vehicle;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <div className="flex items-center gap-4">
                        <Link href={route('stock.manual-entries.index')} className="text-gray-500 hover:text-gray-700 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Détails de la Sortie de Stock</h2>
                            <p className="text-sm text-gray-500 mt-1">Sortie #{manualStockEntry.id}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link
                            href={route('stock.manual-entries.edit', manualStockEntry.id)}
                            className="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                            Modifier
                        </Link>
                        {!manualStockEntry.is_verified && auth.user.role !== 'data_entry' && (
                            <PrimaryButton onClick={confirmVerification} className="rounded-xl">Vérifier</PrimaryButton>
                        )}
                        {auth.user.role !== 'data_entry' && (
                            <DangerButton onClick={confirmEntryDeletion} className="rounded-xl">Supprimer</DangerButton>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={`Sortie: ${manualStockEntry.id}`} />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Header Card */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6">
                            <div className="flex items-start justify-between">
                                <div className="flex items-center gap-4">
                                    <div className="flex-shrink-0 h-16 w-16 bg-primary-100 rounded-xl flex items-center justify-center">
                                        <svg className="h-8 w-8 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 className="text-2xl font-black text-gray-900 uppercase tracking-tighter">{manualStockEntry.product?.name || 'N/A'}</h3>
                                        <div className="flex items-center gap-3 mt-2">
                                            <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${entryType.className}`}>
                                                {entryType.label}
                                            </span>
                                            <span className="text-sm text-gray-500">{formatDate(manualStockEntry.date)}</span>
                                        </div>
                                    </div>
                                </div>
                                <span className={`px-4 py-2 rounded-lg font-semibold text-sm flex items-center gap-2 ${manualStockEntry.is_verified ? 'bg-green-50 text-green-600' : 'bg-gray-50 text-gray-500'}`}>
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        {manualStockEntry.is_verified ? (
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                        ) : (
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        )}
                                    </svg>
                                    {manualStockEntry.is_verified ? 'Vérifiée' : 'En attente'}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Quick Stats */}
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Quantité</p>
                            <p className="text-2xl font-bold text-gray-900 mt-1">{formatNumber(manualStockEntry.quantity)} {UNIT_TYPE_LABELS[manualStockEntry.product?.unit_type] || manualStockEntry.product?.unit_type || ''}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Qui a pris le produit</p>
                            <p className="text-lg font-semibold text-gray-900 mt-1">
                                {manualStockEntry.employee?.full_name || (manualStockEntry.employee_name ? (
                                    <>{manualStockEntry.employee_name} <span className="text-xs font-bold text-amber-600 uppercase">(externe)</span></>
                                ) : 'N/A')}
                            </p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Véhicule</p>
                            <p className="text-lg font-semibold text-gray-900 mt-1">{manualStockEntry.vehicle?.name || 'N/A'}</p>
                        </div>
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                            <p className="text-sm text-gray-500">Opération</p>
                            <p className="text-lg font-semibold text-gray-900 mt-1">{manualStockEntry.operation?.name || 'N/A'}</p>
                        </div>
                    </div>

                    {manualStockEntry.maintenance_log && (
                        <div className="bg-teal-50 border border-teal-200 rounded-2xl p-5 flex items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-semibold text-teal-700 uppercase tracking-wider">Intervention de Maintenance Liée</p>
                                <p className="text-sm text-teal-900 mt-1">{manualStockEntry.maintenance_log.description}</p>
                                <p className="text-xs text-teal-600 mt-1">{formatDate(manualStockEntry.maintenance_log.performed_at)}</p>
                            </div>
                            {manualStockEntry.vehicle_id && (
                                <Link
                                    href={route('stock.vehicles.show', manualStockEntry.vehicle_id)}
                                    className="text-teal-700 hover:text-teal-900 text-sm font-semibold shrink-0"
                                >
                                    Voir le véhicule →
                                </Link>
                            )}
                        </div>
                    )}

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Location */}
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Emplacement</h4>
                            </div>
                            <div className="p-6 space-y-4">
                                <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                    <span className="text-gray-500">Bloc</span>
                                    <span className="font-medium text-gray-900">{manualStockEntry.bloc?.name || 'N/A'}</span>
                                </div>
                                <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                    <span className="text-gray-500">Secteur</span>
                                    <span className="font-medium text-gray-900">{manualStockEntry.sector?.name || 'N/A'}</span>
                                </div>
                                <div className="flex justify-between items-center py-2">
                                    <span className="text-gray-500">Parcelle</span>
                                    <span className="font-medium text-gray-900">{manualStockEntry.parcelle?.name || 'N/A'}</span>
                                </div>
                            </div>
                        </div>

                        {/* Vehicle telemetry or verification info */}
                        {hasVehicleContext ? (
                            <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                                <div className="p-6 border-b border-gray-100">
                                    <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Suivi Véhicule</h4>
                                </div>
                                <div className="p-6 space-y-4">
                                    <div className="flex justify-between items-center py-2">
                                        <span className="text-gray-500">Kilométrage</span>
                                        <span className="font-medium text-gray-900">{manualStockEntry.odometer_km ? `${formatNumber(manualStockEntry.odometer_km)} km` : 'N/A'}</span>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                                <div className="p-6 border-b border-gray-100">
                                    <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Vérification</h4>
                                </div>
                                <div className="p-6 space-y-4">
                                    <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                        <span className="text-gray-500">Saisie par</span>
                                        <span className="font-medium text-gray-900">{manualStockEntry.entered_by?.name || 'N/A'}</span>
                                    </div>
                                    <div className="flex justify-between items-center py-2 border-b border-gray-50">
                                        <span className="text-gray-500">Vérifié par</span>
                                        <span className="font-medium text-gray-900">{manualStockEntry.verified_by?.name || 'N/A'}</span>
                                    </div>
                                    <div className="flex justify-between items-center py-2">
                                        <span className="text-gray-500">Date de Vérification</span>
                                        <span className="font-medium text-gray-900">{formatDate(manualStockEntry.verified_at)}</span>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Verification info when vehicle context takes the second slot */}
                    {hasVehicleContext && (
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Vérification</h4>
                            </div>
                            <div className="p-6 grid grid-cols-1 md:grid-cols-3 gap-x-8">
                                <div className="flex justify-between items-center py-2 border-b md:border-b-0 border-gray-50">
                                    <span className="text-gray-500">Saisie par</span>
                                    <span className="font-medium text-gray-900">{manualStockEntry.entered_by?.name || 'N/A'}</span>
                                </div>
                                <div className="flex justify-between items-center py-2 border-b md:border-b-0 border-gray-50">
                                    <span className="text-gray-500">Vérifié par</span>
                                    <span className="font-medium text-gray-900">{manualStockEntry.verified_by?.name || 'N/A'}</span>
                                </div>
                                <div className="flex justify-between items-center py-2">
                                    <span className="text-gray-500">Date de Vérification</span>
                                    <span className="font-medium text-gray-900">{formatDate(manualStockEntry.verified_at)}</span>
                                </div>
                            </div>
                        </div>
                    )}

                    {manualStockEntry.notes && (
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                            <div className="p-6 border-b border-gray-100">
                                <h4 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Notes</h4>
                            </div>
                            <div className="p-6">
                                <p className="bg-gray-50 p-4 rounded-lg text-sm text-gray-700">{manualStockEntry.notes}</p>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <Modal show={confirmingEntryDeletion} onClose={closeModal}>
                <form onSubmit={deleteEntry} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Êtes-vous sûr de vouloir supprimer cette sortie de stock ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Cette action est irréversible et supprimera toutes les données associées à cette sortie.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <DangerButton className="ml-3" disabled={processing}>
                            Supprimer la Sortie
                        </DangerButton>
                    </div>
                </form>
            </Modal>

            <Modal show={confirmingVerification} onClose={closeModal}>
                <form onSubmit={verifyEntry} className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Confirmer la vérification de cette sortie de stock ?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        Cette action marquera la sortie comme vérifiée.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Annuler</SecondaryButton>
                        <PrimaryButton className="ml-3" disabled={processing}>
                            Vérifier la Sortie
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
