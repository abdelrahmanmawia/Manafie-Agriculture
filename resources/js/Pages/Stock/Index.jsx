import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

export default function Index({ auth, products, categories, unitTypes }) {
    const [isCreating, setIsCreating] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedCategory, setSelectedCategory] = useState('');
    const [imagePreview, setImagePreview] = useState(null);

    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        image: null,
        category: categories.length > 0 ? categories[0] : '',
        unit_type: unitTypes.length > 0 ? unitTypes[0] : '',
        min_stock_level: 0,
        unit_cost: '',
    });

    const handleImageChange = (e) => {
        const file = e.target.files[0] ?? null;
        setData('image', file);
        setImagePreview(file ? URL.createObjectURL(file) : null);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('stock.products.store'), {
            onSuccess: () => {
                reset();
                setImagePreview(null);
                setIsCreating(false);
            },
        });
    };

    const filteredProducts = products.filter(product => {
        const matchesSearch = product.name.toLowerCase().includes(searchTerm.toLowerCase());
        const matchesCategory = !selectedCategory || product.category === selectedCategory;
        return matchesSearch && matchesCategory;
    });

    const getCategoryColor = (category) => {
        const colors = {
            'seeds': 'bg-green-100 text-green-800',
            'fertilizers': 'bg-blue-100 text-blue-800',
            'pesticides': 'bg-red-100 text-red-800',
            'tools': 'bg-gray-100 text-gray-800',
            'packaging': 'bg-yellow-100 text-yellow-800',
            'equipment': 'bg-purple-100 text-purple-800',
            'fuel': 'bg-orange-100 text-orange-800',
            'other': 'bg-indigo-100 text-indigo-800',
        };
        return colors[category] || 'bg-gray-100 text-gray-800';
    };

    const getStockStatus = (product) => {
        const currentStock = product.stock_inventory?.quantity_on_hand || 0;
        const minStock = product.min_stock_level || 0;

        if (currentStock === 0) return { status: 'Épuisé', color: 'bg-red-500', textColor: 'text-red-600' };
        if (currentStock <= minStock) return { status: 'Faible', color: 'bg-orange-500', textColor: 'text-orange-600' };
        if (currentStock <= minStock * 1.5) return { status: 'Normal', color: 'bg-yellow-500', textColor: 'text-yellow-600' };
        return { status: 'Bon', color: 'bg-green-500', textColor: 'text-green-600' };
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div>
                        <h2 className="font-bold text-2xl text-gray-800 leading-tight">Gestion des Produits</h2>
                        <p className="text-sm text-gray-500 mt-1">Gérez votre catalogue de produits et niveaux de stock</p>
                    </div>
                    {auth.user.role !== 'data_entry' && (
                        <button
                            onClick={() => setIsCreating(true)}
                            className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-semibold shadow-lg hover:shadow-xl transition-all flex items-center gap-2"
                        >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Nouveau Produit
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Produits" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Filters and Search */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        placeholder="Rechercher par nom..."
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
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
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="">Toutes les catégories</option>
                                    {categories.map((cat) => (
                                        <option key={cat} value={cat}>{cat}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Products Table */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <div className="flex justify-between items-center">
                                <h3 className="text-lg font-bold text-gray-800">Liste des Produits</h3>
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
                                        onClick={() => setIsCreating(true)}
                                        className="mt-4 text-blue-600 hover:text-blue-700 font-medium"
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
                                            <th scope="col" className="relative px-6 py-3">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {filteredProducts.map((product) => {
                                            const stockStatus = getStockStatus(product);
                                            return (
                                                <tr key={product.id} className="hover:bg-gray-50 transition-colors">
                                                    <td className="px-6 py-4">
                                                        <div className="flex items-center">
                                                            {product.image_url ? (
                                                                <img
                                                                    src={product.image_url}
                                                                    alt={product.name}
                                                                    className="flex-shrink-0 h-10 w-10 rounded-lg object-cover"
                                                                />
                                                            ) : (
                                                                <div className="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                                                    <svg className="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                                                        <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${getCategoryColor(product.category)}`}>
                                                            {product.category}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="text-sm text-gray-900 font-medium">
                                                            {product.stock_inventory?.quantity_on_hand || 0} {product.unit_type}
                                                        </div>
                                                        <div className="text-xs text-gray-500">Min: {product.min_stock_level || 0}</div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap">
                                                        <div className="flex items-center">
                                                            <span className={`h-2 w-2 rounded-full ${stockStatus.color} mr-2`}></span>
                                                            <span className={`text-sm font-medium ${stockStatus.textColor}`}>{stockStatus.status}</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {product.unit_cost ? `${product.unit_cost} MAD` : 'N/A'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <div className="flex items-center justify-end space-x-2">
                                                            <Link
                                                                href={route('stock.products.show', product.id)}
                                                                className="text-gray-400 hover:text-blue-600 transition-colors"
                                                                title="Voir"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                                </svg>
                                                            </Link>
                                                            <Link
                                                                href={route('stock.products.edit', product.id)}
                                                                className="text-gray-400 hover:text-green-600 transition-colors"
                                                                title="Modifier"
                                                            >
                                                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                                </svg>
                                                            </Link>
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

            {/* CREATE PRODUCT MODAL */}
            <Modal show={isCreating} onClose={() => setIsCreating(false)}>
                <div className="p-8">
                    <div className="flex justify-between items-center mb-6">
                        <div className="flex items-center gap-3">
                            <div className="h-10 w-10 bg-blue-100 rounded-xl flex items-center justify-center">
                                <svg className="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                            </div>
                            <h3 className="text-xl font-bold text-gray-800">Ajouter un Nouveau Produit</h3>
                        </div>
                        <button
                            onClick={() => setIsCreating(false)}
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
                                        {imagePreview && (
                                            <img src={imagePreview} alt="Aperçu" className="h-16 w-16 rounded-lg object-cover border border-gray-200" />
                                        )}
                                        <input
                                            id="image"
                                            type="file"
                                            accept="image/*"
                                            onChange={handleImageChange}
                                            className="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                        />
                                    </div>
                                    <InputError message={errors.image} className="mt-2" />
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

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsCreating(false)}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={processing} className="bg-blue-600 hover:bg-blue-700">
                                {processing ? 'Création en cours...' : 'Ajouter le Produit'}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
