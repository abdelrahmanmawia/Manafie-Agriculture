import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

export default function Edit({ auth, product, categories, unitTypes }) {
    const { data, setData, put, processing, errors } = useForm({
        name: product.name,
        reference_code: product.reference_code,
        barcode: product.barcode || '',
        category: product.category,
        unit_type: product.unit_type,
        min_stock_level: product.min_stock_level,
        max_stock_level: product.max_stock_level || '',
        unit_cost: product.unit_cost || '',
        supplier: product.supplier || '',
        storage_location: product.storage_location || '',
        specifications: product.specifications ? JSON.stringify(product.specifications, null, 2) : '',
        is_active: product.is_active,
    });

    const submit = (e) => {
        e.preventDefault();
        // Parse specifications if provided
        const submitData = { ...data };
        if (submitData.specifications) {
            try {
                submitData.specifications = JSON.parse(submitData.specifications);
            } catch (e) {
                // If invalid JSON, keep as string and let backend handle validation
            }
        } else {
            submitData.specifications = null;
        }
        put(route('stock.products.update', product.id), submitData);
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div className="flex items-center gap-4">
                        <Link
                            href={route('stock.products.index')}
                            className="text-gray-500 hover:text-gray-700 transition-colors"
                        >
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h2 className="font-bold text-2xl text-gray-800 leading-tight">Modifier le Produit</h2>
                            <p className="text-sm text-gray-500 mt-1">Mettre à jour les informations du produit</p>
                        </div>
                    </div>
                </div>
            }
        >
            <Head title={`Modifier: ${product.name}`} />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Basic Information */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <h4 className="text-lg font-bold text-gray-800">Informations de Base</h4>
                        </div>
                        <div className="p-6">
                            <form onSubmit={submit} className="space-y-6">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
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

                                    <div>
                                        <InputLabel htmlFor="reference_code" value="Code de Référence *" />
                                        <TextInput
                                            id="reference_code"
                                            type="text"
                                            className="mt-1 block w-full"
                                            value={data.reference_code}
                                            onChange={(e) => setData('reference_code', e.target.value)}
                                            required
                                            placeholder="Ex: FERT-001"
                                        />
                                        <InputError message={errors.reference_code} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="barcode" value="Code-barres" />
                                        <TextInput
                                            id="barcode"
                                            type="text"
                                            className="mt-1 block w-full"
                                            value={data.barcode}
                                            onChange={(e) => setData('barcode', e.target.value)}
                                            placeholder="Ex: 1234567890123"
                                        />
                                        <InputError message={errors.barcode} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="category" value="Catégorie *" />
                                        <select
                                            id="category"
                                            className="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm"
                                            value={data.category}
                                            onChange={(e) => setData('category', e.target.value)}
                                            required
                                        >
                                            {categories.map((cat) => (
                                                <option key={cat} value={cat}>{cat}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.category} className="mt-2" />
                                    </div>

                                    <div>
                                        <InputLabel htmlFor="unit_type" value="Type d'Unité *" />
                                        <select
                                            id="unit_type"
                                            className="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm"
                                            value={data.unit_type}
                                            onChange={(e) => setData('unit_type', e.target.value)}
                                            required
                                        >
                                            {unitTypes.map((unit) => (
                                                <option key={unit} value={unit}>{unit}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.unit_type} className="mt-2" />
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
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
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
                                            <InputLabel htmlFor="max_stock_level" value="Stock Maximum" />
                                            <TextInput
                                                id="max_stock_level"
                                                type="number"
                                                className="mt-1 block w-full"
                                                value={data.max_stock_level}
                                                onChange={(e) => setData('max_stock_level', e.target.value)}
                                                placeholder="Ex: 1000"
                                            />
                                            <InputError message={errors.max_stock_level} className="mt-2" />
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

                                {/* Supplier & Location */}
                                <div className="bg-gray-50 rounded-xl p-4">
                                    <h4 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                                        <svg className="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                        Fournisseur et Emplacement
                                    </h4>
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <InputLabel htmlFor="supplier" value="Fournisseur" />
                                            <TextInput
                                                id="supplier"
                                                type="text"
                                                className="mt-1 block w-full"
                                                value={data.supplier}
                                                onChange={(e) => setData('supplier', e.target.value)}
                                                placeholder="Ex: AgriSupply Maroc"
                                            />
                                            <InputError message={errors.supplier} className="mt-2" />
                                        </div>

                                        <div>
                                            <InputLabel htmlFor="storage_location" value="Emplacement de Stockage" />
                                            <TextInput
                                                id="storage_location"
                                                type="text"
                                                className="mt-1 block w-full"
                                                value={data.storage_location}
                                                onChange={(e) => setData('storage_location', e.target.value)}
                                                placeholder="Ex: Hangar A, Rayon 3"
                                            />
                                            <InputError message={errors.storage_location} className="mt-2" />
                                        </div>
                                    </div>
                                </div>

                                {/* Specifications */}
                                <div className="bg-gray-50 rounded-xl p-4">
                                    <h4 className="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
                                        <svg className="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                        </svg>
                                        Spécifications (Optionnel)
                                    </h4>
                                    <div>
                                        <InputLabel htmlFor="specifications" value="Spécifications (JSON)" />
                                        <textarea
                                            id="specifications"
                                            className="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm"
                                            value={data.specifications}
                                            onChange={(e) => setData('specifications', e.target.value)}
                                            rows="3"
                                            placeholder='{"composition": "NPK 15-15-15", "poids_net": "50kg"}'
                                        ></textarea>
                                        <InputError message={errors.specifications} className="mt-2" />
                                    </div>
                                </div>

                                {/* Status */}
                                <div className="flex items-center justify-between bg-gray-50 rounded-xl p-4">
                                    <div className="flex items-center gap-3">
                                        <div className={`h-10 w-10 rounded-full flex items-center justify-center ${data.is_active ? 'bg-green-100' : 'bg-gray-100'}`}>
                                            {data.is_active ? (
                                                <svg className="h-5 w-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                            ) : (
                                                <svg className="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            )}
                                        </div>
                                        <div>
                                            <InputLabel htmlFor="is_active" value="Statut du Produit" className="mb-0" />
                                            <p className="text-xs text-gray-500">Les produits inactifs ne seront pas affichés dans les sélections</p>
                                        </div>
                                    </div>
                                    <label className="relative inline-flex items-center cursor-pointer">
                                        <input
                                            id="is_active"
                                            type="checkbox"
                                            className="sr-only peer"
                                            checked={data.is_active}
                                            onChange={(e) => setData('is_active', e.target.checked)}
                                        />
                                        <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                    </label>
                                    <InputError message={errors.is_active} className="mt-2" />
                                </div>

                                <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                                    <SecondaryButton onClick={() => window.history.back()}>Annuler</SecondaryButton>
                                    <PrimaryButton disabled={processing} className="bg-blue-600 hover:bg-blue-700">
                                        {processing ? 'Mise à jour...' : 'Mettre à Jour le Produit'}
                                    </PrimaryButton>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
