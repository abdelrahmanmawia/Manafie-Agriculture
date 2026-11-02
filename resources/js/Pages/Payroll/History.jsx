import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { formatNumber } from '@/Helpers/formatNumber';

// How many periods the table opens on (HistoriquePaieService::PERIODES_PAR_DEFAUT).
const PERIODES_PAR_DEFAUT = 6;
// An amount isn't paid just because it's listed: a period not closed can still change.
const STATUTS = {
    en_cours: { label: 'En cours', classe: 'bg-amber-100 text-amber-800' },
    ouverte: { label: 'Non clôturée', classe: 'bg-orange-100 text-orange-800' },
    cloturee: { label: 'Clôturée', classe: 'bg-green-100 text-green-800' },
};

export default function History({ auth, employees, periods, history, selectedEnterpriseId, allEnterprises, equipes = [] }) {
    // Client-side filters over the rows (the division one is server-side): the periods shown, a
    // search on name / matricule / CIN, the équipe, and — on by default — only the workers with an amount.
    const [search, setSearch] = useState('');
    const [equipeFilter, setEquipeFilter] = useState('');
    const [avecMontant, setAvecMontant] = useState(true);
    const [periode, setPeriode] = useState('');

    const periodesVues = useMemo(() => (
        periode === 'toutes' ? periods
            : periode ? periods.filter(p => p.key === periode)
            : periods.slice(0, PERIODES_PAR_DEFAUT)
    ), [periods, periode]);
    const ligne = (emp, period) => (history[emp.id] || []).find(h => h.period_key === period.key);
    const montant = (emp, period) => parseFloat(ligne(emp, period)?.total_net || 0);
    const totalOf = (emp) => periodesVues.reduce((s, p) => s + montant(emp, p), 0);

    const rows = useMemo(() => {
        const q = search.trim().toLowerCase();
        return employees.filter(emp =>
            (!q || [emp.full_name, emp.matricule, emp.cin].some(v => (v || '').toLowerCase().includes(q)))
            && (equipeFilter === '' || (equipeFilter === 'none' ? !emp.equipe_id : String(emp.equipe_id) === equipeFilter))
            && (!avecMontant || totalOf(emp) > 0));
    }, [employees, history, search, equipeFilter, avecMontant, periodesVues]);

    const grandTotal = rows.reduce((acc, emp) => acc + totalOf(emp), 0);
    const filtreActif = search || equipeFilter || !avecMontant || periode;

    const handleEnterpriseChange = (e) => {
        router.get(route('payroll.history'), { enterprise_id: e.target.value });
    };

    const exportHref = route('payroll.history.export', Object.fromEntries(Object.entries({
        enterprise_id: selectedEnterpriseId || '',
        periode,
        equipe: equipeFilter,
        q: search.trim(),
        avec_montant: avecMontant ? '1' : '0',
    }).filter(([, v]) => v !== '')));

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div className="flex flex-col">
                        <h2 className="font-black text-2xl text-gray-800 leading-tight tracking-tight">Historique de Paie</h2>
                        <p className="text-sm text-gray-500 font-medium mt-1">Le net de chaque salarié, période par période</p>
                    </div>
                    <div className="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                        {!auth.user.enterprise_id && (
                            <select
                                className="w-full sm:w-auto rounded-xl border-gray-200 bg-white font-bold text-sm shadow-sm focus:ring-primary-500"
                                value={selectedEnterpriseId || ''}
                                onChange={handleEnterpriseChange}
                            >
                                <option value="">Toutes les divisions</option>
                                {allEnterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                            </select>
                        )}
                        <a href={exportHref} target="_blank" rel="noreferrer"
                            className="inline-flex items-center justify-center px-4 py-2 rounded-lg border border-green-300 bg-green-50 text-green-800 text-sm font-bold hover:bg-green-100 whitespace-nowrap">
                            Exporter en Excel
                        </a>
                    </div>
                </div>
            }
        >
            <Head title="Historique de Paie" />

            <div className="py-6 bg-gray-50 min-h-screen">
                <div className="max-w-full mx-auto px-2 sm:px-6 lg:px-8">
                    <div className="bg-white shadow-sm rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-4 sm:p-6 border-b flex flex-col lg:flex-row lg:items-end gap-4">
                            <div className="grid grid-cols-1 sm:flex sm:flex-wrap sm:items-center gap-3 flex-1">
                                <input type="text" value={search} onChange={e => setSearch(e.target.value)} placeholder="Rechercher (nom, matricule, CIN)…"
                                    className="rounded-xl border-gray-200 text-sm w-full sm:w-72" />
                                <select value={periode} onChange={e => setPeriode(e.target.value)} aria-label="Filtrer par période"
                                    className={`rounded-xl text-sm ${periode ? 'border-primary-300 bg-primary-50 font-bold' : 'border-gray-200'}`}>
                                    <option value="">Les {PERIODES_PAR_DEFAUT} dernières périodes</option>
                                    <option value="toutes">Toutes les périodes</option>
                                    {periods.map(p => <option key={p.key} value={p.key}>{p.label}</option>)}
                                </select>
                                {equipes.length > 0 && (
                                    <select value={equipeFilter} onChange={e => setEquipeFilter(e.target.value)} aria-label="Filtrer par équipe"
                                        className={`rounded-xl text-sm ${equipeFilter ? 'border-primary-300 bg-primary-50 font-bold' : 'border-gray-200'}`}>
                                        <option value="">Toutes les équipes</option>
                                        {equipes.map(q => <option key={q.id} value={String(q.id)}>{q.name}</option>)}
                                        <option value="none">Sans équipe</option>
                                    </select>
                                )}
                                <label className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                    <input type="checkbox" className="rounded border-gray-300 text-primary-600" checked={avecMontant} onChange={e => setAvecMontant(e.target.checked)} />
                                    Seulement les salariés avec un montant
                                </label>
                                {filtreActif && (
                                    <button type="button" onClick={() => { setSearch(''); setEquipeFilter(''); setAvecMontant(true); setPeriode(''); }} className="text-xs font-bold text-gray-500 hover:text-red-600 underline underline-offset-2 text-left">Réinitialiser</button>
                                )}
                            </div>
                            <div className="lg:text-right shrink-0">
                                <span className="text-[11px] font-extrabold uppercase text-gray-500 tracking-wide block">Net cumulé · {rows.length} / {employees.length} salarié(s)</span>
                                <span className="text-2xl sm:text-3xl font-black text-primary-700 leading-none">
                                    {formatNumber(grandTotal)}
                                    <small className="text-sm ml-1 font-bold">DH</small>
                                </span>
                            </div>
                        </div>

                        <p className="px-4 sm:px-6 py-2 text-xs text-gray-500 border-b bg-gray-50">
                            Cliquez sur un montant pour ouvrir le bulletin de paie du salarié.
                        </p>

                        {/* Scrolls inside its own box, so the period headers and totals stay in sight. */}
                        <div className="overflow-auto max-h-[75vh]">
                            <table className="min-w-full border-separate border-spacing-0 text-sm">
                                <thead>
                                    <tr>
                                        <th className="border-b border-r border-gray-200 p-3 sticky top-0 sm:left-0 z-30 bg-gray-100 min-w-[170px] sm:min-w-[240px] text-left text-xs font-extrabold uppercase tracking-wide text-gray-600">Salarié</th>
                                        {periodesVues.map(period => (
                                            <th key={period.key} className="border-b border-r border-gray-200 p-2 sticky top-0 z-20 bg-gray-100 min-w-[120px] text-center">
                                                <div className="flex flex-col items-center gap-1">
                                                    <span className="text-xs font-extrabold text-gray-800">{period.label}</span>
                                                    <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${STATUTS[period.statut]?.classe ?? ''}`}>{STATUTS[period.statut]?.label}</span>
                                                </div>
                                            </th>
                                        ))}
                                        <th className="border-b border-gray-200 p-3 sticky top-0 sm:right-0 z-30 bg-primary-700 text-white text-xs font-extrabold uppercase tracking-wide text-center min-w-[110px]">Cumul</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map(emp => (
                                        <tr key={emp.id} className="hover:bg-primary-50/50 group">
                                            <td className="border-b border-r border-gray-100 px-3 py-2 sm:sticky sm:left-0 z-10 bg-white group-hover:bg-primary-50">
                                                <span className="block font-bold text-gray-900 leading-tight">{emp.full_name}</span>
                                                <span className="block text-[11px] text-gray-500 leading-tight mt-0.5">{emp.matricule} • {emp.cin || 'Pas de CIN'}{emp.enterprise?.name ? ` • ${emp.enterprise.name}` : ''}{!emp.is_active ? ' • inactif' : ''}</span>
                                            </td>
                                            {periodesVues.map(period => {
                                                const l = ligne(emp, period);
                                                const amount = montant(emp, period);
                                                const quinzaines = l?.quinzaines ?? [];

                                                return (
                                                    <td key={period.key} className={`border-b border-r border-gray-100 px-2 py-2 text-center whitespace-nowrap ${amount > 0 ? 'bg-green-50/30' : ''}`}>
                                                        {amount > 0 ? (
                                                            <>
                                                                <a href={route('payroll.payslip', [emp.id, quinzaines[0]])} target="_blank" rel="noreferrer" title="Bulletin de paie (PDF)"
                                                                    className="font-bold text-gray-800 hover:text-primary-700 hover:underline">{formatNumber(amount)}</a>
                                                                {/* Paid in a second division this period (moved): its own bulletin. */}
                                                                {quinzaines.slice(1).map((qid, i) => (
                                                                    <a key={qid} href={route('payroll.payslip', [emp.id, qid])} target="_blank" rel="noreferrer" title="Bulletin de l'autre division"
                                                                        className="ml-1 text-[11px] font-bold text-primary-700 hover:underline">+ {i + 2}</a>
                                                                ))}
                                                            </>
                                                        ) : (
                                                            <span className="text-gray-300">-</span>
                                                        )}
                                                    </td>
                                                );
                                            })}
                                            <td className="border-b border-gray-100 px-3 py-2 sm:sticky sm:right-0 z-10 bg-primary-50 font-bold text-primary-800 text-right whitespace-nowrap group-hover:bg-primary-100">
                                                {formatNumber(totalOf(emp))}
                                            </td>
                                        </tr>
                                    ))}
                                    {rows.length === 0 && (
                                        <tr>
                                            <td colSpan={periodesVues.length + 2} className="p-16 text-center text-gray-400 italic">
                                                {employees.length === 0 ? "Aucun salarié trouvé. Assurez-vous d'avoir ajouté des salariés dans la gestion du personnel." : 'Aucun salarié ne correspond à ces filtres.'}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                                <tfoot>
                                    <tr className="text-white font-bold">
                                        <td className="p-3 sticky bottom-0 sm:left-0 bg-gray-900 z-30 text-xs uppercase tracking-wide text-right">Total période</td>
                                        {periodesVues.map(period => (
                                            <td key={period.key} className="p-3 sticky bottom-0 z-20 bg-gray-900 text-center text-green-300 whitespace-nowrap">
                                                {formatNumber(rows.reduce((s, emp) => s + montant(emp, period), 0))}
                                            </td>
                                        ))}
                                        <td className="p-3 sticky bottom-0 sm:right-0 bg-green-700 z-30 text-right whitespace-nowrap">
                                            {formatNumber(grandTotal)}
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
