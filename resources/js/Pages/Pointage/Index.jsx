import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

export default function Index({ auth, quinzaines, enterprises }) {
    const { props } = usePage();
    const [isCreating, setIsCreating] = useState(false);

    const { data, setData, post, processing, reset, errors } = useForm({
        label: '',
        start_date: '',
        end_date: '',
        enterprise_id: auth.user.enterprise_id || (enterprises?.[0]?.id || ''),
    });

    const [selectedGlobalQuinzaineId, setSelectedGlobalQuinzaineId] = useState(quinzaines.length > 0 ? quinzaines[0].id : '');
    const [filterEnterpriseId, setFilterEnterpriseId] = useState(props.ziggy?.query?.enterprise_id || '');

    const formatDate = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    // Group quinzaines by unique periods (start_date + end_date)
    const getUniquePeriods = () => {
        const periodMap = new Map();

        quinzaines.forEach(q => {
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
    const [selectedPeriodKey, setSelectedPeriodKey] = useState(uniquePeriods.length > 0 ? uniquePeriods[0].key : '');

    const submit = (e) => {
        e.preventDefault();
        post(route('settings.quinzaine'), {
            onSuccess: () => {
                reset();
                setIsCreating(false);
            },
        });
    };

    const handleFilterChange = (e) => {
        const newEnterpriseId = e.target.value;
        setFilterEnterpriseId(newEnterpriseId);
        router.get(route('pointage.index'), { enterprise_id: newEnterpriseId || undefined }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Gestion des Quinzaines (Periods)</h2>
                    {auth.user.role !== 'data_entry' && (
                        <button
                            onClick={() => setIsCreating(true)}
                            className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                        >
                            <span>+</span> Ouvrir une Période
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Pointage" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Global Export Multi-Divisions Section */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 border border-gray-200">
                        <h3 className="font-bold text-xl mb-3 text-gray-800">Export Global Multi-Divisions</h3>
                        <p className="text-gray-600 mb-6 text-sm">
                            Sélectionnez une période de référence pour exporter un fichier Excel contenant les données de pointage de toutes les divisions de la ferme associée, chaque division sur une feuille séparée.
                        </p>
                        {(auth.user.role === 'super_admin' || auth.user.role === 'farm_manager') && (
                            <div className="flex flex-col sm:flex-row items-stretch sm:items-end gap-4">
                                <div className="flex-grow">
                                    <label htmlFor="global_period_select" className="block text-sm font-medium text-gray-700 mb-1">
                                        Période de Référence
                                    </label>
                                    <select
                                        id="global_period_select"
                                        className="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md shadow-sm"
                                        value={selectedPeriodKey}
                                        onChange={(e) => setSelectedPeriodKey(e.target.value)}
                                    >
                                        <option value="">-- Sélectionner une période --</option>
                                        {uniquePeriods.length === 0 ? (
                                            <option value="" disabled>Aucune période disponible</option>
                                        ) : (
                                            uniquePeriods.map((period) => (
                                                <option key={period.key} value={period.key}>
                                                    {period.label} ({formatDate(period.start_date)} - {formatDate(period.end_date)})
                                                </option>
                                            ))
                                        )}
                                    </select>
                                </div>
                                <a
                                    href={selectedPeriodKey ? route('pointage.exportAllDivisions', uniquePeriods.find(p => p.key === selectedPeriodKey)?.quinzaines[0]?.id) : '#'}
                                    target="_blank"
                                    className={`inline-flex items-center px-5 py-2.5 border border-transparent rounded-md shadow-sm text-sm font-medium text-white transition-colors justify-center ${
                                        selectedPeriodKey
                                            ? 'bg-blue-600 hover:bg-blue-700'
                                            : 'bg-gray-400 cursor-not-allowed opacity-75'
                                    }`}
                                    onClick={(e) => !selectedPeriodKey && e.preventDefault()}
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 mr-2" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clipRule="evenodd" />
                                    </svg>
                                    Exporter
                                </a>
                            </div>
                        )}
                        {!(auth.user.role === 'super_admin' || auth.user.role === 'farm_manager') && (
                            <p className="text-gray-500 italic">Cette fonctionnalité est réservée aux Super Administrateurs et aux Responsables de Ferme.</p>
                        )}
                    </div>

                    {/* Enterprise Filter Section (Compact) */}
                    {(auth.user.role === 'super_admin' || auth.user.role === 'farm_manager') && (
                        <div className="flex items-center justify-end gap-4 mb-4"> {/* Compact container, aligned right */}
                            <label htmlFor="enterprise_filter_select" className="block text-sm font-medium text-gray-700 whitespace-nowrap">
                                Filtrer par Division:
                            </label>
                            <select
                                id="enterprise_filter_select"
                                className="block w-auto min-w-[200px] pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md shadow-sm"
                                value={filterEnterpriseId}
                                onChange={handleFilterChange}
                            >
                                <option value="">Toutes les Divisions</option>
                                {enterprises.map((ent) => (
                                    <option key={ent.id} value={ent.id}>
                                        {ent.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {/* List of Quinzaines */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <h3 className="text-lg font-bold mb-6 border-b pb-2">Liste des Périodes de Pointage</h3>

                            {quinzaines.length === 0 ? (
                                <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                                    <p className="text-gray-500 italic">Aucune quinzaine disponible. Cliquez sur "+ Ouvrir une Période" pour commencer.</p>
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                    {quinzaines.map((q) => (
                                        <div key={q.id} className={`relative flex flex-col p-6 rounded-xl border-2 transition-all ${q.is_closed ? 'bg-gray-50 border-gray-100 opacity-75' : 'bg-white border-blue-50 hover:border-blue-300 shadow-sm'}`}>
                                            <div className="flex justify-between items-start mb-4">
                                                <div className="flex flex-col w-full">
                                                    <h4 className="text-2xl font-black uppercase tracking-tighter text-blue-600 mb-1 leading-none">
                                                        {q.label || 'Sans Nom'}
                                                    </h4>
                                                    <p className="text-sm font-bold text-gray-400">
                                                        {formatDate(q.start_date)} — {formatDate(q.end_date)}
                                                    </p>
                                                </div>
                                                <div className="flex flex-col items-end gap-1">
                                                    {q.is_closed ? (
                                                        <span className="bg-gray-200 text-gray-600 px-2 py-1 rounded text-[9px] font-black uppercase tracking-tighter">CLÔTURÉE</span>
                                                    ) : (
                                                        <span className="bg-green-100 text-green-700 px-2 py-1 rounded text-[9px] font-black uppercase tracking-tighter animate-pulse">OUVERTE</span>
                                                    )}
                                                </div>
                                            </div>

                                            <div className="mb-6">
                                                <span className="text-[10px] font-bold text-gray-400 uppercase block mb-1">Ferme / Entreprise</span>
                                                <p className="font-bold text-gray-700">{q.enterprise?.name}</p>
                                            </div>

                                            <div className="mt-auto space-y-2">
                                                <Link
                                                    href={route('pointage.grid', q.id)}
                                                    className={`w-full block text-center py-2.5 rounded-lg font-black text-sm shadow-sm transition-all ${q.is_closed ? 'bg-gray-800 text-white hover:bg-black' : 'bg-blue-600 text-white hover:bg-blue-700'}`}
                                                >
                                                    {q.is_closed ? 'VOIR LES DÉTAILS' : 'REMPLIR LE POINTAGE'}
                                                </Link>

                                                <div className="grid grid-cols-2 gap-2">
                                                    {/* Individual Excel Export */}
                                                    <a
                                                        href={route('pointage.export', q.id)}
                                                        target="_blank"
                                                        className="text-center py-2 bg-green-50 text-green-700 rounded-lg text-[9px] font-black border border-green-200 hover:bg-green-100 transition-colors"
                                                    >
                                                        EXCEL
                                                    </a>

                                                    <a
                                                        href={route('payroll.general-payslip', q.id)}
                                                        target="_blank"
                                                        className="text-center py-2 bg-red-50 text-red-700 rounded-lg text-[9px] font-black border border-red-200 hover:bg-red-100 transition-colors"
                                                    >
                                                        PDF GLOBAL
                                                    </a>
                                                </div>

                                                {!q.is_closed && auth.user.role !== 'data_entry' && (
                                                    <Link
                                                        method="post"
                                                        as="button"
                                                        href={route('settings.quinzaine.close', q.id)}
                                                        className="w-full text-center py-2 text-[10px] font-bold text-red-500 hover:text-red-700 hover:underline"
                                                    >
                                                        CLÔTURER CETTE PÉRIODE
                                                    </Link>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* CREATE QUINZAINE MODAL */}
            <Modal show={isCreating} onClose={() => setIsCreating(false)}>
                <div className="p-8">
                    <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">Ouvrir une Nouvelle Quinzaine</h3>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-4">
                            <div>
                                <label className="block text-xs font-black uppercase text-gray-400 mb-1">Libellé / Nom de la Période (Optionnel)</label>
                                <input
                                    type="text"
                                    placeholder="Ex: Première Quinzaine Juin"
                                    className="w-full rounded-lg border-gray-300"
                                    value={data.label}
                                    onChange={e => setData('label', e.target.value)}
                                />
                                {errors.label && <div className="text-red-500 text-xs mt-1">{errors.label}</div>}
                            </div>

                            {auth.user.role === 'super_admin' && (
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 mb-1">Ferme / Entreprise *</label>
                                    <select
                                        className="w-full rounded-lg border-gray-300"
                                        value={data.enterprise_id}
                                        onChange={e => setData('enterprise_id', e.target.value)}
                                    >
                                        {enterprises.map(ent => <option key={ent.id} value={ent.id}>{ent.name}</option>)}
                                    </select>
                                    {errors.enterprise_id && <div className="text-red-500 text-xs mt-1">{errors.enterprise_id}</div>}
                                </div>
                            )}

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 mb-1">Date de Début</label>
                                    <input type="date" className="w-full rounded-lg border-gray-300" value={data.start_date} onChange={e => setData('start_date', e.target.value)} />
                                    {errors.start_date && <div className="text-red-500 text-xs mt-1">{errors.start_date}</div>}
                                </div>
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 mb-1">Date de Fin</label>
                                    <input type="date" className="w-full rounded-lg border-gray-300" value={data.end_date} onChange={e => setData('end_date', e.target.value)} />
                                    {errors.end_date && <div className="text-red-500 text-xs mt-1">{errors.end_date}</div>}
                                </div>
                            </div>
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsCreating(false)}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={processing}>Ouvrir la Période</PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
