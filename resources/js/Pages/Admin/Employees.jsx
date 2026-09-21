import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { formatNumber } from '@/Helpers/formatNumber';
import { t } from '@/Helpers/i18n';
import EmployeeFormModal from '@/Pages/Admin/Employees/EmployeeFormModal';
import DeleteEmployeeModal from '@/Pages/Admin/Employees/DeleteEmployeeModal';
import ContractModal from '@/Pages/Admin/Employees/ContractModal';

const FILTERS = [
    { key: 'cin', label: 'CIN', has: e => !!e.cin, yes: 'Avec', no: 'Sans' },
    { key: 'rib', label: 'RIB', has: e => !!e.rib, yes: 'Avec', no: 'Sans' },
    { key: 'phone', label: 'Téléphone', has: e => !!e.phone, yes: 'Avec', no: 'Sans' },
    { key: 'cnss', label: 'CNSS', has: e => !!e.cnss_number, yes: 'Avec', no: 'Sans' },
    { key: 'cinPhoto', label: 'Photo CIN', has: e => !!e.cin_photo_path, yes: 'Avec', no: 'Sans' },
    { key: 'active', label: 'Statut', has: e => !!e.is_active, yes: 'Actifs', no: 'Inactifs' },
];
const emptyFilters = { cin: 'all', rib: 'all', phone: 'all', cnss: 'all', cinPhoto: 'all', active: 'all' };

// Three-way toggle: everyone / only those who have the field / only those who don't.
// Each option shows how many employees it would leave, so the counts double as a quick audit.
function TriFilter({ def, value, onChange, counts }) {
    const opts = [['all', 'Tous', counts.all], ['yes', def.yes, counts.yes], ['no', def.no, counts.no]];
    return (
        <div>
            <div className="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1">{def.label}</div>
            <div className="inline-flex rounded-xl bg-gray-100 p-0.5 text-xs font-bold">
                {opts.map(([k, label, n]) => (
                    <button
                        key={k}
                        type="button"
                        onClick={() => onChange(k)}
                        className={`px-3 py-1.5 rounded-[10px] transition-all ${value === k
                            ? (k === 'no' ? 'bg-red-500 text-white shadow-sm' : k === 'yes' ? 'bg-green-600 text-white shadow-sm' : 'bg-white text-gray-800 shadow-sm')
                            : 'text-gray-500 hover:text-gray-800'}`}
                    >
                        {label} <span className={`ml-0.5 font-medium ${value === k ? 'opacity-80' : 'text-gray-400'}`}>{n}</span>
                    </button>
                ))}
            </div>
        </div>
    );
}

