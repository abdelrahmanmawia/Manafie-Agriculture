import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

// Reads TransportController::summary() — a closed period's breakdown comes from the frozen
// transport_snapshots rows; an open period is computed live (TransportService::previewForDateRange)
// and will only lock in once a division's quinzaine for it closes (see EnterpriseController::closeQuinzaine()).
export default function Summary({ auth, periods, selectedPeriodKey, breakdown, isClosed }) {
    const changePeriod = (key) => {
        router.get(route('transport.summary.index'), key ? { period: key } : {}, { preserveState: true });
    };

    const selectedPeriod = periods.find((p) => p.key === selectedPeriodKey);
    const grandTotal = breakdown.reduce((sum, row) => sum + row.subtotal, 0);

    const byCompany = breakdown.reduce((acc, row) => {
        const key = row.company_name || 'Sans société';
        (acc[key] ??= []).push(row);
        return acc;
    }, {});

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div>
                    <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Résumé Transport</h2>
                    <p className="text-sm text-gray-500 mt-1">Coût de transport par société et véhicule</p>
                </div>
            }
        >
            <Head title="Résumé Transport" />

            <div className="py-8">
                <div className="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <label htmlFor="period_select" className="block text-sm font-medium text-gray-700 mb-1">Période</label>
                            <select
                                id="period_select"
                                className="border-gray-300 focus:border-primary-500 focus:ring-primary-500 rounded-md shadow-sm w-full sm:w-80"
                                value={selectedPeriodKey || ''}
                                onChange={(e) => changePeriod(e.target.value)}
                            >
                                {periods.length === 0 && <option value="">Aucune période disponible</option>}
                                {periods.map((p) => (
                                    <option key={p.key} value={p.key}>{p.label}{p.is_closed ? '' : ' (en cours)'}</option>
                                ))}
                            </select>
                        </div>
                        {selectedPeriod && (
                            <Link
                                href={route('transport.grid', selectedPeriod.quinzaine_id)}
                                className="inline-flex items-center px-6 py-2.5 bg-primary-600 border border-transparent rounded-full font-black text-xs text-white uppercase tracking-widest shadow-sm hover:bg-primary-700 transition"
                            >
                                Ouvrir la Grille Transport
                            </Link>
                        )}
                    </div>

                    {selectedPeriodKey && !isClosed && (
                        <div className="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-700">
                            Cette période n'est pas encore clôturée — estimation en direct, se figera automatiquement à la clôture de la quinzaine.
                        </div>
                    )}

                    {selectedPeriodKey && breakdown.length === 0 && (
                        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 text-sm text-gray-500 italic">
                            Aucun véhicule marqué comme ayant circulé pour cette période. Remplissez la Grille Transport de la quinzaine d'abord.
                        </div>
                    )}

                    {Object.entries(byCompany).map(([companyName, rows]) => (
                        <div key={companyName} className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                                <h3 className="font-black text-gray-800 uppercase tracking-tight">{companyName}</h3>
                                <span className="font-bold text-primary-600">{rows.reduce((s, r) => s + r.subtotal, 0).toFixed(2)} dh</span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-gray-500 uppercase text-xs tracking-wider">
                                            <th className="px-6 py-2 font-semibold">Véhicule</th>
                                            <th className="px-6 py-2 font-semibold text-right">Effectif</th>
                                            <th className="px-6 py-2 font-semibold text-right">Net / jour</th>
                                            <th className="px-6 py-2 font-semibold text-right">Jours</th>
                                            <th className="px-6 py-2 font-semibold text-right">Sous-total</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {rows.map((row, i) => (
                                            <tr key={i}>
                                                <td className="px-6 py-2">{row.vehicle_code || '—'}</td>
                                                <td className="px-6 py-2 text-right">{row.employee_count}</td>
                                                <td className="px-6 py-2 text-right">{row.net_per_day.toFixed(2)} dh</td>
                                                <td className="px-6 py-2 text-right">{row.days_count}</td>
                                                <td className="px-6 py-2 text-right font-semibold">{row.subtotal.toFixed(2)} dh</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ))}

                    {breakdown.length > 0 && (
                        <div className="bg-gray-800 rounded-2xl shadow-sm px-6 py-4 flex items-center justify-between">
                            <span className="font-black text-white uppercase tracking-tight">Total Général</span>
                            <span className="font-black text-xl text-white">{grandTotal.toFixed(2)} dh</span>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
