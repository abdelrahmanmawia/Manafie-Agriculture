import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function SuperDashboard({ auth, enterprises }) {
    const { data, setData, post, processing, reset } = useForm({
        name: '',
        contract_type: 'avec_contrat',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('enterprises.store'), {
            onSuccess: () => reset(),
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Super Admin Dashboard - All Fermes</h2>}
        >
            <Head title="Super Admin Dashboard" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    
                    {/* CREATE ENTERPRISE */}
                    <div className="bg-white p-6 shadow sm:rounded-lg border-t-4 border-blue-600">
                        <h3 className="text-lg font-bold mb-4">Create New Ferme (Enterprise)</h3>
                        <form onSubmit={submit} className="flex flex-wrap gap-4 items-end">
                            <div className="flex-1 min-w-[200px]">
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">Nom de la Ferme</label>
                                <input 
                                    type="text" 
                                    placeholder="Enterprise Name" 
                                    className="w-full rounded border-gray-300" 
                                    value={data.name} 
                                    onChange={e => setData('name', e.target.value)} 
                                />
                            </div>
                            <div className="min-w-[200px]">
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">Type de Contrat Global</label>
                                <select 
                                    className="w-full rounded border-gray-300"
                                    value={data.contract_type}
                                    onChange={e => setData('contract_type', e.target.value)}
                                >
                                    <option value="avec_contrat">Avec Contrat (CNSS + AMO)</option>
                                    <option value="sans_contrat">Sans Contrat (Hafila/Direct)</option>
                                </select>
                            </div>
                            <button type="submit" disabled={processing} className="bg-blue-600 text-white px-6 py-2 rounded font-bold h-[42px]">Create Ferme</button>
                        </form>
                    </div>

                    {/* ENTERPRISE LIST */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        {enterprises.map(ent => (
                            <div key={ent.id} className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 border border-gray-200">
                                <div className="flex justify-between items-start mb-2">
                                    <h4 className="text-xl font-black text-gray-900">{ent.name}</h4>
                                    <span className={`px-2 py-1 rounded text-[8px] font-black uppercase ${ent.contract_type === 'avec_contrat' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'}`}>
                                        {ent.contract_type.replace('_', ' ')}
                                    </span>
                                </div>
                                <div className="text-sm text-gray-500 space-y-1 mb-4">
                                    <p>Workers: <span className="font-bold text-gray-800">{ent.employees_count}</span></p>
                                    <p>Periods: <span className="font-bold text-gray-800">{ent.quinzaines_count}</span></p>
                                </div>
                                <div className="flex gap-2">
                                    <Link 
                                        href={route('pointage.index', { enterprise_id: ent.id })} 
                                        className="text-xs bg-blue-600 text-white hover:bg-blue-700 px-3 py-1 rounded font-bold transition-colors"
                                    >
                                        Pointage
                                    </Link>
                                    <Link 
                                        href={route('settings.index', { enterprise_id: ent.id })} 
                                        className="text-xs bg-gray-100 hover:bg-gray-200 px-3 py-1 rounded font-bold transition-colors"
                                    >
                                        Settings
                                    </Link>
                                    <Link 
                                        href={route('employees.index', { enterprise_id: ent.id })} 
                                        className="text-xs bg-gray-100 hover:bg-gray-200 px-3 py-1 rounded font-bold transition-colors"
                                    >
                                        Employees
                                    </Link>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
