import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

export default function Index({ auth, fuelTransactions, vehicles, products, employees }) {
    const [isCreating, setIsCreating] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedVehicle, setSelectedVehicle] = useState('');

    const { data, setData, post, processing, reset, errors } = useForm({
        vehicle_id: vehicles.length > 0 ? vehicles[0].id : '',
        product_id: products.length > 0 ? products[0].id : '',
        transaction_type: 'fueling',
        quantity_liters: '',
        unit_price_per_liter: '',
        driver_id: '',
        date: new Date().toISOString().slice(0, 10),
        odometer_km: '',
        hours_worked: '',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('stock.fuel-transactions.store'), {
            onSuccess: () => {
                reset();
                setIsCreating(false);
            },
        });
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    };

    const filteredTransactions = fuelTransactions.filter(transaction => {
        const matchesSearch = transaction.vehicle?.name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
                             transaction.product?.name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
                             transaction.driver?.full_name?.toLowerCase().includes(searchTerm.toLowerCase());
        const matchesVehicle = !selectedVehicle || transaction.vehicle_id === parseInt(selectedVehicle);
        return matchesSearch && matchesVehicle;
    });

    const getTransactionTypeColor = (type) => {
        const colors = {
            'fueling': 'bg-green-100 text-green-800',
            'transfer': 'bg-blue-100 text-blue-800',
            'adjustment': 'bg-yellow-100 text-yellow-800',
        };
        return colors[type] || 'bg-gray-100 text-gray-800';
    };

    const getTransactionTypeLabel = (type) => {
        const labels = {
            'fueling': 'Ravitaillement',
            'transfer': 'Transfert',
            'adjustment': 'Ajustement',
        };
        return labels[type] || type;
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div>
                        <h2 className="font-bold text-2xl text-gray-800 leading-tight">Transactions de Carburant</h2>
                        <p className="text-sm text-gray-500 mt-1">Suivez les ravitaillements et consommation de carburant</p>
                    </div>
                    {auth.user.role !== 'data_entry' && (
                        <button
                            onClick={() => setIsCreating(true)}
                            className="bg-orange-600 hover:bg-orange-700 text-white px-6 py-3 rounded-xl font-semibold shadow-lg hover:shadow-xl transition-all flex items-center gap-2"
                        >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Nouvelle Transaction
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Transactions de Carburant" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    {/* Filters */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 mb-2">Rechercher</label>
                                <div className="relative">
                                    <input
                                        type="text"
                                        placeholder="Rechercher par véhicule, produit ou conducteur..."
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                                    />
                                    <svg className="absolute left-3 top-2.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Véhicule</label>
                                <select
                                    value={selectedVehicle}
                                    onChange={(e) => setSelectedVehicle(e.target.value)}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                                >
                                    <option value="">Tous les véhicules</option>
                                    {vehicles.map((vehicle) => (
                                        <option key={vehicle.id} value={vehicle.id}>{vehicle.name} ({vehicle.plate_number})</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>

                    {/* Transactions Table */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="p-6 border-b border-gray-100">
                            <div className="flex justify-between items-center">
                                <h3 className="text-lg font-bold text-gray-800">Historique des Transactions</h3>
                                <span className="text-sm text-gray-500">{filteredTransactions.length} transaction(s)</span>
                            </div>
                        </div>

                        {filteredTransactions.length === 0 ? (
                            <div className="text-center py-16">
                                <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <p className="mt-4 text-gray-500">Aucune transaction trouvée</p>
                                {auth.user.role !== 'data_entry' && (
                                    <button
                                        onClick={() => setIsCreating(true)}
                                        className="mt-4 text-orange-600 hover:text-orange-700 font-medium"
                                    >
                                        Enregistrer votre première transaction
                                    </button>
                                )}
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Date
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Véhicule
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Type
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Quantité
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Coût
                                            </th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                                Conducteur
                                            </th>
                                            <th scope="col" className="relative px-6 py-3">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {filteredTransactions.map((transaction) => (
                                            <tr key={transaction.id} className="hover:bg-gray-50 transition-colors">
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {formatDate(transaction.date)}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="flex items-center">
                                                        <div className="flex-shrink-0 h-8 w-8 bg-orange-100 rounded-full flex items-center justify-center">
                                                            <svg className="h-4 w-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                                            </svg>
                                                        </div>
                                                        <div className="ml-3">
                                                            <div className="text-sm font-medium text-gray-900">{transaction.vehicle?.name || 'N/A'}</div>
                                                            <div className="text-xs text-gray-500">{transaction.vehicle?.plate_number || ''}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${getTransactionTypeColor(transaction.transaction_type)}`}>
                                                        {getTransactionTypeLabel(transaction.transaction_type)}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="text-sm font-medium text-gray-900">{transaction.quantity_liters} L</div>
                                                    <div className="text-xs text-gray-500">{transaction.product?.name || ''}</div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    {transaction.total_cost ? `${transaction.total_cost} MAD` : 'N/A'}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {transaction.driver?.full_name || 'N/A'}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <div className="flex items-center justify-end space-x-2">
                                                        <Link
                                                            href={route('stock.fuel-transactions.show', transaction.id)}
                                                            className="text-gray-400 hover:text-orange-600 transition-colors"
                                                            title="Voir"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                            </svg>
                                                        </Link>
                                                        <Link
                                                            href={route('stock.fuel-transactions.edit', transaction.id)}
                                                            className="text-gray-400 hover:text-blue-600 transition-colors"
                                                            title="Modifier"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                        </Link>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* CREATE FUEL TRANSACTION MODAL */}
            <Modal show={isCreating} onClose={() => setIsCreating(false)}>
                <div className="p-8">
                    <div className="flex justify-between items-center mb-6">
                        <h3 className="text-xl font-bold text-gray-800">Enregistrer une Transaction de Carburant</h3>
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
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">

                            <div>
                                <InputLabel htmlFor="vehicle_id" value="Véhicule *" />
                                <select
                                    id="vehicle_id"
                                    className="mt-1 block w-full border-gray-300 focus:border-orange-500 focus:ring-orange-500 rounded-lg shadow-sm"
                                    value={data.vehicle_id}
                                    onChange={(e) => setData('vehicle_id', e.target.value)}
                                    required
                                >
                                    <option value="">-- Sélectionner un véhicule --</option>
                                    {vehicles.map((vehicle) => (
                                        <option key={vehicle.id} value={vehicle.id}>{vehicle.name} ({vehicle.plate_number})</option>
                                    ))}
                                </select>
                                <InputError message={errors.vehicle_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="product_id" value="Produit Carburant *" />
                                <select
                                    id="product_id"
                                    className="mt-1 block w-full border-gray-300 focus:border-orange-500 focus:ring-orange-500 rounded-lg shadow-sm"
                                    value={data.product_id}
                                    onChange={(e) => setData('product_id', e.target.value)}
                                    required
                                >
                                    <option value="">-- Sélectionner un produit carburant --</option>
                                    {products.filter(p => p.category === 'fuel').map((product) => (
                                        <option key={product.id} value={product.id}>{product.name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.product_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="transaction_type" value="Type de Transaction *" />
                                <select
                                    id="transaction_type"
                                    className="mt-1 block w-full border-gray-300 focus:border-orange-500 focus:ring-orange-500 rounded-lg shadow-sm"
                                    value={data.transaction_type}
                                    onChange={(e) => setData('transaction_type', e.target.value)}
                                    required
                                >
                                    <option value="fueling">Ravitaillement</option>
                                    <option value="transfer">Transfert</option>
                                    <option value="adjustment">Ajustement</option>
                                </select>
                                <InputError message={errors.transaction_type} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="driver_id" value="Conducteur" />
                                <select
                                    id="driver_id"
                                    className="mt-1 block w-full border-gray-300 focus:border-orange-500 focus:ring-orange-500 rounded-lg shadow-sm"
                                    value={data.driver_id}
                                    onChange={(e) => setData('driver_id', e.target.value)}
                                >
                                    <option value="">-- Sélectionner un conducteur --</option>
                                    {employees.map((employee) => (
                                        <option key={employee.id} value={employee.id}>{employee.full_name}</option>
                                    ))}
                                </select>
                                <InputError message={errors.driver_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="quantity_liters" value="Quantité (Litres) *" />
                                <TextInput
                                    id="quantity_liters"
                                    type="number"
                                    step="0.01"
                                    className="mt-1 block w-full"
                                    value={data.quantity_liters}
                                    onChange={(e) => setData('quantity_liters', e.target.value)}
                                    required
                                    placeholder="Ex: 50.5"
                                />
                                <InputError message={errors.quantity_liters} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="unit_price_per_liter" value="Prix Unitaire (MAD/L)" />
                                <TextInput
                                    id="unit_price_per_liter"
                                    type="number"
                                    step="0.01"
                                    className="mt-1 block w-full"
                                    value={data.unit_price_per_liter}
                                    onChange={(e) => setData('unit_price_per_liter', e.target.value)}
                                    placeholder="Ex: 12.50"
                                />
                                <InputError message={errors.unit_price_per_liter} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="date" value="Date *" />
                                <TextInput
                                    id="date"
                                    type="date"
                                    className="mt-1 block w-full"
                                    value={data.date}
                                    onChange={(e) => setData('date', e.target.value)}
                                    required
                                />
                                <InputError message={errors.date} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="odometer_km" value="Kilométrage (km)" />
                                <TextInput
                                    id="odometer_km"
                                    type="number"
                                    step="0.01"
                                    className="mt-1 block w-full"
                                    value={data.odometer_km}
                                    onChange={(e) => setData('odometer_km', e.target.value)}
                                    placeholder="Ex: 12500.5"
                                />
                                <InputError message={errors.odometer_km} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="hours_worked" value="Heures Travaillées" />
                                <TextInput
                                    id="hours_worked"
                                    type="number"
                                    step="0.01"
                                    className="mt-1 block w-full"
                                    value={data.hours_worked}
                                    onChange={(e) => setData('hours_worked', e.target.value)}
                                    placeholder="Ex: 8.5"
                                />
                                <InputError message={errors.hours_worked} className="mt-2" />
                            </div>

                            <div className="md:col-span-2">
                                <InputLabel htmlFor="notes" value="Notes" />
                                <textarea
                                    id="notes"
                                    className="mt-1 block w-full border-gray-300 focus:border-orange-500 focus:ring-orange-500 rounded-lg shadow-sm"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    rows="3"
                                    placeholder="Ajoutez des notes supplémentaires..."
                                ></textarea>
                                <InputError message={errors.notes} className="mt-2" />
                            </div>
                        </div>

                        <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                            <SecondaryButton onClick={() => setIsCreating(false)}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={processing} className="bg-orange-600 hover:bg-orange-700">
                                {processing ? 'Enregistrement...' : 'Enregistrer la Transaction'}
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
