import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { useState } from 'react';
import { formatNumber } from '@/Helpers/formatNumber';
import { t } from '@/Helpers/i18n';
import PrimaryButton from '@/Components/PrimaryButton';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';

export default function Harvests({ auth, harvests, blocs, sectors = [], parcelles = [], varieties = [], grades = [], selectedFarmId, farm, farms = [], error }) {
    const [confirmingHarvestDeletion, setConfirmingHarvestDeletion] = useState(null);
    const { delete: destroy, processing: deleteProcessing } = useForm();

    const confirmHarvestDeletion = (harvest) => setConfirmingHarvestDeletion(harvest);
    const closeDeleteModal = () => setConfirmingHarvestDeletion(null);
    const deleteHarvest = (e) => {
        e.preventDefault();
        destroy(route('harvests.destroy', confirmingHarvestDeletion.id), {
            preserveScroll: true,
            onSuccess: closeDeleteModal,
            onError: closeDeleteModal,
            onFinish: closeDeleteModal,
        });
    };

    const { data, setData, post, processing, reset, errors } = useForm({
        bloc_id: '',
        sector_id: '',
        parcelle_id: '',
        date: new Date().toISOString().split('T')[0],
        variety: varieties[0] || 'Hass',
        boxes_count: '',
        grade: grades[0] || 'Catégorie 1',
        unit_price_dh: '',
        comments: '',
        farm_id: selectedFarmId
    });

    const [selectedHarvests, setSelectedHarvests] = useState([]);
    const [showWeighModal, setShowWeighModal] = useState(false);
    const [weighingType, setWeighingType] = useState('total');
    const [totalWeight, setTotalWeight] = useState('');
    const [individualWeights, setIndividualWeights] = useState({});
    const [frontendErrors, setFrontendErrors] = useState({}); // New state for frontend errors

    // Dedicated useForm for bulk weighing
    const bulkWeighForm = useForm({
        harvest_ids: [],
        weighing_type: 'total',
        total_weight_kg: '',
        individual_weights: {},
    });

    // Filter sectors based on selected bloc
    const availableSectors = data.bloc_id
        ? sectors.filter(s => s.bloc_id === parseInt(data.bloc_id))
        : [];

    // Filter parcelles based on selected secteur
    const availableParcelles = data.sector_id
        ? parcelles.filter(p => p.sector_id === parseInt(data.sector_id))
        : [];

    const submit = (e) => {
        e.preventDefault();
        post(route('harvests.store'), {
            onSuccess: () => {
                reset('boxes_count', 'unit_price_dh', 'comments');
            }
        });
    };

    const handleBulkWeigh = (e) => {
        e.preventDefault();
        setFrontendErrors({}); // Clear previous frontend errors

        if (selectedHarvests.length === 0) {
            setFrontendErrors({ harvest_ids: 'Veuillez sélectionner au moins une récolte à peser.' });
            return;
        }

        // Update the bulkWeighForm data directly
        bulkWeighForm.setData({
            harvest_ids: selectedHarvests,
            weighing_type: weighingType,
            total_weight_kg: weighingType === 'total' ? totalWeight : '',
            individual_weights: weighingType === 'individual' ? individualWeights : {},
        });

        bulkWeighForm.post(route('harvests.bulkWeigh'), {
            onSuccess: () => {
                setShowWeighModal(false);
                setSelectedHarvests([]);
                setTotalWeight('');
                setIndividualWeights({});
                setFrontendErrors({}); // Clear errors on success
                bulkWeighForm.reset(); // Reset the bulk weigh form data
            },
            onError: (backendErrors) => {
                setFrontendErrors(backendErrors); // Display backend errors
            }
        });
    };

    const toggleHarvestSelection = (id) => {
        setSelectedHarvests(prev =>
            prev.includes(id) ? prev.filter(h => h !== id) : [...prev, id]
        );
    };

    const toggleAllHarvests = () => {
        if (selectedHarvests.length === harvests.data?.length) {
            setSelectedHarvests([]);
        } else {
            setSelectedHarvests(harvests.data?.map(h => h.id) || []);
        }
    };

    // Standard Inertia pagination component
    const Pagination = ({ links }) => {
        if (!links || links.length <= 3) return null;
        return (
            <div className="flex flex-wrap mt-6 justify-center gap-1">
                {links.map((link, key) => (
                    link.url === null ? (
                        <div
                            key={key}
                            className="px-3 py-2 text-xs text-gray-400 border border-gray-200 rounded-lg bg-gray-50"
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ) : (
                        <Link
                            key={key}
                            className={`px-3 py-2 text-xs border rounded-lg transition-colors ${
                                link.active
                                    ? "bg-blue-600 border-blue-600 text-white font-bold"
                                    : "bg-white border-gray-200 text-gray-700 hover:bg-gray-50"
                            }`}
                            href={link.url}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    )
                ))}
            </div>
        );
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">
                        {t('harvests')}
                    </h2>
                </div>
            }
        >
            <Head title={t('harvests')} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {error && (
                        <div className="bg-red-50 border-l-4 border-red-500 p-4 rounded shadow-sm text-red-700 text-sm">
                            {error}
                        </div>
                    )}

                    {/* Farm selector for super admin */}
                    {auth.user.role === 'super_admin' && farms.length > 0 && (
                        <div className="bg-white p-4 shadow-sm sm:rounded-2xl border border-gray-100">
                            <label htmlFor="harvest_farm_id" className="block text-xs font-black uppercase text-gray-500 mb-2">
                                Sélectionner une ferme
                            </label>
                            <select
                                id="harvest_farm_id"
                                className="w-full max-w-xs rounded-lg border-gray-300 text-sm"
                                value={selectedFarmId || ''}
                                onChange={e => {
                                    const farmId = e.target.value;
                                    window.location.href = farmId ? `/harvests?farm_id=${farmId}` : '/harvests';
                                }}
                            >
                                <option value="">-- Sélectionner une ferme --</option>
                                {farms.map(farm => (
                                    <option key={farm.id} value={farm.id}>
                                        {farm.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {/* UPPER GRID: Form & Arab Explanatory Guide */}
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

                        {/* FORM CARD */}
                        <div className="lg:col-span-2 bg-white p-6 shadow-sm sm:rounded-2xl border border-gray-100 border-t-4 border-t-blue-600">
                            <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter mb-4 flex items-center gap-2">
                                <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                {t('add_harvest')}
                            </h3>

                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">

                                    {/* Bloc / Plot selection */}
                                    <div>
                                        <label htmlFor="harvest_bloc_id" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                            Bloc / Parcelle *
                                        </label>
                                        <select
                                            id="harvest_bloc_id"
                                            className="w-full rounded-lg border-gray-300 text-sm"
                                            value={data.bloc_id}
                                            onChange={e => {
                                                setData('bloc_id', e.target.value);
                                                setData('sector_id', ''); // Reset secteur when bloc changes
                                                setData('parcelle_id', ''); // Reset parcelle when bloc changes
                                            }}
                                            required
                                        >
                                            <option value="">{t('select_plot')}</option>
                                            {blocs.map(bloc => (
                                                <option key={bloc.id} value={bloc.id}>
                                                    {bloc.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.bloc_id && <div className="text-red-500 text-xs mt-1">{errors.bloc_id}</div>}
                                    </div>

                                    {/* Secteur selection */}
                                    {data.bloc_id && availableSectors.length > 0 && (
                                        <div>
                                            <label htmlFor="harvest_sector_id" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                                Secteur *
                                            </label>
                                            <select
                                                id="harvest_sector_id"
                                                className="w-full rounded-lg border-gray-300 text-sm"
                                                value={data.sector_id}
                                                onChange={e => {
                                                    setData('sector_id', e.target.value);
                                                    setData('parcelle_id', ''); // Reset parcelle when secteur changes
                                                }}
                                                required
                                            >
                                                <option value="">-- Sélectionner un secteur --</option>
                                                {availableSectors.map(sector => (
                                                    <option key={sector.id} value={sector.id}>
                                                        {sector.name}
                                                    </option>
                                                ))}
                                            </select>
                                            {errors.sector_id && <div className="text-red-500 text-xs mt-1">{errors.sector_id}</div>}
                                        </div>
                                    )}

                                    {/* Parcelle selection (optional) */}
                                    {data.sector_id && availableParcelles.length > 0 && (
                                        <div>
                                            <label htmlFor="harvest_parcelle_id" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                                Parcelle (optionnel)
                                            </label>
                                            <select
                                                id="harvest_parcelle_id"
                                                className="w-full rounded-lg border-gray-300 text-sm"
                                                value={data.parcelle_id}
                                                onChange={e => setData('parcelle_id', e.target.value)}
                                            >
                                                <option value="">-- Sélectionner une parcelle --</option>
                                                {availableParcelles.map(parcelle => (
                                                    <option key={parcelle.id} value={parcelle.id}>
                                                        {parcelle.name} ({parcelle.area_ha} Ha)
                                                    </option>
                                                ))}
                                            </select>
                                            {errors.parcelle_id && <div className="text-red-500 text-xs mt-1">{errors.parcelle_id}</div>}
                                        </div>
                                    )}

                                    {/* Date */}
                                    <div>
                                        <label htmlFor="harvest_date" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                            {t('harvest_date')} *
                                        </label>
                                        <input
                                            id="harvest_date"
                                            type="date"
                                            className="w-full rounded-lg border-gray-300 text-sm"
                                            value={data.date}
                                            onChange={e => setData('date', e.target.value)}
                                            required
                                        />
                                        {errors.date && <div className="text-red-500 text-xs mt-1">{errors.date}</div>}
                                    </div>

                                    {/* Variety */}
                                    <div>
                                        <label htmlFor="harvest_variety" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                            {t('variety')} *
                                        </label>
                                        <select
                                            id="harvest_variety"
                                            className="w-full rounded-lg border-gray-300 text-sm"
                                            value={data.variety}
                                            onChange={e => setData('variety', e.target.value)}
                                            required
                                        >
                                            {varieties.map(v => <option key={v} value={v}>{v}</option>)}
                                        </select>
                                        {errors.variety && <div className="text-red-500 text-xs mt-1">{errors.variety}</div>}
                                    </div>

                                    {/* Grade */}
                                    <div>
                                        <label htmlFor="harvest_grade" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                            {t('grade')} *
                                        </label>
                                        <select
                                            id="harvest_grade"
                                            className="w-full rounded-lg border-gray-300 text-sm"
                                            value={data.grade}
                                            onChange={e => setData('grade', e.target.value)}
                                        >
                                            {grades.map(g => <option key={g} value={g}>{g}</option>)}
                                        </select>
                                        {errors.grade && <div className="text-red-500 text-xs mt-1">{errors.grade}</div>}
                                    </div>

                                    {/* Boxes */}
                                    <div>
                                        <label htmlFor="harvest_boxes_count" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                            {t('boxes_count')} *
                                        </label>
                                        <input
                                            id="harvest_boxes_count"
                                            type="number"
                                            className="w-full rounded-lg border-gray-300 text-sm"
                                            placeholder="Ex: 50"
                                            value={data.boxes_count}
                                            onChange={e => setData('boxes_count', e.target.value)}
                                            required
                                        />
                                        {errors.boxes_count && <div className="text-red-500 text-xs mt-1">{errors.boxes_count}</div>}
                                        <p className="text-[10px] text-gray-400 mt-1">Estimation: {data.boxes_count * (farm?.box_weight_kg || 50)} Kg ({farm?.box_weight_kg || 50} Kg/box)</p>
                                    </div>

                                    {/* Price */}
                                    <div>
                                        <label htmlFor="harvest_unit_price" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                            {t('unit_price')} (optionnel)
                                        </label>
                                        <input
                                            id="harvest_unit_price"
                                            type="number"
                                            step="0.01"
                                            className="w-full rounded-lg border-gray-300 text-sm"
                                            placeholder="Ex: 15.50"
                                            value={data.unit_price_dh}
                                            onChange={e => setData('unit_price_dh', e.target.value)}
                                        />
                                        {errors.unit_price_dh && <div className="text-red-500 text-xs mt-1">{errors.unit_price_dh}</div>}
                                    </div>

                                    {/* Comments */}
                                    <div className="md:col-span-2">
                                        <label htmlFor="harvest_comments" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                            Observations
                                        </label>
                                        <textarea
                                            id="harvest_comments"
                                            rows="2"
                                            className="w-full rounded-lg border-gray-300 text-sm"
                                            placeholder="Commentaires..."
                                            value={data.comments}
                                            onChange={e => setData('comments', e.target.value)}
                                        />
                                    </div>
                                </div>

                                <div className="flex justify-end pt-2">
                                    <PrimaryButton disabled={processing}>
                                        Enregistrer
                                    </PrimaryButton>
                                </div>
                            </form>
                        </div>

                        {/* EXPLANATORY Card */}
                        <div className="bg-gradient-to-br from-green-50 to-emerald-100 p-6 shadow-sm sm:rounded-2xl border-l-4 border-green-600">
                            <h3 className="text-lg font-black text-green-900 mb-3 flex items-center gap-2">
                                <svg className="w-5 h-5 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Guide d'analyse de production
                            </h3>
                            <div className="text-xs text-green-800 space-y-4 leading-relaxed font-semibold">
                                <p>
                                    Cette interface permet de suivre quotidiennement les quantités de fruits récoltés et leur distribution géographique.
                                </p>
                                <div className="border-t border-green-200/60 my-2 pt-2">
                                    <span className="text-[10px] font-black uppercase text-green-700 tracking-wider block mb-1">💡 Options d'entrée flexibles:</span>
                                    <ul>
                                        <li>• Le système permet d'enregistrer la présence des travailleurs soit au niveau du bloc général (ex: <code className="bg-white/50 px-1 rounded font-bold">B1</code>) pour les opérations générales, soit pour les parcelles détaillées (ex: <code className="bg-white/50 px-1 rounded font-bold">B1 - S1 - P1</code>) pour plus de précision.</li>
                                    </ul>
                                </div>
                                <div className="border-t border-green-200/60 my-2 pt-2">
                                    <span className="text-[10px] font-black uppercase text-green-700 tracking-wider block mb-1">📊 Analyses automatiques:</span>
                                    <ul className="space-y-1.5 list-disc list-inside">
                                        <li><strong>Rendement/hectare:</strong> Division de la production totale par la superficie en hectares.</li>
                                        <li><strong>Rendement/arbre:</strong> Division de la production totale par le nombre d'arbres.</li>
                                        <li><strong>Coût économique (coût de la main-d'œuvre/kg):</strong> Comparaison des dépenses journalières de main-d'œuvre (enregistrées dans Pointage) avec le volume de production pour connaître le coût de production par kilogramme.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* LOWER CARD: HARVESTS TABLE */}
                    <div className="bg-white p-6 shadow-sm sm:rounded-2xl border border-gray-100">
                        <div className="mb-4 flex justify-between items-center">
                            <div>
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">
                                    Historique des Récoltes
                                </h3>
                                <p className="text-xs text-gray-400 font-bold uppercase tracking-widest">
                                    {harvests.total || 0} enregistrements trouvés
                                </p>
                            </div>
                            {selectedHarvests.length > 0 && (
                                <button
                                    onClick={() => setShowWeighModal(true)}
                                    className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-xl text-xs font-black uppercase tracking-widest shadow-md transition-colors"
                                >
                                    Peser {selectedHarvests.length} récolte(s)
                                </button>
                            )}
                        </div>

                        <div className="overflow-x-auto -mx-6 sm:mx-0">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead className="bg-gray-50">
                                    <tr className="text-left text-xs font-bold uppercase tracking-wider text-gray-500">
                                        <th className="px-4 py-3 text-center">
                                            <input
                                                type="checkbox"
                                                checked={selectedHarvests.length === harvests.data?.length && harvests.data?.length > 0}
                                                onChange={toggleAllHarvests}
                                                className="rounded border-gray-300"
                                            />
                                        </th>
                                        <th className="px-4 py-3">Date</th>
                                        <th className="px-4 py-3">Bloc / Parcelle</th>
                                        <th className="px-4 py-3">Variété</th>
                                        <th className="px-4 py-3 text-right">Est. (Kg)</th>
                                        <th className="px-4 py-3 text-right">Actuel (Kg)</th>
                                        <th className="px-4 py-3 text-right">Caisses</th>
                                        <th className="px-4 py-3">Grade</th>
                                        <th className="px-4 py-3 text-right">Prix Unitaire</th>
                                        <th className="px-4 py-3 text-right">Revenu</th>
                                        <th className="px-4 py-3 text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {harvests.data && harvests.data.map(h => (
                                        <tr key={h.id} className={`hover:bg-gray-50 transition-colors ${h.is_weighed ? 'bg-green-50/30' : ''}`}>
                                            <td className="px-4 py-3 whitespace-nowrap text-center">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedHarvests.includes(h.id)}
                                                    onChange={() => toggleHarvestSelection(h.id)}
                                                    className="rounded border-gray-300"
                                                />
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap font-medium text-gray-600">
                                                {h.date.split('T')[0]}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="font-bold text-gray-800">{h.bloc?.name}</div>
                                                {h.parcelle && (
                                                    <div className="text-[9px] text-blue-600 font-bold uppercase tracking-wide">
                                                        → {h.parcelle.name}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <span className="px-2.5 py-1 text-xs font-bold rounded-lg bg-blue-50 text-blue-700 uppercase tracking-tight">
                                                    {h.variety}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-right text-gray-500 font-medium">
                                                {h.estimated_kg ? `${formatNumber(h.estimated_kg)} Kg` : '-'}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-right font-black text-gray-800">
                                                {formatNumber(h.quantity_kg)} Kg
                                                {h.is_weighed && (
                                                    <span className="ml-1 text-[9px] text-green-600 font-bold uppercase">✓ Pesé</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-right text-gray-500 font-medium">
                                                {h.boxes_count || '-'}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap font-bold text-gray-700">
                                                {h.grade || '-'}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-right text-gray-500 font-medium">
                                                {h.unit_price_dh ? `${formatNumber(h.unit_price_dh)} DH` : '-'}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-right font-black text-green-700">
                                                {h.total_revenue_dh ? `${formatNumber(h.total_revenue_dh)} DH` : '-'}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-center">
                                                {auth.user.role !== 'data_entry' && (
                                                    <div className="flex items-center justify-center gap-2">
                                                        <button
                                                            type="button"
                                                            title="Supprimer"
                                                            onClick={() => confirmHarvestDeletion(h)}
                                                            className="text-gray-400 hover:text-red-600 transition-colors"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {(!harvests.data || harvests.data.length === 0) && (
                                        <tr>
                                            <td colSpan="11" className="px-4 py-12 text-center text-gray-400 italic bg-gray-50/20">
                                                Aucune récolte enregistrée.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination links */}
                        <Pagination links={harvests.links} />
                    </div>

                    {/* Bulk Weigh Modal */}
                    {showWeighModal && (
                        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
                            <div className="bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6">
                                <h3 className="text-lg font-black text-gray-800 uppercase mb-4">
                                    Peser {selectedHarvests.length} récolte(s)
                                </h3>

                                <form onSubmit={handleBulkWeigh}>
                                    {frontendErrors.harvest_ids && (
                                        <div className="text-red-500 text-xs mt-1 mb-4">{frontendErrors.harvest_ids}</div>
                                    )}
                                    <div className="mb-4">
                                        <label className="block text-xs font-black uppercase text-gray-500 mb-2">
                                            Type de pesage
                                        </label>
                                        <div className="flex gap-4">
                                            <label className="flex items-center gap-2 cursor-pointer">
                                                <input
                                                    type="radio"
                                                    value="total"
                                                    checked={weighingType === 'total'}
                                                    onChange={e => setWeighingType(e.target.value)}
                                                    className="rounded border-gray-300"
                                                />
                                                <span className="text-sm font-bold">Poids total</span>
                                            </label>
                                            <label className="flex items-center gap-2 cursor-pointer">
                                                <input
                                                    type="radio"
                                                    value="individual"
                                                    checked={weighingType === 'individual'}
                                                    onChange={e => setWeighingType(e.target.value)}
                                                    className="rounded border-gray-300"
                                                />
                                                <span className="text-sm font-bold">Poids individuel</span>
                                            </label>
                                        </div>
                                    </div>

                                    {weighingType === 'total' ? (
                                        <div className="mb-4">
                                            <label htmlFor="harvest_total_weight" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                                Poids total (Kg)
                                            </label>
                                            <input
                                                id="harvest_total_weight"
                                                type="number"
                                                step="0.01"
                                                className="w-full rounded-lg border-gray-300 text-sm"
                                                placeholder="Ex: 2500"
                                                value={totalWeight}
                                                onChange={e => setTotalWeight(e.target.value)}
                                                required
                                            />
                                            {frontendErrors.total_weight_kg && <div className="text-red-500 text-xs mt-1">{frontendErrors.total_weight_kg}</div>}
                                            <p className="text-[10px] text-gray-400 mt-1">
                                                Sera distribué proportionnellement par nombre de caisses
                                            </p>
                                        </div>
                                    ) : (
                                        <div className="mb-4 space-y-2 max-h-60 overflow-y-auto">
                                            {harvests.data?.filter(h => selectedHarvests.includes(h.id)).map(h => (
                                                <div key={h.id} className="flex items-center gap-2">
                                                    <span className="text-xs font-bold w-32 truncate">
                                                        {h.bloc?.name} - {h.date.split('T')[0]}
                                                    </span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        aria-label={`Poids (Kg) - ${h.bloc?.name} ${h.date.split('T')[0]}`}
                                                        className="flex-1 rounded-lg border-gray-300 text-sm"
                                                        placeholder="Kg"
                                                        value={individualWeights[h.id] || ''}
                                                        onChange={e => setIndividualWeights(prev => ({
                                                            ...prev,
                                                            [h.id]: e.target.value
                                                        }))}
                                                        required
                                                    />
                                                    {frontendErrors[`individual_weights.${h.id}`] && <div className="text-red-500 text-xs mt-1">{frontendErrors[`individual_weights.${h.id}`]}</div>}
                                                </div>
                                            ))}
                                            {frontendErrors.individual_weights && <div className="text-red-500 text-xs mt-1">{frontendErrors.individual_weights}</div>}
                                        </div>
                                    )}

                                    <div className="flex justify-end gap-3 pt-4">
                                        <button
                                            type="button"
                                            onClick={() => setShowWeighModal(false)}
                                            className="px-4 py-2 rounded-lg text-xs font-bold uppercase text-gray-600 hover:bg-gray-100 transition-colors"
                                        >
                                            Annuler
                                        </button>
                                        <PrimaryButton disabled={bulkWeighForm.processing}> {/* Use bulkWeighForm.processing */}
                                            Confirmer
                                        </PrimaryButton>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <Modal show={confirmingHarvestDeletion !== null} onClose={closeDeleteModal}>
                <form onSubmit={deleteHarvest} className="p-8">
                    <div className="flex items-center gap-4 mb-4">
                        <div className="h-12 w-12 bg-red-100 rounded-full flex items-center justify-center">
                            <svg className="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter">
                            Supprimer la récolte
                        </h2>
                    </div>
                    <p className="text-gray-600 mb-6">
                        Voulez-vous supprimer cette récolte ? Cette action est irréversible.
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={closeDeleteModal}>Annuler</SecondaryButton>
                        <DangerButton className="rounded-xl" disabled={deleteProcessing}>
                            {deleteProcessing ? 'Suppression...' : 'Supprimer'}
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
