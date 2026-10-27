import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ReportPeriodPicker from '@/Components/ReportPeriodPicker';
import { Head, Link } from '@inertiajs/react';
import { formatNumber, formatMAD } from '@/utils/number';
import { UNIT_TYPE_LABELS } from '@/utils/stockLabels';

const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const dateFr = (d) => d.split('-').reverse().join('/');
const moisFr = (m) => `${MOIS[Number(m.slice(5, 7)) - 1]} ${m.slice(0, 4)}`;
const unite = (type) => UNIT_TYPE_LABELS[type] || type || '';
// How many lines from i on share the same key (a day, a month): the first one's cell spans
// them all, the others have none — one date per day however many engrais went out.
const span = (lines, i, key) => {
    if (i > 0 && lines[i - 1][key] === lines[i][key]) return 0;
    let n = 1;
    while (i + n < lines.length && lines[i + n][key] === lines[i][key]) n++;
    return n;
};
// A quantity cell: empty when nothing went to that bloc, like the farm's sheet.
const qte = (q) => (q ? formatNumber(q) : '');

const th = 'px-3 py-2 text-xs font-semibold text-gray-600 uppercase tracking-wider border border-gray-200';
const td = 'px-3 py-2 text-sm border border-gray-200';

function Kpi({ label, value, hint }) {
    return (
        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-5">
            <p className="text-sm text-gray-500">{label}</p>
            <p className="text-2xl font-bold text-gray-900 mt-1">{value}</p>
            {hint && <p className="text-xs text-gray-400 mt-1">{hint}</p>}
        </div>
    );
}

/**
 * Suivi des engrais (SuiviEngraisService): the farm's sheet — date, type d'engrais, one column
 * per bloc, total — then per engrais its quantities and dose per hectare, the cost per bloc and
 * per hectare, and month by month when the period spans several. Exported to Excel as is.
 */
