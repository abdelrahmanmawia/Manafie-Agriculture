import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatNumber } from '@/Helpers/formatNumber';
import EmployeeFormModal from '@/Pages/Admin/Employees/EmployeeFormModal';
import DeleteEmployeeModal from '@/Pages/Admin/Employees/DeleteEmployeeModal';
import ContractModal from '@/Pages/Admin/Employees/ContractModal';

function InfoRow({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4 py-2 border-b border-gray-50 last:border-0">
            <span className="text-xs font-bold text-gray-400 uppercase tracking-wide">{label}</span>
            <span className="text-sm font-semibold text-gray-800 text-right">{value ?? <span className="text-gray-300 font-normal">—</span>}</span>
        </div>
    );
}

// Quinzaine.start_date/end_date cast as plain 'date' (not 'date:Y-m-d' like Employee's own
// dob/hire_date), so they arrive as full ISO timestamps — trimmed to the date portion here
// rather than touching that cast, which other pages already rely on as-is.
function formatDate(value) {
    return value ? value.slice(0, 10) : '';
}

function StatCard({ label, value, accent }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
            <div className="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">{label}</div>
            <div className={`text-2xl font-black ${accent || 'text-gray-800'}`}>{value}</div>
        </div>
    );
}

// Everything the trimmed Admin/Employees.jsx table doesn't have room for — full profile fields
// plus payroll stats (days worked, net earned) pulled from PointageRecord, see
// EmployeeController::show().
export default function Show({ auth, employee, stats, recentQuinzaines, enterprises, transportLocations }) {
    const [isEditing, setIsEditing] = useState(false);
    const [isGeneratingContract, setIsGeneratingContract] = useState(false);
    const [confirmingDeletion, setConfirmingDeletion] = useState(false);
    const canManage = auth.user.role !== 'data_entry' || auth.user.can_access_pointage;

    const salNetJ = employee.enterprise?.contract_type === 'avec_contrat'
        ? (parseFloat(employee.base_rate) * (1 - 0.0674)) + parseFloat(employee.complement || 0)
        : parseFloat(employee.base_rate);

    const handleToggleActive = () => {
        router.post(route('employees.toggle-active', employee.id));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        {employee.photo_path ? (
                            <img src={`/storage/${employee.photo_path}`} alt="" className="w-14 h-14 rounded-xl object-cover border border-gray-200" />
                        ) : (
                            <div className="w-14 h-14 rounded-xl bg-gray-100 flex items-center justify-center text-gray-300 font-black text-xl">
                                {employee.full_name?.charAt(0)}
                            </div>
                        )}
                        <div>
                            <h2 className="font-black text-2xl text-gray-800 leading-tight tracking-tighter uppercase">{employee.full_name}</h2>
                            <p className="text-xs text-gray-400 font-bold uppercase tracking-widest">
                                {employee.matricule}
                                {employee.enterprise?.name && ` · ${employee.enterprise.name}`}
                                {' · '}
                                <span className={employee.is_active ? 'text-green-600' : 'text-gray-400'}>{employee.is_active ? 'Actif' : 'Inactif'}</span>
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Link
                            href={route('employees.index')}
                            className="text-sm font-bold text-gray-500 hover:text-gray-700 flex items-center gap-1"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            Retour à la liste
                        </Link>
                        {canManage && (
                            <>
                                <button
                                    type="button"
                                    onClick={handleToggleActive}
                                    className={`text-xs font-black uppercase tracking-widest px-4 py-2 rounded-full border transition-colors ${
                                        employee.is_active ? 'border-gray-200 text-gray-500 hover:bg-gray-50' : 'border-green-200 text-green-700 hover:bg-green-50'
                                    }`}
                                >
                                    {employee.is_active ? 'Désactiver' : 'Activer'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setIsGeneratingContract(true)}
                                    className="text-xs font-black uppercase tracking-widest px-4 py-2 rounded-full border border-primary-200 text-primary-700 hover:bg-primary-50 transition-colors"
                                >
                                    Générer Contrat
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setIsEditing(true)}
                                    className="text-xs font-black uppercase tracking-widest px-4 py-2 rounded-full bg-primary-600 text-white hover:bg-primary-700 transition-colors"
                                >
                                    Modifier
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setConfirmingDeletion(true)}
                                    className="text-xs font-black uppercase tracking-widest px-4 py-2 rounded-full border border-red-200 text-red-600 hover:bg-red-50 transition-colors"
                                >
                                    Supprimer
                                </button>
                            </>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={employee.full_name} />

            <div className="py-12">
                <div className="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

                    {/* STATS */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <StatCard label="Jours Travaillés (Total)" value={stats.total_days} />
                        <StatCard label="Net Gagné (Total)" value={`${formatNumber(stats.total_net)} DH`} accent="text-green-700" />
                        <StatCard label={stats.current_period ? `Jours — ${stats.current_period.label}` : 'Jours — Période Ouverte'} value={stats.current_period ? stats.current_period.days : '—'} />
                        <StatCard label={stats.current_period ? `Net — ${stats.current_period.label}` : 'Net — Période Ouverte'} value={stats.current_period ? `${formatNumber(stats.current_period.net)} DH` : '—'} accent="text-green-700" />
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {/* IDENTITÉ */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 className="text-sm font-black text-gray-800 uppercase tracking-tighter mb-3">Identité</h3>
                            <InfoRow label="CIN" value={employee.cin} />
                            <InfoRow label="N° CNSS" value={employee.cnss_number} />
                            <InfoRow label="Date de Naissance" value={employee.dob} />
                            <InfoRow label="Téléphone" value={employee.phone} />
                            <InfoRow label="Adresse (CIN)" value={employee.address} />
                        </div>

                        {/* EMPLOI */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 className="text-sm font-black text-gray-800 uppercase tracking-tighter mb-3">Emploi</h3>
                            <InfoRow label="Division" value={employee.enterprise?.name} />
                            <InfoRow label="Type de Contrat" value={employee.enterprise?.contract_type?.replace('_', ' ')} />
                            <InfoRow label="Date d'Embauche" value={employee.hire_date} />
                            <InfoRow label="Taux Journalier (Brut)" value={`${formatNumber(employee.base_rate)} DH`} />
                            <InfoRow label="Complément / Prime" value={`${formatNumber(employee.complement || 0)} DH`} />
                            <InfoRow label="Net / Jour" value={<span className="text-green-700 font-black">{formatNumber(salNetJ)} DH</span>} />
                        </div>

                        {/* BANQUE */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 className="text-sm font-black text-gray-800 uppercase tracking-tighter mb-3">Banque</h3>
                            <InfoRow label="Banque" value={employee.bank_name} />
                            <InfoRow label="RIB" value={employee.rib && <span className="font-mono text-xs">{employee.rib}</span>} />
                        </div>

                        {/* TRANSPORT */}
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 className="text-sm font-black text-gray-800 uppercase tracking-tighter mb-3">Transport</h3>
                            <InfoRow
                                label="Résidence"
                                value={employee.residence_location && `${employee.residence_location.name} (${employee.residence_location.price_per_person} dh)`}
                            />
                            <InfoRow
                                label="Véhicule"
                                value={employee.transport_vehicle && `${employee.transport_vehicle.code} — ${employee.transport_vehicle.transport_company?.name || '?'}`}
                            />
                        </div>
                    </div>

                    {/* RECENT QUINZAINES */}
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-black text-gray-800 uppercase tracking-tighter">Dernières Périodes</h3>
                        </div>
                        {recentQuinzaines.length === 0 ? (
                            <p className="px-6 py-8 text-sm text-gray-400 italic">Aucun pointage enregistré pour cet employé.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-gray-500 uppercase text-xs tracking-wider">
                                        <th className="px-6 py-2 font-semibold">Période</th>
                                        <th className="px-6 py-2 font-semibold text-right">Jours</th>
                                        <th className="px-6 py-2 font-semibold text-right">Net</th>
                                        <th className="px-6 py-2 font-semibold text-center">Statut</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {recentQuinzaines.map((q) => (
                                        <tr key={q.id}>
                                            <td className="px-6 py-2">{q.label} <span className="text-gray-400 text-xs">({formatDate(q.start_date)} — {formatDate(q.end_date)})</span></td>
                                            <td className="px-6 py-2 text-right">{q.days}</td>
                                            <td className="px-6 py-2 text-right font-semibold text-green-700">{formatNumber(q.net || 0)} DH</td>
                                            <td className="px-6 py-2 text-center">
                                                <span className={`text-[10px] font-black uppercase px-2 py-1 rounded ${q.is_closed ? 'bg-gray-200 text-gray-600' : 'bg-green-100 text-green-700'}`}>
                                                    {q.is_closed ? 'Clôturée' : 'Ouverte'}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>

            <EmployeeFormModal
                show={isEditing}
                onClose={() => setIsEditing(false)}
                employee={employee}
                enterprises={enterprises}
                transportLocations={transportLocations}
            />

            <ContractModal
                employee={isGeneratingContract ? employee : null}
                onClose={() => setIsGeneratingContract(false)}
            />

            <DeleteEmployeeModal
                employee={confirmingDeletion ? employee : null}
                onClose={() => setConfirmingDeletion(false)}
            />
        </AuthenticatedLayout>
    );
}
