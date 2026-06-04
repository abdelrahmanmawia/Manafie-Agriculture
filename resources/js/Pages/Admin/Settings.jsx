import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';

export default function Settings({ auth, enterprise, operations, blocs, quinzaines }) {
    const opForm = useForm({ name: '', enterprise_id: enterprise.id });
    const blocForm = useForm({ name: '', enterprise_id: enterprise.id });
    const qForm = useForm({ start_date: '', end_date: '', enterprise_id: enterprise.id });

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Settings - {enterprise.name}</h2>}
        >
            <Head title="Settings" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    {/* QUINZAINE MANAGEMENT */}
                    <div className="bg-white p-6 shadow sm:rounded-lg lg:col-span-3 border-t-4 border-green-500">
                        <h3 className="text-lg font-bold mb-4">Manage Periods (Quinzaines)</h3>
                        <form onSubmit={(e) => { e.preventDefault(); qForm.post(route('settings.quinzaine')); }} className="flex flex-wrap gap-4 items-end mb-6 bg-gray-50 p-4 rounded-lg">
                            <div>
                                <label className="block text-sm font-bold text-gray-700">Start Date</label>
                                <input type="date" className="rounded border-gray-300" value={qForm.data.start_date} onChange={e => qForm.setData('start_date', e.target.value)} />
                            </div>
                            <div>
                                <label className="block text-sm font-bold text-gray-700">End Date</label>
                                <input type="date" className="rounded border-gray-300" value={qForm.data.end_date} onChange={e => qForm.setData('end_date', e.target.value)} />
                            </div>
                            <button type="submit" className="bg-green-600 text-white px-6 py-2 rounded font-bold">Open New Period</button>
                        </form>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead>
                                    <tr className="text-left font-bold text-gray-500 uppercase tracking-wider text-xs">
                                        <th className="px-4 py-2">Period</th>
                                        <th className="px-4 py-2">Status</th>
                                        <th className="px-4 py-2 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {quinzaines.map(q => (
                                        <tr key={q.id}>
                                            <td className="px-4 py-3">{q.start_date} to {q.end_date}</td>
                                            <td className="px-4 py-3">
                                                {q.is_closed ? 
                                                    <span className="bg-gray-100 text-gray-600 px-2 py-1 rounded text-[10px] font-bold">CLOSED</span> : 
                                                    <span className="bg-green-100 text-green-700 px-2 py-1 rounded text-[10px] font-bold">OPEN</span>
                                                }
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {!q.is_closed && (
                                                    <Link 
                                                        method="post"
                                                        as="button"
                                                        href={route('settings.quinzaine.close', q.id)}
                                                        className="text-red-600 font-bold hover:underline"
                                                    >
                                                        Close Period
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* OPERATIONS MANAGEMENT */}
                    <div className="bg-white p-6 shadow sm:rounded-lg border-t-4 border-blue-500">
                        <h3 className="text-lg font-bold mb-4">Manage Operations</h3>
                        <form onSubmit={(e) => { e.preventDefault(); opForm.post(route('settings.operation'), { onSuccess: () => opForm.reset() }); }} className="flex gap-2 mb-4">
                            <input type="text" placeholder="e.g. Harvesting" className="flex-1 rounded border-gray-300 text-sm" value={opForm.data.name} onChange={e => opForm.setData('name', e.target.value)} />
                            <button type="submit" className="bg-blue-600 text-white px-4 py-2 rounded font-bold">+</button>
                        </form>
                        <ul className="divide-y divide-gray-100 max-h-60 overflow-y-auto">
                            {operations.map(op => (
                                <li key={op.id} className="py-2 flex justify-between text-sm items-center">
                                    <span>{op.name}</span> 
                                    <Link 
                                        href={route('settings.operation.destroy', op.id)} 
                                        method="delete" 
                                        as="button" 
                                        className="text-red-400 hover:text-red-600 text-xs font-bold"
                                    >
                                        Delete
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* BLOCS MANAGEMENT */}
                    <div className="bg-white p-6 shadow sm:rounded-lg border-t-4 border-blue-500">
                        <h3 className="text-lg font-bold mb-4">Manage Blocs</h3>
                        <form onSubmit={(e) => { e.preventDefault(); blocForm.post(route('settings.bloc'), { onSuccess: () => blocForm.reset() }); }} className="flex gap-2 mb-4">
                            <input type="text" placeholder="e.g. Sector 1" className="flex-1 rounded border-gray-300 text-sm" value={blocForm.data.name} onChange={e => blocForm.setData('name', e.target.value)} />
                            <button type="submit" className="bg-blue-600 text-white px-4 py-2 rounded font-bold">+</button>
                        </form>
                        <ul className="divide-y divide-gray-100 max-h-60 overflow-y-auto">
                            {blocs.map(b => (
                                <li key={b.id} className="py-2 flex justify-between text-sm items-center">
                                    <span>{b.name}</span> 
                                    <Link 
                                        href={route('settings.bloc.destroy', b.id)} 
                                        method="delete" 
                                        as="button" 
                                        className="text-red-400 hover:text-red-600 text-xs font-bold"
                                    >
                                        Delete
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
