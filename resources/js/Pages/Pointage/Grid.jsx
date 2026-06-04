import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import axios from 'axios';

export default function Grid({ auth, quinzaine, employees, operations, blocs, days, existingRecords }) {
    const [selectedCell, setSelectedCell] = useState(null);
    const [summary, setSummary] = useState([]);
    
    const { data, setData, post, processing, reset, errors } = useForm({
        employee_id: '',
        quinzaine_id: quinzaine.id,
        operation_id: '',
        bloc_id: '',
        date: '',
        hours: 0, // This is H.S
    });

    const openForm = (employeeId, date) => {
        if (quinzaine.is_closed) return; // Prevent editing if closed

        const record = existingRecords[employeeId]?.[date]?.[0];
        setSelectedCell({ employeeId, date });
        setData({
            employee_id: employeeId,
            quinzaine_id: quinzaine.id,
            operation_id: record?.operation_id || '',
            bloc_id: record?.bloc_id || '',
            date: date,
            hours: record?.hours || 0,
        });
    };

    const fetchSummary = async () => {
        const response = await axios.get(route('pointage.summary', quinzaine.id));
        setSummary(response.data);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('pointage.cell'), {
            onSuccess: () => {
                setSelectedCell(null);
                fetchSummary();
            },
            preserveScroll: true,
        });
    };

    useEffect(() => {
        fetchSummary();
    }, []);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Pointage Grid: {quinzaine.start_date} - {quinzaine.end_date}
                    </h2>
                    {quinzaine.is_closed && (
                        <span className="bg-red-100 text-red-700 px-4 py-2 rounded-full font-black text-sm border-2 border-red-200 shadow-sm animate-pulse">
                            LOCKED / CLOSED
                        </span>
                    )}
                </div>
            }
        >
            <Head title="Pointage Grid" />

            <div className="py-6">
                <div className="max-w-full mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* 1. THE GRID */}
                    <div className="bg-white overflow-x-auto shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-lg font-bold mb-4 text-blue-800">1. Daily Pointage Matrix (Click a cell to edit)</h3>
                        <table className="min-w-full border-collapse border border-gray-300 text-[10px]">
                            <thead>
                                <tr>
                                    <th className="border border-gray-300 p-2 bg-gray-100 sticky left-0 z-10 min-w-[150px]">Employee</th>
                                    {days.map(day => (
                                        <th key={day} className="border border-gray-300 p-1 bg-gray-100 min-w-[40px]">
                                            {day.split('-')[2]}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {employees.map(emp => (
                                    <tr key={emp.id}>
                                        <td className="border border-gray-300 p-2 font-bold bg-gray-50 sticky left-0 z-10">
                                            {emp.full_name}
                                        </td>
                                        {days.map(day => {
                                            const record = existingRecords[emp.id]?.[day]?.[0];
                                            return (
                                                <td 
                                                    key={day} 
                                                    onClick={() => openForm(emp.id, day)}
                                                    className={`border border-gray-300 p-1 text-center cursor-pointer hover:bg-blue-50 transition-colors ${record ? 'bg-green-100' : 'bg-white'}`}
                                                >
                                                    {record ? (
                                                        <div className="font-bold">
                                                            {operations.find(o => o.id === record.operation_id)?.name.substring(0, 3)}
                                                            <br/>
                                                            {record.hours > 0 && <span className="text-blue-600">+{record.hours}h</span>}
                                                        </div>
                                                    ) : '-'}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* 2. THE AUTOMATIC SUMMARY */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-lg font-bold mb-4 text-green-800">2. Operations Summary (Updates automatically)</h3>
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Operation</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bloc</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider text-center">Total H.S</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider text-center">Workers</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider text-right">Estimated Cost</th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200 text-sm">
                                {summary.length === 0 ? (
                                    <tr><td colSpan="5" className="px-6 py-4 text-center text-gray-500 italic">No data yet for this period.</td></tr>
                                ) : (
                                    summary.map((item, index) => (
                                        <tr key={index}>
                                            <td className="px-6 py-4 font-medium">{item.operation}</td>
                                            <td className="px-6 py-4">{item.bloc}</td>
                                            <td className="px-6 py-4 text-center">{item.total_hours}h</td>
                                            <td className="px-6 py-4 text-center">{item.worker_count}</td>
                                            <td className="px-6 py-4 text-right font-bold text-green-700">{parseFloat(item.total_net).toFixed(2)} DH</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* POPUP FORM */}
                    {selectedCell && (
                        <div className="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center z-50">
                            <div className="bg-white p-6 rounded-lg shadow-2xl w-full max-w-md border-t-4 border-blue-600">
                                <h3 className="text-xl font-bold mb-2">
                                    Pointage: {employees.find(e => e.id === selectedCell.employeeId).full_name}
                                </h3>
                                <p className="text-sm text-gray-500 mb-6 font-medium">Date: {selectedCell.date}</p>
                                
                                <form onSubmit={submit} className="space-y-6">
                                    <div className="space-y-4">
                                        <div>
                                            <label className="block text-sm font-bold text-gray-700 mb-1">Status / Operation</label>
                                            <select 
                                                className="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                value={data.operation_id}
                                                onChange={e => setData('operation_id', e.target.value)}
                                            >
                                                <option value="">-- Absent --</option>
                                                {operations.map(o => <option key={o.id} value={o.id}>{o.name}</option>)}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-sm font-bold text-gray-700 mb-1">Bloc (Location)</label>
                                            <select 
                                                className="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                value={data.bloc_id}
                                                onChange={e => setData('bloc_id', e.target.value)}
                                            >
                                                <option value="">-- Select Bloc --</option>
                                                {blocs.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                            </select>
                                        </div>
                                        <div className="bg-blue-50 p-4 rounded-lg">
                                            <label className="block text-sm font-bold text-blue-900 mb-1">H.S (Heures Supplémentaires)</label>
                                            <p className="text-[10px] text-blue-700 mb-2 italic">Enter 0 if no overtime. 1 day is already counted.</p>
                                            <input 
                                                type="number" 
                                                step="0.5"
                                                min="0"
                                                className="block w-full rounded-lg border-blue-200 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                value={data.hours}
                                                onChange={e => setData('hours', e.target.value)}
                                            />
                                        </div>
                                    </div>

                                    <div className="flex justify-end space-x-3 pt-6 border-t border-gray-100">
                                        <button 
                                            type="button" 
                                            onClick={() => setSelectedCell(null)}
                                            className="bg-gray-100 hover:bg-gray-200 px-5 py-2.5 rounded-lg text-sm font-bold text-gray-700 transition-colors"
                                        >
                                            Cancel
                                        </button>
                                        <button 
                                            type="submit" 
                                            disabled={processing}
                                            className="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2.5 rounded-lg font-bold shadow-lg transition-all disabled:opacity-50"
                                        >
                                            {processing ? 'Saving...' : 'Save Pointage'}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
