import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import axios from 'axios';
import { formatNumber } from '@/Helpers/formatNumber';

export default function Grid({ auth, quinzaine, employees, operations, blocs, days, existingRecords, mode = 'division', equipeId = null, equipes = [], salairesFixes = {} }) {
    const [selectedCell, setSelectedCell] = useState(null);
    // Jour férié: the day whose header was clicked (action modal), and the per-division result
    // the server flashes back (summary modal).
    const { flash } = usePage().props;
    const [jourFerieDay, setJourFerieDay] = useState(null);
    // Keeps the modal's title on its day while it fades out (jourFerieDay is cleared first).
    const lastJourFerieDay = useRef(null);
    if (jourFerieDay) lastJourFerieDay.current = jourFerieDay;
    const [jourFerieProcessing, setJourFerieProcessing] = useState(false);
    const [jourFerieResult, setJourFerieResult] = useState(null);
    const lastJourFerieResult = useRef(null);
    if (jourFerieResult) lastJourFerieResult.current = jourFerieResult;
    const shownResult = jourFerieResult ?? lastJourFerieResult.current;
    useEffect(() => {
        if (flash?.jourFerie) setJourFerieResult(flash.jourFerie);
    }, [flash?.jourFerie]);
    const dayIsJf = (day) => employees.some(e => existingRecords[e.id]?.[day]?.[0]?.is_jf);
    const formatDay = (day) => day ? new Date(day).toLocaleDateString('fr-FR', { weekday: 'long', day: '2-digit', month: 'long' }) : '';
    const applyJourFerie = (remove) => {
        const payload = { quinzaine_id: quinzaine.id, date: jourFerieDay };
        const options = {
            preserveScroll: true,
            onStart: () => setJourFerieProcessing(true),
            onFinish: () => { setJourFerieProcessing(false); setJourFerieDay(null); },
        };
        if (remove) {
            router.delete(route('pointage.jour-ferie.destroy'), { data: payload, ...options });
        } else {
            router.post(route('pointage.jour-ferie.store'), payload, options);
        }
    };
    const [summaryData, setSummaryData] = useState({ bloc_matrices: {}, blocs: [], operations: [], days: [], daily_totals: {} });
    const [isMobile, setIsMobile] = useState(false);
    // Opens on today when it falls in the period (the day being pointed), else its first day.
    // The local calendar day — toISOString() is UTC and gives yesterday's date before 1 a.m. here.
    const today = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();
    const [currentDate, setCurrentDate] = useState(days.includes(today) ? today : (days[0] || today));
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
    // Excel-style fill-handle: mousedown on a day cell while holding a clipboard starts a drag;
    // dragAnchor is where it started, dragRange is the currently-highlighted set of days (both
    // recomputed on every cell the pointer enters, clamped to the SAME employee's row — this is
    // a horizontal-only range, not a rectangular multi-employee one). A plain click (no movement
    // across cells) never sets dragRange past length 1, so it falls through to the existing
    // single-cell onClick/pasteToCell path untouched.
    const [dragAnchor, setDragAnchor] = useState(null);
    const [dragRange, setDragRange] = useState(null);
    const gridScrollRef = useRef(null);
    const scrollGridBy = (amount) => gridScrollRef.current?.scrollBy({ left: amount, behavior: 'smooth' });

    // Present with the operation still to enter — the équipe step (RepartitionService).
    const isAPreciser = (record) => !!record && record.present && !record.operation_id;
    const dayAPreciser = (day) => employees.filter(e => isAPreciser(existingRecords[e.id]?.[day]?.[0])).length;
    const aPreciserTotal = days.reduce((n, day) => n + dayAPreciser(day), 0);
    const { errors: pageErrors = {} } = usePage().props;
    const [repartitionDay, setRepartitionDay] = useState(null);
    const [lignes, setLignes] = useState([]);
    const [repartitionProcessing, setRepartitionProcessing] = useState(false);

    // Total days pointé this quinzaine for one employee — the same count shown in the
    // desktop table's own JOURS column, factored out so both views and the filter agree.
    const dayCountFor = (empId) => Object.keys(existingRecords[empId] || {}).length;

    // Each row is pointed against ITS OWN division's quinzaine for these dates (in équipe mode
    // the members can belong to several divisions). Null = that division has no quinzaine for
    // the period yet, so the row is read-only.
    const empById = (id) => employees.find(e => e.id === id);
    const qidFor = (id) => empById(id)?.quinzaine_id ?? null;
    const closedFor = (id) => { const e = empById(id); return e ? !!e.quinzaine_closed : !!quinzaine.is_closed; };
    const entFor = (emp) => emp.enterprise ?? quinzaine.enterprise;

    // Division mode narrows the list client-side; équipe mode reloads from the server so it
    // can bring in members from other divisions.
    const [equipeFilter, setEquipeFilter] = useState('all');
    const equipeLabel = (e) => `${e.name}${e.chef ? ` — chef : ${e.chef.full_name}` : ''} (${e.employees_count})`;
    const chefId = mode === 'equipe' ? equipes.find(e => String(e.id) === String(equipeId))?.chef_employee_id : null;
    const equipeName = equipeId === 'none' ? 'Sans équipe' : (equipes.find(e => String(e.id) === String(equipeId))?.name || 'choisir une équipe');
    const openMode = (m, t) => router.get(
        route('pointage.grid', quinzaine.id),
        m === 'equipe' ? { mode: 'equipe', ...(t ? { equipe: t } : {}) } : {},
        { preserveScroll: true },
    );

    const filteredEmployees = employees.filter(emp => {
        const term = searchTerm.trim().toLowerCase();
        const matchesSearch = !term
            || emp.full_name.toLowerCase().includes(term)
            || (emp.matricule || '').toLowerCase().includes(term);
        const days = dayCountFor(emp.id);
        const matchesDayFilter = dayFilter === 'all'
            || (dayFilter === 'zero' && days === 0)
            || (dayFilter === 'worked' && days > 0);
        const matchesEquipe = mode === 'equipe' || equipeFilter === 'all'
            // "Sans équipe" includes members of a deactivated équipe (only active ones are listed).
            || (equipeFilter === 'none' ? !equipes.some(e => e.id === emp.equipe_id) : String(emp.equipe_id) === equipeFilter);
        return matchesSearch && matchesDayFilter && matchesEquipe;
    });


    // Pointage mode switch: by division (the quinzaine's own people) or by équipe (a chef's team,
    // riders, whatever their division). In division mode the select just filters the list.
    const modeBar = (
        <div className="flex flex-wrap items-center gap-2">
            <div className="inline-flex rounded-xl bg-gray-100 p-0.5 text-xs font-black uppercase tracking-wide">
                <button type="button" onClick={() => mode !== 'division' && openMode('division')}
                    className={`px-3 py-1.5 rounded-[10px] transition-all ${mode === 'division' ? 'bg-white text-primary-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'}`}>
                    Par division
                </button>
                <button type="button" onClick={() => mode !== 'equipe' && openMode('equipe', equipeId || (equipes[0]?.id ?? null))}
                    className={`px-3 py-1.5 rounded-[10px] transition-all ${mode === 'equipe' ? 'bg-white text-primary-700 shadow-sm' : 'text-gray-500 hover:text-gray-800'}`}>
                    Par équipe
                </button>
            </div>
            {mode === 'equipe' ? (
                <select value={equipeId || ''} onChange={(e) => openMode('equipe', e.target.value)}
                    className="text-xs font-bold rounded-lg border-gray-200 bg-gray-50">
                    <option value="">-- Choisir une équipe --</option>
                    {equipes.map(e => <option key={e.id} value={e.id}>{equipeLabel(e)}</option>)}
                    <option value="none">Sans équipe</option>
                </select>
            ) : (
                <select value={equipeFilter} onChange={(e) => setEquipeFilter(e.target.value)}
                    className="text-xs font-bold rounded-lg border-gray-200 bg-gray-50">
                    <option value="all">Toutes les équipes</option>
                    <option value="none">Sans équipe</option>
                    {equipes.map(e => <option key={e.id} value={String(e.id)}>{equipeLabel(e)}</option>)}
                </select>
            )}
        </div>
    );
    const noQuinzaineNote = mode === 'equipe' && employees.some(e => !e.quinzaine_id) && (
        <p className="text-[10px] font-bold text-orange-600 uppercase tracking-wide">
            ⚠ {employees.filter(e => !e.quinzaine_id).length} ouvrier(s) : leur division n'a pas de quinzaine pour ces dates, ligne en lecture seule.
        </p>
    );
    const equipeEmptyNote = mode === 'equipe' && !equipeId && (
        <p className="text-center text-xs font-bold text-gray-400 uppercase py-6">Choisissez une équipe pour afficher ses ouvriers.</p>
    );

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
        if (closedFor(employeeId)) return; // Prevent editing if closed

        const record = existingRecords[employeeId]?.[date]?.[0];
        setSelectedCell({ employeeId, date });
        setOperationSearchTerm('');
        setShowOperationDropdown(false);
        setData({
            employee_id: employeeId,
            quinzaine_id: qidFor(employeeId),
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
        if (!clipboard || closedFor(employeeId) || pasting) return;
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
            quinzaine_id: qidFor(employeeId),
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
        if (!clipboard || closedFor(employeeId) || pasting) return;

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
                quinzaine_id: qidFor(employeeId),
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
            quinzaine_id: qidFor(employeeId),
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

    // Inclusive day range between two dates, ordered by their position in `days` (not string/date
    // comparison) so it works regardless of which end the drag started from.
    const dateRangeBetween = (dateA, dateB) => {
        const idxA = days.indexOf(dateA);
        const idxB = days.indexOf(dateB);
        if (idxA === -1 || idxB === -1) return [dateA];
        const [start, end] = idxA <= idxB ? [idxA, idxB] : [idxB, idxA];
        return days.slice(start, end + 1);
    };

    const startDrag = (employeeId, date) => {
        if (!clipboard || closedFor(employeeId) || pasting) return;
        setDragAnchor({ employeeId, date });
        setDragRange({ employeeId, dates: [date] });
    };

    const extendDrag = (employeeId, date) => {
        if (!dragAnchor || dragAnchor.employeeId !== employeeId) return;
        setDragRange({ employeeId, dates: dateRangeBetween(dragAnchor.date, date) });
    };

    // Fires on mouseup anywhere on the grid. A plain click (mousedown+mouseup on the same cell,
    // no drag) leaves dragRange at length 1 — that case is left alone here so the cell's own
    // onClick (pasteToCell) handles it exactly as before, avoiding a double paste.
    const finishDrag = () => {
        if (!dragAnchor) return;
        const range = dragRange;
        setDragAnchor(null);
        setDragRange(null);
        if (!range || range.dates.length <= 1) return;
        const { employeeId, dates } = range;
        if (!clipboard || closedFor(employeeId) || pasting) return;
        const hasExisting = dates.some(d => existingRecords[employeeId]?.[d]?.[0]?.operation_id);
        const message = clipboard.isEmpty
            ? `Voulez-vous effacer les données de ces ${dates.length} jours ?`
            : `Coller "${clipboard.label}" sur ces ${dates.length} jours${hasExisting ? ' (des données existantes seront remplacées)' : ''} ?`;
        if (!confirm(message)) return;

        setPasting(true);
        router.post(route('pointage.cell.bulk'), {
            employee_id: employeeId,
            quinzaine_id: qidFor(employeeId),
            dates,
            operation_id: clipboard.isEmpty ? null : clipboard.operationId,
            bloc_id: clipboard.isEmpty ? null : clipboard.blocId,
            hours: clipboard.isEmpty ? 0 : (clipboard.hours || 0),
        }, {
            preserveScroll: true,
            onSuccess: fetchSummary,
            onFinish: () => setPasting(false),
        });
    };

    const handleQuickSave = (employeeId, isPresent) => {
        if (closedFor(employeeId)) return;

        if (!isPresent) {
            router.post(route('pointage.cell'), {
                employee_id: employeeId,
                quinzaine_id: qidFor(employeeId),
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
        // No Opération/Bloc chosen: present, operation "à préciser" (entered later, per division).
        if (!operationId || !blocId) {
            router.post(route('pointage.cell'), {
                employee_id: employeeId,
                quinzaine_id: qidFor(employeeId),
                date: currentDate,
                present: true,
                hours: globalHours || 0,
            }, { preserveScroll: true });
            return;
        }

        router.post(route('pointage.cell'), {
            employee_id: employeeId,
            quinzaine_id: qidFor(employeeId),
            date: currentDate,
            operation_id: operationId,
            bloc_id: blocId,
            hours: globalHours || (existingRecords[employeeId]?.[currentDate]?.[0]?.hours || 0),
            is_jf: existingRecords[employeeId]?.[currentDate]?.[0]?.is_jf || false,
        }, { preserveScroll: true });
    };

    // "Tous présents": everyone listed (search/filters applied) with nothing that day yet.
    const markAllPresent = (day) => {
        const ids = filteredEmployees.filter(e => !closedFor(e.id) && e.quinzaine_id !== null && !existingRecords[e.id]?.[day]?.[0]).map(e => e.id);
        if (!ids.length) return;
        if (!window.confirm(`Marquer ${ids.length} ouvrier(s) présent(s) le ${day.split('-').reverse().join('/')} ? L'opération restera « à préciser ».`)) return;
        router.post(route('pointage.presence'), { quinzaine_id: quinzaine.id, date: day, employee_ids: ids }, { preserveScroll: true, onSuccess: fetchSummary });
    };

    // The day's headcount per bloc/operation, from the workers already assigned.
    const dayLignes = (day) => Object.values(employees.reduce((acc, e) => {
        const r = existingRecords[e.id]?.[day]?.[0];
        if (r?.operation_id && r?.bloc_id) {
            const k = `${r.bloc_id}|${r.operation_id}`;
            acc[k] ??= { bloc_id: r.bloc_id, operation_id: r.operation_id, effectif: 0 };
            acc[k].effectif++;
        }
        return acc;
    }, {}));
    const dayPresent = (day) => employees.filter(e => existingRecords[e.id]?.[day]?.[0]?.present).length;

    // Pre-filled with the day's own counts, else the last earlier day that has some.
    const openRepartition = (day) => {
        let start = dayLignes(day);
        for (let i = days.indexOf(day) - 1; !start.length && i >= 0; i--) start = dayLignes(days[i]);
        setLignes(start.length ? start : [{ bloc_id: '', operation_id: '', effectif: 1 }]);
        setRepartitionDay(day);
    };
    const setLigne = (i, field, value) => setLignes(lignes.map((l, j) => (j === i ? { ...l, [field]: value } : l)));
    const submitRepartition = () => {
        router.post(route('pointage.repartition'), {
            quinzaine_id: quinzaine.id,
            date: repartitionDay,
            lignes: lignes.filter(l => l.bloc_id && l.operation_id).map(l => ({ ...l, effectif: parseInt(l.effectif || 0, 10) })),
        }, {
            preserveScroll: true,
            onStart: () => setRepartitionProcessing(true),
            onFinish: () => setRepartitionProcessing(false),
            onSuccess: () => { setRepartitionDay(null); fetchSummary(); },
        });
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

    const savePresent = () => {
        router.post(route('pointage.cell'), { ...data, operation_id: '', bloc_id: '', quantity: '', present: true }, {
            preserveScroll: true,
            onSuccess: () => { setSelectedCell(null); fetchSummary(); },
        });
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

    // Global, not just on the table, so releasing the mouse outside the grid (a fast drag,
    // scrolling mid-drag, etc.) still ends the drag instead of leaving it stuck armed.
    useEffect(() => {
        window.addEventListener('mouseup', finishDrag);
        return () => window.removeEventListener('mouseup', finishDrag);
    });

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
                            <span className="text-[10px] bg-primary-600 text-white px-2 py-0.5 rounded-full font-bold uppercase">{mode === 'equipe' ? equipeName : quinzaine.enterprise.name}</span>
                        </div>
                        <div className="flex gap-2">
                             <select
                                value={currentDate}
                                onChange={(e) => setCurrentDate(e.target.value)}
                                className="flex-1 rounded-xl border-gray-200 font-bold text-sm shadow-sm focus:ring-primary-500"
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
                                <p className="mt-2 text-[10px] font-bold text-amber-600 uppercase tracking-wide">
                                    Sans Opération et Bloc, la présence est enregistrée « à préciser »
                                </p>
                            )}
                        </div>

                        <div className="bg-white p-3 rounded-2xl shadow-sm border border-gray-100 mb-4 sticky top-[92px] z-20 space-y-2">
                            {modeBar}
                            {noQuinzaineNote}
                            {pageErrors.date && !selectedCell && (
                                <div role="alert" className="bg-red-50 border border-red-200 rounded-lg px-3 py-2 text-[11px] font-bold text-red-700">{pageErrors.date}</div>
                            )}
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
                                            <span className="text-[9px] font-bold text-gray-400 uppercase tracking-widest">{emp.matricule} • {record ? (isAPreciser(record) ? 'À préciser' : `${record.hours}h HS`) : 'Absent'}</span>
                                        </div>
                                        <button
                                            onClick={() => handleQuickSave(emp.id, !isPresent)}
                                            disabled={!!emp.quinzaine_closed}
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
                            <span className="text-2xl font-black text-primary-700">{Object.keys(dailyRecords).length} / {employees.length}</span>
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
                            {mode === 'equipe' ? `Pointage par équipe : ${equipeName}` : `Gestion Pointage : ${quinzaine.enterprise?.name}`}
                        </h2>
                        <span className="text-[10px] text-gray-400 font-black uppercase tracking-widest">{formatDate(quinzaine.start_date)} au {formatDate(quinzaine.end_date)}</span>
                    </div>
                    <div className="flex gap-2 items-center">
                        {/* A team spans divisions: its export is its payment sheet, not one division's fiche. */}
                        {mode === 'equipe' ? (equipeId && (
                            <a
                                href={route('pointage.paiementEquipes', { quinzaine: quinzaine.id, equipe: equipeId })}
                                target="_blank"
                                onClick={() => triggerDownload('paiement')}
                                className="bg-green-600 hover:bg-green-700 text-white px-6 py-1.5 rounded-full font-black text-xs shadow-lg flex items-center gap-2 transition-all"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="3" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                {downloadingKey === 'paiement' ? 'GÉNÉRATION...' : "PAIEMENT DE L'ÉQUIPE"}
                            </a>
                        )) : (<>
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
                        </>)}
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
                    <section className="bg-white shadow-2xl sm:rounded-2xl border-t-8 border-primary-600 overflow-hidden">
                        <div className="p-6 bg-gray-50 border-b flex flex-wrap justify-between items-center gap-4">
                            <h3 className="text-xl font-black text-primary-900 uppercase tracking-tighter">1. Pointage du Personnel (Journalier)</h3>
                            <div className="flex flex-wrap items-center gap-3">
                                {modeBar}
                                <input
                                    type="text"
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    placeholder="Rechercher un ouvrier..."
                                    className="text-xs font-bold rounded-lg border-gray-200 shadow-sm focus:ring-primary-500 focus:border-primary-500"
                                />
                                <select
                                    value={dayFilter}
                                    onChange={(e) => setDayFilter(e.target.value)}
                                    className="text-xs font-bold rounded-lg border-gray-200 shadow-sm focus:ring-primary-500 focus:border-primary-500"
                                >
                                    <option value="all">Tous les ouvriers</option>
                                    <option value="zero">Non pointés (0 jour)</option>
                                    <option value="worked">Déjà pointés</option>
                                </select>
                                <span className="text-[10px] font-black text-gray-400 uppercase tracking-widest">{filteredEmployees.length} / {employees.length}</span>
                                <div className="flex gap-4 ml-2">
                                    <div className="flex items-center gap-1.5 text-[10px] font-bold text-gray-500 uppercase"><span className="w-3 h-3 bg-green-100 border border-green-300 rounded"></span> Présent</div>
                                    <div className="flex items-center gap-1.5 text-[10px] font-bold text-gray-500 uppercase"><span className="w-3 h-3 bg-purple-100 border border-purple-300 rounded"></span> Jour Férié</div>
                                    <div className="flex items-center gap-1.5 text-[10px] font-bold text-gray-500 uppercase"><span className="w-3 h-3 bg-amber-100 border border-amber-300 rounded"></span> À préciser</div>
                                </div>
                            </div>
                        </div>
                        {(noQuinzaineNote || equipeEmptyNote) && <div className="px-6 pt-3">{noQuinzaineNote}{equipeEmptyNote}</div>}
                        {/* A refused write from a quick action (no modal to show it), e.g. a worker already present in another division that day. */}
                        {pageErrors.date && !selectedCell && (
                            <div role="alert" className="mx-6 mt-3 bg-red-50 border border-red-200 rounded-xl px-4 py-2 text-xs font-bold text-red-700">{pageErrors.date}</div>
                        )}
                        {aPreciserTotal > 0 && (
                            <div className="mx-6 mt-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-2 text-xs font-bold text-amber-800">
                                {aPreciserTotal} jour(s) de présence « à préciser » : cliquez la case pour choisir l'opération{mode === 'division' ? ', ou « Répartir » sous le jour pour saisir les effectifs par opération' : ' (ou répartissez-les par effectif dans la grille de la division)'}. La clôture attend qu'ils soient complétés.
                            </div>
                        )}
                        {clipboard && (
                            <div className="px-6 py-3 bg-primary-600 text-white flex flex-wrap items-center gap-3 text-xs font-bold">
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                                <span className="uppercase tracking-wide">Copié : {clipboard.label} — cliquez sur un jour pour coller, ou glissez sur plusieurs jours de la même ligne</span>
                                <button type="button" onClick={() => setClipboard(null)} className="ml-auto bg-primary-800 hover:bg-primary-900 px-3 py-1 rounded-full uppercase tracking-widest transition-colors">Annuler</button>
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
                                            <th
                                                key={day}
                                                onClick={() => !quinzaine.is_closed && setJourFerieDay(day)}
                                                title={quinzaine.is_closed ? undefined : 'Jour férié pour ce jour…'}
                                                className={`border border-gray-300 p-1 min-w-[38px] ${dayIsJf(day) ? 'bg-purple-100 text-purple-700' : 'text-gray-600'} ${quinzaine.is_closed ? '' : 'cursor-pointer hover:bg-purple-50'}`}
                                            >
                                                {day.split('-')[2]}
                                                {dayIsJf(day) && <div className="text-[6px] uppercase font-black">JF</div>}
                                            </th>
                                        ))}
                                        <th className="border border-gray-300 p-2 bg-primary-50 sticky right-[225px] z-20 text-primary-800 font-black shadow-md w-[70px] min-w-[70px] max-w-[70px] whitespace-nowrap overflow-hidden">NET/J</th>
                                        <th className="border border-gray-300 p-2 bg-primary-50 sticky right-[170px] z-20 text-primary-800 font-black shadow-md w-[55px] min-w-[55px] max-w-[55px] whitespace-nowrap overflow-hidden">JOURS</th>
                                        <th className="border border-gray-300 p-2 bg-primary-50 sticky right-[100px] z-20 text-primary-800 font-black shadow-md w-[70px] min-w-[70px] max-w-[70px] whitespace-nowrap overflow-hidden">Total H.S</th>
                                        <th className="border border-gray-300 p-2 bg-primary-700 sticky right-0 z-20 text-white font-black shadow-md uppercase tracking-tighter w-[100px] min-w-[100px] max-w-[100px] whitespace-nowrap overflow-hidden">Total Net</th>
                                    </tr>
                                    {!quinzaine.is_closed && (
                                        <tr className="bg-gray-50">
                                            <th className="border border-gray-300 px-2 py-1 sticky left-0 z-20 bg-gray-50 text-[8px] font-black text-gray-400 uppercase tracking-widest text-left">
                                                ✓ Présents{mode === 'division' ? ' · ≡ Répartir' : ''}
                                            </th>
                                            {days.map(day => (
                                                <th key={day} className="border border-gray-300 p-0.5">
                                                    <div className="flex items-center justify-center gap-0.5">
                                                        <button type="button" onClick={() => markAllPresent(day)} title="Tous présents (opération à préciser)"
                                                            className="w-4 h-4 rounded bg-green-100 hover:bg-green-200 text-green-700 text-[9px] font-black leading-none">✓</button>
                                                        {mode === 'division' && (
                                                            <button type="button" onClick={() => openRepartition(day)} title="Répartir par effectif"
                                                                className="w-4 h-4 rounded bg-primary-100 hover:bg-primary-200 text-primary-700 text-[9px] font-black leading-none">≡</button>
                                                        )}
                                                    </div>
                                                    {dayAPreciser(day) > 0 && <div className="text-[7px] font-black text-amber-700">{dayAPreciser(day)} P</div>}
                                                </th>
                                            ))}
                                            <th className="border border-gray-300 bg-gray-50 sticky right-[225px] z-20 w-[70px] min-w-[70px] max-w-[70px]"></th>
                                            <th className="border border-gray-300 bg-gray-50 sticky right-[170px] z-20 w-[55px] min-w-[55px] max-w-[55px]"></th>
                                            <th className="border border-gray-300 bg-gray-50 sticky right-[100px] z-20 w-[70px] min-w-[70px] max-w-[70px]"></th>
                                            <th className="border border-gray-300 bg-gray-50 sticky right-0 z-20 w-[100px] min-w-[100px] max-w-[100px]"></th>
                                        </tr>
                                    )}
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
                                        // A fixed monthly salary (paid in the month's 2nd quinzaine) is not a day: it comes apart.
                                        const salaireFixe = parseFloat(salairesFixes[emp.id] || 0);
                                        const totalNet = Object.values(employeeRecords).reduce((sum, dayRecords) => sum + parseFloat(dayRecords[0]?.net || 0), 0) + salaireFixe;
                                        const totalHs = Object.values(employeeRecords).reduce((sum, dayRecords) => sum + parseFloat(dayRecords[0]?.hours || 0), 0);
                                        const salNetJ = entFor(emp).contract_type === 'avec_contrat'
                                            ? (parseFloat(entFor(emp).default_brut_rate) * (1 - 0.0674)) + parseFloat(emp.complement || 0)
                                            : parseFloat(entFor(emp).default_brut_rate);

                                        return (
                                            <tr key={emp.id} className="hover:bg-primary-50 transition-colors group">
                                                <td className="border border-gray-200 p-2 font-bold bg-white sticky left-0 z-10 shadow-sm group-hover:bg-primary-50">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <div className="flex flex-col">
                                                            <span className="text-gray-900 leading-none mb-1 uppercase tracking-tighter">
                                                                {emp.full_name}
                                                                {emp.id === chefId && <span className="ml-1 px-1 rounded bg-amber-100 text-amber-700 text-[7px] font-black align-middle">CHEF</span>}
                                                            </span>
                                                            <span className="text-[7px] text-gray-400 font-black tracking-widest">{emp.matricule}</span>
                                                        </div>
                                                        {clipboard && (
                                                            <button
                                                                type="button"
                                                                title="Coller sur les jours restants"
                                                                onClick={() => pasteRemainingDays(emp.id)}
                                                                disabled={pasting}
                                                                className="shrink-0 bg-primary-600 hover:bg-primary-700 text-white rounded-full p-1.5 disabled:opacity-50 transition-colors"
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
                                                    const isDragHighlighted = dragRange && dragRange.employeeId === emp.id && dragRange.dates.includes(day);
                                                    return (
                                                        <td
                                                            key={day}
                                                            onClick={() => clipboard ? pasteToCell(emp.id, day) : openForm(emp.id, day)}
                                                            onMouseDown={() => startDrag(emp.id, day)}
                                                            onMouseEnter={() => extendDrag(emp.id, day)}
                                                            className={`relative border border-gray-200 p-1 text-center cursor-pointer transition-all group/cell select-none ${record ? (isAPreciser(record) ? 'bg-amber-100 border-amber-300' : record.is_jf ? 'bg-purple-100 border-purple-200' : 'bg-green-100 border-green-200') : 'bg-white'} ${clipboard ? 'hover:ring-2 hover:ring-inset hover:ring-primary-400' : ''} ${isDragHighlighted ? 'ring-2 ring-inset ring-primary-600 bg-primary-200' : ''}`}
                                                        >
                                                            {record ? (
                                                                <div className="font-black leading-tight">
                                                                    {record.is_jf && <div className="text-[6px] text-purple-700 uppercase mb-0.5">JF</div>}
                                                                    {isAPreciser(record) ? (
                                                                        <span className="text-amber-700 text-[9px]" title="Présent — opération à préciser">P</span>
                                                                    ) : (
                                                                        <span className="text-gray-700 text-[8px] uppercase">
                                                                            {operations.find(o => o.id === record.operation_id)?.abbreviation || record.operation_id}
                                                                        </span>
                                                                    )}
                                                                    <div className="text-[7px] text-gray-400">
                                                                        {blocs.find(b => b.id === record.bloc_id)?.name}
                                                                    </div>
                                                                    {record.quantity > 0 && <div className="text-emerald-600 text-[8px]">{record.quantity}u</div>}
                                                                    {record.hours > 0 && <div className="text-primary-600 text-[8px]">+{record.hours}h</div>}
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
                                                <td className="border border-gray-200 p-2 text-center font-black text-primary-600 bg-primary-50 sticky right-[170px] z-10 shadow-sm w-[55px] min-w-[55px] max-w-[55px] whitespace-nowrap overflow-hidden">{totalJours}</td>
                                                <td className="border border-gray-200 p-2 text-center font-black text-primary-600 bg-primary-50 sticky right-[100px] z-10 shadow-sm w-[70px] min-w-[70px] max-w-[70px] whitespace-nowrap overflow-hidden">{totalHs > 0 ? formatNumber(totalHs, 2) : '-'}</td>
                                                <td className="border border-gray-200 p-2 text-right font-black text-primary-900 bg-primary-100 sticky right-0 z-10 shadow-sm w-[100px] min-w-[100px] max-w-[100px] whitespace-nowrap overflow-hidden">
                                                    {formatNumber(totalNet)}
                                                    {salaireFixe > 0 && <div className="text-[7px] font-black text-amber-700 uppercase" title="Salaire mensuel fixe">dont fixe {formatNumber(salaireFixe)}</div>}
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
                                                <th className="border border-gray-300 p-2 bg-primary-600 text-white font-black uppercase text-center sticky right-0 z-10">Total Net Op</th>
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
                                                            <td key={day} className={`border border-gray-200 p-1 text-center font-bold ${dayValues[day] > 0 ? 'bg-primary-50 text-primary-700' : 'text-gray-200'}`}>
                                                                {dayValues[day] > 0 ? formatNumber(dayValues[day], 1) : '-'}
                                                            </td>
                                                        ))}
                                                        <td className="border border-gray-200 p-2 text-center font-black text-primary-800 bg-primary-100 sticky right-0 z-10 shadow-sm">
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
                                                        <td key={day} className="border border-gray-700 p-1 text-center text-primary-400 text-[8px] shadow-inner">
                                                            {colTotal > 0 ? formatNumber(colTotal, 1) : '-'}
                                                        </td>
                                                    );
                                                })}
                                                <td className="border border-gray-700 bg-primary-600 text-white p-3 text-center sticky right-0 z-10 uppercase tracking-tighter text-[10px]">
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
                            <div className="bg-white p-8 rounded-3xl shadow-2xl w-full max-w-md border-t-[12px] border-primary-600 transform transition-all scale-105">
                                <div className="flex justify-between items-start mb-8">
                                    <div>
                                        <h3 className="text-3xl font-black text-gray-900 leading-none mb-2 tracking-tighter uppercase">Pointage</h3>
                                        <p className="text-sm font-black text-primary-600 bg-primary-50 px-3 py-1 rounded-lg inline-block uppercase tracking-widest">{employees.find(e => e.id === selectedCell.employeeId).full_name}</p>
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
                                                className="block w-full rounded-2xl border-2 border-gray-100 bg-gray-50 font-black text-gray-800 focus:border-primary-500 focus:ring-0 py-4 px-6 text-sm uppercase transition-all"
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
                                                            className="w-full text-left px-4 py-3 hover:bg-primary-50 transition-colors font-black text-sm"
                                                        >
                                                            {o.name.toUpperCase()} {o.abbreviation ? `(${o.abbreviation.toUpperCase()})` : ''}
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                        <div className="relative">
                                            <label htmlFor="cell_bloc_id" className="text-[10px] font-black uppercase text-gray-400 tracking-[0.2em] mb-2 block ml-1">Lieu / Parcelle</label>
                                            <select id="cell_bloc_id" className="block w-full rounded-2xl border-2 border-gray-100 bg-gray-50 font-black text-gray-800 focus:border-primary-500 focus:ring-0 py-4 px-6 text-sm uppercase transition-all" value={data.bloc_id} onChange={e => setData('bloc_id', e.target.value)}>
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
                                                    <label htmlFor="cell_hours" className="text-[10px] font-black uppercase text-primary-500 tracking-[0.2em] mb-2 block ml-1">Heures Sup (H.S)</label>
                                                    <div className="relative">
                                                        <input id="cell_hours" type="number" step="0.01" min="0" className="block w-full rounded-2xl border-2 border-primary-100 bg-primary-50/50 font-black text-primary-900 text-2xl focus:border-primary-500 focus:ring-0 py-3 pl-6 pr-10 transition-all" value={data.hours} onChange={e => setData('hours', e.target.value)} />
                                                        <span className="absolute right-4 top-3.5 text-primary-300 font-black text-sm">H</span>
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
                                        {!isPieceRate && (
                                            <button type="button" onClick={savePresent} disabled={processing} className="w-full bg-amber-50 hover:bg-amber-100 border-2 border-amber-200 text-amber-700 py-3 rounded-2xl font-black text-xs transition-all disabled:opacity-50 tracking-widest uppercase">
                                                Présent — opération à préciser
                                            </button>
                                        )}
                                        <button type="submit" disabled={processing} className="w-full bg-primary-600 hover:bg-primary-700 text-white py-5 rounded-2xl font-black text-sm shadow-xl shadow-primary-100 transition-all transform hover:-translate-y-1 active:scale-95 disabled:opacity-50 tracking-[0.3em] uppercase">
                                            {processing ? 'Chargement...' : 'Enregistrer'}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Operations by headcount for one day (PointageController::repartir / RepartitionService) */}
            <Modal show={repartitionDay !== null} onClose={() => !repartitionProcessing && setRepartitionDay(null)} maxWidth="lg">
                {repartitionDay && (
                    <div className="p-6 space-y-4">
                        <div>
                            <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Répartir — {formatDay(repartitionDay)}</h3>
                            <p className="text-xs text-gray-500 mt-1">
                                {dayPresent(repartitionDay)} présent(s), dont {dayAPreciser(repartitionDay)} « à préciser ». Indiquez le nombre total d'ouvriers par opération :
                                ceux déjà affectés restent, les « à préciser » sont répartis en gardant d'abord l'opération de leur dernier jour travaillé.
                            </p>
                        </div>
                        {pageErrors.repartition && <div className="bg-red-50 border border-red-200 text-red-700 text-xs font-bold rounded-xl p-3">{pageErrors.repartition}</div>}
                        <div className="space-y-2">
                            {lignes.map((l, i) => (
                                <div key={i} className="flex items-center gap-2">
                                    <select value={l.bloc_id} onChange={e => setLigne(i, 'bloc_id', e.target.value)} className="text-xs rounded-lg border-gray-200 w-24">
                                        <option value="">Bloc</option>
                                        {blocs.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    </select>
                                    <select value={l.operation_id} onChange={e => setLigne(i, 'operation_id', e.target.value)} className="text-xs rounded-lg border-gray-200 flex-1">
                                        <option value="">Opération</option>
                                        {operations.filter(o => !o.unit_rate).map(o => <option key={o.id} value={o.id}>{o.name}{o.abbreviation ? ` (${o.abbreviation})` : ''}</option>)}
                                    </select>
                                    <input type="number" min="0" value={l.effectif} onChange={e => setLigne(i, 'effectif', e.target.value)} className="text-xs rounded-lg border-gray-200 w-20 text-center font-black" />
                                    <button type="button" onClick={() => setLignes(lignes.filter((_, j) => j !== i))} className="text-gray-400 hover:text-red-600 text-lg leading-none px-1" title="Retirer">×</button>
                                </div>
                            ))}
                            <button type="button" onClick={() => setLignes([...lignes, { bloc_id: '', operation_id: '', effectif: 1 }])} className="text-xs font-bold text-primary-600 hover:text-primary-700">+ Ajouter une opération</button>
                        </div>
                        <div className="flex items-center justify-between border-t pt-3">
                            <span className="text-xs font-black text-gray-600">
                                Total : {lignes.reduce((n, l) => n + (parseInt(l.effectif, 10) || 0), 0)} / {dayPresent(repartitionDay)} présents
                            </span>
                            <div className="flex gap-2">
                                <SecondaryButton type="button" disabled={repartitionProcessing} onClick={() => setRepartitionDay(null)}>Annuler</SecondaryButton>
                                <PrimaryButton type="button" disabled={repartitionProcessing} onClick={submitRepartition}>Répartir</PrimaryButton>
                            </div>
                        </div>
                    </div>
                )}
            </Modal>

            {/* Jour férié for a whole day (PointageController::markJourFerie / JourFerieService) */}
            <Modal show={jourFerieDay !== null} onClose={() => !jourFerieProcessing && setJourFerieDay(null)} maxWidth="md">
                <div className="p-6">
                    <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Jour férié — {formatDay(jourFerieDay ?? lastJourFerieDay.current)}</h3>
                    <p className="text-sm text-gray-600 mt-3">
                        {auth.user.enterprise_id
                            ? 'Appliquer à votre division pour ce jour.'
                            : 'Appliquer à toutes les divisions ouvertes de la ferme pour ce jour.'}
                    </p>
                    <ul className="text-xs text-gray-500 mt-3 space-y-1 list-disc pl-5">
                        <li>Ceux qui ont travaillé ce jour : jour férié travaillé (NET/J + NET/J).</li>
                        <li>Les autres : un NET/J payé s'ils ont travaillé le dernier jour travaillé avant la fête, ou 13 jours sur les 30 jours précédents (Code du travail, art. 219).</li>
                        <li>Le travail à la tâche est laissé à traiter manuellement (art. 220).</li>
                    </ul>
                    <div className="flex flex-wrap justify-end gap-2 mt-6">
                        <SecondaryButton type="button" disabled={jourFerieProcessing} onClick={() => setJourFerieDay(null)}>Annuler</SecondaryButton>
                        <SecondaryButton type="button" disabled={jourFerieProcessing} onClick={() => applyJourFerie(true)}>Retirer le jour férié</SecondaryButton>
                        <PrimaryButton type="button" disabled={jourFerieProcessing} onClick={() => applyJourFerie(false)} className="!bg-purple-600 hover:!bg-purple-700">
                            {jourFerieProcessing ? 'En cours…' : 'Marquer jour férié'}
                        </PrimaryButton>
                    </div>
                </div>
            </Modal>

            <Modal show={jourFerieResult !== null} onClose={() => setJourFerieResult(null)} maxWidth="lg">
                <div className="p-6">
                    <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">
                        {shownResult?.action === 'unmark' ? 'Jour férié retiré' : 'Jour férié enregistré'} — {formatDay(shownResult?.date)}
                    </h3>
                    {shownResult?.divisions?.length === 0 && (
                        <p className="text-sm text-gray-600 mt-4">Aucune période ouverte ne couvre ce jour.</p>
                    )}
                    <div className="mt-4 space-y-3 max-h-[60vh] overflow-y-auto">
                        {shownResult?.divisions?.map(d => (
                            <div key={d.division} className="border border-gray-200 rounded-xl p-3 text-sm">
                                <div className="font-black text-gray-800 uppercase text-xs tracking-wide">{d.division}</div>
                                {shownResult.action === 'unmark' ? (
                                    <div className="text-gray-600 mt-1">{d.removed} jour(s) férié(s) non travaillé(s) supprimé(s), {d.reverted} jour(s) travaillé(s) remis en jour normal.</div>
                                ) : (
                                    <>
                                        <div className="text-gray-600 mt-1">
                                            {d.worked} travaillé(s) le jour férié · {d.paid} payé(s) sans travailler · {d.already} déjà marqué(s)
                                        </div>
                                        {d.not_eligible.length > 0 && (
                                            <div className="text-amber-700 text-xs mt-2"><strong>Non éligibles ({d.not_eligible.length}) :</strong> {d.not_eligible.join(', ')}</div>
                                        )}
                                        {d.manual.length > 0 && (
                                            <div className="text-purple-700 text-xs mt-1"><strong>À traiter manuellement — tâche ({d.manual.length}) :</strong> {d.manual.join(', ')}</div>
                                        )}
                                    </>
                                )}
                            </div>
                        ))}
                    </div>
                    <div className="flex justify-end mt-6">
                        <PrimaryButton type="button" onClick={() => setJourFerieResult(null)}>Fermer</PrimaryButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
