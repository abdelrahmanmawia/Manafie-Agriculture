import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { formatNumber } from '@/utils/number';

export default function Index({ auth, vehicles, days, startDate, endDate, existingUsages, closedDays, availableQuinzaines }) {
    const { errors } = usePage().props;
    const [customStart, setCustomStart] = useState(startDate);
    const [customEnd, setCustomEnd] = useState(endDate);

    const closedSet = new Set(closedDays || []);

    // One click toggles the cell: off -> on (at the vehicle's own default_daily_rate, set once
    // from the vehicle's edit form, not retyped every day), on -> off. Mirrors the Pointage grid's
    // own quick-toggle interaction.
    const toggleCell = (vehicle, date) => {
        if (closedSet.has(date)) {
            return; // Closed period — server would reject this anyway, don't even try.
        }
        router.post(route('stock.vehicle-usage.cell'), {
            vehicle_id: vehicle.id,
            date,
        }, { preserveScroll: true });
    };

    const goToRange = (start, end) => {
        router.get(route('stock.vehicle-usage.index'), { start_date: start, end_date: end });
    };

    const shiftPeriod = (direction) => {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const spanDays = Math.round((end - start) / 86400000) + 1;
        start.setDate(start.getDate() + direction * spanDays);
        end.setDate(end.getDate() + direction * spanDays);
        goToRange(start.toISOString().split('T')[0], end.toISOString().split('T')[0]);
    };

    const selectQuinzaine = (e) => {
        const value = e.target.value;
        if (!value) return;
        const [start, end] = value.split('|');
        goToRange(start, end);
    };

    const applyCustomRange = (e) => {
        e.preventDefault();
        if (customStart && customEnd) {
            goToRange(customStart, customEnd);
        }
    };

    const vehicleTotal = (vehicleId) => {
        const perDate = existingUsages[vehicleId] || {};
        return Object.values(perDate).reduce((sum, entries) => sum + parseFloat(entries[0]?.daily_rate || 0), 0);
    };

    const dayTotal = (date) => {
        return vehicles.reduce((sum, v) => sum + parseFloat(existingUsages[v.id]?.[date]?.[0]?.daily_rate || 0), 0);
    };

    const grandTotal = vehicles.reduce((sum, v) => sum + vehicleTotal(v.id), 0);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-3">
                    <div className="flex justify-between items-center">
                        <div className="flex flex-col">
                            <h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">
                                Location / Utilisation des Véhicules
                            </h2>
                            <span className="text-[10px] text-gray-400 font-black uppercase tracking-widest">
                                {startDate} au {endDate}
                            </span>
                        </div>
                        <div className="flex gap-2">
                            <button onClick={() => shiftPeriod(-1)} className="bg-gray-100 hover:bg-gray-200 px-4 py-1.5 rounded-full font-bold text-xs">
                                ← Période préc.
                            </button>
                            <button onClick={() => shiftPeriod(1)} className="bg-gray-100 hover:bg-gray-200 px-4 py-1.5 rounded-full font-bold text-xs">
                                Période suiv. →
                            </button>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <select
                            onChange={selectQuinzaine}
                            defaultValue=""
                            className="text-xs font-bold rounded-lg border-gray-200 bg-gray-50 py-1.5"
                        >
                            <option value="">-- Aller à une quinzaine --</option>
                            {(availableQuinzaines || []).map((q, i) => (
                                <option key={i} value={`${q.start_date}|${q.end_date}`}>
                                    {q.label || `${q.start_date} au ${q.end_date}`}
                                </option>
                            ))}
                        </select>

                        <form onSubmit={applyCustomRange} className="flex items-center gap-2">
                            <input
                                type="date"
                                value={customStart}
                                onChange={e => setCustomStart(e.target.value)}
                                className="text-xs font-bold rounded-lg border-gray-200 bg-gray-50 py-1.5"
                            />
                            <span className="text-xs text-gray-400 font-bold">au</span>
                            <input
                                type="date"
                                value={customEnd}
                                onChange={e => setCustomEnd(e.target.value)}
                                className="text-xs font-bold rounded-lg border-gray-200 bg-gray-50 py-1.5"
                            />
                            <button type="submit" className="bg-gray-700 hover:bg-gray-800 text-white px-3 py-1.5 rounded-full font-bold text-xs">
                                Filtrer
                            </button>
                        </form>
                    </div>
                </div>
            }
        >
            <Head title="Location Véhicules" />

            <div className="py-6">
                <div className="max-w-full mx-auto sm:px-4 lg:px-8 space-y-4">
                    {(errors?.date || errors?.daily_rate) && (
                        <div className="bg-red-50 border border-red-200 text-red-700 text-sm font-bold rounded-xl p-4">
                            {errors.date || errors.daily_rate}
                        </div>
                    )}

                    <p className="text-xs text-gray-400 font-bold uppercase tracking-widest">
                        Cliquez sur une case pour marquer/démarquer l'utilisation ce jour-là (au tarif journalier par défaut du véhicule).
                        Les jours grisés 🔒 appartiennent à une quinzaine clôturée et ne peuvent plus être modifiés.
                    </p>

                    <section className="bg-white shadow-2xl sm:rounded-2xl border-t-8 border-purple-600 overflow-hidden">
                        <div className="overflow-x-auto p-4">
                            <table className="min-w-full border-collapse border border-gray-200 text-[10px]">
                                <thead>
                                    <tr className="bg-gray-100">
                                        <th className="border border-gray-300 p-2 sticky left-0 z-20 bg-gray-100 min-w-[180px] shadow-md uppercase tracking-tighter">Véhicule / Équipement</th>
                                        {days.map(day => (
                                            <th key={day} className={`border border-gray-300 p-1 min-w-[38px] text-gray-600 ${closedSet.has(day) ? 'bg-gray-200' : ''}`}>
                                                {day.split('-')[2]}{closedSet.has(day) ? ' 🔒' : ''}
                                            </th>
                                        ))}
                                        <th className="border border-gray-300 p-2 bg-purple-700 sticky right-0 z-20 text-white font-black shadow-md uppercase tracking-tighter">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {vehicles.map(vehicle => (
                                        <tr key={vehicle.id} className="hover:bg-purple-50 transition-colors group">
                                            <td className="border border-gray-200 p-2 font-bold bg-white sticky left-0 z-10 shadow-sm group-hover:bg-purple-50">
                                                <div className="flex flex-col">
                                                    <span className="text-gray-900 leading-none mb-1 uppercase tracking-tighter">{vehicle.name}</span>
                                                    <span className="text-[7px] text-gray-400 font-black tracking-widest">{vehicle.plate_number || vehicle.serial_number || ''}</span>
                                                </div>
                                            </td>
                                            {days.map(day => {
                                                const usage = existingUsages[vehicle.id]?.[day]?.[0];
                                                const isClosed = closedSet.has(day);
                                                return (
                                                    <td
                                                        key={day}
                                                        onClick={() => toggleCell(vehicle, day)}
                                                        className={`border border-gray-200 p-1 text-center transition-all ${isClosed
                                                            ? 'bg-gray-100 cursor-not-allowed text-gray-400'
                                                            : usage
                                                                ? 'bg-purple-100 border-purple-200 hover:bg-purple-200 cursor-pointer'
                                                                : 'bg-white hover:bg-gray-50 cursor-pointer'
                                                        }`}
                                                    >
                                                        {usage ? (
                                                            <span className={`font-black text-[9px] ${isClosed ? 'text-gray-400' : 'text-purple-800'}`}>{formatNumber(usage.daily_rate, 0)}</span>
                                                        ) : isClosed ? '🔒' : '-'}
                                                    </td>
                                                );
                                            })}
                                            <td className="border border-gray-200 p-2 text-right font-black text-purple-900 bg-purple-100 sticky right-0 z-10 shadow-sm">
                                                {formatNumber(vehicleTotal(vehicle.id))}
                                            </td>
                                        </tr>
                                    ))}
                                    {vehicles.length === 0 && (
                                        <tr>
                                            <td colSpan={days.length + 2} className="p-8 text-center text-gray-400 italic">
                                                Aucun véhicule marqué "Disponible en Location" — activez cette option depuis la page Véhicules.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-yellow-50 font-black">
                                        <td className="p-3 border border-gray-300 sticky left-0 z-10 bg-yellow-50 text-right uppercase text-[8px] tracking-widest text-orange-900">Total / Jour</td>
                                        {days.map(day => (
                                            <td key={day} className="border border-gray-300 p-1 text-center text-orange-800 text-[8px] shadow-inner">
                                                {dayTotal(day) > 0 ? formatNumber(dayTotal(day), 0) : '-'}
                                            </td>
                                        ))}
                                        <td className="border border-gray-300 bg-purple-700 text-white text-right px-4 py-3 uppercase tracking-widest text-xs">
                                            {formatNumber(grandTotal)} DH
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
