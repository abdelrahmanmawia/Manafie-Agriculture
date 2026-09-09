import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

// Day-by-day grid, rows = vehicles (not employees) — mirrors Pointage/Grid.jsx's sticky-table
// shape, but each cell is a plain toggle (this vehicle operated this day or not) rather than an
// operation/bloc picker, since a vehicle's cost is a flat net_per_day, not hours-based. See
// TransportController::grid()/toggleAttendance() and TransportVehicle::netPerDay().
export default function Grid({ auth, quinzaine, vehicles, days, existingAttendances }) {
    const [pendingCell, setPendingCell] = useState(null);

    const isMarked = (vehicleId, day) => !!(existingAttendances[vehicleId] && existingAttendances[vehicleId][day]);

    const toggleCell = (vehicleId, day) => {
        if (quinzaine.is_closed) return;
        const key = `${vehicleId}_${day}`;
        setPendingCell(key);
        router.post(route('transport.attendance.toggle'), {
            transport_vehicle_id: vehicleId,
            quinzaine_id: quinzaine.id,
            date: day,
        }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setPendingCell(null),
        });
    };

    const grandTotal = vehicles.reduce((sum, v) => {
        const daysCount = days.filter(day => isMarked(v.id, day)).length;
        return sum + daysCount * v.net_per_day;
    }, 0);

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

                    {quinzaine.is_closed && (
                        <div className="bg-gray-100 border border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-600">
                            Cette période est clôturée — les cases ne peuvent plus être modifiées. Les montants figés sont visibles sur le <Link href={route('transport.summary.index')} className="underline font-semibold">Résumé Transport</Link>.
                        </div>
                    )}

                    <div className="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="overflow-x-auto p-4">
                            <table className="min-w-full border-collapse border border-gray-200 text-[11px]">
                                <thead>
                                    <tr className="bg-gray-100">
                                        <th className="border border-gray-300 p-2 sticky left-0 z-20 bg-gray-100 min-w-[200px] shadow-md uppercase tracking-tighter text-left">Véhicule</th>
                                        {days.map(day => (
                                            <th key={day} className="border border-gray-300 p-1 min-w-[34px] text-gray-600">
                                                {day.split('-')[2]}
                                            </th>
                                        ))}
                                        <th className="border border-gray-300 p-2 bg-primary-50 sticky right-[90px] z-20 text-primary-800 font-black shadow-md w-[70px] min-w-[70px] whitespace-nowrap">Jours</th>
                                        <th className="border border-gray-300 p-2 bg-primary-700 sticky right-0 z-20 text-white font-black shadow-md uppercase tracking-tighter w-[90px] min-w-[90px] whitespace-nowrap">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {vehicles.map(vehicle => {
                                        const daysCount = days.filter(day => isMarked(vehicle.id, day)).length;
                                        const total = daysCount * vehicle.net_per_day;
                                        return (
                                            <tr key={vehicle.id} className="hover:bg-primary-50 transition-colors group">
                                                <td className="border border-gray-200 p-2 font-bold bg-white sticky left-0 z-10 shadow-sm group-hover:bg-primary-50 text-left">
                                                    <div className="flex flex-col">
                                                        <span className="text-gray-900 leading-tight uppercase tracking-tighter">
                                                            {vehicle.code}
                                                            <span className="text-gray-400 font-normal normal-case"> — {vehicle.company_name || '—'}</span>
                                                        </span>
                                                        <span className="text-[9px] text-gray-400">
                                                            {vehicle.net_per_day} dh/jour
                                                            <span className={vehicle.fixed_net_per_day !== null ? 'text-amber-600 font-bold' : ''}> ({vehicle.fixed_net_per_day !== null ? 'fixe' : 'auto'})</span>
                                                        </span>
                                                    </div>
                                                </td>
                                                {days.map(day => {
                                                    const marked = isMarked(vehicle.id, day);
                                                    const pending = pendingCell === `${vehicle.id}_${day}`;
                                                    return (
                                                        <td
                                                            key={day}
                                                            onClick={() => toggleCell(vehicle.id, day)}
                                                            className={`border border-gray-200 p-1 text-center transition-all select-none ${quinzaine.is_closed ? 'cursor-not-allowed' : 'cursor-pointer'} ${marked ? 'bg-green-100 border-green-200' : 'bg-white'} ${pending ? 'opacity-50' : ''} ${!quinzaine.is_closed ? 'hover:ring-2 hover:ring-inset hover:ring-primary-400' : ''}`}
                                                        >
                                                            {marked ? <span className="text-green-700 font-black">✓</span> : ''}
                                                        </td>
                                                    );
                                                })}
                                                <td className="border border-gray-300 p-2 text-center font-black bg-primary-50 sticky right-[90px] z-10">{daysCount}</td>
                                                <td className="border border-gray-300 p-2 text-center font-black bg-primary-700 text-white sticky right-0 z-10">{total.toFixed(2)} dh</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                                {vehicles.length > 0 && (
                                    <tfoot>
                                        <tr className="bg-gray-800 text-white">
                                            <td className="border border-gray-700 p-2 sticky left-0 z-10 bg-gray-800 font-black uppercase tracking-widest text-left" colSpan={days.length + 2}>Total Général</td>
                                            <td className="border border-gray-700 p-2 text-center font-black sticky right-0 bg-gray-800">{grandTotal.toFixed(2)} dh</td>
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
