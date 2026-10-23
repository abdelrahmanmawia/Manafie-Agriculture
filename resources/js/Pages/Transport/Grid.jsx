import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

// Day-by-day grid, rows = vehicles — mirrors Pointage/Grid.jsx's sticky-table shape. Nothing is
// ticked by hand any more: each cell shows how many of the vehicle's riders worked that day and
// what that costs (TransportService::daily()). The only manual input is the exception "the
// vehicle didn't come" (its riders came on their own: paid, but no transport cost that day),
// set by clicking a cell (TransportController::setAbsence()).
export default function Grid({ auth, quinzaine, vehicles, days, cells }) {
    const [pendingCell, setPendingCell] = useState(null);

    const cellOf = (vehicleId, day) => cells[vehicleId]?.[day] ?? { riders: 0, absent: false, cost: 0 };
    const chargedDays = (vehicle) => days.map(day => cellOf(vehicle.id, day)).filter(c => !c.absent && c.riders > 0);
    const totalOf = (vehicle) => chargedDays(vehicle).reduce((sum, c) => sum + c.cost, 0);
    const grandTotal = vehicles.reduce((sum, v) => sum + totalOf(v), 0);

    const priceLabel = (vehicle) => vehicle.fixed_net_per_day !== null
        ? `forfait ${vehicle.fixed_net_per_day} dh/jour`
        : vehicle.price_per_person !== null ? `${vehicle.price_per_person} dh/personne` : null;

    // Sends the state the click asks for (not "toggle"), so a double-click or a retried request
    // can't flip it back; clicks on a cell already being saved are ignored.
    const toggleAbsence = (vehicleId, day) => {
        if (quinzaine.is_closed) return;
        const key = `${vehicleId}_${day}`;
        if (pendingCell === key) return;
        setPendingCell(key);
        router.post(route('transport.absence.set'), {
            transport_vehicle_id: vehicleId,
            quinzaine_id: quinzaine.id,
            date: day,
            absent: !cellOf(vehicleId, day).absent,
        }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setPendingCell(null),
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Grille Transport</h2>
                        <p className="text-sm text-gray-500 mt-1">{quinzaine.label} — {quinzaine.enterprise?.name}</p>
                    </div>
                    {quinzaine.is_closed ? (
                        <span className="bg-gray-200 text-gray-600 px-3 py-1.5 rounded text-xs font-black uppercase tracking-tighter">Clôturée</span>
                    ) : (
                        <span className="bg-green-100 text-green-700 px-3 py-1.5 rounded text-xs font-black uppercase tracking-tighter">Ouverte</span>
                    )}
                </div>
            }
        >
            <Head title="Grille Transport" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
                    {vehicles.length === 0 && (
                        <div className="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-700">
                            Aucun véhicule actif. <Link href={route('transport.vehicles.index')} className="underline font-semibold">Créez-en un d'abord</Link>.
                        </div>
                    )}

                    {quinzaine.is_closed ? (
                        <div className="bg-gray-100 border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-600">
                            Cette période est clôturée — les cases ne peuvent plus être modifiées. Les montants figés sont visibles sur le <Link href={route('transport.summary.index')} className="underline font-semibold">Résumé Transport</Link>.
                        </div>
                    ) : (
                        <div className="bg-white border border-gray-100 rounded-xl px-4 py-3 text-sm text-gray-600">
                            Calculé automatiquement depuis le pointage : <b>ouvriers des équipes du véhicule ayant travaillé × prix par personne</b>, 0 si aucun n'a travaillé.
                            Si le véhicule n'est pas venu et que des ouvriers sont venus par leurs propres moyens, cliquez la case : ils restent payés, le transport ne l'est pas ce jour-là.
                        </div>
                    )}

                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="overflow-x-auto p-4">
                            <table className="min-w-full border-collapse border border-gray-200 text-[11px]">
                                <thead>
                                    <tr className="bg-gray-100">
                                        <th className="border border-gray-300 p-2 sticky left-0 z-20 bg-gray-100 min-w-[200px] shadow-md uppercase tracking-tighter text-left">Véhicule</th>
                                        {days.map(day => (
                                            <th key={day} className="border border-gray-300 p-1 min-w-[44px] text-gray-600">
                                                {day.split('-')[2]}
                                            </th>
                                        ))}
                                        <th className="border border-gray-300 p-2 bg-primary-50 text-primary-800 font-black whitespace-nowrap" title="Jours facturés">Jours</th>
                                        <th className="border border-gray-300 p-2 bg-primary-50 text-primary-800 font-black whitespace-nowrap" title="Total des ouvriers transportés sur les jours facturés">Pers.</th>
                                        <th className="border border-gray-300 p-2 bg-primary-700 sticky right-0 z-20 text-white font-black shadow-md uppercase tracking-tighter w-[100px] min-w-[100px] whitespace-nowrap">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {vehicles.map(vehicle => {
                                        const charged = chargedDays(vehicle);
                                        const price = priceLabel(vehicle);
                                        return (
                                            <tr key={vehicle.id} className="hover:bg-primary-50 transition-colors group">
                                                <td className="border border-gray-200 p-2 font-bold bg-white sticky left-0 z-10 shadow-sm group-hover:bg-primary-50 text-left">
                                                    <div className="flex flex-col">
                                                        <span className="text-gray-900 leading-tight uppercase tracking-tighter">
                                                            {vehicle.code}
                                                            <span className="text-gray-400 font-normal normal-case"> — {vehicle.company_name || '—'}</span>
                                                        </span>
                                                        {price
                                                            ? <span className={`text-[9px] ${vehicle.fixed_net_per_day !== null ? 'text-amber-600 font-bold' : 'text-gray-400'}`}>{price}</span>
                                                            : <Link href={route('transport.vehicles.index')} className="text-[9px] text-red-600 underline">prix par personne non défini</Link>}
                                                    </div>
                                                </td>
                                                {days.map(day => {
                                                    const cell = cellOf(vehicle.id, day);
                                                    const pending = pendingCell === `${vehicle.id}_${day}`;
                                                    const tone = cell.absent
                                                        ? 'bg-gray-100 text-gray-400'
                                                        : cell.riders > 0 ? 'bg-green-100 border-green-200 text-green-800' : 'bg-white text-gray-300';
                                                    return (
                                                        <td
                                                            key={day}
                                                            onClick={() => toggleAbsence(vehicle.id, day)}
                                                            title={cell.absent
                                                                ? `Transport absent — ${cell.riders} ouvrier(s) venu(s) seul(s). Cliquer pour annuler.`
                                                                : `${cell.riders} ouvrier(s) — ${cell.cost.toFixed(2)} dh. Cliquer si le transport n'est pas venu.`}
                                                            className={`border border-gray-200 p-1 text-center transition-all select-none leading-tight ${tone} ${quinzaine.is_closed ? 'cursor-not-allowed' : 'cursor-pointer hover:ring-2 hover:ring-inset hover:ring-primary-400'} ${pending ? 'opacity-50' : ''}`}
                                                        >
                                                            {cell.absent ? (
                                                                <>
                                                                    <span className="block font-black text-[9px] uppercase text-red-500">abs.</span>
                                                                    {cell.riders > 0 && <span className="block text-[9px] line-through">{cell.riders}</span>}
                                                                </>
                                                            ) : cell.riders > 0 ? (
                                                                <>
                                                                    <span className="block font-black">{cell.riders}</span>
                                                                    <span className="block text-[9px] text-green-700">{cell.cost.toFixed(0)}</span>
                                                                </>
                                                            ) : '·'}
                                                        </td>
                                                    );
                                                })}
                                                <td className="border border-gray-300 p-2 text-center font-black bg-primary-50">{charged.length}</td>
                                                <td className="border border-gray-300 p-2 text-center font-black bg-primary-50">{charged.reduce((n, c) => n + c.riders, 0)}</td>
                                                <td className="border border-gray-300 p-2 text-center font-black bg-primary-700 text-white sticky right-0 z-10 whitespace-nowrap">{totalOf(vehicle).toFixed(2)} dh</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                                {vehicles.length > 0 && (
                                    <tfoot>
                                        <tr className="bg-gray-800 text-white">
                                            <td className="border border-gray-700 p-2 sticky left-0 z-10 bg-gray-800 font-black uppercase tracking-widest text-left" colSpan={days.length + 3}>Total Général</td>
                                            <td className="border border-gray-700 p-2 text-center font-black sticky right-0 bg-gray-800 whitespace-nowrap">{grandTotal.toFixed(2)} dh</td>
                                        </tr>
                                    </tfoot>
                                )}
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
