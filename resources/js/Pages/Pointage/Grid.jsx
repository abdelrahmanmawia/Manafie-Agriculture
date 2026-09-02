import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { formatNumber } from '@/Helpers/formatNumber';

export default function Grid({ auth, quinzaine, employees, operations, blocs, days, existingRecords }) {
    const [selectedCell, setSelectedCell] = useState(null);
    const [summaryData, setSummaryData] = useState({ bloc_matrices: {}, blocs: [], operations: [], days: [], daily_totals: {} });
    const [isMobile, setIsMobile] = useState(false);
    const [currentDate, setCurrentDate] = useState(days[0] || new Date().toISOString().split('T')[0]);
    const [globalOperation, setGlobalOperation] = useState('');
    const [globalBloc, setGlobalBloc] = useState('');
    const [globalHours, setGlobalHours] = useState(0);
    const [searchTerm, setSearchTerm] = useState('');
    const [operationSearchTerm, setOperationSearchTerm] = useState('');
    const [showOperationDropdown, setShowOperationDropdown] = useState(false);
    // 'all' | 'zero' (not pointé at all yet) | 'worked' (already has at least 1 day)
    const [dayFilter, setDayFilter] = useState('all');
    // { operationId, blocId, label } of a copied cell, or null — while set, clicking any other
    // day cell pastes it directly instead of opening the edit modal (see pasteToCell below).
    const [clipboard, setClipboard] = useState(null);
    const [pasting, setPasting] = useState(false);
    const gridScrollRef = useRef(null);
    const scrollGridBy = (amount) => gridScrollRef.current?.scrollBy({ left: amount, behavior: 'smooth' });

    // Total days pointé this quinzaine for one employee — the same count shown in the
    // desktop table's own JOURS column, factored out so both views and the filter agree.
    const dayCountFor = (empId) => Object.keys(existingRecords[empId] || {}).length;

    const filteredEmployees = employees.filter(emp => {
        const term = searchTerm.trim().toLowerCase();
        const matchesSearch = !term
            || emp.full_name.toLowerCase().includes(term)
            || (emp.matricule || '').toLowerCase().includes(term);
        const days = dayCountFor(emp.id);
        const matchesDayFilter = dayFilter === 'all'
            || (dayFilter === 'zero' && days === 0)
            || (dayFilter === 'worked' && days > 0);
        return matchesSearch && matchesDayFilter;
    });

    const filteredOperations = operations.filter(op => {
        const term = operationSearchTerm.trim().toLowerCase();
        if (!term) return true;
        return op.name.toLowerCase().includes(term)
            || (op.abbreviation || '').toLowerCase().includes(term);
    });

    // Excel/PDF exports are plain <a target="_blank"> links, not Inertia visits — there's no
    // JS-observable "download finished" event, so this just gives brief visual feedback that
    // the click registered while the file generates server-side.
    const [downloadingKey, setDownloadingKey] = useState(null);
    const triggerDownload = (key) => {
        setDownloadingKey(key);
        setTimeout(() => setDownloadingKey(prev => (prev === key ? null : prev)), 2500);
    };

    // Check for mobile on mount and resize
    useEffect(() => {
        const checkMobile = () => setIsMobile(window.innerWidth < 768);
        checkMobile();
        window.addEventListener('resize', checkMobile);
        return () => window.removeEventListener('resize', checkMobile);
    }, []);

    const { data, setData, post, processing, reset, errors } = useForm({
        employee_id: '',
        quinzaine_id: quinzaine.id,
        operation_id: '',
        bloc_id: '',
        date: '',
        hours: 0, // This is H.S
        quantity: '',
        is_jf: false,
    });

    // The selected Operation's unit_rate decides whether this cell asks for a quantity
    // (piece-rate, e.g. meters) instead of hours/JF.
    const selectedOperation = operations.find(o => String(o.id) === String(data.operation_id));
    const isPieceRate = !!selectedOperation?.unit_rate;

    const openForm = (employeeId, date) => {
        if (quinzaine.is_closed) return; // Prevent editing if closed

        const record = existingRecords[employeeId]?.[date]?.[0];
        setSelectedCell({ employeeId, date });
        setOperationSearchTerm('');
        setShowOperationDropdown(false);
        setData({
            employee_id: employeeId,
            quinzaine_id: quinzaine.id,
            operation_id: record?.operation_id || '',
            bloc_id: record?.bloc_id || '',
            date: date,
            hours: record?.hours || 0,
            quantity: record?.quantity || '',
            is_jf: record?.is_jf || false,
        });
    };

    // Copies a cell's Operation+Bloc so it can be pasted onto other days.
    // Can also copy empty cells to facilitate clearing days.
    const copyCell = (e, employeeId, date) => {
        e.stopPropagation();
        const record = existingRecords[employeeId]?.[date]?.[0];
        if (!record || !record.operation_id) {
            // Copy empty cell for cleaning
            setClipboard({
                operationId: null,
                blocId: null,
                label: 'VIDE (Nettoyage)',
                isEmpty: true,
            });
            return;
        }
        const op = operations.find(o => o.id === record.operation_id);
        if (op?.unit_rate) return; // Piece-rate cells excluded
        const bloc = blocs.find(b => b.id === record.bloc_id);
        const hours = parseFloat(record.hours || 0);
        setClipboard({
            operationId: record.operation_id,
            blocId: record.bloc_id,
            hours,
            label: `${op?.abbreviation || op?.name || ''} @ ${bloc?.name || ''}${hours > 0 ? ` (+${hours}h HS)` : ''}`,
            isEmpty: false,
        });
    };

    // Pastes the clipboard directly (no modal) onto one cell — active only while a clipboard
    // is held; see the day-cell onClick below. Replaces existing data if present.
    const pasteToCell = (employeeId, date) => {
        if (!clipboard || quinzaine.is_closed || pasting) return;
        const existingRecord = existingRecords[employeeId]?.[date]?.[0];

        // If replacing existing data, confirm first
        if (existingRecord && existingRecord.operation_id && !clipboard.isEmpty) {
            if (!confirm('Cette journée contient déjà des données. Voulez-vous les remplacer ?')) {
                return;
            }
        }

        setPasting(true);
        router.post(route('pointage.cell'), {
            employee_id: employeeId,
            quinzaine_id: quinzaine.id,
            date,
            operation_id: clipboard.operationId,
            bloc_id: clipboard.blocId,
            hours: clipboard.isEmpty ? 0 : (clipboard.hours || 0),
            is_jf: clipboard.isEmpty ? false : undefined,
        }, {
            preserveScroll: true,
            onSuccess: fetchSummary,
            onFinish: () => setPasting(false),
        });
    };

    // "Coller sur les jours restants" — fills every day this employee has NO record for yet
    // with the clipboard's Operation+Bloc, in one request. If clipboard is empty, clears all days.
    const pasteRemainingDays = (employeeId) => {
        if (!clipboard || quinzaine.is_closed || pasting) return;

        if (clipboard.isEmpty) {
            // Clear all days for this employee
            if (!confirm('Voulez-vous effacer toutes les données de cet ouvrier pour cette période ?')) {
                return;
            }
            const allDays = days.filter(d => existingRecords[employeeId]?.[d]?.[0]);
            if (allDays.length === 0) return;
            setPasting(true);
            router.post(route('pointage.cell.bulk'), {
                employee_id: employeeId,
                quinzaine_id: quinzaine.id,
                dates: allDays,
                operation_id: null,
                bloc_id: null,
                hours: 0,
                is_jf: false,
            }, {
                preserveScroll: true,
                onSuccess: fetchSummary,
                onFinish: () => setPasting(false),
            });
            return;
        }

        // For full records, fill ALL days (replacing existing ones)
        if (!confirm('Voulez-vous remplacer toutes les données de cet ouvrier avec cette opération ?')) {
            return;
        }

        const allDays = days;
        setPasting(true);
        router.post(route('pointage.cell.bulk'), {
            employee_id: employeeId,
            quinzaine_id: quinzaine.id,
            operation_id: clipboard.operationId,
            bloc_id: clipboard.blocId,
            hours: clipboard.hours || 0,
            dates: allDays,
        }, {
            preserveScroll: true,
            onSuccess: fetchSummary,
            onFinish: () => setPasting(false),
        });
    };

    const handleQuickSave = (employeeId, isPresent) => {
        if (quinzaine.is_closed) return;

        if (!isPresent) {
            router.post(route('pointage.cell'), {
                employee_id: employeeId,
                quinzaine_id: quinzaine.id,
                date: currentDate,
                operation_id: '',
                bloc_id: '',
            }, { preserveScroll: true });
            return;
        }

        // No fallback to operations[0]/blocs[0] here on purpose — silently picking
        // "whatever's first in the list" would mis-attribute this employee's pay to the
        // wrong operation/bloc with no indication anything went wrong. The buttons below
        // are already disabled until both are chosen, so this is just a defensive backstop.
        const operationId = globalOperation || existingRecords[employeeId]?.[currentDate]?.[0]?.operation_id;
        const blocId = globalBloc || existingRecords[employeeId]?.[currentDate]?.[0]?.bloc_id;
        if (!operationId || !blocId) {
            alert('Veuillez d\'abord choisir une Opération et un Bloc en haut de la page.');
            return;
        }

        router.post(route('pointage.cell'), {
            employee_id: employeeId,
            quinzaine_id: quinzaine.id,
            date: currentDate,
            operation_id: operationId,
            bloc_id: blocId,
            hours: globalHours || (existingRecords[employeeId]?.[currentDate]?.[0]?.hours || 0),
            is_jf: existingRecords[employeeId]?.[currentDate]?.[0]?.is_jf || false,
        }, { preserveScroll: true });
    };

    const fetchSummary = async () => {
        try {
            const response = await axios.get(route('pointage.summary', quinzaine.id));
            setSummaryData(response.data);
        } catch (error) {
            if (error.response?.status === 404 || error.response?.status === 500) {
                router.visit(route('pointage.quinzaines'));
            }
        }
    };

    const submit = (e) => {
        e.preventDefault();
        setOperationSearchTerm('');
        setShowOperationDropdown(false);
        post(route('pointage.cell'), {
            onSuccess: () => {
                setSelectedCell(null);
                fetchSummary();
            },
            preserveScroll: true,
        });
    };

    const formatDate = (dateString) => {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    };

    useEffect(() => {
        fetchSummary();
    }, []);

    // 📱 MOBILE VIEW (Field Mode)
    if (isMobile) {
        const dailyRecords = {};
        Object.keys(existingRecords).forEach(empId => {
            if (existingRecords[empId][currentDate]) {
                dailyRecords[empId] = existingRecords[empId][currentDate][0];
            }
        });

        return (
            <AuthenticatedLayout
                user={auth.user}
                header={
                    <div className="flex flex-col gap-2">
                        <div className="flex justify-between items-center">
                            <h2 className="font-black text-xl text-gray-800 uppercase tracking-tighter">Mode Terrain</h2>
                            <span className="text-[10px] bg-blue-600 text-white px-2 py-0.5 rounded-full font-bold uppercase">{quinzaine.enterprise.name}</span>
                        </div>
                        <div className="flex gap-2">
                             <select
                                value={currentDate}
                                onChange={(e) => setCurrentDate(e.target.value)}
                                className="flex-1 rounded-xl border-gray-200 font-bold text-sm shadow-sm focus:ring-blue-500"
                            >
                                {days.map(d => (
                                    <option key={d} value={d}>
                                        {new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', weekday: 'short' })}
                                    </option>
                                ))}
                            </select>
                            <button onClick={() => setIsMobile(false)} className="bg-gray-100 p-2 rounded-xl text-gray-500">
                                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16m-7 6h7" /></svg>
                            </button>
                        </div>
                    </div>
                }
            >
                <Head title="Mode Terrain" />
                <div className="py-4 bg-gray-50 min-h-screen pb-32">
                    <div className="max-w-7xl mx-auto px-4">
                        <div className="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 mb-6 sticky top-4 z-30">
                            <div className="grid grid-cols-2 gap-3">
                                <select value={globalOperation} onChange={(e) => setGlobalOperation(e.target.value)} className="text-xs font-bold rounded-lg border-gray-100 bg-gray-50">
                                    <option value="">-- Opération --</option>
                                    {operations.map(op => <option key={op.id} value={op.id}>{op.name} {op.abbreviation ? `(${op.abbreviation})` : ''}</option>)}
                                </select>
                                <select value={globalBloc} onChange={(e) => setGlobalBloc(e.target.value)} className="text-xs font-bold rounded-lg border-gray-100 bg-gray-50">
                                    <option value="">-- Bloc --</option>
                                    {blocs.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                </select>
                            </div>
                            <div className="mt-3 flex items-center justify-between">
                                <label htmlFor="grid_global_hours" className="text-[10px] font-black text-gray-400 uppercase">H.S:</label>
                                <input id="grid_global_hours" type="number" value={globalHours} onChange={(e) => setGlobalHours(e.target.value)} className="w-16 h-8 text-xs font-bold rounded-lg border-gray-100 bg-gray-50" min="0" />
                            </div>
                            {(!globalOperation || !globalBloc) && (
                                <p className="mt-2 text-[10px] font-bold text-orange-500 uppercase tracking-wide">
                                    ⚠ Choisissez une Opération et un Bloc avant de marquer des présences
                                </p>
                            )}
                        </div>

                        <div className="bg-white p-3 rounded-2xl shadow-sm border border-gray-100 mb-4 sticky top-[92px] z-20 space-y-2">
                            <input
                                type="text"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                placeholder="Rechercher un ouvrier..."
                                className="w-full text-xs font-bold rounded-lg border-gray-200 bg-gray-50"
                            />
                            <select
                                value={dayFilter}
                                onChange={(e) => setDayFilter(e.target.value)}
                                className="w-full text-xs font-bold rounded-lg border-gray-200 bg-gray-50"
                            >
                                <option value="all">Tous les ouvriers</option>
                                <option value="zero">Non pointés (0 jour)</option>
                                <option value="worked">Déjà pointés</option>
                            </select>
                        </div>

                        <div className="space-y-3">
                            {filteredEmployees.length === 0 && (
                                <p className="text-center text-xs font-bold text-gray-400 uppercase py-8">Aucun ouvrier trouvé</p>
                            )}
                            {filteredEmployees.map(emp => {
                                const record = dailyRecords[emp.id];
                                const isPresent = !!record;
                                return (
                                    <div key={emp.id} className={`p-4 rounded-2xl border-2 transition-all flex items-center justify-between ${isPresent ? 'bg-green-50 border-green-200' : 'bg-white border-gray-100 opacity-60'}`}>
                                        <div className="flex flex-col">
                                            <span className="font-black text-gray-900 uppercase leading-none mb-1 text-sm">{emp.full_name}</span>
                                            <span className="text-[9px] font-bold text-gray-400 uppercase tracking-widest">{emp.matricule} • {record ? `${record.hours}h HS` : 'Absent'}</span>
                                        </div>
                                        <button
                                            onClick={() => handleQuickSave(emp.id, !isPresent)}
                                            disabled={quinzaine.is_closed || (!isPresent && (!globalOperation || !globalBloc))}
                                            className={`w-12 h-12 rounded-full flex items-center justify-center transition-all disabled:opacity-40 disabled:cursor-not-allowed ${isPresent ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-300'}`}
                                        >
                                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="4" d={isPresent ? "M5 13l4 4L19 7" : "M12 4v16m8-8H4"} /></svg>
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
                <div className="fixed bottom-0 left-0 right-0 bg-white border-t p-4 pb-8 z-40 rounded-t-[32px] shadow-lg">
                    <div className="flex justify-between items-center max-w-7xl mx-auto">
                        <div className="flex flex-col">
                            <span className="text-[10px] font-black text-gray-400 uppercase">Présents</span>
                            <span className="text-2xl font-black text-blue-700">{Object.keys(dailyRecords).length} / {employees.length}</span>
                        </div>
                        <button onClick={() => setIsMobile(false)} className="bg-gray-900 text-white px-6 py-3 rounded-2xl font-black text-xs uppercase shadow-xl">Vue Tableau</button>
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    // 🖥️ DESKTOP VIEW (Original Grid)
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div className="flex flex-col">
                        <h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">
                            Gestion Pointage : {quinzaine.enterprise?.name}
                        </h2>
                        <span className="text-[10px] text-gray-400 font-black uppercase tracking-widest">{formatDate(quinzaine.start_date)} au {formatDate(quinzaine.end_date)}</span>
                    </div>
                    <div className="flex gap-2 items-center">
                        <a
                            href={route('pointage.export', quinzaine.id)}
                            target="_blank"
                            onClick={() => triggerDownload('excel')}
                            className="bg-green-600 hover:bg-green-700 text-white px-6 py-1.5 rounded-full font-black text-xs shadow-lg flex items-center gap-2 transition-all mr-2"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                            {downloadingKey === 'excel' ? 'GÉNÉRATION...' : 'EXCEL'}
                        </a>
                        <a
                            href={route('payroll.general-payslip', quinzaine.id)}
                            target="_blank"
                            onClick={() => triggerDownload('pdf')}
                            className="bg-primary-600 hover:bg-primary-700 text-white px-6 py-1.5 rounded-full font-black text-xs shadow-lg flex items-center gap-2 transition-all mr-4"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                            {downloadingKey === 'pdf' ? 'GÉNÉRATION...' : 'PDF GLOBAL'}
                        </a>
                        <span className={`px-4 py-1.5 rounded-full text-[10px] font-black border-2 uppercase tracking-tighter ${quinzaine.enterprise?.contract_type === 'avec_contrat' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-orange-50 border-orange-200 text-orange-700'}`}>
                            {quinzaine.enterprise?.contract_type.replace('_', ' ')}
                        </span>
                        {quinzaine.is_closed && (
                            <span className="bg-red-600 text-white px-6 py-1.5 rounded-full font-black text-xs shadow-lg animate-pulse ml-2">
                                SESSION CLÔTURÉE
                            </span>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Pointage Grid" />

            <div className="py-6">
                <div className="max-w-full mx-auto sm:px-4 lg:px-8 space-y-10">

                    {/* 1. THE MAIN POINTAGE MATRIX */}
                    <section className="bg-white shadow-2xl sm:rounded-2xl border-t-8 border-blue-600 overflow-hidden">
                        <div className="p-6 bg-gray-50 border-b flex flex-wrap justify-between items-center gap-4">
                            <h3 className="text-xl font-black text-blue-900 uppercase tracking-tighter">1. Pointage du Personnel (Journalier)</h3>
                            <div className="flex flex-wrap items-center gap-3">
                                <input
                                    type="text"
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    placeholder="Rechercher un ouvrier..."
                                    className="text-xs font-bold rounded-lg border-gray-200 shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                />
                                <select
                                    value={dayFilter}
                                    onChange={(e) => setDayFilter(e.target.value)}
                                    className="text-xs font-bold rounded-lg border-gray-200 shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="all">Tous les ouvriers</option>
                                    <option value="zero">Non pointés (0 jour)</option>
                                    <option value="worked">Déjà pointés</option>
                                </select>
                                <span className="text-[10px] font-black text-gray-400 uppercase tracking-widest">{filteredEmployees.length} / {employees.length}</span>
                                <div className="flex gap-4 ml-2">
                                    <div className="flex items-center gap-1.5 text-[10px] font-bold text-gray-500 uppercase"><span className="w-3 h-3 bg-green-100 border border-green-300 rounded"></span> Présent</div>
                                    <div className="flex items-center gap-1.5 text-[10px] font-bold text-gray-500 uppercase"><span className="w-3 h-3 bg-purple-100 border border-purple-300 rounded"></span> Jour Férié</div>
                                </div>
                            </div>
                        </div>
                        {clipboard && (
                            <div className="px-6 py-3 bg-blue-600 text-white flex flex-wrap items-center gap-3 text-xs font-bold">
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                                <span className="uppercase tracking-wide">Copié : {clipboard.label} — cliquez sur un jour pour coller</span>
                                <button type="button" onClick={() => setClipboard(null)} className="ml-auto bg-blue-800 hover:bg-blue-900 px-3 py-1 rounded-full uppercase tracking-widest transition-colors">Annuler</button>
                            </div>
                        )}
                        <div className="flex items-center gap-2 px-4 pt-3">
                            <button
                                type="button"
                                onClick={() => scrollGridBy(-300)}
                                title="Défiler vers la gauche"
                                className="shrink-0 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-full w-8 h-8 flex items-center justify-center transition-colors"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M15 19l-7-7 7-7" /></svg>
                            </button>
                            <span className="text-[9px] font-black text-gray-400 uppercase tracking-widest">Défiler pour voir tous les jours</span>
                            <button
                                type="button"
                                onClick={() => scrollGridBy(300)}
                                title="Défiler vers la droite"
                                className="shrink-0 bg-gray-100 hover:bg-gray-200 text-gray-600 rounded-full w-8 h-8 flex items-center justify-center transition-colors"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M9 5l7 7-7 7" /></svg>
                            </button>
                        </div>
                        <div ref={gridScrollRef} className="overflow-x-auto p-4">
                            <table className="min-w-full border-collapse border border-gray-200 text-[10px]">
                                <thead>
                                    <tr className="bg-gray-100">
                                        <th className="border border-gray-300 p-2 sticky left-0 z-20 bg-gray-100 min-w-[180px] shadow-md uppercase tracking-tighter">Personnel</th>
                                        {days.map(day => (
                                            <th key={day} className="border border-gray-300 p-1 min-w-[38px] text-gray-600">
                                                {day.split('-')[2]}
                                            </th>
                                        ))}
                                        <th className="border border-gray-300 p-2 bg-blue-50 sticky right-[225px] z-20 text-blue-800 font-black shadow-md w-[70px] min-w-[70px] max-w-[70px] whitespace-nowrap overflow-hidden">NET/J</th>
                                        <th className="border border-gray-300 p-2 bg-blue-50 sticky right-[170px] z-20 text-blue-800 font-black shadow-md w-[55px] min-w-[55px] max-w-[55px] whitespace-nowrap overflow-hidden">JOURS</th>
                                        <th className="border border-gray-300 p-2 bg-blue-50 sticky right-[100px] z-20 text-blue-800 font-black shadow-md w-[70px] min-w-[70px] max-w-[70px] whitespace-nowrap overflow-hidden">Total H.S</th>
                                        <th className="border border-gray-300 p-2 bg-blue-700 sticky right-0 z-20 text-white font-black shadow-md uppercase tracking-tighter w-[100px] min-w-[100px] max-w-[100px] whitespace-nowrap overflow-hidden">Total Net</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredEmployees.length === 0 && (
                                        <tr>
                                            <td colSpan={days.length + 5} className="p-6 text-center text-xs font-bold text-gray-400 uppercase">Aucun ouvrier trouvé</td>
                                        </tr>
                                    )}
                                    {filteredEmployees.map(emp => {
                                        const employeeRecords = existingRecords[emp.id] || {};
                                        const totalJours = Object.keys(employeeRecords).length;
                                        const totalNet = Object.values(employeeRecords).reduce((sum, dayRecords) => sum + parseFloat(dayRecords[0]?.net || 0), 0);
                                        const totalHs = Object.values(employeeRecords).reduce((sum, dayRecords) => sum + parseFloat(dayRecords[0]?.hours || 0), 0);
                                        const salNetJ = quinzaine.enterprise.contract_type === 'avec_contrat'
                                            ? (parseFloat(quinzaine.enterprise.default_brut_rate) * (1 - 0.0674)) + parseFloat(emp.complement || 0)
                                            : parseFloat(quinzaine.enterprise.default_brut_rate);

                                        return (
                                            <tr key={emp.id} className="hover:bg-blue-50 transition-colors group">
                                                <td className="border border-gray-200 p-2 font-bold bg-white sticky left-0 z-10 shadow-sm group-hover:bg-blue-50">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <div className="flex flex-col">
                                                            <span className="text-gray-900 leading-none mb-1 uppercase tracking-tighter">{emp.full_name}</span>
                                                            <span className="text-[7px] text-gray-400 font-black tracking-widest">{emp.matricule}</span>
                                                        </div>
                                                        {clipboard && (
                                                            <button
                                                                type="button"
                                                                title="Coller sur les jours restants"
                                                                onClick={() => pasteRemainingDays(emp.id)}
                                                                disabled={pasting}
                                                                className="shrink-0 bg-blue-600 hover:bg-blue-700 text-white rounded-full p-1.5 disabled:opacity-50 transition-colors"
                                                            >
                                                                <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                                {days.map(day => {
                                                    const record = employeeRecords[day]?.[0];
                                                    const recordOp = record ? operations.find(o => o.id === record.operation_id) : null;
                                                    const canCopy = (!record && !clipboard) || (record && record.operation_id && !recordOp?.unit_rate);
                                                    return (
                                                        <td
                                                            key={day}
                                                            onClick={() => clipboard ? pasteToCell(emp.id, day) : openForm(emp.id, day)}
                                                            className={`relative border border-gray-200 p-1 text-center cursor-pointer transition-all group/cell ${record ? (record.is_jf ? 'bg-purple-100 border-purple-200' : 'bg-green-100 border-green-200') : 'bg-white'} ${clipboard ? 'hover:ring-2 hover:ring-inset hover:ring-blue-400' : ''}`}
                                                        >
                                                            {record ? (
                                                                <div className="font-black leading-tight">
                                                                    {record.is_jf && <div className="text-[6px] text-purple-700 uppercase mb-0.5">JF</div>}
                                                                    <span className="text-gray-700 text-[8px] uppercase">
                                                                        {operations.find(o => o.id === record.operation_id)?.abbreviation ||record.operation_id }
                                                                    </span>
                                                                    <div className="text-[7px] text-gray-400">
                                                                        {blocs.find(b => b.id === record.bloc_id)?.name}
                                                                    </div>
                                                                    {record.quantity > 0 && <div className="text-emerald-600 text-[8px]">{record.quantity}u</div>}
                                                                    {record.hours > 0 && <div className="text-blue-600 text-[8px]">+{record.hours}h</div>}
                                                                </div>
                                                            ) : '-'}
                                                            {canCopy && !clipboard && (
                                                                <button
                                                                    type="button"
                                                                    title="Copier ce jour"
                                                                    onClick={(e) => copyCell(e, emp.id, day)}
                                                                    className="hidden group-hover/cell:flex absolute top-0 right-0 bg-gray-900/70 hover:bg-gray-900 text-white rounded-bl items-center justify-center w-4 h-4"
                                                                >
                                                                    <svg className="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                                                </button>
                                                            )}
                                                        </td>
                                                    );
                                                })}
                                                <td className="border border-gray-200 p-2 text-right font-bold text-gray-500 bg-gray-50 sticky right-[225px] z-10 shadow-sm w-[70px] min-w-[70px] max-w-[70px] whitespace-nowrap overflow-hidden">{formatNumber(salNetJ)}</td>
                                                <td className="border border-gray-200 p-2 text-center font-black text-blue-600 bg-blue-50 sticky right-[170px] z-10 shadow-sm w-[55px] min-w-[55px] max-w-[55px] whitespace-nowrap overflow-hidden">{totalJours}</td>
                                                <td className="border border-gray-200 p-2 text-center font-black text-blue-600 bg-blue-50 sticky right-[100px] z-10 shadow-sm w-[70px] min-w-[70px] max-w-[70px] whitespace-nowrap overflow-hidden">{totalHs > 0 ? formatNumber(totalHs, 2) : '-'}</td>
                                                <td className="border border-gray-200 p-2 text-right font-black text-blue-900 bg-blue-100 sticky right-0 z-10 shadow-sm w-[100px] min-w-[100px] max-w-[100px] whitespace-nowrap overflow-hidden">
                                                    {formatNumber(totalNet)}
                                            </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                                <tfoot>
                                    <tr className="bg-yellow-50 font-black">
                                        <td className="p-3 border border-gray-300 sticky left-0 z-10 bg-yellow-50 text-right uppercase text-[8px] tracking-widest text-orange-900">Total Charges TTC (Par Jour)</td>
                                        {days.map(day => (
                                            <td key={day} className="border border-gray-300 p-1 text-center text-orange-800 text-[8px] shadow-inner">
                                                {summaryData.daily_totals[day] > 0 ? formatNumber(summaryData.daily_totals[day], 1) : '-'}
                                            </td>
                                        ))}
                                        <td colSpan="4" className="border border-gray-300 bg-green-700 text-white text-right px-4 py-3 uppercase tracking-widest text-xs">
                                            TOTAL NET : {formatNumber(Object.values(summaryData.daily_totals).reduce((a,b) => a+b, 0))} DH
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </section>

                    {/* 2. PER-BLOC COST GRIDS */}
                    <div className="space-y-12">
                        <div className="flex items-center gap-4">
                            <div className="h-0.5 bg-gray-200 flex-1"></div>
                            <h3 className="text-2xl font-black text-gray-400 uppercase tracking-widest italic">2. Détail des Salaires Nets par Bloc</h3>
                            <div className="h-0.5 bg-gray-200 flex-1"></div>
                        </div>

                        {summaryData.blocs.map(blocName => (
                            <section key={blocName} className="bg-white shadow-xl sm:rounded-2xl border-t-8 border-green-500 overflow-hidden">
                                <div className="p-6 bg-green-50 border-b flex justify-between items-center">
                                    <div className="flex items-center gap-4">
                                        <span className="bg-green-600 text-white w-12 h-12 flex items-center justify-center rounded-xl font-black text-xl shadow-lg uppercase">{blocName}</span>
                                        <div>
                                            <h4 className="text-xl font-black text-green-900 uppercase leading-none tracking-tighter">Situation des Salaires : Bloc {blocName}</h4>
                                            <span className="text-[10px] text-green-600 font-bold uppercase tracking-widest">Somme des salaires nets journaliers</span>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <span className="text-[10px] text-gray-400 font-black uppercase block mb-1">Total Net Bloc</span>
                                        <span className="text-2xl font-black text-green-700 leading-none">
                                            {formatNumber(Object.values(summaryData.bloc_matrices[blocName] || {}).reduce((acc, opDays) => acc + Object.values(opDays).reduce((dAcc, net) => dAcc + net, 0), 0))}
                                            <small className="text-xs ml-1 font-bold italic uppercase">DH NET</small>
                                        </span>
                                    </div>
                                </div>
                                <div className="overflow-x-auto p-4">
                                    <table className="min-w-full border-collapse border border-gray-200 text-[10px]">
                                        <thead>
                                            <tr className="bg-gray-100">
                                                <th className="border border-gray-300 p-2 sticky left-0 z-10 bg-gray-100 min-w-[200px] text-left uppercase font-black text-gray-500">Opération</th>
                                                {days.map(day => (
                                                    <th key={day} className="border border-gray-300 p-1 min-w-[38px] text-gray-600">{day.split('-')[2]}</th>
                                                ))}
                                                <th className="border border-gray-300 p-2 bg-blue-600 text-white font-black uppercase text-center sticky right-0 z-10">Total Net Op</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {summaryData.operations.map(opName => {
                                                const dayValues = summaryData.bloc_matrices[blocName]?.[opName] || {};
                                                const opTotal = Object.values(dayValues).reduce((a,b) => a+b, 0);
                                                if (opTotal === 0) return null; // Skip empty rows

                                                return (
                                                    <tr key={opName} className="hover:bg-green-50 transition-colors">
                                                        <td className="border border-gray-200 p-2 font-black text-gray-700 bg-white sticky left-0 z-10 shadow-sm uppercase">{opName}</td>
                                                        {days.map(day => (
                                                            <td key={day} className={`border border-gray-200 p-1 text-center font-bold ${dayValues[day] > 0 ? 'bg-blue-50 text-blue-700' : 'text-gray-200'}`}>
                                                                {dayValues[day] > 0 ? formatNumber(dayValues[day], 1) : '-'}
                                                            </td>
                                                        ))}
                                                        <td className="border border-gray-200 p-2 text-center font-black text-blue-800 bg-blue-100 sticky right-0 z-10 shadow-sm">
                                                            {formatNumber(opTotal)}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                        <tfoot>
                                            <tr className="bg-gray-800 text-white font-black shadow-lg">
                                                <td className="p-3 border border-gray-700 sticky left-0 z-10 bg-gray-800 uppercase tracking-widest text-[9px]">Salaire Net Total / Jour</td>
                                                {days.map(day => {
                                                    let colTotal = 0;
                                                    summaryData.operations.forEach(op => colTotal += (summaryData.bloc_matrices[blocName]?.[op]?.[day] || 0));
                                                    return (
                                                        <td key={day} className="border border-gray-700 p-1 text-center text-blue-400 text-[8px] shadow-inner">
                                                            {colTotal > 0 ? formatNumber(colTotal, 1) : '-'}
                                                        </td>
                                                    );
                                                })}
                                                <td className="border border-gray-700 bg-blue-600 text-white p-3 text-center sticky right-0 z-10 uppercase tracking-tighter text-[10px]">
                                                    NET BLOC
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </section>
                        ))}
                    </div>

                    {/* POPUP FORM */}
                    {selectedCell && (
                        <div className="fixed inset-0 bg-gray-900 bg-opacity-80 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
                            <div className="bg-white p-8 rounded-3xl shadow-2xl w-full max-w-md border-t-[12px] border-blue-600 transform transition-all scale-105">
                                <div className="flex justify-between items-start mb-8">
                                    <div>
                                        <h3 className="text-3xl font-black text-gray-900 leading-none mb-2 tracking-tighter uppercase">Pointage</h3>
                                        <p className="text-sm font-black text-blue-600 bg-blue-50 px-3 py-1 rounded-lg inline-block uppercase tracking-widest">{employees.find(e => e.id === selectedCell.employeeId).full_name}</p>
                                        <div className="mt-2 text-xs font-black text-gray-400 uppercase tracking-widest">Date: {selectedCell.date}</div>
                                    </div>
                                    <button onClick={() => setSelectedCell(null)} className="p-2 bg-gray-100 hover:bg-gray-200 rounded-full text-gray-400 transition-all">
                                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>

                                {errors.date && (
                                    <div className="mb-6 bg-red-50 border border-red-200 text-red-700 text-sm font-bold rounded-xl p-4">
                                        {errors.date}
                                    </div>
                                )}

                                <form onSubmit={submit} className="space-y-6">
                                    <div className="space-y-6">
                                        <div className="relative">
                                            <label htmlFor="cell_operation_id" className="text-[10px] font-black uppercase text-gray-400 tracking-[0.2em] mb-2 block ml-1">Activité / Mission</label>
                                            <input
                                                id="cell_operation_id"
                                                className="block w-full rounded-2xl border-2 border-gray-100 bg-gray-50 font-black text-gray-800 focus:border-blue-500 focus:ring-0 py-4 px-6 text-sm uppercase transition-all"
                                                value={data.operation_id ? operations.find(o => o.id === data.operation_id)?.name.toUpperCase() + (operations.find(o => o.id === data.operation_id)?.abbreviation ? ` (${operations.find(o => o.id === data.operation_id).abbreviation.toUpperCase()})` : '') : operationSearchTerm}
                                                onChange={e => {
                                                    setOperationSearchTerm(e.target.value);
                                                    setShowOperationDropdown(true);
                                                }}
                                                onFocus={() => setShowOperationDropdown(true)}
                                                placeholder="Rechercher par nom ou abréviation..."
                                                readOnly={!!data.operation_id}
                                            />
                                            {showOperationDropdown && (
                                                <div className="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-xl shadow-lg max-h-48 overflow-y-auto">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setData('operation_id', '');
                                                            setOperationSearchTerm('');
                                                            setShowOperationDropdown(false);
                                                        }}
                                                        className="w-full text-left px-4 py-3 hover:bg-gray-50 transition-colors font-black text-sm border-b border-gray-100"
                                                    >
                                                        ABSENCE
                                                    </button>
                                                    {filteredOperations.map(o => (
                                                        <button
                                                            key={o.id}
                                                            type="button"
                                                            onClick={() => {
                                                                setData('operation_id', o.id);
                                                                setOperationSearchTerm('');
                                                                setShowOperationDropdown(false);
                                                            }}
                                                            className="w-full text-left px-4 py-3 hover:bg-blue-50 transition-colors font-black text-sm"
                                                        >
                                                            {o.name.toUpperCase()} {o.abbreviation ? `(${o.abbreviation.toUpperCase()})` : ''}
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                        <div className="relative">
                                            <label htmlFor="cell_bloc_id" className="text-[10px] font-black uppercase text-gray-400 tracking-[0.2em] mb-2 block ml-1">Lieu / Parcelle</label>
                                            <select id="cell_bloc_id" className="block w-full rounded-2xl border-2 border-gray-100 bg-gray-50 font-black text-gray-800 focus:border-blue-500 focus:ring-0 py-4 px-6 text-sm uppercase transition-all" value={data.bloc_id} onChange={e => setData('bloc_id', e.target.value)}>
                                                <option value="">-- CHOISIR UN BLOC --</option>
                                                {blocs.map(b => <option key={b.id} value={b.id}>🏠 {b.name.toUpperCase()}</option>)}
                                            </select>
                                        </div>

                                        {isPieceRate ? (
                                            <div className="group">
                                                <label htmlFor="cell_quantity" className="text-[10px] font-black uppercase text-emerald-500 tracking-[0.2em] mb-2 block ml-1">
                                                    Quantité ({Number(selectedOperation.unit_rate).toFixed(2)} DH/unité)
                                                </label>
                                                <div className="relative">
                                                    <input id="cell_quantity" type="number" step="0.01" min="0" className="block w-full rounded-2xl border-2 border-emerald-100 bg-emerald-50/50 font-black text-emerald-900 text-2xl focus:border-emerald-500 focus:ring-0 py-3 pl-6 pr-10 transition-all" value={data.quantity} onChange={e => setData('quantity', e.target.value)} />
                                                </div>
                                                {data.quantity > 0 && (
                                                    <p className="text-xs font-bold text-emerald-600 mt-2 ml-1">
                                                        = {(data.quantity * selectedOperation.unit_rate).toFixed(2)} DH
                                                    </p>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="grid grid-cols-2 gap-6">
                                                <div className="group">
                                                    <label htmlFor="cell_hours" className="text-[10px] font-black uppercase text-blue-500 tracking-[0.2em] mb-2 block ml-1">Heures Sup (H.S)</label>
                                                    <div className="relative">
                                                        <input id="cell_hours" type="number" step="0.01" min="0" className="block w-full rounded-2xl border-2 border-blue-100 bg-blue-50/50 font-black text-blue-900 text-2xl focus:border-blue-500 focus:ring-0 py-3 pl-6 pr-10 transition-all" value={data.hours} onChange={e => setData('hours', e.target.value)} />
                                                        <span className="absolute right-4 top-3.5 text-blue-300 font-black text-sm">H</span>
                                                    </div>
                                                </div>

                                                <div className="flex flex-col">
                                                    <label id="cell_is_jf_label" className="text-[10px] font-black uppercase text-purple-500 tracking-[0.2em] mb-2 block ml-1 text-center">Statut Spécial</label>
                                                    <button type="button" aria-labelledby="cell_is_jf_label" onClick={() => setData('is_jf', !data.is_jf)} className={`flex-1 rounded-2xl border-2 font-black text-xs uppercase tracking-widest transition-all ${data.is_jf ? 'bg-purple-600 border-purple-700 text-white shadow-lg shadow-purple-200' : 'bg-gray-50 border-gray-100 text-gray-300 hover:text-gray-400 hover:bg-gray-100'}`}>
                                                        {data.is_jf ? 'JOUR FÉRIÉ' : 'Standard'}
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    <div className="flex flex-col gap-4 pt-6">
                                        <button type="submit" disabled={processing} className="w-full bg-blue-600 hover:bg-blue-700 text-white py-5 rounded-2xl font-black text-sm shadow-xl shadow-blue-100 transition-all transform hover:-translate-y-1 active:scale-95 disabled:opacity-50 tracking-[0.3em] uppercase">
                                            {processing ? 'Chargement...' : 'Enregistrer'}
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
