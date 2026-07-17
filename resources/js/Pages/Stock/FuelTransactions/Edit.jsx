import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

export default function Edit({ auth, fuelTransaction, vehicles, products, employees }) {
    const { data, setData, put, processing, errors } = useForm({
        vehicle_id: fuelTransaction.vehicle_id || '',
        product_id: fuelTransaction.product_id,
        transaction_type: fuelTransaction.transaction_type,
        quantity_liters: fuelTransaction.quantity_liters,
        unit_price_per_liter: fuelTransaction.unit_price_per_liter || '',
        driver_id: fuelTransaction.driver_id || '',
        date: fuelTransaction.date,
        odometer_km: fuelTransaction.odometer_km || '',
        hours_worked: fuelTransaction.hours_worked || '',
        notes: fuelTransaction.notes || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('stock.fuel-transactions.update', fuelTransaction.id));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Modifier la Transaction de Carburant</h2>
                    <Link
                        href={route('stock.fuel-transactions.index')}
                        className="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                    >
                        Retour aux Transactions
                    </Link>
                </div>
            }
        >
            <Head title={`Modifier Transaction: ${fuelTransaction.id}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">Modifier la Transaction #{fuelTransaction.id}</h3>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="space-y-4">
                                <div>
                                    <InputLabel htmlFor="vehicle_id" value="Véhicule *" />
                                    <select
                                        id="vehicle_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
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
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
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
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
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

                                <div className="grid grid-cols-2 gap-4">
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
                                        />
                                        <InputError message={errors.quantity_liters} className="mt-2" />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="unit_price_per_liter" value="Prix Unitaire par Litre" />
                                        <TextInput
                                            id="unit_price_per_liter"
                                            type="number"
                                            step="0.01"
                                            className="mt-1 block w-full"
                                            value={data.unit_price_per_liter}
                                            onChange={(e) => setData('unit_price_per_liter', e.target.value)}
                                        />
                                        <InputError message={errors.unit_price_per_liter} className="mt-2" />
                                    </div>
                                </div>

                                <div>
                                    <InputLabel htmlFor="driver_id" value="Conducteur" />
                                    <select
                                        id="driver_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
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

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <InputLabel htmlFor="odometer_km" value="Kilométrage (km)" />
                                        <TextInput
                                            id="odometer_km"
                                            type="number"
                                            step="0.01"
                                            className="mt-1 block w-full"
                                            value={data.odometer_km}
                                            onChange={(e) => setData('odometer_km', e.target.value)}
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
                                        />
                                        <InputError message={errors.hours_worked} className="mt-2" />
                                    </div>
                                </div>

                                <div>
                                    <InputLabel htmlFor="notes" value="Notes" />
                                    <textarea
                                        id="notes"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        rows="3"
                                    ></textarea>
                                    <InputError message={errors.notes} className="mt-2" />
                                </div>
                            </div>

                            <div className="flex justify-end gap-4 pt-6 border-t mt-6">
                                <SecondaryButton onClick={() => window.history.back()}>Annuler</SecondaryButton>
                                <PrimaryButton disabled={processing}>Mettre à Jour la Transaction</PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
