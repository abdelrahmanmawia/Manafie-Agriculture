import { router } from '@inertiajs/react';

const MODES = [
    ['mois', 'Mois'],
    ['quinzaine', 'Quinzaine'],
    ['dates', 'Dates'],
    ['tout', 'Tout'],
];

const thisMonth = () => new Date().toISOString().slice(0, 7);

// "2026-10" moved by n months.
const shiftMonth = (month, n) => {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + n, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

// "2026-10-2" moved by n quinzaines.
const shiftQuinzaine = (quinzaine, n) => {
    const [y, m, half] = quinzaine.split('-').map(Number);
    const index = (y * 12 + (m - 1)) * 2 + (half - 1) + n;
    const months = Math.floor(index / 2);
    return `${Math.floor(months / 12)}-${String((months % 12) + 1).padStart(2, '0')}-${(index % 2) + 1}`;
};

/**
 * The period a stock report covers (StockReportPeriod on the server): a month, a quinzaine
 * (1–15 / 16–fin, as the farm pays), two dates or everything, with ‹ › to step through months
 * and quinzaines. `extra` keeps the page's own filters (e.g. a movement type) on every change.
 */
export default function ReportPeriodPicker({ routeName, period, extra = {} }) {
    const visit = (params) => router.get(route(routeName), { ...params, ...extra }, { preserveState: true, preserveScroll: true, replace: true });

    const month = period.mois || period.quinzaine?.slice(0, 7) || thisMonth();
    const quinzaine = period.quinzaine || `${month}-${new Date().getDate() >= 16 && month === thisMonth() ? 2 : 1}`;

    const choose = (mode) => {
        if (mode === 'mois') visit({ periode: 'mois', mois: month });
        if (mode === 'quinzaine') visit({ periode: 'quinzaine', quinzaine });
        if (mode === 'dates') visit({ periode: 'dates', from: period.from || '', to: period.to || '' });
        if (mode === 'tout') visit({ periode: 'tout' });
    };

    const step = (n) => (period.periode === 'quinzaine'
        ? visit({ periode: 'quinzaine', quinzaine: shiftQuinzaine(quinzaine, n) })
        : visit({ periode: 'mois', mois: shiftMonth(month, n) }));

    const input = 'border border-gray-300 rounded-lg text-sm px-3 py-1.5 focus:ring-2 focus:ring-primary-500 focus:border-primary-500';
    const arrow = 'px-2.5 py-1.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm';

    return (
        <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-4 flex flex-wrap items-center gap-3">
            <div className="inline-flex rounded-lg border border-gray-200 p-0.5 bg-gray-50">
                {MODES.map(([mode, label]) => (
                    <button
                        key={mode}
                        type="button"
                        onClick={() => choose(mode)}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-md transition-colors ${
                            period.periode === mode ? 'bg-white shadow-sm text-primary-700' : 'text-gray-500 hover:text-gray-800'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {period.periode === 'mois' && (
                <div className="flex items-center gap-1.5">
                    <button type="button" className={arrow} onClick={() => step(-1)} title="Mois précédent">‹</button>
                    <input type="month" value={month} onChange={(e) => e.target.value && visit({ periode: 'mois', mois: e.target.value })} className={input} />
                    <button type="button" className={arrow} onClick={() => step(1)} title="Mois suivant">›</button>
                </div>
            )}

            {period.periode === 'quinzaine' && (
                <div className="flex items-center gap-1.5">
                    <button type="button" className={arrow} onClick={() => step(-1)} title="Quinzaine précédente">‹</button>
                    <input
                        type="month"
                        value={quinzaine.slice(0, 7)}
                        onChange={(e) => e.target.value && visit({ periode: 'quinzaine', quinzaine: `${e.target.value}-${quinzaine.slice(-1)}` })}
                        className={input}
                    />
                    <select
                        value={quinzaine.slice(-1)}
                        onChange={(e) => visit({ periode: 'quinzaine', quinzaine: `${quinzaine.slice(0, 7)}-${e.target.value}` })}
                        className={input}
                    >
                        <option value="1">1ère (1–15)</option>
                        <option value="2">2ème (16–fin)</option>
                    </select>
                    <button type="button" className={arrow} onClick={() => step(1)} title="Quinzaine suivante">›</button>
                </div>
            )}

            {period.periode === 'dates' && (
                <div className="flex flex-wrap items-center gap-2 text-sm text-gray-600">
                    <span>Du</span>
                    <input type="date" value={period.from || ''} onChange={(e) => visit({ periode: 'dates', from: e.target.value, to: period.to || '' })} className={input} />
                    <span>au</span>
                    <input type="date" value={period.to || ''} onChange={(e) => visit({ periode: 'dates', from: period.from || '', to: e.target.value })} className={input} />
                </div>
            )}

            <span className="ml-auto text-sm font-semibold text-gray-700">{period.label}</span>
        </div>
    );
}
