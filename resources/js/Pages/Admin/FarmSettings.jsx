import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { useState, Fragment } from 'react';
import { t } from '@/Helpers/i18n';

// Same path strings as AuthenticatedLayout.jsx's ICONS.sliders/pencil/map — reused directly
// rather than picking new emoji, so this page's tabs match the app's SVG icon system instead
// of standing out with a different visual register.
const TAB_ICONS = {
    general: 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4',
    operations: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
    structure: 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7',
};

export default function FarmSettings({ auth, farm, operations, blocs }) {
    const [activeTab, setActiveTab] = useState('general');
    const [expandedBlocs, setExpandedBlocs] = useState({});
    const [expandedSectors, setExpandedSectors] = useState({});

    const toggleBloc = (blocId) => {
        setExpandedBlocs(prev => ({ ...prev, [blocId]: !prev[blocId] }));
    };

    const toggleSector = (sectorId) => {
        setExpandedSectors(prev => ({ ...prev, [sectorId]: !prev[sectorId] }));
    };

    const opForm = useForm({ name: '', abbreviation: '', unit_rate: '' });
    const editOpForm = useForm({ name: '', abbreviation: '', unit_rate: '' });
    const [editingOpId, setEditingOpId] = useState(null);
    const blocForm = useForm({ name: '' });
    const sectorForm = useForm({ bloc_id: '', name: '', description: '', area_m2: '', area_ha: '', total_trees: '', spacing: '' });
    const parcelleForm = useForm({ bloc_id: '', sector_id: '', name: '', hass_trees: '', fuerte_trees: '', lambhass_trees: '', zutano_trees: '', area_m2: '', area_ha: '', spacing: '', total_trees: '' });
    const farmSettingsForm = useForm({ name: farm.name, box_weight_kg: farm.box_weight_kg || 50 });

    const submitOp = (e) => {
        e.preventDefault();
        opForm.post(route('farms.operations.store', farm.id), {
            onSuccess: () => opForm.reset(),
        });
    };

    const startEditOp = (op) => {
        setEditingOpId(op.id);
        editOpForm.setData({
            name: op.name,
            abbreviation: op.abbreviation || '',
            unit_rate: op.unit_rate ?? '',
        });
    };

    const submitEditOp = (e) => {
        e.preventDefault();
        editOpForm.put(route('farms.operations.update', editingOpId), {
            onSuccess: () => setEditingOpId(null),
        });
    };

    const submitBloc = (e) => {
        e.preventDefault();
        blocForm.post(route('farms.blocs.store', farm.id), {
            onSuccess: () => blocForm.reset(),
        });
    };

    const submitSector = (e) => {
        e.preventDefault();
        sectorForm.post(route('farms.sectors.store', farm.id), {
            onSuccess: () => sectorForm.reset(),
        });
    };

    const submitParcelle = (e) => {
        e.preventDefault();
        parcelleForm.post(route('farms.parcelles.store', farm.id), {
            onSuccess: () => parcelleForm.reset(),
        });
    };

    const submitFarmSettings = (e) => {
        e.preventDefault();
        farmSettingsForm.patch(route('farms.updateSettings', farm.id), {
            onSuccess: () => {
                // Form will be updated with new data from server
            }
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center w-full">
                    <div className="flex flex-col">
                        <h2 className="font-semibold text-xl text-gray-800 leading-tight">{t('settings')} - {farm.name}</h2>
                        <Link
                            href={route('farms.destroy', farm.id)}
                            method="delete"
                            as="button"
                            onBefore={() => confirm('ATTENTION: Cette action supprimera DÉFINITIVEMENT la ferme, toutes ses divisions, salariés, pointages et quinzaines. Voulez-vous continuer ?')}
                            className="text-[10px] text-red-500 hover:text-red-700 font-bold uppercase text-left mt-1"
                        >
                            Supprimer cette Ferme
                        </Link>
                    </div>
                    <Link
                        href={route('dashboard', { farm_id: farm.id })}
                        className="text-xs bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded font-bold transition-colors"
                    >
                        {t('back')} {t('dashboard')}
                    </Link>
                </div>
            }
        >
            <Head title={`${t('settings')} ${farm.name}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {/* TABS */}
                    <div className="bg-white rounded-t-lg shadow-sm border-b border-gray-200">
                        <div className="flex space-x-1 px-4">
                            {[
                                { id: 'general', label: 'Général', icon: TAB_ICONS.general },
                                { id: 'operations', label: 'Opérations', icon: TAB_ICONS.operations },
                                { id: 'structure', label: 'Structure', icon: TAB_ICONS.structure },
                            ].map(tab => (
                                <button
                                    key={tab.id}
                                    onClick={() => setActiveTab(tab.id)}
                                    className={`px-4 py-3 text-sm font-bold uppercase tracking-wider border-b-2 transition-colors flex items-center ${
                                        activeTab === tab.id
                                            ? 'border-blue-600 text-blue-600'
                                            : 'border-transparent text-gray-500 hover:text-gray-700'
                                    }`}
                                >
                                    <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={tab.icon} />
                                    </svg>
                                    {tab.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* TAB CONTENT */}
                    <div className="bg-white rounded-b-lg shadow-sm p-6">
                        {activeTab === 'general' && (
                            <div>
                                <h3 className="text-lg font-bold leading-none mb-6">Paramètres Généraux</h3>
                                <form onSubmit={submitFarmSettings} className="max-w-2xl space-y-4">
                                    <div>
                                        <label htmlFor="farm_name" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                            Nom de la ferme
                                        </label>
                                        <input
                                            id="farm_name"
                                            type="text"
                                            className="w-full rounded-lg border-gray-300 text-sm"
                                            value={farmSettingsForm.data.name}
                                            onChange={e => farmSettingsForm.setData('name', e.target.value)}
                                            required
                                        />
                                        {farmSettingsForm.errors.name && <div className="text-red-500 text-xs mt-1">{farmSettingsForm.errors.name}</div>}
                                    </div>
                                    <div>
                                        <label htmlFor="farm_box_weight_kg" className="block text-xs font-black uppercase text-gray-500 mb-1">
                                            Poids estimé par caisse (Kg)
                                        </label>
                                        <input
                                            id="farm_box_weight_kg"
                                            type="number"
                                            step="0.01"
                                            min="1"
                                            max="1000"
                                            className="w-full rounded-lg border-gray-300 text-sm"
                                            value={farmSettingsForm.data.box_weight_kg}
                                            onChange={e => farmSettingsForm.setData('box_weight_kg', e.target.value)}
                                            required
                                        />
                                        <p className="text-[10px] text-gray-400 mt-1">Utilisé pour l'estimation du poids des récoltes</p>
                                        {farmSettingsForm.errors.box_weight_kg && <div className="text-red-500 text-xs mt-1">{farmSettingsForm.errors.box_weight_kg}</div>}
                                    </div>
                                    <div>
                                        <button type="submit" className="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold hover:bg-blue-700 transition-colors" disabled={farmSettingsForm.processing}>
                                            Mettre à jour
                                        </button>
                                    </div>
                                </form>
                            </div>
                        )}

                        {activeTab === 'operations' && (
                            <div>
                                <h3 className="text-lg font-bold leading-none mb-6">Gérer les Opérations</h3>
                                <form onSubmit={submitOp} className="flex gap-2 mb-6 max-w-2xl">
                                    <input
                                        type="text"
                                        placeholder="Ex: Récolte, Taille..."
                                        className="flex-1 rounded-lg border-gray-300 text-sm"
                                        value={opForm.data.name}
                                        onChange={e => opForm.setData('name', e.target.value)}
                                        required
                                    />
                                    <input
                                        type="text"
                                        placeholder="Abréviation (ex: REC)"
                                        className="w-32 rounded-lg border-gray-300 text-sm"
                                        value={opForm.data.abbreviation}
                                        onChange={e => opForm.setData('abbreviation', e.target.value)}
                                    />
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        placeholder="Prix/unité (DH)"
                                        className="w-36 rounded-lg border-gray-300 text-sm"
                                        value={opForm.data.unit_rate}
                                        onChange={e => opForm.setData('unit_rate', e.target.value)}
                                    />
                                    <button type="submit" className="bg-blue-600 text-white px-4 py-2 rounded-lg font-bold" disabled={opForm.processing}>Ajouter</button>
                                </form>
                                {Object.keys(opForm.errors).length > 0 && (
                                    <div className="text-red-500 text-xs mb-3 max-w-2xl">{Object.values(opForm.errors).join(' ')}</div>
                                )}
                                <p className="text-[10px] text-gray-400 mb-3 max-w-2xl">
                                    Laissez le prix/unité vide pour une opération payée au tarif journalier normal de l'employé. Renseignez-le pour une opération payée à la quantité (ex: 10 DH/mètre) — la grille de pointage demandera alors une quantité au lieu des heures.
                                </p>
                                <div className="bg-gray-50 rounded-lg overflow-hidden">
                                    <table className="w-full text-sm">
                                        <thead className="bg-gray-100">
                                            <tr>
                                                <th className="px-4 py-2 text-left font-bold text-gray-600">Opération</th>
                                                <th className="px-4 py-2 text-left font-bold text-gray-600">Abréviation</th>
                                                <th className="px-4 py-2 text-left font-bold text-gray-600">Prix/unité</th>
                                                <th className="px-4 py-2 text-right font-bold text-gray-600">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {operations.map(op => (
                                                editingOpId === op.id ? (
                                                    <Fragment key={op.id}>
                                                    {Object.keys(editOpForm.errors).length > 0 && (
                                                        <tr className="bg-blue-50">
                                                            <td colSpan="4" className="px-4 pb-2 text-red-500 text-xs">{Object.values(editOpForm.errors).join(' ')}</td>
                                                        </tr>
                                                    )}
                                                    <tr className="bg-blue-50">
                                                        <td className="px-4 py-2">
                                                            <input
                                                                type="text"
                                                                className="w-full rounded-lg border-gray-300 text-sm"
                                                                value={editOpForm.data.name}
                                                                onChange={e => editOpForm.setData('name', e.target.value)}
                                                                required
                                                            />
                                                        </td>
                                                        <td className="px-4 py-2">
                                                            <input
                                                                type="text"
                                                                className="w-full rounded-lg border-gray-300 text-sm"
                                                                value={editOpForm.data.abbreviation}
                                                                onChange={e => editOpForm.setData('abbreviation', e.target.value)}
                                                            />
                                                        </td>
                                                        <td className="px-4 py-2">
                                                            <input
                                                                type="number"
                                                                step="0.01"
                                                                min="0"
                                                                placeholder="Journalier"
                                                                className="w-full rounded-lg border-gray-300 text-sm"
                                                                value={editOpForm.data.unit_rate}
                                                                onChange={e => editOpForm.setData('unit_rate', e.target.value)}
                                                            />
                                                        </td>
                                                        <td className="px-4 py-3 text-right whitespace-nowrap">
                                                            <button
                                                                onClick={submitEditOp}
                                                                className="text-green-600 hover:text-green-800 text-xs font-bold mr-3"
                                                                disabled={editOpForm.processing}
                                                            >
                                                                Enregistrer
                                                            </button>
                                                            <button
                                                                onClick={() => setEditingOpId(null)}
                                                                className="text-gray-500 hover:text-gray-700 text-xs font-bold"
                                                            >
                                                                Annuler
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    </Fragment>
                                                ) : (
                                                    <tr key={op.id} className="hover:bg-gray-50">
                                                        <td className="px-4 py-3 font-medium text-gray-700">{op.name}</td>
                                                        <td className="px-4 py-3 text-xs text-gray-500 font-mono">{op.abbreviation || '-'}</td>
                                                        <td className="px-4 py-3 text-xs text-gray-600">
                                                            {op.unit_rate ? `${Number(op.unit_rate).toFixed(2)} DH/unité` : 'Journalier'}
                                                        </td>
                                                        <td className="px-4 py-3 text-right whitespace-nowrap">
                                                            <button
                                                                onClick={() => startEditOp(op)}
                                                                className="text-blue-600 hover:text-blue-800 text-xs font-bold mr-3"
                                                            >
                                                                Modifier
                                                            </button>
                                                            <Link
                                                                href={route('farms.operations.destroy', op.id)}
                                                                method="delete"
                                                                as="button"
                                                                className="text-red-500 hover:text-red-700 text-xs font-bold"
                                                            >
                                                                Supprimer
                                                            </Link>
                                                        </td>
                                                    </tr>
                                                )
                                            ))}
                                            {operations.length === 0 && (
                                                <tr>
                                                    <td colSpan="4" className="px-4 py-8 text-center text-gray-400 italic">Aucune opération</td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {activeTab === 'structure' && (
                            <div>
                                <h3 className="text-lg font-bold leading-none mb-6">Structure de la Ferme</h3>

                                {/* ADD NEW BLOC FORM */}
                                <div className="mb-6 bg-gradient-to-r from-green-50 to-emerald-50 p-4 rounded-lg border border-green-200">
                                    <h4 className="text-sm font-bold text-green-800 mb-3 flex items-center gap-2">
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                        </svg>
                                        Ajouter un nouveau Bloc
                                    </h4>
                                    <form onSubmit={submitBloc} className="flex gap-2">
                                        <input
                                            type="text"
                                            placeholder="Nom du bloc (ex: Bloc A)"
                                            className="flex-1 rounded-lg border-green-300 text-sm focus:ring-green-500 focus:border-green-500"
                                            value={blocForm.data.name}
                                            onChange={e => blocForm.setData('name', e.target.value)}
                                            required
                                        />
                                        <button type="submit" className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold text-sm transition-colors" disabled={blocForm.processing}>
                                            Ajouter
                                        </button>
                                    </form>
                                    {blocForm.errors.name && <div className="text-red-500 text-xs mt-2">{blocForm.errors.name}</div>}
                                </div>

                                {/* HIERARCHICAL STRUCTURE DISPLAY */}
                                <div className="space-y-3">
                                    {blocs.length === 0 ? (
                                        <div className="bg-gray-50 rounded-lg p-8 text-center text-gray-400 italic">
                                            Aucun bloc défini. Commencez par ajouter un bloc ci-dessus.
                                        </div>
                                    ) : (
                                        blocs.map(bloc => {
                                            const blocSectors = farm.sectors?.filter(s => s.bloc_id === bloc.id) || [];
                                            const isExpanded = expandedBlocs[bloc.id];

                                            return (
                                                <div key={bloc.id} className="border border-gray-200 rounded-lg overflow-hidden">
                                                    {/* BLOC HEADER */}
                                                    <div
                                                        className="bg-gradient-to-r from-green-100 to-green-50 px-4 py-3 flex items-center justify-between cursor-pointer hover:from-green-200 hover:to-green-100 transition-colors"
                                                        onClick={() => toggleBloc(bloc.id)}
                                                    >
                                                        <div className="flex items-center gap-3">
                                                            <svg className={`w-5 h-5 text-green-600 transition-transform ${isExpanded ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                                                            </svg>
                                                            <div>
                                                                <span className="font-bold text-green-900">{bloc.name}</span>
                                                                <span className="text-xs text-green-600 ml-2">({blocSectors.length} secteur{blocSectors.length !== 1 ? 's' : ''})</span>
                                                            </div>
                                                        </div>
                                                        <Link
                                                            href={route('farms.blocs.destroy', bloc.id)}
                                                            method="delete"
                                                            as="button"
                                                            onBefore={() => confirm('Supprimer ce bloc et tous ses secteurs/parcelles ?')}
                                                            className="text-red-500 hover:text-red-700 text-xs font-bold px-2 py-1 rounded hover:bg-red-50 transition-colors"
                                                        >
                                                            Supprimer
                                                        </Link>
                                                    </div>

                                                    {/* BLOC CONTENT - SECTEURS */}
                                                    {isExpanded && (
                                                        <div className="p-4 bg-white">
                                                            {/* ADD SECTEUR FORM */}
                                                            <div className="mb-4 bg-purple-50 p-3 rounded-lg border border-purple-200">
                                                                <h5 className="text-xs font-bold text-purple-800 mb-2">Ajouter un secteur à {bloc.name}</h5>
                                                                <form onSubmit={(e) => {
                                                                    e.preventDefault();
                                                                    sectorForm.setData('bloc_id', bloc.id);
                                                                    submitSector(e);
                                                                }} className="space-y-2">
                                                                    <input
                                                                        type="text"
                                                                        placeholder="Nom du secteur"
                                                                        className="w-full rounded-lg border-purple-300 text-xs focus:ring-purple-500 focus:border-purple-500"
                                                                        value={sectorForm.data.name}
                                                                        onChange={e => sectorForm.setData('name', e.target.value)}
                                                                        required
                                                                    />
                                                                    {sectorForm.errors.name && <div className="text-red-500 text-[10px]">{sectorForm.errors.name}</div>}
                                                                    <div className="grid grid-cols-3 gap-2">
                                                                        <input
                                                                            type="number"
                                                                            placeholder="Surface ha"
                                                                            className="rounded-lg border-purple-300 text-xs"
                                                                            value={sectorForm.data.area_ha}
                                                                            onChange={e => sectorForm.setData('area_ha', e.target.value)}
                                                                        />
                                                                        <input
                                                                            type="number"
                                                                            placeholder="Nb arbres"
                                                                            className="rounded-lg border-purple-300 text-xs"
                                                                            value={sectorForm.data.total_trees}
                                                                            onChange={e => sectorForm.setData('total_trees', e.target.value)}
                                                                        />
                                                                        <button type="submit" className="bg-purple-600 hover:bg-purple-700 text-white px-3 py-1 rounded-lg font-bold text-xs transition-colors" disabled={sectorForm.processing}>
                                                                            Ajouter
                                                                        </button>
                                                                    </div>
                                                                    {(sectorForm.errors.area_ha || sectorForm.errors.total_trees) && (
                                                                        <div className="text-red-500 text-[10px]">{sectorForm.errors.area_ha || sectorForm.errors.total_trees}</div>
                                                                    )}
                                                                </form>
                                                            </div>

                                                            {/* SECTEURS LIST */}
                                                            {blocSectors.length === 0 ? (
                                                                <div className="text-center text-gray-400 text-xs italic py-4">
                                                                    Aucun secteur dans ce bloc
                                                                </div>
                                                            ) : (
                                                                <div className="space-y-2">
                                                                    {blocSectors.map(sector => {
                                                                        const sectorParcelles = farm.parcelles?.filter(p => p.sector_id === sector.id) || [];
                                                                        const isSectorExpanded = expandedSectors[sector.id];

                                                                        return (
                                                                            <div key={sector.id} className="border border-purple-200 rounded-lg overflow-hidden">
                                                                                {/* SECTEUR HEADER */}
                                                                                <div
                                                                                    className="bg-purple-50 px-3 py-2 flex items-center justify-between cursor-pointer hover:bg-purple-100 transition-colors"
                                                                                    onClick={() => toggleSector(sector.id)}
                                                                                >
                                                                                    <div className="flex items-center gap-2">
                                                                                        <svg className={`w-4 h-4 text-purple-600 transition-transform ${isSectorExpanded ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                                                                                        </svg>
                                                                                        <span className="font-bold text-purple-900 text-sm">{sector.name}</span>
                                                                                        <span className="text-xs text-purple-600">({sectorParcelles.length} parcelle{sectorParcelles.length !== 1 ? 's' : ''})</span>
                                                                                    </div>
                                                                                    <div className="flex items-center gap-2">
                                                                                        <span className="text-xs text-gray-500">{sector.area_ha} Ha • {sector.total_trees} arbres</span>
                                                                                        <Link
                                                                                            href={route('farms.sectors.destroy', sector.id)}
                                                                                            method="delete"
                                                                                            as="button"
                                                                                            onBefore={() => confirm('Supprimer ce secteur et ses parcelles ?')}
                                                                                            className="text-red-500 hover:text-red-700 text-xs font-bold px-2 py-1 rounded hover:bg-red-50 transition-colors"
                                                                                        >
                                                                                            Supprimer
                                                                                        </Link>
                                                                                    </div>
                                                                                </div>

                                                                                {/* SECTEUR CONTENT - PARCELLES */}
                                                                                {isSectorExpanded && (
                                                                                    <div className="p-3 bg-white">
                                                                                        {/* ADD PARCELLE FORM */}
                                                                                        <div className="mb-3 bg-orange-50 p-3 rounded-lg border border-orange-200">
                                                                                            <h5 className="text-xs font-bold text-orange-800 mb-2">Ajouter une parcelle à {sector.name}</h5>
                                                                                            <form onSubmit={(e) => {
                                                                                                e.preventDefault();
                                                                                                parcelleForm.setData('bloc_id', bloc.id);
                                                                                                parcelleForm.setData('sector_id', sector.id);
                                                                                                submitParcelle(e);
                                                                                            }} className="space-y-2">
                                                                                                <input
                                                                                                    type="text"
                                                                                                    placeholder="Nom de la parcelle"
                                                                                                    className="w-full rounded-lg border-orange-300 text-xs focus:ring-orange-500 focus:border-orange-500"
                                                                                                    value={parcelleForm.data.name}
                                                                                                    onChange={e => parcelleForm.setData('name', e.target.value)}
                                                                                                    required
                                                                                                />
                                                                                                {parcelleForm.errors.name && <div className="text-red-500 text-[10px]">{parcelleForm.errors.name}</div>}
                                                                                                <div className="grid grid-cols-4 gap-2">
                                                                                                    <input
                                                                                                        type="number"
                                                                                                        placeholder="Hass"
                                                                                                        className="rounded-lg border-orange-300 text-xs"
                                                                                                        value={parcelleForm.data.hass_trees}
                                                                                                        onChange={e => parcelleForm.setData('hass_trees', e.target.value)}
                                                                                                    />
                                                                                                    <input
                                                                                                        type="number"
                                                                                                        placeholder="Fuerte"
                                                                                                        className="rounded-lg border-orange-300 text-xs"
                                                                                                        value={parcelleForm.data.fuerte_trees}
                                                                                                        onChange={e => parcelleForm.setData('fuerte_trees', e.target.value)}
                                                                                                    />
                                                                                                    <input
                                                                                                        type="number"
                                                                                                        placeholder="Lambhass"
                                                                                                        className="rounded-lg border-orange-300 text-xs"
                                                                                                        value={parcelleForm.data.lambhass_trees}
                                                                                                        onChange={e => parcelleForm.setData('lambhass_trees', e.target.value)}
                                                                                                    />
                                                                                                    <input
                                                                                                        type="number"
                                                                                                        placeholder="Zutano"
                                                                                                        className="rounded-lg border-orange-300 text-xs"
                                                                                                        value={parcelleForm.data.zutano_trees}
                                                                                                        onChange={e => parcelleForm.setData('zutano_trees', e.target.value)}
                                                                                                    />
                                                                                                </div>
                                                                                                <div className="grid grid-cols-2 gap-2">
                                                                                                    <input
                                                                                                        type="number"
                                                                                                        placeholder="Surface ha"
                                                                                                        className="rounded-lg border-orange-300 text-xs"
                                                                                                        value={parcelleForm.data.area_ha}
                                                                                                        onChange={e => parcelleForm.setData('area_ha', e.target.value)}
                                                                                                    />
                                                                                                    <button type="submit" className="bg-orange-600 hover:bg-orange-700 text-white px-3 py-1 rounded-lg font-bold text-xs transition-colors" disabled={parcelleForm.processing}>
                                                                                                        Ajouter
                                                                                                    </button>
                                                                                                </div>
                                                                                                {Object.keys(parcelleForm.errors).filter(k => k !== 'name').length > 0 && (
                                                                                                    <div className="text-red-500 text-[10px]">
                                                                                                        {Object.entries(parcelleForm.errors).filter(([k]) => k !== 'name').map(([, v]) => v).join(' ')}
                                                                                                    </div>
                                                                                                )}
                                                                                            </form>
                                                                                        </div>

                                                                                        {/* PARCELLES LIST */}
                                                                                        {sectorParcelles.length === 0 ? (
                                                                                            <div className="text-center text-gray-400 text-xs italic py-3">
                                                                                                Aucune parcelle dans ce secteur
                                                                                            </div>
                                                                                        ) : (
                                                                                            <div className="space-y-1">
                                                                                                {sectorParcelles.map(parcelle => (
                                                                                                    <div key={parcelle.id} className="flex items-center justify-between bg-orange-50 px-3 py-2 rounded border border-orange-200">
                                                                                                        <div>
                                                                                                            <span className="font-bold text-orange-900 text-sm">{parcelle.name}</span>
                                                                                                            <span className="text-xs text-orange-600 ml-2">
                                                                                                                {parcelle.area_ha} Ha • {parcelle.total_trees} arbres
                                                                                                            </span>
                                                                                                            <div className="text-xs text-gray-500 mt-1">
                                                                                                                H:{parcelle.hass_trees || 0} • F:{parcelle.fuerte_trees || 0} • L:{parcelle.lambhass_trees || 0} • Z:{parcelle.zutano_trees || 0}
                                                                                                            </div>
                                                                                                        </div>
                                                                                                        <Link
                                                                                                            href={route('farms.parcelles.destroy', parcelle.id)}
                                                                                                            method="delete"
                                                                                                            as="button"
                                                                                                            onBefore={() => confirm('Supprimer cette parcelle ?')}
                                                                                                            className="text-red-500 hover:text-red-700 text-xs font-bold px-2 py-1 rounded hover:bg-red-50 transition-colors"
                                                                                                        >
                                                                                                            Supprimer
                                                                                                        </Link>
                                                                                                    </div>
                                                                                                ))}
                                                                                            </div>
                                                                                        )}
                                                                                    </div>
                                                                                )}
                                                                            </div>
                                                                        );
                                                                    })}
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
