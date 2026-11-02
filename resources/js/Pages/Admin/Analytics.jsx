import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatCard from '@/Components/StatCard';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatNumber } from '@/Helpers/formatNumber';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Cell, LineChart, Line, LabelList } from 'recharts';

const COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#6366f1'];
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const AXE = { fontSize: 11, fill: '#6b7280' };

// A period's name from its dates, with the year: the free-text labels differ between divisions.
const nomPeriode = (startDate) => {
    const [annee, mois, jour] = String(startDate).slice(0, 10).split('-').map(Number);
    return `${jour <= 15 ? 1 : 2}QZ ${MOIS[mois - 1]} ${annee}`;
};
const dh = (value) => `${formatNumber(value)} DH`;
const compact = (value) => (Math.abs(value) >= 1000 ? `${formatNumber(value / 1000, 0)} k` : formatNumber(value, 0));

function Carte({ titre, children }) {
    return (
        <div className="bg-white p-4 sm:p-6 rounded-2xl shadow-sm border border-gray-100">
            <h4 className="text-xs font-extrabold text-gray-500 mb-4 uppercase tracking-widest">{titre}</h4>
            {children}
        </div>
    );
}

function Filtre({ label, children }) {
    return (
        <label className="flex flex-col gap-1 min-w-0">
            <span className="text-[11px] font-bold uppercase text-gray-500 tracking-wide">{label}</span>
            {children}
        </label>
    );
}

const SELECT = 'w-full rounded-xl border-gray-200 bg-white font-bold text-xs shadow-sm focus:ring-primary-500';
const TH = 'py-2 px-3 text-[11px] font-extrabold text-gray-500 uppercase tracking-wide';

