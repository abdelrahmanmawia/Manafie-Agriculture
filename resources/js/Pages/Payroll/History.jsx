import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { formatNumber } from '@/Helpers/formatNumber';

export default function History({ auth, employees, periods, history, selectedEnterpriseId, allEnterprises, equipes = [] }) {
    // Client-side filters over the rows (the division one is server-side): a search on name /
    // matricule / CIN, the équipe, and — on by default — only the workers paid at least once.
    const [search, setSearch] = useState('');
    const [equipeFilter, setEquipeFilter] = useState('');
    const [paidOnly, setPaidOnly] = useState(true);
    const totalOf = (emp) => (history[emp.id] || []).reduce((s, h) => s + parseFloat(h.total_net), 0);
    const rows = useMemo(() => {
        const q = search.trim().toLowerCase();
        return employees.filter(emp =>
            (!q || [emp.full_name, emp.matricule, emp.cin].some(v => (v || '').toLowerCase().includes(q)))
            && (equipeFilter === '' || (equipeFilter === 'none' ? !emp.equipe_id : String(emp.equipe_id) === equipeFilter))
            && (!paidOnly || totalOf(emp) > 0));
    }, [employees, history, search, equipeFilter, paidOnly]);

    const handleEnterpriseChange = (e) => {
        router.get(route('payroll.history'), { enterprise_id: e.target.value });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div className="flex flex-col">
                        <h2 className="font-black text-xl sm:text-2xl text-gray-800 uppercase tracking-tighter leading-none">Historique des Salaires</h2>
                        <p className="text-[10px] sm:text-xs text-gray-400 font-bold uppercase tracking-widest mt-1">Récapitulatif global par salarié et par période</p>
                    </div>
                    {(auth.user.role === 'super_admin' || auth.user.role === 'farm_manager') && (
                        <select
                            className="w-full md:w-auto rounded-xl border-gray-200 bg-white font-bold text-sm shadow-sm focus:ring-primary-500"
                            value={selectedEnterpriseId || ''}
                            onChange={handleEnterpriseChange}
                        >
                            <option value="">Toutes les divisions</option>
                            {allEnterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                        </select>
                    )}
                </div>
            }
        >
            <Head title="Historique Salaires" />

            <div className="py-6 sm:py-12 bg-gray-50 min-h-screen">
                <div className="max-w-full mx-auto px-2 sm:px-6 lg:px-8">
                    <div className="bg-white shadow-2xl sm:rounded-3xl border-t-[8px] sm:border-t-[12px] border-primary-700 overflow-hidden">
                        <div className="p-4 sm:p-8 bg-white border-b flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4">
                            <div>
                                <h3 className="text-xl sm:text-2xl font-black text-gray-900 leading-none mb-2">Matrice des Paiements</h3>
                                <span className="text-[10px] font-black text-primary-600 bg-primary-50 px-3 py-1 rounded-lg inline-block uppercase tracking-widest">Total Net à payer (DH)</span>
                            </div>
                            <div className="text-left sm:text-right">
                                <span className="text-[10px] font-black uppercase text-gray-400 block mb-1">Masse Salariale Cumulée</span>
                                <span className="text-2xl sm:text-3xl font-black text-primary-700 leading-none">
                                    {formatNumber(rows.reduce((acc, emp) => acc + totalOf(emp), 0))}
                                    <small className="text-sm ml-1 font-bold">DH</small>
                                </span>
                            </div>
                        </div>

                        <div className="px-4 sm:px-8 py-4 border-b bg-gray-50 flex flex-wrap items-center gap-3">
                            <input type="text" value={search} onChange={e => setSearch(e.target.value)} placeholder="Rechercher (nom, matricule, CIN)…"
                                className="rounded-xl border-gray-200 text-sm w-full sm:w-72" />
                            {equipes.length > 0 && (
                                <select value={equipeFilter} onChange={e => setEquipeFilter(e.target.value)} aria-label="Filtrer par équipe"
                                    className={`rounded-xl text-sm ${equipeFilter ? 'border-primary-300 bg-primary-50 font-bold' : 'border-gray-200'}`}>
                                    <option value="">Toutes les équipes</option>
                                    {equipes.map(q => <option key={q.id} value={String(q.id)}>{q.name}</option>)}
                                    <option value="none">Sans équipe</option>
                                </select>
                            )}
                            <label className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                <input type="checkbox" className="rounded border-gray-300 text-primary-600" checked={paidOnly} onChange={e => setPaidOnly(e.target.checked)} />
                                Seulement les salariés payés
                            </label>
                            <span className="text-xs font-bold text-gray-500 ml-auto">{rows.length} / {employees.length} salarié(s)</span>
                            {(search || equipeFilter || !paidOnly) && (
                                <button type="button" onClick={() => { setSearch(''); setEquipeFilter(''); setPaidOnly(true); }} className="text-xs font-bold text-gray-500 hover:text-red-600 underline underline-offset-2">Réinitialiser</button>
                            )}
                        </div>

                        <div className="overflow-x-auto p-2">
                            <table className="min-w-full border-collapse border-spacing-0 text-[10px]">
                                <thead>
                                    <tr className="bg-gray-100">
                                        <th className="border border-gray-200 p-4 sticky left-0 z-20 bg-gray-100 min-w-[200px] text-left font-black uppercase tracking-widest text-gray-500 shadow-md">Salarié</th>
                                        {periods.map(period => (
                                            <th key={period.key} className="border border-gray-200 p-3 min-w-[120px] text-center">
                                                <div className="flex flex-col">
                                                    <span className="text-[8px] font-black text-primary-500 uppercase">{period.label}</span>
                                                    <span className="font-black text-gray-800 leading-none">
                                                        {new Date(period.start_date).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })} - {new Date(period.end_date).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })}
                                                    </span>
                                                </div>
                                            </th>
                                        ))}
                                        <th className="border border-gray-200 p-4 sticky right-0 z-20 bg-primary-700 text-white font-black uppercase tracking-widest text-center shadow-md">Cumul Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {rows.map(emp => {
                                        const empHistory = history[emp.id] || [];
                                        let cumulativeRowTotal = 0;

                                        return (
                                            <tr key={emp.id} className="hover:bg-primary-50/50 transition-colors group">
                                                <td className="border border-gray-100 p-4 sticky left-0 z-10 bg-white group-hover:bg-primary-50 shadow-sm">
                                                    <div className="flex flex-col">
                                                        <span className="font-black text-gray-900 uppercase leading-none mb-1">{emp.full_name}</span>
                                                        <span className="text-[8px] font-bold text-gray-400 tracking-tighter">{emp.matricule} • {emp.cin || 'PAS DE CIN'}{emp.enterprise?.name ? ` • ${emp.enterprise.name}` : ''}{!emp.is_active ? ' • inactif' : ''}</span>
                                                    </div>
                                                </td>
                                                {periods.map(period => {
                                                    const periodData = empHistory.find(h => h.period_key === period.key);
                                                    const amount = periodData ? parseFloat(periodData.total_net) : 0;
                                                    cumulativeRowTotal += amount;

                                                    return (
                                                        <td key={period.key} className={`border border-gray-100 p-3 text-center transition-all ${amount > 0 ? 'bg-green-50/30' : ''}`}>
                                                            {amount > 0 ? (
                                                                <span className="font-black text-gray-700">{formatNumber(amount)}</span>
                                                            ) : (
                                                                <span className="text-gray-200">-</span>
                                                            )}
                                                        </td>
                                                    );
                                                })}
                                                <td className="border border-gray-100 p-4 sticky right-0 z-10 bg-primary-50 font-black text-primary-700 text-right shadow-sm group-hover:bg-primary-100 transition-colors">
                                                    {formatNumber(cumulativeRowTotal)} <small className="text-[8px]">DH</small>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {rows.length === 0 && (
                                        <tr>
                                            <td colSpan={periods.length + 2} className="p-20 text-center text-gray-400 italic">
                                                {employees.length === 0 ? "Aucun salarié trouvé. Assurez-vous d'avoir ajouté des salariés dans la gestion du personnel." : 'Aucun salarié ne correspond à ces filtres.'}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-gray-900 text-white font-black">
                                        <td className="p-4 sticky left-0 bg-gray-900 z-10 uppercase tracking-widest text-[9px] text-right border-t border-gray-800">TOTAL PÉRIODE</td>
                                        {periods.map(period => {
                                            let periodTotal = 0;
                                            rows.forEach(emp => {
                                                const pData = (history[emp.id] || []).find(h => h.period_key === period.key);
                                                periodTotal += pData ? parseFloat(pData.total_net) : 0;
                                            });
                                            return (
                                                <td key={period.key} className="p-3 text-center text-green-400 border-t border-gray-800 border-l border-gray-800">
                                                    {formatNumber(periodTotal)}
                                                </td>
                                            );
                                        })}
                                        <td className="p-4 sticky right-0 bg-green-600 z-10 text-right text-xs uppercase tracking-tighter border-t border-green-700">
                                            GRAND TOTAL
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