export default function SuiviEngrais({ auth, suivi, period }) {
    const { blocs, lignes, engrais, couts, couts_ha: coutsHa, par_mois: parMois } = suivi;
    const { label, ...params } = period;
    const blocHeads = blocs.map((bloc) => (
        <th key={bloc.id ?? 'sans'} className={`${th} text-center`}>
            {bloc.name}
            {bloc.area_ha > 0 && <span className="block text-[10px] font-normal normal-case text-gray-400">{formatNumber(bloc.area_ha)} ha</span>}
        </th>
    ));

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <div className="flex items-center gap-4">
                        <Link href={route('stock.reports.index')} className="text-gray-500 hover:text-gray-700 transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Suivi des Engrais</h2>
                            <p className="text-sm text-gray-500 mt-1">Les sorties d'engrais par jour et par bloc, la dose par hectare et le coût</p>
                        </div>
                    </div>
                    <a
                        href={route('stock.reports.suivi-engrais.export', params)}
                        className="inline-flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg shadow-sm transition-colors"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Exporter Excel
                    </a>
                </div>
            }
        >
            <Head title="Suivi des Engrais" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <ReportPeriodPicker routeName="stock.reports.suivi-engrais" period={period} />

                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <Kpi label="Coût des engrais" value={formatMAD(suivi.cout_total)} />
                        <Kpi label="Coût par hectare" value={suivi.cout_ha != null ? formatMAD(suivi.cout_ha) : '—'} hint={`sur ${formatNumber(suivi.surface)} ha`} />
                        <Kpi label="Jours d'apport" value={suivi.jours} />
                        <Kpi label="Engrais utilisés" value={engrais.length} />
                    </div>

                    {/* The farm's sheet */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-5 border-b border-gray-100">
                            <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Situation des engrais — {label}</h3>
                        </div>
                        {lignes.length === 0 ? (
                            <p className="text-center py-12 text-gray-500">
                                Aucune sortie d'engrais sur cette période.
                                <span className="block text-xs text-gray-400 mt-1">Ce sont les sorties « Consommation » des produits d'une catégorie marquée Engrais (Produits → Gérer les catégories).</span>
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full border-collapse">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className={`${th} text-left`}>Date</th>
                                            <th className={`${th} text-left`}>Type d'engrais</th>
                                            {blocHeads}
                                            <th className={`${th} text-center`}>Total</th>
                                            <th className={`${th} text-right`}>Coût</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lignes.map((ligne, i) => (
                                            <tr key={`${ligne.date}-${ligne.product_id}`} className={i > 0 && lignes[i - 1].date !== ligne.date ? 'border-t-2 border-gray-300' : ''}>
                                                {span(lignes, i, 'date') > 0 && (
                                                    <td rowSpan={span(lignes, i, 'date')} className={`${td} whitespace-nowrap align-middle text-center font-semibold text-gray-800 bg-gray-50`}>{dateFr(ligne.date)}</td>
                                                )}
                                                <td className={`${td} font-medium text-gray-900`}>{ligne.engrais}</td>
                                                {ligne.quantites.map((q, b) => <td key={b} className={`${td} text-center text-gray-700`}>{qte(q)}</td>)}
                                                <td className={`${td} text-center font-semibold text-gray-900 whitespace-nowrap`}>{formatNumber(ligne.total)} {unite(ligne.unit_type)}</td>
                                                <td className={`${td} text-right text-gray-600 whitespace-nowrap`}>{formatMAD(ligne.cout)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    {engrais.length > 0 && (
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                            <div className="p-5 border-b border-gray-100">
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Récapitulatif par engrais</h3>
                                <p className="text-sm text-gray-500 mt-1">Quantité apportée sur chaque bloc et, dessous, la dose par hectare (quantité ÷ surface du bloc).</p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full border-collapse">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className={`${th} text-left`}>Engrais</th>
                                            {blocHeads}
                                            <th className={`${th} text-center`}>Total ferme</th>
                                            <th className={`${th} text-right`}>Coût</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {engrais.map((e) => (
                                            <tr key={e.product_id}>
                                                <td className={`${td} font-medium text-gray-900`}>{e.engrais}</td>
                                                {e.quantites.map((q, b) => (
                                                    <td key={b} className={`${td} text-center`}>
                                                        <span className="text-gray-800">{qte(q)}</span>
                                                        {q > 0 && e.doses[b] != null && <span className="block text-xs text-lime-700">{formatNumber(e.doses[b])} {unite(e.unit_type)}/ha</span>}
                                                    </td>
                                                ))}
                                                <td className={`${td} text-center`}>
                                                    <span className="font-semibold text-gray-900">{formatNumber(e.total)} {unite(e.unit_type)}</span>
                                                    {e.dose_totale != null && <span className="block text-xs text-lime-700">{formatNumber(e.dose_totale)} {unite(e.unit_type)}/ha</span>}
                                                </td>
                                                <td className={`${td} text-right text-gray-600 whitespace-nowrap`}>{formatMAD(e.cout)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot className="bg-amber-50 font-semibold">
                                        <tr>
                                            <td className={`${td} text-gray-800`}>Coût</td>
                                            {couts.map((c, b) => <td key={b} className={`${td} text-center whitespace-nowrap`}>{c ? formatMAD(c) : ''}</td>)}
                                            <td className={`${td} text-center whitespace-nowrap`}>{formatMAD(suivi.cout_total)}</td>
                                            <td className={td} />
                                        </tr>
                                        <tr>
                                            <td className={`${td} text-gray-800`}>Coût / ha</td>
                                            {coutsHa.map((c, b) => <td key={b} className={`${td} text-center whitespace-nowrap`}>{c ? formatMAD(c) : ''}</td>)}
                                            <td className={`${td} text-center whitespace-nowrap`}>{suivi.cout_ha != null ? formatMAD(suivi.cout_ha) : ''}</td>
                                            <td className={td} />
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    )}

                    {parMois.length > 0 && (
                        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                            <div className="p-5 border-b border-gray-100">
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Par mois</h3>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full border-collapse">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className={`${th} text-left`}>Mois</th>
                                            <th className={`${th} text-left`}>Engrais</th>
                                            {blocHeads}
                                            <th className={`${th} text-center`}>Total</th>
                                            <th className={`${th} text-right`}>Coût</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {parMois.map((ligne, i) => (
                                            <tr key={`${ligne.mois}-${ligne.product_id}`} className={i > 0 && parMois[i - 1].mois !== ligne.mois ? 'border-t-2 border-gray-300' : ''}>
                                                {span(parMois, i, 'mois') > 0 && (
                                                    <td rowSpan={span(parMois, i, 'mois')} className={`${td} whitespace-nowrap align-middle text-center capitalize font-semibold text-gray-800 bg-gray-50`}>{moisFr(ligne.mois)}</td>
                                                )}
                                                <td className={`${td} font-medium text-gray-900`}>{ligne.engrais}</td>
                                                {ligne.quantites.map((q, b) => <td key={b} className={`${td} text-center text-gray-700`}>{qte(q)}</td>)}
                                                <td className={`${td} text-center font-semibold text-gray-900 whitespace-nowrap`}>{formatNumber(ligne.total)} {unite(ligne.unit_type)}</td>
                                                <td className={`${td} text-right text-gray-600 whitespace-nowrap`}>{formatMAD(ligne.cout)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