export default function Analytics({
    auth,
    opCosts = [],
    autresOperations = 0,
    blocCosts = [],
    horsBlocs = null,
    trend = [],
    totalNet = 0,
    cout = null,
    employeeCount = 0,
    netMoyenParJour = 0,
    avgCostPerHa = 0,
    precedente = null,
    periodeEnCours = false,
    selectedQuinzaineId = '',
    quinzaineFromId = '',
    quinzaineToId = '',
    blocId = '',
    sectorId = '',
    quinzaineOptions = [],
    enterprise,
    enterprises = [],
    blocs = [],
    sectors = [],
    // Harvest analytics
    harvestByVariety = [],
    harvestByBloc = [],
    harvestBySector = [],
    totalHarvestKg = 0,
    totalHarvestRevenue = 0,
    recoltesHorsPeriode = 0,
    costPerKg = null,
    coutBaseNet = false,
    ratiosComparables = true,
    // Productivity metrics
    revenuePerHa = null,
    yieldPerHa = null,
    laborCostPercentage = null,
    margePerHa = null,
}) {
    const [chargement, setChargement] = useState(false);

    // One entry per period, newest first; a filter sends one of its quinzaines.
    const periodes = [];
    quinzaineOptions.forEach(q => {
        const key = `${q.start_date}_${q.end_date}`;
        if (!periodes.some(p => p.key === key)) {
            periodes.push({ key, id: q.id, label: nomPeriode(q.start_date), finie: new Date(q.end_date) < new Date(new Date().toDateString()) });
        }
    });
    const cleDe = (id) => {
        const q = quinzaineOptions.find(o => String(o.id) === String(id));
        return q ? `${q.start_date}_${q.end_date}` : '';
    };
    const idDe = (key) => periodes.find(p => p.key === key)?.id ?? '';
    const deCle = cleDe(quinzaineFromId || selectedQuinzaineId);
    const aCle = cleDe(quinzaineToId || selectedQuinzaineId);
    const derniereFinie = periodes.find(p => p.finie);

    const params = (changes = {}) => {
        const all = {
            enterprise_id: enterprise?.id ?? '', bloc_id: blocId ?? '', sector_id: sectorId ?? '',
            quinzaine_from: idDe(deCle), quinzaine_to: idDe(aCle),
            ...changes,
        };
        return Object.fromEntries(Object.entries(all).filter(([, v]) => v !== '' && v !== null));
    };
    const filtrer = (changes) => router.get(route('analytics.index'), params(changes), {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setChargement(true),
        onFinish: () => setChargement(false),
    });
    const filtreActif = deCle || aCle || blocId || sectorId;

    // Against the period before, once this one is over. A plain figure, not a judgement.
    const ecart = (actuel, avant) => {
        if (!precedente || !(avant > 0)) return undefined;
        const pct = ((actuel - avant) / avant) * 100;
        const fleche = pct > 0 ? '▲' : pct < 0 ? '▼' : '=';
        return { direction: 'flat', label: `${fleche} ${formatNumber(Math.abs(pct), 1)} % vs ${nomPeriode(precedente.start_date)}` };
    };
    const ecartCout = cout && precedente ? ecart(cout.total, precedente.cout) : undefined;

    // A period still running is named so: its first days aren't a fall in the trend.
    const trendData = trend.map(t => ({ ...t, nom: `${nomPeriode(t.start_date)}${t.en_cours ? ' (en cours)' : ''}` }));
    const baseLabel = coutBaseNet ? 'Net' : 'Coût';

    // Why a figure against production is empty, rather than a misleading number.
    const ratioNote = !ratiosComparables ? 'Récolte de toute la ferme : voir sans filtre de division ni de secteur' : null;
    const horsBloc = horsBlocs && [
        horsBlocs.jf > 0 && `J.F non travaillés ${dh(horsBlocs.jf)}`,
        horsBlocs.salaires > 0 && `salaires fixes ${dh(horsBlocs.salaires)}`,
        horsBlocs.a_preciser > 0 && `jours à préciser ${dh(horsBlocs.a_preciser)}`,
    ].filter(Boolean);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap justify-between items-center gap-3">
                        <h2 className="font-black text-2xl text-gray-800 leading-tight tracking-tight">Analyses &amp; Statistiques</h2>
                        <a href={route('analytics.export', params())} target="_blank" rel="noreferrer"
                            className="inline-flex items-center px-4 py-2 rounded-lg border border-green-300 bg-green-50 text-green-800 text-sm font-bold hover:bg-green-100">
                            Exporter en Excel
                        </a>
                    </div>

                    <div className="grid grid-cols-2 sm:flex sm:flex-wrap sm:items-end gap-3 bg-gray-50 p-3 rounded-2xl border border-gray-100">
                        {enterprises.length > 0 && (
                            <div className="sm:w-48">
                                <Filtre label="Division">
                                    <select className={SELECT} value={enterprise?.id || ''}
                                        onChange={(e) => filtrer({ enterprise_id: e.target.value, quinzaine_from: '', quinzaine_to: '' })}>
                                        <option value="">Toutes</option>
                                        {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                    </select>
                                </Filtre>
                            </div>
                        )}
                        <div className="sm:w-32">
                            <Filtre label="Bloc">
                                <select className={SELECT} value={blocId || ''} onChange={(e) => filtrer({ bloc_id: e.target.value, sector_id: '' })}>
                                    <option value="">Tous</option>
                                    {blocs.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                </select>
                            </Filtre>
                        </div>
                        {blocId && (
                            <div className="sm:w-40">
                                <Filtre label="Secteur (récolte)">
                                    <select className={SELECT} value={sectorId || ''} onChange={(e) => filtrer({ sector_id: e.target.value })}>
                                        <option value="">Tous</option>
                                        {sectors.filter(s => s.bloc_id === parseInt(blocId)).map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                                    </select>
                                </Filtre>
                            </div>
                        )}
                        <div className="sm:w-40">
                            <Filtre label="Période : du">
                                <select className={SELECT} value={deCle} onChange={(e) => filtrer({ quinzaine_from: idDe(e.target.value) })}>
                                    <option value="">Le début</option>
                                    {periodes.map(p => <option key={p.key} value={p.key}>{p.label}</option>)}
                                </select>
                            </Filtre>
                        </div>
                        <div className="sm:w-40">
                            <Filtre label="au">
                                <select className={SELECT} value={aCle} onChange={(e) => filtrer({ quinzaine_to: idDe(e.target.value) })}>
                                    <option value="">La dernière</option>
                                    {periodes.map(p => <option key={p.key} value={p.key}>{p.label}</option>)}
                                </select>
                            </Filtre>
                        </div>
                        <div className="col-span-2 flex items-center gap-3 sm:pb-1.5">
                            {derniereFinie && (
                                <button type="button" onClick={() => filtrer({ quinzaine_from: derniereFinie.id, quinzaine_to: derniereFinie.id })}
                                    className="text-xs font-bold text-primary-700 hover:underline">
                                    Dernière quinzaine terminée
                                </button>
                            )}
                            {filtreActif && (
                                <button type="button" onClick={() => filtrer({ quinzaine_from: '', quinzaine_to: '', bloc_id: '', sector_id: '' })}
                                    className="text-xs font-bold text-gray-500 hover:underline">
                                    Réinitialiser
                                </button>
                            )}
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Analyses & Statistiques" />

            <div className={`py-6 bg-gray-50 min-h-screen transition-opacity ${chargement ? 'opacity-50' : ''}`}>
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

                    {/* What the farm pays, not what the workers get (CoutFermeService). */}
                    <div className="bg-primary-900 p-5 sm:p-6 rounded-2xl shadow-sm text-white flex flex-col lg:flex-row lg:items-center gap-4 lg:gap-10">
                        <div className="shrink-0">
                            <span className="text-[11px] font-extrabold uppercase text-primary-200 tracking-widest block mb-1">
                                Coût pour la ferme{enterprise ? ` — ${enterprise.name} (hors transport)` : ''}
                                {periodeEnCours && <span className="ml-2 px-2 py-0.5 rounded-full bg-amber-400 text-amber-950 normal-case tracking-normal">période en cours</span>}
                            </span>
                            <p className="text-3xl sm:text-4xl font-black leading-none">{cout ? dh(cout.total) : '—'}</p>
                            {ecartCout && <p className="text-xs font-bold text-primary-200 mt-2">{ecartCout.label}</p>}
                            {!cout && <p className="text-xs font-bold text-primary-200 mt-2">Connu par division, pas par bloc.</p>}
                        </div>
                        {cout && (
                            <dl className="grid grid-cols-2 lg:flex lg:flex-wrap gap-x-8 gap-y-2 text-sm">
                                {cout.ttc > 0 && <div><dt className="text-[11px] font-bold text-primary-200">TTC intérim</dt><dd className="font-bold">{formatNumber(cout.ttc)}</dd></div>}
                                {cout.net_direct > 0 && <div><dt className="text-[11px] font-bold text-primary-200">Net direct</dt><dd className="font-bold">{formatNumber(cout.net_direct)}</dd></div>}
                                {cout.marge > 0 && <div><dt className="text-[11px] font-bold text-primary-200">Marge 4 %</dt><dd className="font-bold">{formatNumber(cout.marge)}</dd></div>}
                                {cout.transport !== null && <div><dt className="text-[11px] font-bold text-primary-200">Transport (+ 10 %)</dt><dd className="font-bold">{formatNumber(cout.transport)}</dd></div>}
                            </dl>
                        )}
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                        <StatCard label="Net versé aux ouvriers (DH)" value={formatNumber(totalNet)} tone="green" trend={ecart(totalNet, precedente?.net)} />
                        <StatCard label={`${baseLabel} moyen / ha par quinzaine (DH)`} value={formatNumber(avgCostPerHa)} tone="orange"
                            trend={precedente ? ecart(avgCostPerHa, precedente.cost_per_ha) : (trend.length > 1 ? { direction: 'flat', label: 'Hors période en cours' } : undefined)} />
                        <StatCard label="Net moyen par jour travaillé (DH)" value={formatNumber(netMoyenParJour)} tone="blue" trend={ecart(netMoyenParJour, precedente?.net_par_jour)} />
                        <StatCard label="Ouvriers payés sur la période" value={formatNumber(employeeCount, 0)} tone="gray" trend={ecart(employeeCount, precedente?.employes)} />
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <Carte titre="Net par opération (top 10)">
                            {opCosts.length === 0 ? <p className="text-gray-400 text-sm text-center py-8">Aucune donnée disponible</p> : (
                                <div style={{ height: Math.max(220, opCosts.length * 36) }}>
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={opCosts} layout="vertical" margin={{ left: 0, right: 56 }}>
                                            <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="#f3f4f6" />
                                            <XAxis type="number" hide />
                                            <YAxis dataKey="name" type="category" width={130} tick={AXE} interval={0} />
                                            <Tooltip formatter={(v) => [dh(v), 'Net']} contentStyle={{ borderRadius: '12px' }} />
                                            <Bar dataKey="total_net" fill="#3b82f6" radius={[0, 4, 4, 0]}>
                                                <LabelList dataKey="total_net" position="right" formatter={compact} style={AXE} />
                                            </Bar>
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            )}
                            {autresOperations > 0 && <p className="text-xs font-bold text-gray-500 mt-3">+ autres opérations : {dh(autresOperations)}</p>}
                        </Carte>

                        <Carte titre="Net par bloc">
                            {blocCosts.length === 0 ? <p className="text-gray-400 text-sm text-center py-8">Aucune donnée disponible</p> : (
                                <>
                                    <div className="h-48">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={blocCosts} margin={{ top: 18 }}>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                                                <XAxis dataKey="name" tick={AXE} />
                                                <YAxis hide />
                                                <Tooltip formatter={(v) => [dh(v), 'Net']} contentStyle={{ borderRadius: '12px' }} />
                                                <Bar dataKey="total_net" fill="#10b981" radius={[4, 4, 0, 0]}>
                                                    <LabelList dataKey="total_net" position="top" formatter={compact} style={AXE} />
                                                </Bar>
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="overflow-x-auto mt-3">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b-2 border-gray-100">
                                                    <th className={`${TH} text-left`}>Bloc</th>
                                                    <th className={`${TH} text-right`}>Surface</th>
                                                    <th className={`${TH} text-right`}>Net</th>
                                                    <th className={`${TH} text-right text-primary-700`}>Net / ha</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {blocCosts.map(b => (
                                                    <tr key={b.bloc_id} className="border-b border-gray-50">
                                                        <td className="py-2 px-3 font-bold text-gray-800">{b.name}</td>
                                                        <td className="py-2 px-3 text-right text-gray-600 whitespace-nowrap">{b.area_ha ? `${formatNumber(b.area_ha)} ha` : '—'}</td>
                                                        <td className="py-2 px-3 text-right font-bold text-gray-800 whitespace-nowrap">{dh(b.total_net)}</td>
                                                        <td className="py-2 px-3 text-right font-bold text-primary-800 whitespace-nowrap">{b.charge_per_ha !== null ? dh(b.charge_per_ha) : '—'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </>
                            )}
                            {/* The rest of the total: paid, but on no bloc. */}
                            {horsBloc?.length > 0 && <p className="text-xs font-bold text-gray-500 mt-3">+ hors bloc : {horsBloc.join(' · ')}</p>}
                        </Carte>
                    </div>

                    {/* An evolution needs at least two points. */}
                    {trendData.length > 1 && (
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <Carte titre="Évolution du net versé">
                                <div className="h-56">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <LineChart data={trendData} margin={{ left: 0, right: 16, top: 8 }}>
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                                            <XAxis dataKey="nom" tick={AXE} />
                                            <YAxis tick={AXE} width={48} tickFormatter={compact} />
                                            <Tooltip formatter={(v) => [dh(v), 'Net']} contentStyle={{ borderRadius: '12px' }} />
                                            <Line type="monotone" dataKey="total_net" stroke="#10b981" strokeWidth={3} dot={{ r: 5 }} />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                            </Carte>
                            <Carte titre={`${baseLabel} / ha par quinzaine`}>
                                <div className="h-56">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <LineChart data={trendData} margin={{ left: 0, right: 16, top: 8 }}>
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                                            <XAxis dataKey="nom" tick={AXE} />
                                            <YAxis tick={AXE} width={48} tickFormatter={compact} />
                                            <Tooltip formatter={(v) => [`${dh(v)}/ha`, baseLabel]} contentStyle={{ borderRadius: '12px' }} />
                                            <Line type="monotone" dataKey="cost_per_ha" stroke="#f59e0b" strokeWidth={3} dot={{ r: 5 }} />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                            </Carte>
                        </div>
                    )}

                    {/* PRODUCTION */}
                    <h3 className="text-sm font-extrabold text-gray-700 uppercase tracking-widest pt-2">Production — récolte</h3>

                    {recoltesHorsPeriode > 0 && (
                        <p className="text-xs font-bold text-amber-700 bg-amber-50 rounded-xl px-4 py-2">
                            {recoltesHorsPeriode} récolte(s) en dehors de ces périodes ne sont pas comptées : la production est comparée aux mêmes dates que la paie.
                        </p>
                    )}

                    {totalHarvestKg <= 0 ? (
                        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center">
                            <p className="text-sm font-bold text-gray-500">Aucune récolte sur cette période.</p>
                            <p className="text-xs text-gray-400 mt-1">
                                Le coût au kilo et la marge s'afficheront dès qu'une récolte sera saisie dans{' '}
                                <Link href={route('harvests.index')} className="font-bold text-primary-700 hover:underline">Récoltes</Link>.
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                                <StatCard label="Total récolte (Kg)" value={formatNumber(totalHarvestKg, 0)} tone="green"
                                    trend={yieldPerHa !== null ? { direction: 'flat', label: `${formatNumber(yieldPerHa, 0)} Kg/ha` } : undefined} />
                                <StatCard label="Chiffre d'affaires (DH)" value={formatNumber(totalHarvestRevenue)} tone="green"
                                    trend={revenuePerHa !== null ? { direction: 'flat', label: `${dh(revenuePerHa)}/ha` } : undefined} />
                                <StatCard label={`${baseLabel} main d'œuvre (DH/Kg)`} value={costPerKg !== null ? formatNumber(costPerKg) : '—'} tone="orange"
                                    trend={laborCostPercentage !== null ? { direction: 'flat', label: `${formatNumber(laborCostPercentage, 1)} % du CA` } : undefined} />
                                {/* Not a profit: inputs, fuel and the rest aren't in it. */}
                                <StatCard label="Marge sur main-d'œuvre (DH/ha)" value={margePerHa !== null ? formatNumber(margePerHa) : '—'} tone="blue" />
                            </div>
                            {ratioNote && <p className="text-xs font-bold text-gray-500">{ratioNote}</p>}

                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <Carte titre="Récolte par variété">
                                    <div className="h-64">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={harvestByVariety} margin={{ top: 18 }}>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                                                <XAxis dataKey="variety" tick={AXE} />
                                                <YAxis hide />
                                                <Tooltip formatter={(v) => [`${formatNumber(v)} Kg`, 'Quantité']} contentStyle={{ borderRadius: '12px' }} />
                                                <Bar dataKey="total_kg" radius={[6, 6, 0, 0]}>
                                                    {harvestByVariety.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
                                                    <LabelList dataKey="total_kg" position="top" formatter={compact} style={AXE} />
                                                </Bar>
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </Carte>

                                {harvestByBloc.length > 0 && (
                                    <Carte titre="Rendement par bloc">
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="border-b-2 border-gray-100">
                                                        <th className={`${TH} text-left`}>Bloc</th>
                                                        <th className={`${TH} text-right`}>Surface</th>
                                                        <th className={`${TH} text-right`}>Total</th>
                                                        <th className={`${TH} text-right text-green-700`}>Kg / ha</th>
                                                        <th className={`${TH} text-right`}>Revenu</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {harvestByBloc.map((h, i) => (
                                                        <tr key={i} className="border-b border-gray-50">
                                                            <td className="py-2 px-3 font-bold text-gray-800">{h.name}</td>
                                                            <td className="py-2 px-3 text-right text-gray-600 whitespace-nowrap">{h.area_ha ? `${formatNumber(h.area_ha)} ha` : '—'}</td>
                                                            <td className="py-2 px-3 text-right font-bold whitespace-nowrap">{formatNumber(h.total_kg, 0)} Kg</td>
                                                            <td className="py-2 px-3 text-right font-bold text-green-800 whitespace-nowrap">{h.yield_per_ha !== null ? formatNumber(h.yield_per_ha) : '—'}</td>
                                                            <td className="py-2 px-3 text-right font-bold text-gray-700 whitespace-nowrap">{dh(h.total_revenue)}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </Carte>
                                )}
                            </div>

                            {harvestBySector.length > 0 && (
                                <Carte titre="Rendement par secteur">
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b-2 border-gray-100">
                                                    <th className={`${TH} text-left`}>Bloc</th>
                                                    <th className={`${TH} text-left`}>Secteur</th>
                                                    <th className={`${TH} text-right`}>Surface</th>
                                                    <th className={`${TH} text-right`}>Arbres</th>
                                                    <th className={`${TH} text-right`}>Total</th>
                                                    <th className={`${TH} text-right text-green-700`}>Kg / ha</th>
                                                    <th className={`${TH} text-right text-green-700`}>Kg / arbre</th>
                                                    <th className={`${TH} text-right`}>Revenu</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {harvestBySector.map((h, i) => (
                                                    <tr key={i} className="border-b border-gray-50">
                                                        <td className="py-2 px-3 font-bold text-gray-800">{h.bloc_name}</td>
                                                        <td className="py-2 px-3 font-bold text-gray-800">{h.name}</td>
                                                        <td className="py-2 px-3 text-right text-gray-600 whitespace-nowrap">{h.area_ha ? `${formatNumber(h.area_ha)} ha` : '—'}</td>
                                                        <td className="py-2 px-3 text-right text-gray-600">{h.total_trees ? formatNumber(h.total_trees, 0) : '—'}</td>
                                                        <td className="py-2 px-3 text-right font-bold whitespace-nowrap">{formatNumber(h.total_kg, 0)} Kg</td>
                                                        <td className="py-2 px-3 text-right font-bold text-green-800">{h.yield_per_ha !== null ? formatNumber(h.yield_per_ha) : '—'}</td>
                                                        <td className="py-2 px-3 text-right font-bold text-green-800">{h.yield_per_tree !== null ? formatNumber(h.yield_per_tree, 3) : '—'}</td>
                                                        <td className="py-2 px-3 text-right font-bold text-gray-700 whitespace-nowrap">{dh(h.total_revenue)}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </Carte>
                            )}
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
