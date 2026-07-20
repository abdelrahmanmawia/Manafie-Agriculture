import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState, useEffect } from 'react'; // Import useEffect
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
    avgCostPerHa = 0,
    selectedQuinzaineId = '',
    quinzaineFromId = '',
    quinzaineToId = '',
    blocId = '',
    sectorId = '',
    quinzaineOptions = [],
    farm,
    enterprise,
    enterprises = [],
    blocs = [],
    sectors = [],
    // Harvest analytics
    harvestByVariety = [],
    harvestByBloc = [],
    harvestBySector = [],
    totalHarvestKg = 0,
    totalHarvestRevenue = 0,
    costPerKg = null,
    totalAreaHa = 0,
    // Productivity metrics
    revenuePerHa = 0,
    yieldPerHa = 0,
    laborCostPercentage = 0,
    profitPerHa = 0,
}) {
    const [activeTab, setActiveTab] = useState('financial');

    // Group quinzaines by unique periods (start_date + end_date)
    const getUniquePeriods = () => {
        const periodMap = new Map();

        quinzaineOptions.forEach(q => {
            const key = `${q.start_date}_${q.end_date}`;
            if (!periodMap.has(key)) {
                // Determine if it's 1QZ or 2QZ based on day of month
                const startDate = new Date(q.start_date);
                const day = startDate.getDate();
                const month = startDate.toLocaleDateString('fr-FR', { month: 'long' });
                const quinzaineNum = day <= 15 ? '1' : '2';
                const periodLabel = `${quinzaineNum}QZ ${month.charAt(0).toUpperCase() + month.slice(1)}`;

                periodMap.set(key, {
                    key,
                    start_date: q.start_date,
                    end_date: q.end_date,
                    label: periodLabel,
                    quinzaines: []
                });
            }
            periodMap.get(key).quinzaines.push(q);
        });

        return Array.from(periodMap.values());
    };

    const uniquePeriods = getUniquePeriods();
    const [selectedPeriodKey, setSelectedPeriodKey] = useState('');
    const [selectedPeriodFromKey, setSelectedPeriodFromKey] = useState('');
    const [selectedPeriodToKey, setSelectedPeriodToKey] = useState('');

    // Initialize selectedPeriodKey, selectedPeriodFromKey, selectedPeriodToKey based on props
    useEffect(() => {
        if (selectedQuinzaineId && quinzaineOptions.length > 0) {
            const selectedQ = quinzaineOptions.find(q => q.id === parseInt(selectedQuinzaineId));
            if (selectedQ) {
                setSelectedPeriodKey(`${selectedQ.start_date}_${selectedQ.end_date}`);
            } else {
                setSelectedPeriodKey('');
            }
        } else {
            setSelectedPeriodKey('');
        }

        if (quinzaineFromId && quinzaineOptions.length > 0) {
            const fromQ = quinzaineOptions.find(q => q.id === parseInt(quinzaineFromId));
            if (fromQ) {
                setSelectedPeriodFromKey(`${fromQ.start_date}_${fromQ.end_date}`);
            } else {
                setSelectedPeriodFromKey('');
            }
        } else {
            setSelectedPeriodFromKey('');
        }

        if (quinzaineToId && quinzaineOptions.length > 0) {
            const toQ = quinzaineOptions.find(q => q.id === parseInt(quinzaineToId));
            if (toQ) {
                setSelectedPeriodToKey(`${toQ.start_date}_${toQ.end_date}`);
            } else {
                setSelectedPeriodToKey('');
            }
        } else {
            setSelectedPeriodToKey('');
        }
    }, [selectedQuinzaineId, quinzaineFromId, quinzaineToId, quinzaineOptions]);


    const formatDate = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const handleFilterChange = (newParams) => {
        router.get(route('analytics.index'), {
            enterprise_id: enterprise?.id || '',
            bloc_id: blocId,
            sector_id: sectorId,
            // Default to empty string if not provided in newParams
            quinzaine_id: '',
            quinzaine_from: '',
            quinzaine_to: '',
            ...newParams // This will override the above defaults if newParams has them
        });
    };

    const handleEnterpriseChange = (e) => {
        handleFilterChange({ enterprise_id: e.target.value, quinzaine_id: '', quinzaine_from: '', quinzaine_to: '' });
    };

    const handleQuinzaineChange = (e) => {
        const periodKey = e.target.value;
        setSelectedPeriodKey(periodKey); // Update local state immediately
        const selectedPeriod = uniquePeriods.find(p => p.key === periodKey);
        const quinzaineIdToSend = selectedPeriod?.quinzaines[0]?.id || '';
        handleFilterChange({ quinzaine_id: quinzaineIdToSend, quinzaine_from: '', quinzaine_to: '' });
    };

    const handleRangeChange = (fromPeriodKey, toPeriodKey) => {
        setSelectedPeriodFromKey(fromPeriodKey); // Update local state immediately
        setSelectedPeriodToKey(toPeriodKey); // Update local state immediately

        const fromPeriod = uniquePeriods.find(p => p.key === fromPeriodKey);
        const toPeriod = uniquePeriods.find(p => p.key === toPeriodKey);
        const fromIdToSend = fromPeriod?.quinzaines[0]?.id || '';
        const toIdToSend = toPeriod?.quinzaines[0]?.id || '';
        handleFilterChange({ quinzaine_id: '', quinzaine_from: fromIdToSend, quinzaine_to: toIdToSend });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                        <h2 className="font-black text-2xl text-gray-800 leading-tight uppercase tracking-tighter shrink-0">Tableau de Bord Analytique</h2>
                        <div className="flex flex-wrap sm:flex-nowrap gap-3 w-full justify-end">
                            {(auth.user.role === 'super_admin' || auth.user.farm_id) && (
                                <select
                                    className="rounded-xl border-gray-200 bg-white font-bold text-xs shadow-sm focus:ring-blue-500 min-w-[150px]"
                                    value={enterprise?.id || ''}
                                    onChange={handleEnterpriseChange}
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
                                onChange={(e) => handleFilterChange({ bloc_id: e.target.value, sector_id: '' })}
                                disabled={!farm}
                            >
                                <option value="">Tous les blocs</option>
                                {blocs.map(b => (
                                    <option key={b.id} value={b.id}>{b.name}</option>
                                ))}
                            </select>
                        </div>

                        {blocId && (
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest">Secteur:</span>
                                <select
                                    className="rounded-xl border-gray-200 bg-white font-bold text-[10px] shadow-sm focus:ring-blue-500 min-w-[120px]"
                                    value={sectorId || ''}
                                    onChange={(e) => handleFilterChange({ sector_id: e.target.value })}
                                >
                                    <option value="">Tous les secteurs</option>
                                    {sectors.filter(s => s.bloc_id === parseInt(blocId)).map(s => (
                                        <option key={s.id} value={s.id}>{s.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}

                        <div className="h-4 w-[1px] bg-gray-300 hidden sm:block mx-2"></div>

                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest">Période:</span>
                            <select
                                className="rounded-xl border-gray-200 bg-white font-bold text-[10px] shadow-sm focus:ring-blue-500"
                                value={selectedPeriodKey || ''}
                                onChange={handleQuinzaineChange}
                            >
                                <option value="">Toutes les périodes</option>
                                {uniquePeriods.map(period => (
                                    <option key={period.key} value={period.key}>{period.label}</option>
                                ))}
                            </select>
                        </div>

                        <div className="h-4 w-[1px] bg-gray-300 hidden sm:block mx-2"></div>

                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest">Intervalle:</span>
                            <div className="flex items-center gap-1">
                                <select
                                    className="rounded-xl border-gray-200 bg-white font-bold text-[10px] shadow-sm focus:ring-blue-500"
                                    value={selectedPeriodFromKey || ''}
                                    onChange={(e) => handleRangeChange(e.target.value, selectedPeriodToKey)}
                                >
                                    <option value="">De...</option>
                                    {uniquePeriods.map(period => (
                                        <option key={period.key} value={period.key}>{period.label}</option>
                                    ))}
                                </select>
                                <span className="text-gray-400 font-bold">→</span>
                                <select
                                    className="rounded-xl border-gray-200 bg-white font-bold text-[10px] shadow-sm focus:ring-blue-500"
                                    value={selectedPeriodToKey || ''}
                                    onChange={(e) => handleRangeChange(selectedPeriodFromKey, e.target.value)}
                                >
                                    <option value="">À...</option>
                                    {uniquePeriods.map(period => (
                                        <option key={period.key} value={period.key}>{period.label}</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        {(selectedQuinzaineId || quinzaineFromId || quinzaineToId || blocId || sectorId) && (
                            <button
                                onClick={() => handleFilterChange({ quinzaine_id: '', quinzaine_from: '', quinzaine_to: '', bloc_id: '', sector_id: '' })}
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
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Coût Moyen / ha</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(avgCostPerHa)} <small className="text-xs ml-1 font-bold">DH</small>
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
                                <h4 className="text-[10px] sm:text-sm font-black text-gray-400 mb-6 uppercase tracking-widest">Coût Moyen / ha (DH/ha)</h4>
                                <div className="h-64 sm:h-80 w-full">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <LineChart data={trend}>
                                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                                            <XAxis dataKey="label" tick={{fontSize: 8, fontWeight: 'bold'}} />
                                            <YAxis tick={{fontSize: 10}} />
                                            <Tooltip />
                                            <Line type="monotone" dataKey="cost_per_ha" stroke="#f59e0b" strokeWidth={4} dot={{r: 6}} />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* SECTION 2: PRODUCTION ANALYTICS — Récolte & Charge/Ha */}
                    <div>
                        <div className="flex justify-between items-center mb-6">
                            <h3 className="text-lg sm:text-xl font-black text-green-900 uppercase tracking-widest flex items-center gap-3">
                                <span className="bg-green-600 text-white p-2 rounded-lg">
                                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" /></svg>
                                </span>
                                Analyses Production — Récolte
                            </h3>
                        </div>

                        {/* KPI Cards row */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
                            <div className="bg-white p-6 rounded-2xl shadow-sm border-l-8 border-green-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Total Récolte</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(totalHarvestKg, 0)}
                                    <small className="text-xs ml-1 font-bold">Kg</small>
                                </p>
                            </div>
                            <div className="bg-white p-6 rounded-2xl shadow-sm border-l-8 border-emerald-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Chiffre d'Affaires</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(totalHarvestRevenue)}
                                    <small className="text-xs ml-1 font-bold">DH</small>
                                </p>
                            </div>
                            <div className={`p-6 rounded-2xl shadow-sm border-l-8 ${costPerKg !== null ? 'bg-amber-50 border-amber-500' : 'bg-gray-50 border-gray-300'}`}>
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Coût Main d'Œuvre / Kg</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {costPerKg !== null ? formatNumber(costPerKg) : '—'}
                                    {costPerKg !== null && <small className="text-xs ml-1 font-bold">DH/Kg</small>}
                                </p>
                                {costPerKg === null && <p className="text-xs text-gray-400 mt-1">Aucune récolte enregistrée</p>}
                            </div>
                            <div className="bg-purple-50 p-6 rounded-2xl shadow-sm border-l-8 border-purple-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Rendement / ha</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(yieldPerHa, 0)}
                                    <small className="text-xs ml-1 font-bold">Kg/ha</small>
                                </p>
                            </div>
                        </div>

                        {/* Productivity Metrics row */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-8">
                            <div className="bg-blue-50 p-6 rounded-2xl shadow-sm border-l-8 border-blue-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Revenu / ha</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(revenuePerHa)}
                                    <small className="text-xs ml-1 font-bold">DH/ha</small>
                                </p>
                            </div>
                            <div className="bg-green-50 p-6 rounded-2xl shadow-sm border-l-8 border-green-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Profit / ha</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(profitPerHa)}
                                    <small className="text-xs ml-1 font-bold">DH/ha</small>
                                </p>
                            </div>
                            <div className="bg-orange-50 p-6 rounded-2xl shadow-sm border-l-8 border-orange-500">
                                <span className="text-[10px] font-black uppercase text-gray-400 tracking-widest block mb-1">Coût Main d'Œuvre %</span>
                                <p className="text-2xl sm:text-3xl font-black text-gray-900 leading-none">
                                    {formatNumber(laborCostPercentage, 1)}
                                    <small className="text-xs ml-1 font-bold">%</small>
                                </p>
                            </div>
                        </div>

                        {/* Charge/Ha table + Harvest chart side by side */}
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8 mb-8">

                            {/* Charge / Hectare table */}
                            <div className="bg-white p-4 sm:p-8 rounded-3xl shadow-sm border border-gray-100">
                                <h4 className="text-[10px] sm:text-sm font-black text-gray-400 mb-6 uppercase tracking-widest">Charge Main d'Œuvre par Hectare</h4>
                                {blocCosts.length === 0 ? (
                                    <p className="text-gray-400 text-sm text-center py-8">Aucune donnée disponible</p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b-2 border-gray-100">
                                                    <th className="text-left py-2 px-3 text-[10px] font-black text-gray-400 uppercase tracking-widest">Bloc</th>
                                                    <th className="text-right py-2 px-3 text-[10px] font-black text-gray-400 uppercase tracking-widest">Surface (Ha)</th>
                                                    <th className="text-right py-2 px-3 text-[10px] font-black text-gray-400 uppercase tracking-widest">Total Net (DH)</th>
                                                    <th className="text-right py-2 px-3 text-[10px] font-black text-blue-700 uppercase tracking-widest">DH/Ha</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {blocCosts.map((b, i) => (
                                                    <tr key={i} className="border-b border-gray-50 hover:bg-blue-50/40 transition-colors">
                                                        <td className="py-3 px-3 font-black text-gray-800 uppercase">{b.name}</td>
                                                        <td className="py-3 px-3 text-right text-gray-600">{b.area_ha ? `${formatNumber(b.area_ha)} Ha` : '—'}</td>
                                                        <td className="py-3 px-3 text-right font-bold text-gray-800">{formatNumber(b.total_net)} DH</td>
                                                        <td className="py-3 px-3 text-right">
                                                            <span className={`inline-block px-2 py-1 rounded-lg font-black text-sm ${
                                                                b.charge_per_ha !== null ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-400'
                                                            }`}>
                                                                {b.charge_per_ha !== null ? `${formatNumber(b.charge_per_ha)} DH` : '—'}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>

                            {/* Harvest by variety chart */}
                            <div className="bg-white p-4 sm:p-8 rounded-3xl shadow-sm border border-gray-100">
                                <h4 className="text-[10px] sm:text-sm font-black text-gray-400 mb-6 uppercase tracking-widest">Récolte par Variété (Kg)</h4>
                                {harvestByVariety.length === 0 ? (
                                    <div className="flex flex-col items-center justify-center h-64 text-gray-300">
                                        <svg className="w-16 h-16 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                                        <p className="text-sm font-bold">Aucune récolte enregistrée</p>
                                        <p className="text-xs mt-1">Utilisez le menu <strong>Récoltes</strong> pour saisir la production</p>
                                    </div>
                                ) : (
                                    <div className="h-64 sm:h-80 w-full">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={harvestByVariety}>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                                                <XAxis dataKey="variety" tick={{fontSize: 9, fontWeight: 'bold'}} />
                                                <YAxis tick={{fontSize: 10}} />
                                                <Tooltip formatter={(val) => [`${formatNumber(val)} Kg`, 'Quantité']} />
                                                <Bar dataKey="total_kg" radius={[6, 6, 0, 0]}>
                                                    {harvestByVariety.map((_, i) => (
                                                        <Cell key={i} fill={COLORS[i % COLORS.length]} />
                                                    ))}
                                                </Bar>
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Rendement par Bloc table */}
                        {harvestByBloc.length > 0 && (
                            <div className="bg-white p-4 sm:p-8 rounded-3xl shadow-sm border border-gray-100">
                                <h4 className="text-[10px] sm:text-sm font-black text-gray-400 mb-6 uppercase tracking-widest">Rendement par Bloc</h4>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b-2 border-gray-100">
                                                <th className="text-left py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Bloc</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Surface (Ha)</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Total (Kg)</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-green-700 uppercase">Kg/Ha</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Revenu (DH)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {harvestByBloc.map((h, i) => (
                                                <tr key={i} className="border-b border-gray-50 hover:bg-green-50/40 transition-colors">
                                                    <td className="py-3 px-3 font-black text-gray-800 uppercase">{h.name}</td>
                                                    <td className="py-3 px-3 text-right text-gray-600">{h.area_ha ? `${formatNumber(h.area_ha)} Ha` : '—'}</td>
                                                    <td className="py-3 px-3 text-right font-bold">{formatNumber(h.total_kg, 0)} Kg</td>
                                                    <td className="py-3 px-3 text-right">
                                                        <span className="inline-block px-2 py-1 rounded-lg font-black text-sm bg-green-100 text-green-800">
                                                            {h.yield_per_ha !== null ? `${formatNumber(h.yield_per_ha)} Kg` : '—'}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 px-3 text-right font-bold text-gray-700">{formatNumber(h.total_revenue)} DH</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {/* Rendement par Secteur table */}
                        {harvestBySector.length > 0 && (
                            <div className="bg-white p-4 sm:p-8 rounded-3xl shadow-sm border border-gray-100">
                                <h4 className="text-[10px] sm:text-sm font-black text-gray-400 mb-6 uppercase tracking-widest">Rendement par Secteur</h4>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b-2 border-gray-100">
                                                <th className="text-left py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Bloc</th> {/* Added Bloc header */}
                                                <th className="text-left py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Secteur</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Surface (Ha)</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Arbres</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Total (Kg)</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-green-700 uppercase">Kg/Ha</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-emerald-700 uppercase">Kg/Arbre</th>
                                                <th className="text-right py-2 px-3 text-[10px] font-black text-gray-400 uppercase">Revenu (DH)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {harvestBySector.map((h, i) => (
                                                <tr key={i} className="border-b border-gray-50 hover:bg-blue-50/40 transition-colors">
                                                    <td className="py-3 px-3 font-black text-gray-800 uppercase">{h.bloc_name}</td> {/* Display bloc_name */}
                                                    <td className="py-3 px-3 font-black text-gray-800 uppercase">{h.name}</td>
                                                    <td className="py-3 px-3 text-right text-gray-600">{h.area_ha ? formatNumber(h.area_ha) : '—'}</td>
                                                    <td className="py-3 px-3 text-right text-gray-600">{h.total_trees ? formatNumber(h.total_trees, 0) : '—'}</td>
                                                    <td className="py-3 px-3 text-right font-bold">{formatNumber(h.total_kg, 0)} Kg</td>
                                                    <td className="py-3 px-3 text-right">
                                                        <span className="inline-block px-2 py-1 rounded-lg font-black text-sm bg-blue-100 text-blue-800">
                                                            {h.yield_per_ha !== null ? `${formatNumber(h.yield_per_ha)} Kg` : '—'}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 px-3 text-right">
                                                        <span className="inline-block px-2 py-1 rounded-lg font-black text-sm bg-indigo-100 text-indigo-800">
                                                            {h.yield_per_tree !== null ? `${h.yield_per_tree} Kg` : '—'}
                                                        </span>
                                                    </td>
                                                    <td className="py-3 px-3 text-right font-bold text-gray-700">{formatNumber(h.total_revenue)} DH</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
