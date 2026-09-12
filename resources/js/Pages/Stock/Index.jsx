import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import ToggleSwitch from '@/Components/ToggleSwitch';
import { formatNumber, formatMAD } from '@/utils/number';
import { UNIT_TYPE_LABELS } from '@/utils/stockLabels';
import ManageCategoriesModal from '@/Components/ManageCategoriesModal';
import ManageSuppliersModal from '@/Components/ManageSuppliersModal';

export default function Index({ auth, products, categories, suppliers, unitTypes, employees, vehicles, blocs, sectors, parcelles, operations, vehicleMaintenanceLogs, exitTypes }) {
    const [isCreating, setIsCreating] = useState(false);
    const [editingProduct, setEditingProduct] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedCategory, setSelectedCategory] = useState('');
    const [imagePreview, setImagePreview] = useState(null);
    const [movementModal, setMovementModal] = useState(null); // { product, type: 'in' | 'out' }
    const [isManagingCategories, setIsManagingCategories] = useState(false);
    const [isManagingSuppliers, setIsManagingSuppliers] = useState(false);

    const { data, setData, post, transform, processing, reset, errors, clearErrors } = useForm({
        name: '',
        image: undefined,
        category_id: categories.length > 0 ? categories[0].id : '',
        unit_type: unitTypes.length > 0 ? unitTypes[0] : '',
        min_stock_level: 0,
        unit_cost: '',
        is_active: true,
    });

    // "Entrée" (réception) — a delivery arriving at the magasin
    const receiveForm = useForm({
        product_id: '',
        quantity: '',
        unit_cost: '',
        supplier_id: '',
        numero_bl: '',
        date: new Date().toISOString().slice(0, 10),
        notes: '',
    });

    // "Sortie" — same shape as the Sorties de Stock form, since a sortie IS a manual stock entry
    // (consumption/loss/etc. with who/where context), just triggered from the product row.
    const sortieForm = useForm({
        product_id: '',
        entry_type: 'consumption',
        quantity: '',
        employee_id: '',
        vehicle_id: '',
        maintenance_log_id: '',
        operation_id: '',
        bloc_id: '',
        sector_id: '',
        parcelle_id: '',
        date: new Date().toISOString().slice(0, 10),
        notes: '',
        odometer_km: '',
    });

    const formatLogOption = (log) => {
        const date = new Date(log.performed_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
        return `${date} — ${log.description.slice(0, 60)}`;
    };
    const vehicleLogsFor = (vehicleId) => vehicleMaintenanceLogs.filter((log) => String(log.vehicle_id) === String(vehicleId));

    const sortieSelectedVehicle = vehicles.find((v) => String(v.id) === String(sortieForm.data.vehicle_id));
    // A tractor/truck works a field (bloc/opération apply); a car/van is just transport (they don't).
    const sortieHidesFieldContext = sortieSelectedVehicle && ['car', 'van'].includes(sortieSelectedVehicle.type);

    const sortieFilteredSectors = sortieForm.data.bloc_id
        ? sectors.filter((sector) => String(sector.bloc_id) === String(sortieForm.data.bloc_id))
        : [];
    const sortieFilteredParcelles = sortieForm.data.sector_id
        ? parcelles.filter((parcelle) => String(parcelle.sector_id) === String(sortieForm.data.sector_id))
        : [];

    const handleImageChange = (e) => {
        const file = e.target.files[0] ?? null;
        setData('image', file);
        setImagePreview(file ? URL.createObjectURL(file) : null);
    };

    const openCreate = () => {
        setEditingProduct(null);
        clearErrors();
        reset();
        setImagePreview(null);
        setIsCreating(true);
    };

    const openEdit = (product) => {
        setEditingProduct(product);
        clearErrors();
        setData({
            name: product.name,
            image: undefined, // Don't send image field unless changed
            category_id: product.category_id,
            unit_type: product.unit_type,
            min_stock_level: product.min_stock_level || 0,
            unit_cost: product.unit_cost || '',
            is_active: product.is_active,
        });
        setImagePreview(product.image_url || null);
        setIsCreating(true);
    };

    const closeProductModal = () => {
        setIsCreating(false);
        setEditingProduct(null);
        setImagePreview(null);
    };

    const submit = (e) => {
        e.preventDefault();
        if (editingProduct) {
            // PHP only parses multipart/form-data bodies into $_POST/$_FILES for a real
            // POST, never for PUT — a real PUT with the image file attached would arrive
            // empty. Route it as POST with a spoofed _method field instead.
            transform((data) => {
                // Only include image field if it's actually set (not undefined)
                const submitData = { ...data, _method: 'put' };
                if (submitData.image === undefined) {
                    delete submitData.image;
                }
                return submitData;
            });
            post(route('stock.products.update', editingProduct.id), {
                onSuccess: () => closeProductModal(),
            });
        } else {
            transform((data) => data);
            post(route('stock.products.store'), {
                onSuccess: () => closeProductModal(),
            });
        }
    };

    const handleToggleActive = (product) => {
        router.post(route('stock.products.toggle-active', product.id), {}, { preserveScroll: true });
    };

    const openMovementModal = (product, type) => {
        if (type === 'in') {
            receiveForm.clearErrors();
            receiveForm.setData({
                product_id: product.id,
                quantity: '',
                unit_cost: '',
                supplier_id: '',
                numero_bl: '',
                date: new Date().toISOString().slice(0, 10),
                notes: '',
            });
        } else {
            sortieForm.clearErrors();
            sortieForm.setData({
                product_id: product.id,
                entry_type: 'consumption',
                quantity: '',
                employee_id: '',
                vehicle_id: '',
                maintenance_log_id: '',
                operation_id: '',
                bloc_id: '',
                sector_id: '',
                parcelle_id: '',
                date: new Date().toISOString().slice(0, 10),
                notes: '',
                odometer_km: '',
            });
        }
        setMovementModal({ product, type });
    };

    const submitMovement = (e) => {
        e.preventDefault();
        if (movementModal.type === 'in') {
            receiveForm.post(route('stock.movements.in'), {
                onSuccess: () => {
                    receiveForm.reset();
                    setMovementModal(null);
                },
            });
        } else {
            sortieForm.post(route('stock.manual-entries.store'), {
                onSuccess: () => {
                    sortieForm.reset();
                    setMovementModal(null);
                },
            });
        }
    };

    const filteredProducts = products.filter(product => {
        const matchesSearch = product.name.toLowerCase().includes(searchTerm.toLowerCase());
        const matchesCategory = !selectedCategory || String(product.category_id) === String(selectedCategory);
        return matchesSearch && matchesCategory;
    });

    // Only fuel/oil/parts-type products are tied to a specific vehicle when they leave the
    // magasin — driven by the category's own "usage véhicule" flag (Gérer les Catégories)
    // rather than a fixed list of category names, so it still works if categories are renamed.
    const isVehicleConsumable = (product) => Boolean(product?.category?.is_vehicle_related);

    const getCurrentStock = (product) =>
        (product.stock_inventory ?? []).reduce((sum, inv) => sum + parseFloat(inv.quantity_on_hand || 0), 0);

    const getStockStatus = (product) => {
        const currentStock = getCurrentStock(product);
        const minStock = product.min_stock_level || 0;

        if (currentStock === 0) return { status: 'Épuisé', color: 'bg-red-500', textColor: 'text-red-600' };
        // Without a configured threshold there's no basis to call this stock "Bon" — a product
        // nobody has ever set a minimum for shouldn't look safer than one that has.
        if (minStock <= 0) return { status: 'Seuil non défini', color: 'bg-gray-400', textColor: 'text-gray-500' };
        if (currentStock <= minStock) return { status: 'Faible', color: 'bg-orange-500', textColor: 'text-orange-600' };
        if (currentStock <= minStock * 1.5) return { status: 'Normal', color: 'bg-yellow-500', textColor: 'text-yellow-600' };
        return { status: 'Bon', color: 'bg-green-500', textColor: 'text-green-600' };
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-4">
                    <div>
                        <h2 className="font-black text-2xl text-gray-800 uppercase tracking-tighter leading-tight">Gestion des Produits</h2>
                        <p className="text-sm text-gray-500 mt-1">Gérez votre catalogue de produits et niveaux de stock</p>
                    </div>
                    {auth.user.role !== 'data_entry' && (
                        <div className="flex items-center gap-3">
                            <button
                                onClick={() => setIsManagingCategories(true)}
                                className="bg-white hover:bg-gray-50 text-gray-700 px-5 py-3 rounded-xl font-black uppercase tracking-widest shadow-sm border border-gray-200 transition-all flex items-center gap-2"
                            >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                                Catégories
                            </button>
                            <button
                                onClick={() => setIsManagingSuppliers(true)}
                                className="bg-white hover:bg-gray-50 text-gray-700 px-5 py-3 rounded-xl font-black uppercase tracking-widest shadow-sm border border-gray-200 transition-all flex items-center gap-2"
                            >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                                Fournisseurs
                            </button>
                            <button
                                onClick={openCreate}
                                className="bg-primary-600 hover:bg-primary-700 text-white px-6 py-3 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                            >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Nouveau Produit
                            </button>
                        </div>
                    )}
                </div>
            }
        >
            <Head title="Produits" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Filters and Search */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        placeholder="Rechercher par nom..."
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                    />
                                    <svg className="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Catégorie</label>
                                <select
                                    value={selectedCategory}
                                    onChange={(e) => setSelectedCategory(e.target.value)}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                >
                                    <option value="">Toutes les catégories</option>
                                    {categories.map((cat) => (
                                        <option key={cat.id} value={cat.id}>{cat.name}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Products Table */}
                    <div className="bg-white shadow-sm sm:rounded-2xl border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <div className="flex justify-between items-center">
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter">Liste des Produits</h3>
                                <span className="text-sm text-gray-500">{filteredProducts.length} produit(s)</span>
                            </div>
                        </div>

                        {filteredProducts.length === 0 ? (
                            <div className="text-center py-16">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                </svg>
                                <p className="mt-4 text-gray-500">Aucun produit trouvé</p>
                                {auth.user.role !== 'data_entry' && (
                                    <button
                                        onClick={openCreate}
                                        className="mt-4 text-primary-600 hover:text-primary-700 font-medium"
                                    >
                                        Ajouter votre premier produit
                                    </button>
                                )}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Produit
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Catégorie
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Stock
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Statut
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Prix Unitaire
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Actif
                                            </th>
                                            <th scope="col" className="relative px-6 py-3">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {filteredProducts.map((product) => {
                                            const stockStatus = getStockStatus(product);
                                            return (
                                                <tr key={product.id} className={`hover:bg-gray-50 transition-colors ${!product.is_active ? 'opacity-60 bg-gray-50' : ''}`}>
                                                    <td className="px-6 py-4">
                                                        <div className="flex items-center">
                                                            {product.image_url ? (
                                                                <img
                                                                    src={product.image_url}
                                                                    alt={product.name}
                                                                    className="flex-shrink-0 h-10 w-10 rounded-lg object-cover"
                                                                />
                                                            ) : (
                                                                <div className="flex-shrink-0 h-10 w-10 bg-primary-100 rounded-lg flex items-center justify-center">
                                                                    <svg className="h-6 w-6 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />
                                                                    </svg>
                                                                </div>
                                                            )}
                                                            <div className="ml-4">
                                                                <div className="text-sm font-medium text-gray-900">{product.name}</div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <span className="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-primary-100 text-primary-800">
                                                            {product.category?.name ?? 'Sans catégorie'}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-900 font-medium">
                                                            {formatNumber(getCurrentStock(product))} {UNIT_TYPE_LABELS[product.unit_type] || product.unit_type}
                                                        </div>
                                                        <div className="text-xs text-gray-500">Min : {formatNumber(product.min_stock_level || 0)}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex items-center">
                                                            <span className={`h-2 w-2 rounded-full ${stockStatus.color} mr-2`}></span>
                                                            <span className={`text-sm font-medium ${stockStatus.textColor}`}>{stockStatus.status}</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {product.unit_cost ? formatMAD(product.unit_cost) : 'N/A'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <ToggleSwitch
                                                            checked={product.is_active}
                                                            onChange={() => handleToggleActive(product)}
                                                            disabled={auth.user.role === 'data_entry'}
                                                        />
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <div className="flex items-center justify-end space-x-2">
                                                            <button
                                                                onClick={() => openMovementModal(product, 'in')}
                                                                className="text-gray-400 hover:text-green-600 transition-colors"
                                                                title="Entrée de stock"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                </svg>
                                                            </button>
                                                            <button
                                                                onClick={() => openMovementModal(product, 'out')}
                                                                className="text-gray-400 hover:text-red-600 transition-colors"
                                                                title="Sortie de stock"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                </svg>
                                                            </button>
                                                            <Link
                                                                href={route('stock.products.show', product.id)}
                                                                className="text-gray-400 hover:text-primary-600 transition-colors"
                                                                title="Voir"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                                </svg>
                                                            </Link>
                                                            <button
                                                                type="button"
                                                                onClick={() => openEdit(product)}
                                                                className="text-gray-400 hover:text-gray-700 transition-colors"
                                                                title="Modifier"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* CREATE / EDIT PRODUCT MODAL */}
            <Modal show={isCreating} onClose={closeProductModal}>
                <div className="p-8">
                    <div className="flex justify-between items-center mb-6">
                        <div className="flex items-center gap-3">
                            <div className="h-10 w-10 bg-primary-100 rounded-xl flex items-center justify-center">
                                <svg className="h-6 w-6 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                            </div>
                            <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">{editingProduct ? 'Modifier le Produit' : 'Ajouter un Nouveau Produit'}</h3>
                        </div>
                        <button
                            onClick={closeProductModal}
                            className="text-gray-400 hover:text-gray-600 transition-colors"
                        >
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <form onSubmit={submit} className="space-y-6">
                        {/* Basic Information */}
                        <div className="bg-gray-50 rounded-xl p-4">
                            <h4 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                                <svg className="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Informations de Base
                            </h4>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="md:col-span-2">
                                    <InputLabel htmlFor="name" value="Nom du Produit *" />
                                    <TextInput
                                        id="name"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        required
                                        autoFocus
                                        placeholder="Ex: Engrais NPK 15-15-15"
                                    />
                                    <InputError message={errors.name} className="mt-2" />
                                </div>

                                <div className="md:col-span-2">
                                    <InputLabel htmlFor="image" value="Photo du Produit" />
                                    <div className="mt-1 flex items-center gap-4">
                                        {(imagePreview || editingProduct?.image_url) && (
                                            <img src={imagePreview || editingProduct.image_url} alt="Aperçu" className="h-16 w-16 rounded-lg object-cover border border-gray-200" />
                                        )}
                                        <input
                                            id="image"
                                            type="file"
                                            accept="image/*"
                                            onChange={handleImageChange}
                                            className="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100"
                                        />
                                    </div>
                                    <InputError message={errors.image} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="category" value="Catégorie *" />
                                    <select
                                        id="category"
                                        className="mt-1 block w-full border-gray-300 focus:border-primary-500 focus:ring-primary-500 rounded-lg shadow-sm"
                                        value={data.category_id}
                                        onChange={(e) => setData('category_id', e.target.value)}
                                        required
                                    >
                                        {categories.map((cat) => (
                                            <option key={cat.id} value={cat.id}>{cat.name}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.category_id} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="unit_type" value="Type d'Unité *" />
                                    <select
                                        id="unit_type"
                                        className="mt-1 block w-full border-gray-300 focus:border-primary-500 focus:ring-primary-500 rounded-lg shadow-sm"
                                        value={data.unit_type}
                                        onChange={(e) => setData('unit_type', e.target.value)}
                                        required
                                    >
                                        {unitTypes.map((unit) => (
                                            <option key={unit} value={unit}>{UNIT_TYPE_LABELS[unit] || unit}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.unit_type} className="mt-2" />
                                </div>
                            </div>
                        </div>

                        {/* Stock Information */}
                        <div className="bg-gray-50 rounded-xl p-4">
                            <h4 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                                <svg className="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10" />
                                </svg>
                                Informations de Stock
                            </h4>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <InputLabel htmlFor="min_stock_level" value="Stock Minimum" />
                                    <TextInput
                                        id="min_stock_level"
                                        type="number"
                                        className="mt-1 block w-full"
                                        value={data.min_stock_level}
                                        onChange={(e) => setData('min_stock_level', e.target.value)}
                                        placeholder="Ex: 100"
                                    />
                                    <InputError message={errors.min_stock_level} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="unit_cost" value="Coût Unitaire (MAD)" />
                                    <TextInput
                                        id="unit_cost"
                                        type="number"
                                        step="0.01"
                                        className="mt-1 block w-full"
                                        value={data.unit_cost}
                                        onChange={(e) => setData('unit_cost', e.target.value)}
                                        placeholder="Ex: 15.50"
                                    />
                                    <InputError message={errors.unit_cost} className="mt-2" />
                                </div>
                            </div>
                        </div>

                        {editingProduct && (
                            <div className="flex items-center justify-between bg-gray-50 rounded-xl p-4">
                                <div>
                                    <InputLabel htmlFor="is_active" value="Produit Actif" className="mb-0" />
                                    <p className="text-xs text-gray-500">Les produits inactifs ne sont plus proposés dans les sélections</p>
                                </div>
                                <ToggleSwitch
                                    checked={data.is_active}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                />
                            </div>
                        )}

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={closeProductModal}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={processing} className="bg-primary-600 hover:bg-primary-700">
                                {processing
                                    ? (editingProduct ? 'Mise à jour...' : 'Création en cours...')
                                    : (editingProduct ? 'Mettre à Jour le Produit' : 'Ajouter le Produit')}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>

            {/* STOCK IN / STOCK OUT MODAL */}
            <Modal show={movementModal !== null} onClose={() => setMovementModal(null)}>
                {movementModal && (
                    <div className="p-8">
                        <div className="flex justify-between items-center mb-6">
                            <div className="flex items-center gap-3">
                                <div className={`h-10 w-10 rounded-xl flex items-center justify-center ${movementModal.type === 'in' ? 'bg-green-100' : 'bg-red-100'}`}>
                                    <svg className={`h-6 w-6 ${movementModal.type === 'in' ? 'text-green-600' : 'text-red-600'}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        {movementModal.type === 'in' ? (
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        ) : (
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        )}
                                    </svg>
                                </div>
                                <div>
                                    <h3 className="text-xl font-black text-gray-800 uppercase tracking-tighter">
                                        {movementModal.type === 'in' ? 'Entrée de Stock' : 'Sortie de Stock'}
                                    </h3>
                                    <p className="text-sm text-gray-500">{movementModal.product.name}</p>
                                </div>
                            </div>
                            <button onClick={() => setMovementModal(null)} className="text-gray-400 hover:text-gray-600 transition-colors">
                                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {movementModal.type === 'in' ? (
                            <form onSubmit={submitMovement} className="space-y-6">
                                <div className="bg-gray-50 rounded-xl p-4">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <InputLabel htmlFor="movement_quantity" value={`Quantité (${UNIT_TYPE_LABELS[movementModal.product.unit_type] || movementModal.product.unit_type}) *`} />
                                            <TextInput
                                                id="movement_quantity"
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                className="mt-1 block w-full"
                                                value={receiveForm.data.quantity}
                                                onChange={(e) => receiveForm.setData('quantity', e.target.value)}
                                                required
                                                autoFocus
                                            />
                                            <InputError message={receiveForm.errors.quantity} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="movement_unit_cost" value="Coût Unitaire (MAD)" />
                                            <TextInput
                                                id="movement_unit_cost"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                className="mt-1 block w-full"
                                                value={receiveForm.data.unit_cost}
                                                onChange={(e) => receiveForm.setData('unit_cost', e.target.value)}
                                                placeholder={movementModal.product.unit_cost ?? 'Ex: 15.50'}
                                            />
                                            <InputError message={receiveForm.errors.unit_cost} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="movement_date" value="Date *" />
                                            <TextInput
                                                id="movement_date"
                                                type="date"
                                                className="mt-1 block w-full"
                                                value={receiveForm.data.date}
                                                onChange={(e) => receiveForm.setData('date', e.target.value)}
                                                required
                                            />
                                            <InputError message={receiveForm.errors.date} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="movement_supplier_id" value="Fournisseur" />
                                            <select
                                                id="movement_supplier_id"
                                                className="mt-1 block w-full border-gray-300 focus:border-green-500 focus:ring-green-500 rounded-lg shadow-sm"
                                                value={receiveForm.data.supplier_id}
                                                onChange={(e) => receiveForm.setData('supplier_id', e.target.value)}
                                            >
                                                <option value="">-- Aucun --</option>
                                                {suppliers.filter((s) => s.is_active || String(s.id) === String(receiveForm.data.supplier_id)).map((s) => (
                                                    <option key={s.id} value={s.id}>{s.name}</option>
                                                ))}
                                            </select>
                                            <InputError message={receiveForm.errors.supplier_id} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="movement_numero_bl" value="N° B.L" />
                                            <TextInput
                                                id="movement_numero_bl"
                                                type="text"
                                                className="mt-1 block w-full"
                                                value={receiveForm.data.numero_bl}
                                                onChange={(e) => receiveForm.setData('numero_bl', e.target.value)}
                                                placeholder="Optionnel"
                                            />
                                            <InputError message={receiveForm.errors.numero_bl} className="mt-2" />
                                        </div>

                                        <div className="md:col-span-2">
                                            <InputLabel htmlFor="movement_notes" value="Notes" />
                                            <textarea
                                                id="movement_notes"
                                                rows={2}
                                                className="mt-1 block w-full border-gray-300 focus:border-green-500 focus:ring-green-500 rounded-lg shadow-sm"
                                                value={receiveForm.data.notes}
                                                onChange={(e) => receiveForm.setData('notes', e.target.value)}
                                                placeholder="Optionnel"
                                            />
                                            <InputError message={receiveForm.errors.notes} className="mt-2" />
                                        </div>
                                    </div>
                                </div>

                                <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                                    <SecondaryButton onClick={() => setMovementModal(null)}>Annuler</SecondaryButton>
                                    <PrimaryButton disabled={receiveForm.processing} className="bg-green-600 hover:bg-green-700">
                                        {receiveForm.processing ? 'Enregistrement...' : "Enregistrer l'Entrée"}
                                    </PrimaryButton>
                                </div>
                            </form>
                        ) : (
                            // Same fields as the Sorties de Stock form — a sortie IS a manual stock entry,
                            // just started from the product row instead of the Sorties de Stock page.
                            <form onSubmit={submitMovement} className="space-y-6">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <InputLabel htmlFor="sortie_entry_type" value="Type de Sortie *" />
                                        <select
                                            id="sortie_entry_type"
                                            className="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-lg shadow-sm"
                                            value={sortieForm.data.entry_type}
                                            onChange={(e) => {
                                                const nextType = exitTypes.find((t) => t.key === e.target.value);
                                                sortieForm.setData((prev) => ({
                                                    ...prev,
                                                    entry_type: e.target.value,
                                                    maintenance_log_id: nextType?.requires_maintenance_log ? prev.maintenance_log_id : '',
                                                }));
                                            }}
                                            required
                                        >
                                            {exitTypes.map((exitType) => (
                                                <option key={exitType.key} value={exitType.key}>{exitType.label}</option>
                                            ))}
                                        </select>
                                        <InputError message={sortieForm.errors.entry_type} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="sortie_quantity" value={`Quantité (${UNIT_TYPE_LABELS[movementModal.product.unit_type] || movementModal.product.unit_type}) *`} />
                                        <TextInput
                                            id="sortie_quantity"
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            className="mt-1 block w-full"
                                            value={sortieForm.data.quantity}
                                            onChange={(e) => sortieForm.setData('quantity', e.target.value)}
                                            required
                                            autoFocus
                                        />
                                        <InputError message={sortieForm.errors.quantity} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="sortie_date" value="Date *" />
                                        <TextInput
                                            id="sortie_date"
                                            type="date"
                                            className="mt-1 block w-full"
                                            value={sortieForm.data.date}
                                            onChange={(e) => sortieForm.setData('date', e.target.value)}
                                            required
                                        />
                                        <InputError message={sortieForm.errors.date} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="sortie_employee_id" value="Employé" />
                                        <select
                                            id="sortie_employee_id"
                                            className="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-lg shadow-sm"
                                            value={sortieForm.data.employee_id}
                                            onChange={(e) => sortieForm.setData('employee_id', e.target.value)}
                                        >
                                            <option value="">-- Sélectionner un employé --</option>
                                            {employees.map((employee) => (
                                                <option key={employee.id} value={employee.id}>{employee.full_name}</option>
                                            ))}
                                        </select>
                                        <InputError message={sortieForm.errors.employee_id} className="mt-2" />
                                    </div>

                                    {isVehicleConsumable(movementModal.product) && (
                                        <div>
                                            <InputLabel htmlFor="sortie_vehicle_id" value="Véhicule" />
                                            <select
                                                id="sortie_vehicle_id"
                                                className="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-lg shadow-sm"
                                                value={sortieForm.data.vehicle_id}
                                                onChange={(e) => sortieForm.setData((prev) => ({ ...prev, vehicle_id: e.target.value, maintenance_log_id: '' }))}
                                            >
                                                <option value="">-- Sélectionner un véhicule --</option>
                                                {vehicles.map((vehicle) => (
                                                    <option key={vehicle.id} value={vehicle.id}>{vehicle.name}{(vehicle.plate_number || vehicle.serial_number) ? ` (${vehicle.plate_number || vehicle.serial_number})` : ''}</option>
                                                ))}
                                            </select>
                                            <InputError message={sortieForm.errors.vehicle_id} className="mt-2" />
                                        </div>
                                    )}

                                    {isVehicleConsumable(movementModal.product) && sortieForm.data.vehicle_id && (
                                        <div>
                                            <InputLabel htmlFor="sortie_odometer_km" value="Kilométrage (km)" />
                                            <TextInput
                                                id="sortie_odometer_km"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                className="mt-1 block w-full"
                                                value={sortieForm.data.odometer_km}
                                                onChange={(e) => sortieForm.setData('odometer_km', e.target.value)}
                                                placeholder="Ex: 12500.5"
                                            />
                                            <InputError message={sortieForm.errors.odometer_km} className="mt-2" />
                                        </div>
                                    )}

                                    {isVehicleConsumable(movementModal.product) && sortieForm.data.vehicle_id && exitTypes.find((t) => t.key === sortieForm.data.entry_type)?.requires_maintenance_log && (
                                        <div className="md:col-span-2">
                                            <InputLabel htmlFor="sortie_maintenance_log_id" value="Intervention de Maintenance Liée" />
                                            <select
                                                id="sortie_maintenance_log_id"
                                                className="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-lg shadow-sm"
                                                value={sortieForm.data.maintenance_log_id}
                                                onChange={(e) => sortieForm.setData('maintenance_log_id', e.target.value)}
                                            >
                                                <option value="">-- Aucune (pièce hors intervention) --</option>
                                                {vehicleLogsFor(sortieForm.data.vehicle_id).map((log) => (
                                                    <option key={log.id} value={log.id}>{formatLogOption(log)}</option>
                                                ))}
                                            </select>
                                            <p className="text-xs text-gray-500 mt-1">Rattache le coût de cette pièce à une intervention enregistrée sur la fiche du véhicule.</p>
                                            <InputError message={sortieForm.errors.maintenance_log_id} className="mt-2" />
                                        </div>
                                    )}

                                    {!sortieHidesFieldContext && (
                                        <>
                                            <div>
                                                <InputLabel htmlFor="sortie_operation_id" value="Opération" />
                                                <select
                                                    id="sortie_operation_id"
                                                    className="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-lg shadow-sm"
                                                    value={sortieForm.data.operation_id}
                                                    onChange={(e) => sortieForm.setData('operation_id', e.target.value)}
                                                >
                                                    <option value="">-- Sélectionner une opération --</option>
                                                    {operations.map((operation) => (
                                                        <option key={operation.id} value={operation.id}>{operation.name}</option>
                                                    ))}
                                                </select>
                                                <InputError message={sortieForm.errors.operation_id} className="mt-2" />
                                            </div>

                                            <div className="grid grid-cols-3 gap-4 md:col-span-2">
                                                <div>
                                                    <InputLabel htmlFor="sortie_bloc_id" value="Bloc" />
                                                    <select
                                                        id="sortie_bloc_id"
                                                        className="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-lg shadow-sm"
                                                        value={sortieForm.data.bloc_id}
                                                        onChange={(e) => sortieForm.setData((prev) => ({ ...prev, bloc_id: e.target.value, sector_id: '', parcelle_id: '' }))}
                                                    >
                                                        <option value="">-- Sélectionner --</option>
                                                        {blocs.map((bloc) => (
                                                            <option key={bloc.id} value={bloc.id}>{bloc.name}</option>
                                                        ))}
                                                    </select>
                                                    <InputError message={sortieForm.errors.bloc_id} className="mt-2" />
                                                </div>
                                                <div>
                                                    <InputLabel htmlFor="sortie_sector_id" value="Secteur" />
                                                    <select
                                                        id="sortie_sector_id"
                                                        className="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-lg shadow-sm disabled:bg-gray-100 disabled:text-gray-400"
                                                        value={sortieForm.data.sector_id}
                                                        onChange={(e) => sortieForm.setData((prev) => ({ ...prev, sector_id: e.target.value, parcelle_id: '' }))}
                                                        disabled={!sortieForm.data.bloc_id}
                                                    >
                                                        <option value="">{sortieForm.data.bloc_id ? '-- Sélectionner --' : '-- Choisir un bloc d\'abord --'}</option>
                                                        {sortieFilteredSectors.map((sector) => (
                                                            <option key={sector.id} value={sector.id}>{sector.name}</option>
                                                        ))}
                                                    </select>
                                                    <InputError message={sortieForm.errors.sector_id} className="mt-2" />
                                                </div>
                                                <div>
                                                    <InputLabel htmlFor="sortie_parcelle_id" value="Parcelle" />
                                                    <select
                                                        id="sortie_parcelle_id"
                                                        className="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-lg shadow-sm disabled:bg-gray-100 disabled:text-gray-400"
                                                        value={sortieForm.data.parcelle_id}
                                                        onChange={(e) => sortieForm.setData('parcelle_id', e.target.value)}
                                                        disabled={!sortieForm.data.sector_id}
                                                    >
                                                        <option value="">{sortieForm.data.sector_id ? '-- Sélectionner --' : '-- Choisir un secteur d\'abord --'}</option>
                                                        {sortieFilteredParcelles.map((parcelle) => (
                                                            <option key={parcelle.id} value={parcelle.id}>{parcelle.name}</option>
                                                        ))}
                                                    </select>
                                                    <InputError message={sortieForm.errors.parcelle_id} className="mt-2" />
                                                </div>
                                            </div>
                                        </>
                                    )}

                                    <div className="md:col-span-2">
                                        <InputLabel htmlFor="sortie_notes" value="Notes" />
                                        <textarea
                                            id="sortie_notes"
                                            className="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-lg shadow-sm"
                                            value={sortieForm.data.notes}
                                            onChange={(e) => sortieForm.setData('notes', e.target.value)}
                                            rows="3"
                                            placeholder="Ajoutez des notes supplémentaires..."
                                        ></textarea>
                                        <InputError message={sortieForm.errors.notes} className="mt-2" />
                                    </div>
                                </div>

                                <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                                    <SecondaryButton onClick={() => setMovementModal(null)}>Annuler</SecondaryButton>
                                    <PrimaryButton disabled={sortieForm.processing} className="bg-red-600 hover:bg-red-700">
                                        {sortieForm.processing ? 'Enregistrement...' : 'Enregistrer la Sortie'}
                                    </PrimaryButton>
                                </div>
                            </form>
                        )}
                    </div>
                )}
            </Modal>

            <ManageCategoriesModal
                show={isManagingCategories}
                onClose={() => setIsManagingCategories(false)}
                categories={categories}
            />

            <ManageSuppliersModal
                show={isManagingSuppliers}
                onClose={() => setIsManagingSuppliers(false)}
                suppliers={suppliers}
            />
        </AuthenticatedLayout>
    );
}