export default function Employees({ auth, employees, enterprises, selectedEnterpriseId, searchQuery, transportLocations }) {
    const [isAddingEmployee, setIsAddingEmployee] = useState(false);
    const [editingEmployee, setEditingEmployee] = useState(null);
    const [localSearch, setLocalSearch] = useState(searchQuery || '');
    const [confirmingEmployeeDeletion, setConfirmingEmployeeDeletion] = useState(null);
    const [contractEmployee, setContractEmployee] = useState(null);

    const [filters, setFilters] = useState(emptyFilters);
    const [incompleteOnly, setIncompleteOnly] = useState(false);
    const [sortBy, setSortBy] = useState('name');
    const [showFilters, setShowFilters] = useState(false);

    // An employee's file is "incomplete" when the essentials for paying them are missing.
    const isIncomplete = (e) => !e.cin || !e.rib || !e.phone;

    const passes = (e, skipKey = null) => FILTERS.every(f => {
        if (f.key === skipKey || filters[f.key] === 'all') return true;
        return f.has(e) === (filters[f.key] === 'yes');
    }) && (!incompleteOnly || isIncomplete(e));

    // Count per option against the *other* active filters, so a click never lands on a dead end.
    const countsFor = (def) => {
        const base = employees.filter(e => passes(e, def.key));
        return { all: base.length, yes: base.filter(def.has).length, no: base.filter(e => !def.has(e)).length };
    };

    const filtered = useMemo(() => {
        const list = employees.filter(e => passes(e));
        const collator = new Intl.Collator('fr', { numeric: true, sensitivity: 'base' });
        return list.sort((a, b) => sortBy === 'matricule'
            ? collator.compare(a.matricule, b.matricule)
            : collator.compare(a.full_name, b.full_name));
    }, [employees, filters, incompleteOnly, sortBy]);

    const activeFilterCount = FILTERS.filter(f => filters[f.key] !== 'all').length + (incompleteOnly ? 1 : 0);
    const resetFilters = () => { setFilters(emptyFilters); setIncompleteOnly(false); };
    const incompleteCount = employees.filter(isIncomplete).length;

    const handleToggleActive = (employee) => {
        router.post(route('employees.toggle-active', employee.id));
    };

    const handleFilterChange = (e) => {
        const id = e.target.value;
        router.get(route('employees.index'), { enterprise_id: id, search: localSearch });
    };

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('employees.index'), { enterprise_id: selectedEnterpriseId, search: localSearch });
    };

    const clearSearch = () => {
        setLocalSearch('');
        router.get(route('employees.index'), { enterprise_id: selectedEnterpriseId, search: '' });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">{t('personnel_management')}</h2>
                    <div className="flex gap-4 items-center">
                        {(auth.user.role !== 'data_entry' || auth.user.can_access_pointage) && (
                            <button
                                onClick={() => setIsAddingEmployee(true)}
                                className="bg-primary-600 hover:bg-primary-700 text-white px-6 py-2 rounded-xl font-black text-sm uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                            >
                                <span>+</span> {t('add_employee_btn')}
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title={t('employees')} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                    {/* EMPLOYEES LIST */}
                    <div className="bg-white p-6 shadow-sm sm:rounded-2xl border border-gray-100">
                        <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
                            <div>
                                <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">
                                    {t('employees_list_title')} {selectedEnterpriseId && `- ${enterprises.find(e => e.id == selectedEnterpriseId)?.name}`}
                                </h3>
                                <p className="text-xs text-gray-400 font-bold uppercase tracking-widest">{filtered.length}{filtered.length !== employees.length && ` / ${employees.length}`} {t('salaries_recorded')}</p>
                            </div>
                            <div className="flex gap-3 items-center flex-wrap">
                                {enterprises && enterprises.length > 0 && (
                                    <select
                                        className="rounded-xl border-gray-200 bg-gray-50 text-sm px-4 py-2"
                                        value={selectedEnterpriseId || ''}
                                        onChange={handleFilterChange}
                                    >
                                        <option value="">{t('all_fermes')}</option>
                                        {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                    </select>
                                )}
                                <form onSubmit={handleSearch} className="flex gap-2">
                                    <input
                                        type="text"
                                        placeholder="Rechercher..."
                                        value={localSearch}
                                        onChange={(e) => setLocalSearch(e.target.value)}
                                        className="rounded-xl border-gray-200 bg-gray-50 text-sm px-4 py-2 w-64"
                                    />
                                    {localSearch && (
                                        <button
                                            type="button"
                                            onClick={clearSearch}
                                            className="text-gray-500 hover:text-gray-700 font-bold text-xs"
                                        >
                                            ✕
                                        </button>
                                    )}
                                </form>
                            </div>
                        </div>

                        {/* FILTERS */}
                        <div className="mb-6 rounded-2xl border border-gray-100 bg-gray-50/60 p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-[10px] font-black uppercase tracking-widest text-gray-400 mr-1">Raccourcis</span>
                                <button type="button" onClick={() => setFilters(f => ({ ...f, rib: f.rib === 'no' ? 'all' : 'no' }))}
                                    className={`px-3 py-1.5 rounded-full text-xs font-bold border transition-all ${filters.rib === 'no' ? 'bg-red-500 border-red-500 text-white' : 'bg-white border-gray-200 text-gray-600 hover:border-red-300'}`}>
                                    Sans RIB
                                </button>
                                <button type="button" onClick={() => setFilters(f => ({ ...f, cin: f.cin === 'no' ? 'all' : 'no' }))}
                                    className={`px-3 py-1.5 rounded-full text-xs font-bold border transition-all ${filters.cin === 'no' ? 'bg-red-500 border-red-500 text-white' : 'bg-white border-gray-200 text-gray-600 hover:border-red-300'}`}>
                                    Sans CIN
                                </button>
                                <button type="button" onClick={() => setIncompleteOnly(v => !v)}
                                    className={`px-3 py-1.5 rounded-full text-xs font-bold border transition-all ${incompleteOnly ? 'bg-amber-500 border-amber-500 text-white' : 'bg-white border-gray-200 text-gray-600 hover:border-amber-300'}`}>
                                    Dossier incomplet <span className="opacity-70">({incompleteCount})</span>
                                </button>
                                <div className="ml-auto flex items-center gap-2">
                                    <select value={sortBy} onChange={e => setSortBy(e.target.value)} className="rounded-xl border-gray-200 bg-white text-xs font-bold py-1.5 pl-3 pr-8">
                                        <option value="name">Tri : Nom A → Z</option>
                                        <option value="matricule">Tri : Matricule</option>
                                    </select>
                                    <button type="button" onClick={() => setShowFilters(v => !v)}
                                        className="px-3 py-1.5 rounded-xl text-xs font-bold bg-white border border-gray-200 text-gray-700 hover:border-primary-400">
                                        Plus de filtres{activeFilterCount > 0 && <span className="ml-1.5 inline-flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-primary-600 text-white text-[10px] px-1">{activeFilterCount}</span>}
                                    </button>
                                    {activeFilterCount > 0 && (
                                        <button type="button" onClick={resetFilters} className="text-xs font-bold text-gray-500 hover:text-red-600 underline underline-offset-2">Réinitialiser</button>
                                    )}
                                </div>
                            </div>
                            {showFilters && (
                                <div className="mt-4 pt-4 border-t border-gray-200 flex flex-wrap gap-x-6 gap-y-4">
                                    {FILTERS.map(def => (
                                        <TriFilter key={def.key} def={def} value={filters[def.key]} counts={countsFor(def)}
                                            onChange={v => setFilters(f => ({ ...f, [def.key]: v }))} />
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* MOBILE CARD VIEW — the wide table below is unusable on a phone even with
                            horizontal scroll, so screens below md get a stacked card per employee
                            showing the essentials instead, with the full table reserved for md+. */}
                        <div className="md:hidden space-y-3">
                            {filtered.map(emp => {
                                const salNetJ = emp.enterprise?.contract_type === 'avec_contrat'
                                    ? (parseFloat(emp.base_rate) * (1 - 0.0674)) + parseFloat(emp.complement || 0)
                                    : parseFloat(emp.base_rate);

                                return (
                                    <div key={emp.id} className={`p-4 rounded-2xl border border-gray-100 ${!emp.is_active ? 'opacity-50 bg-gray-50' : 'bg-white'} shadow-sm`}>
                                        <div className="mb-2">
                                            <a href={route('employees.show', emp.id)} className="font-bold text-gray-800 hover:text-primary-600 hover:underline">{emp.full_name}</a>
                                            <div className="text-xs text-gray-400">{emp.matricule}{auth.user.role === 'super_admin' && emp.enterprise?.name ? ` • ${emp.enterprise.name}` : ''}</div>
                                        </div>
                                        <div className="grid grid-cols-3 gap-2 my-3 text-center">
                                            <div className="bg-gray-50 rounded-lg py-1.5">
                                                <div className="text-[8px] font-bold text-gray-400 uppercase">{t('daily_rate_brut')}</div>
                                                <div className="text-xs font-bold text-gray-600">{formatNumber(emp.base_rate)} DH</div>
                                            </div>
                                            <div className="bg-gray-50 rounded-lg py-1.5">
                                                <div className="text-[8px] font-bold text-gray-400 uppercase">{t('complement')}</div>
                                                <div className="text-xs font-bold text-gray-600">{formatNumber(emp.complement || 0)} DH</div>
                                            </div>
                                            <div className="bg-green-50 rounded-lg py-1.5">
                                                <div className="text-[8px] font-bold text-green-600 uppercase">{t('daily_net')}</div>
                                                <div className="text-xs font-black text-green-700">{formatNumber(salNetJ)} DH</div>
                                            </div>
                                        </div>
                                        <div className="text-xs text-gray-500 space-y-0.5 mb-3">
                                            <div>{t('cin')}: {emp.cin || <span className="text-amber-600 font-bold">manquant</span>}</div>
                                            <div>RIB: {emp.rib ? <span className="font-mono">••••{emp.rib.slice(-6)}</span> : <span className="text-red-600 font-bold">manquant</span>}</div>
                                            {emp.phone && <div>{t('phone')}: {emp.phone}</div>}
                                            {emp.hire_date && <div>{t('hire_date')}: {emp.hire_date}</div>}
                                        </div>
                                        <div className="flex items-center justify-between pt-2 border-t border-gray-100">
                                            <label className="relative inline-flex items-center cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={emp.is_active}
                                                    onChange={() => handleToggleActive(emp)}
                                                    className="sr-only peer"
                                                />
                                                <div className="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600"></div>
                                            </label>
                                            {(auth.user.role !== 'data_entry' || auth.user.can_access_pointage) && (
                                                <div className="flex items-center justify-end gap-4">
                                                    <button
                                                        type="button"
                                                        onClick={() => setContractEmployee(emp)}
                                                        title="Générer Contrat"
                                                        className="text-gray-400 hover:text-primary-600 transition-colors"
                                                    >
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                        </svg>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setEditingEmployee(emp)}
                                                        title="Modifier"
                                                        className="text-gray-400 hover:text-gray-700 transition-colors"
                                                    >
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                        </svg>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setConfirmingEmployeeDeletion(emp)}
                                                        title="Supprimer"
                                                        className="text-gray-400 hover:text-red-600 transition-colors"
                                                    >
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                            {filtered.length === 0 && (
                                <div className="py-12 text-center text-gray-400 italic">{t('no_employee_found')}</div>
                            )}
                        </div>

                        <div className="hidden md:block overflow-x-auto -mx-6 sm:mx-0">
                            <div className="inline-block min-w-full align-middle">
                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr className="text-left text-xs font-bold uppercase tracking-wider text-gray-500">
                                    <th className="px-4 py-3">{t('matricule')}</th>
                                    <th className="px-4 py-3">{t('full_name')}</th>
                                    {auth.user.role === 'super_admin' && <th className="px-4 py-3 text-primary-600">{t('fermes')}</th>}
                                    <th className="px-4 py-3">{t('cin')}</th>
                                    <th className="px-4 py-3">RIB</th>
                                    <th className="px-4 py-3">{t('phone')}</th>
                                    <th className="px-4 py-3 text-right text-green-600">{t('daily_net')}</th>
                                    <th className="px-4 py-3 text-center">{t('status')}</th>
                                    <th className="px-4 py-3 text-center">{t('actions')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {filtered.map(emp => {
                                    const salNetJ = emp.enterprise?.contract_type === 'avec_contrat'
                                        ? (parseFloat(emp.base_rate) * (1 - 0.0674)) + parseFloat(emp.complement || 0)
                                        : parseFloat(emp.base_rate);

                                    return (
                                        <tr key={emp.id} className={`hover:bg-gray-50 transition-colors ${!emp.is_active ? 'opacity-50 bg-gray-100' : ''}`}>
                                            <td className="px-4 py-3 font-medium text-gray-900">{emp.matricule}</td>
                                            <td className="px-4 py-3">
                                                <a href={route('employees.show', emp.id)} className="font-bold text-gray-800 hover:text-primary-600 hover:underline">{emp.full_name}</a>
                                            </td>
                                            {auth.user.role === 'super_admin' && (
                                                <td className="px-4 py-3 font-bold text-primary-600 text-xs">{emp.enterprise?.name || 'N/A'}</td>
                                            )}
                                            <td className="px-4 py-3">{emp.cin || <span className="inline-block rounded-full bg-amber-50 text-amber-700 text-[10px] font-black uppercase px-2 py-0.5">Sans CIN</span>}</td>
                                            <td className="px-4 py-3">
                                                {emp.rib
                                                    ? <span className="font-mono text-xs text-gray-600" title={emp.rib}>••••{emp.rib.slice(-6)}</span>
                                                    : <span className="inline-block rounded-full bg-red-50 text-red-600 text-[10px] font-black uppercase px-2 py-0.5">Manquant</span>}
                                            </td>
                                            <td className="px-4 py-3">{emp.phone || '-'}</td>
                                            <td className="px-4 py-3 text-right font-black text-green-700 bg-green-50/30">
                                                {formatNumber(salNetJ)} <small className="text-[10px]">DH</small>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <label className="relative inline-flex items-center cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        checked={emp.is_active}
                                                        onChange={() => handleToggleActive(emp)}
                                                        className="sr-only peer"
                                                    />
                                                    <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                                                </label>
                                            </td>
                                            <td className="px-4 py-3 text-center">
                                                <div className="flex items-center justify-center gap-3">
                                                    <a
                                                        href={route('employees.show', emp.id)}
                                                        title="Voir la fiche"
                                                        className="text-gray-400 hover:text-primary-600 transition-colors"
                                                    >
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                        </svg>
                                                    </a>
                                                {(auth.user.role !== 'data_entry' || auth.user.can_access_pointage) && (
                                                    <>
                                                        <button
                                                            type="button"
                                                            onClick={() => setContractEmployee(emp)}
                                                            title="Générer Contrat"
                                                            className="text-gray-400 hover:text-primary-600 transition-colors"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                            </svg>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => setEditingEmployee(emp)}
                                                            title="Modifier"
                                                            className="text-gray-400 hover:text-gray-700 transition-colors"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => setConfirmingEmployeeDeletion(emp)}
                                                            title="Supprimer"
                                                            className="text-gray-400 hover:text-red-600 transition-colors"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                            </svg>
                                                        </button>
                                                    </>
                                                )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {filtered.length === 0 && (
                                    <tr>
                                        <td colSpan={auth.user.role === 'super_admin' ? 9 : 8} className="px-4 py-12 text-center text-gray-400 italic">
                                            {t('no_employee_found')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <EmployeeFormModal
                show={isAddingEmployee || editingEmployee !== null}
                onClose={() => { setIsAddingEmployee(false); setEditingEmployee(null); }}
                employee={editingEmployee}
                enterprises={enterprises}
                transportLocations={transportLocations}
                defaultEnterpriseId={selectedEnterpriseId}
            />

            <DeleteEmployeeModal
                employee={confirmingEmployeeDeletion}
                onClose={() => setConfirmingEmployeeDeletion(null)}
            />

            <ContractModal
                employee={contractEmployee}
                onClose={() => setContractEmployee(null)}
            />
        </AuthenticatedLayout>
    );
}
