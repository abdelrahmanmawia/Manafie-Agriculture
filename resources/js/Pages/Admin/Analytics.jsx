import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { formatNumber } from '@/Helpers/formatNumber';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, PieChart, Pie, Cell, LineChart, Line } from 'recharts';

const COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#6366f1'];

export default function Analytics({
    auth,
    opCosts = [],
    blocCosts = [],
    trend = [],
    totalNet = 0,
    employeeCount = 0,
    avgNetPerEmployee = 0,
    avgCostPerHour = 0,
    selectedQuinzaineId = '',
    quinzaineFromId = '',
    quinzaineToId = '',
    blocId = '',
    quinzaineOptions = [],
    farm,
    enterprise,
    farms = [],
    enterprises = [],
    blocs = [],
}) {

    const handleFilterChange = (params) => {
        router.get(route('analytics.index'), {
            farm_id: farm?.id || '',
            enterprise_id: enterprise?.id || '',
            bloc_id: blocId,
            quinzaine_id: selectedQuinzaineId,
            quinzaine_from: quinzaineFromId,
            quinzaine_to: quinzaineToId,
            ...params
        });
    };

    const handleFarmChange = (e) => {
        handleFilterChange({ farm_id: e.target.value, enterprise_id: '', bloc_id: '', quinzaine_id: '', quinzaine_from: '', quinzaine_to: '' });
    };

    const handleEnterpriseChange = (e) => {
        handleFilterChange({ enterprise_id: e.target.value, quinzaine_id: '', quinzaine_from: '', quinzaine_to: '' });
    };

    const handleQuinzaineChange = (e) => {
        handleFilterChange({ quinzaine_id: e.target.value, quinzaine_from: '', quinzaine_to: '' });
    };

    const handleRangeChange = (from, to) => {
        handleFilterChange({ quinzaine_id: '', quinzaine_from: from, quinzaine_to: to });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <h2 className="font-black text-2xl text-gray-800 leading-tight uppercase tracking-tighter shrink-0">Tableau de Bord Analytique</h2>
                        <div className="flex flex-wrap sm:flex-nowrap gap-3 w-full justify-end">
                            {auth.user.role === 'super_admin' && (
                                <select
                                    className="rounded-xl border-gray-200 bg-white font-bold text-xs shadow-sm focus:ring-blue-500 min-w-[150px]"
                                    value={farm?.id || ''}
                                    onChange={handleFarmChange}
                                >
                                    <option value="">-- Toutes les Fermes --</option>
                                    {farms.map(f => <option key={f.id} value={f.id}>{f.name}</option>)}
                                </select>
                            )}
                            {(auth.user.role === 'super_admin' || auth.user.farm_id) && (
                                <select
                                    className="rounded-xl border-gray-200 bg-white font-bold text-xs shadow-sm focus:ring-blue-500 min-w-[150px]"
                                    value={enterprise?.id || ''}
                                    onChange={handleEnterpriseChange}
                                    disabled={!farm && auth.user.role === 'super_admin'}
                                >
                                    <option value="">-- Toutes les Divisions --</option>
                                    {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                </select>
                            )}
                        </div>
                    </div>

                    {/* ADVANCED FILTERS ROW */}
                    <div className="flex flex-wrap items-center gap-4 bg-gray-50 p-3 rounded-2xl border border-gray-100">
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest">Bloc:</span>
                            <select
                                className="rounded-xl border-gray-200 bg-white font-bold text-[10px] shadow-sm focus:ring-blue-500 min-w-[120px]"
                                value={blocId || ''}
                                onChange={(e) => handleFilterChange({ bloc_id: e.target.value })}
                                disabled={!farm}
                            >
                                <option value="">Tous les blocs</option>
                                {blocs.map(b => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                        </div>

                        <div className="h-4 w-[1px] bg-gray-300 hidden sm:block mx-2"></div>

                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest">Période:</span>
                            <select
                                className="rounded-xl border-gray-200 bg-white font-bold text-[10px] shadow-sm focus:ring-blue-500"
                                value={selectedQuinzaineId || ''}
                                onChange={handleQuinzaineChange}
                            >
                                <option value="">Toutes les périodes</option>
                                {quinzaineOptions.map(q => (
                                    <option key={q.id} value={q.id}>{q.label} ({q.start_date})</option>
                                ))}
                            </select>
                        </div>

                        <div className="h-4 w-[1px] bg-gray-300 hidden sm:block mx-2"></div>

                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest">Intervalle:</span>
                            <div className="flex items-center gap-1">
                                <select
                                    className="rounded-xl border-gray-200 bg-white font-bold text-[10px] shadow-sm focus:ring-blue-500"
                                    value={quinzaineFromId || ''}
                                    onChange={(e) => handleRangeChange(e.target.value, quinzaineToId)}
                                >
                                    <option value="">De...</option>
                                    {quinzaineOptions.map(q => (
                                        <option key={q.id} value={q.id}>{q.label} ({q.start_date})</option>
                                    ))}
                                </select>
                                <span className="text-gray-400 font-bold">→</span>
                                <select
                                    className="rounded-xl border-gray-200 bg-white font-bold text-[10px] shadow-sm focus:ring-blue-500"
                                    value={quinzaineToId || ''}
                                    onChange={(e) => handleRangeChange(quinzaineFromId, e.target.value)}
                                >
                                    <option value="">À...</option>
                                    {quinzaineOptions.map(q => (
                                        <option key={q.id} value={q.id}>{q.label} ({q.start_date})</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {(selectedQuinzaineId || quinzaineFromId || quinzaineToId || blocId) && (
                            <button 
                                onClick={() => handleFilterChange({ quinzaine_id: '', quinzaine_from: '', quinzaine_to: '', bloc_id: '' })}
                                className="text-[10px] font-black text-red-500 uppercase hover:underline ml-auto"
                            >
                                Réinitialiser
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Analyses & Statistiques" />

            <div className="py-6 sm:py-12 bg-gray-50 min-h-screen">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8 sm:space-y-12">

                    {/* SECTION 1: FINANCIAL OVERVIEW */}
                    <div>
                        <div className="flex justify-between items-center mb-6">
                            <h3 className="text-lg sm:text-xl font-black text-blue-900 uppercase tracking-widest flex items-center gap-3">
                                <span className="bg-blue-600 text-white p-2 rounded-lg">
                                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m.599-1c.53-.05 1.07-.13 1.599-.24M12 16H11.401c-.53-.05-1.07-.13-1.599-.24M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </span>
                                Analyses Financières
                            </h3>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6 mb-8">
                            <div className="bg-white p-6 rounded-2xl shadow-sm border-l-8 border-blue-600">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Masse Salariale Net</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(totalNet)}
                                    <small className="text-xs ml-1 font-bold">DH</small>
                                </p>
                            </div>
                            <div className="bg-white p-6 rounded-2xl shadow-sm border-l-8 border-green-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Top Opération</span>
                                <p className="text-xl sm:text-2xl font-black text-gray-900 leading-none uppercase truncate">{opCosts[0]?.name || 'N/A'}</p>
                            </div>
                            <div className="bg-white p-6 rounded-2xl shadow-sm border-l-8 border-yellow-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Top Bloc (Coût)</span>
                                <p className="text-xl sm:text-2xl font-black text-gray-900 leading-none uppercase truncate">{blocCosts[0]?.name || 'N/A'}</p>
                            </div>

                                {/* PREDICTIVE FORECAST CARD REMOVED FOR NOW */}
                            <div className="bg-blue-900 p-6 rounded-2xl shadow-xl border-l-8 border-blue-400 text-white relative overflow-hidden group hover:scale-[1.02] transition-transform duration-300">
                                <div className="relative z-10">
                                    <span className="text-[10px] font-black uppercase text-blue-300 tracking-widest block mb-1 flex items-center gap-1">
                                        Vue d'ensemble
                                    </span>
                                    <p className="text-sm font-bold text-blue-200 opacity-80">
                                        Ferme: {farm?.name || 'Globale'}
                                    </p>
                                    <p className="text-sm font-bold text-blue-200 opacity-80">
                                        Division: {enterprise?.name || 'Toutes'}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-6 mb-8">
                            <div className="bg-green-50 p-6 rounded-2xl shadow-sm border-l-8 border-green-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Total Salariés Actifs</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">{formatNumber(employeeCount, 0)}</p>
                            </div>
                            <div className="bg-white p-6 rounded-2xl shadow-sm border-l-8 border-blue-600">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Net Moyen par Salarié</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(avgNetPerEmployee)} <small className="text-xs ml-1 font-bold">DH</small>
                                </p>
                            </div>
                            <div className="bg-white p-6 rounded-2xl shadow-sm border-l-8 border-yellow-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Coût Moyen / Heure</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(avgCostPerHour)} <small className="text-xs ml-1 font-bold">DH</small>
                                </p>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8">
                            <div className="bg-white p-4 sm:p-8 rounded-3xl shadow-sm border border-gray-100">
                                <h4 className="text-[10px] sm:text-sm font-black text-gray-400 mb-6 uppercase tracking-widest">Coûts par Opération (Top 10)</h4>
                                <div className="h-64 sm:h-80 w-full"><ResponsiveContainer width="100%" height="100%"><BarChart data={opCosts} layout="vertical"><CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="#f3f4f6" /><XAxis type="number" hide /><YAxis dataKey="name" type="category" width={80} tick={{fontSize: 8, fontWeight: 'bold'}} /><Tooltip contentStyle={{borderRadius: '12px', border: 'none'}} /><Bar dataKey="total_net" fill="#3b82f6" radius={[0, 4, 4, 0]} /></BarChart></ResponsiveContainer></div>
                            </div>
                            <div className="bg-white p-4 sm:p-8 rounded-3xl shadow-sm border border-gray-100">
                                <h4 className="text-[10px] sm:text-sm font-black text-gray-400 mb-6 uppercase tracking-widest">Répartition par Bloc</h4>
                                <div className="h-64 sm:h-80 w-full"><ResponsiveContainer width="100%" height="100%"><BarChart data={blocCosts}><CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" /><XAxis dataKey="name" tick={{fontSize: 8, fontWeight: 'bold'}} /><YAxis /><Tooltip /><Bar dataKey="total_net" fill="#10b981" radius={[4, 4, 0, 0]} /></BarChart></ResponsiveContainer></div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8 mt-8">
                            <div className="bg-white p-4 sm:p-8 rounded-3xl shadow-sm border border-gray-100">
                                <h4 className="text-[10px] sm:text-sm font-black text-gray-400 mb-6 uppercase tracking-widest">Tendance (Masse Salariale)</h4>
                                <div className="h-64 sm:h-80 w-full"><ResponsiveContainer width="100%" height="100%"><LineChart data={trend}><CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" /><XAxis dataKey="label" tick={{fontSize: 8, fontWeight: 'bold'}} /><YAxis tick={{fontSize: 10}} /><Tooltip /><Line type="monotone" dataKey="total_net" stroke="#10b981" strokeWidth={4} dot={{r: 6}} /></LineChart></ResponsiveContainer></div>
                            </div>
                            <div className="bg-white p-4 sm:p-8 rounded-3xl shadow-sm border border-gray-100">
                                <h4 className="text-[10px] sm:text-sm font-black text-gray-400 mb-6 uppercase tracking-widest">Coût Horaire Moyen (DH/H)</h4>
                                <div className="h-64 sm:h-80 w-full">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <LineChart data={trend}>
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                                            <XAxis dataKey="label" tick={{fontSize: 8, fontWeight: 'bold'}} />
                                            <YAxis tick={{fontSize: 10}} />
                                            <Tooltip />
                                            <Line type="monotone" dataKey="cost_per_hour" stroke="#f59e0b" strokeWidth={4} dot={{r: 6}} />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
