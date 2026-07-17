import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

export default function Edit({ auth, manualStockEntry, products, employees, vehicles, blocs, sectors, parcelles, operations }) {
    const { data, setData, put, processing, errors } = useForm({
        product_id: manualStockEntry.product_id,
        entry_type: manualStockEntry.entry_type,
        quantity: manualStockEntry.quantity,
        employee_id: manualStockEntry.employee_id || '',
        vehicle_id: manualStockEntry.vehicle_id || '',
        pointage_record_id: manualStockEntry.pointage_record_id || '',
        operation_id: manualStockEntry.operation_id || '',
        bloc_id: manualStockEntry.bloc_id || '',
        sector_id: manualStockEntry.sector_id || '',
        parcelle_id: manualStockEntry.parcelle_id || '',
        date: manualStockEntry.date,
        notes: manualStockEntry.notes || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('stock.manual-entries.update', manualStockEntry.id));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Modifier l'Entrée Manuelle de Stock</h2>
                    <Link
                        href={route('stock.manual-entries.index')}
                        className="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                    >
                        Retour aux Entrées Manuelles
                    </Link>
                </div>
            }
        >
            <Head title={`Modifier Entrée: ${manualStockEntry.id}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">Modifier l'Entrée Manuelle #{manualStockEntry.id}</h3>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="space-y-4">
                                <div>
                                    <InputLabel htmlFor="product_id" value="Produit *" />
                                    <select
                                        id="product_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.product_id}
                                        onChange={(e) => setData('product_id', e.target.value)}
                                        required
                                    >
                                        <option value="">-- Sélectionner un produit --</option>
                                        {products.map((product) => (
                                            <option key={product.id} value={product.id}>{product.name} ({product.unit_type})</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.product_id} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="entry_type" value="Type d'Entrée *" />
                                    <select
                                        id="entry_type"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.entry_type}
                                        onChange={(e) => setData('entry_type', e.target.value)}
                                        required
                                    >
                                        <option value="consumption">Consommation</option>
                                        <option value="transfer">Transfert</option>
                                        <option value="loss">Perte</option>
                                        <option value="theft">Vol</option>
                                        <option value="damage">Dommage</option>
                                    </select>
                                    <InputError message={errors.entry_type} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="quantity" value="Quantité *" />
                                    <TextInput
                                        id="quantity"
                                        type="number"
                                        step="0.01"
                                        className="mt-1 block w-full"
                                        value={data.quantity}
                                        onChange={(e) => setData('quantity', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.quantity} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="employee_id" value="Employé (Qui a utilisé/consommé)" />
                                    <select
                                        id="employee_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.employee_id}
                                        onChange={(e) => setData('employee_id', e.target.value)}
                                    >
                                        <option value="">-- Sélectionner un employé --</option>
                                        {employees.map((employee) => (
                                            <option key={employee.id} value={employee.id}>{employee.full_name}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.employee_id} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="vehicle_id" value="Véhicule (Si utilisé pour un véhicule)" />
                                    <select
                                        id="vehicle_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.vehicle_id}
                                        onChange={(e) => setData('vehicle_id', e.target.value)}
                                    >
                                        <option value="">-- Sélectionner un véhicule --</option>
                                        {vehicles.map((vehicle) => (
                                            <option key={vehicle.id} value={vehicle.id}>{vehicle.name} ({vehicle.plate_number})</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.vehicle_id} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="operation_id" value="Opération" />
                                    <select
                                        id="operation_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.operation_id}
                                        onChange={(e) => setData('operation_id', e.target.value)}
                                    >
                                        <option value="">-- Sélectionner une opération --</option>
                                        {operations.map((operation) => (
                                            <option key={operation.id} value={operation.id}>{operation.name}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.operation_id} className="mt-2" />
                                </div>

                                <div className="grid grid-cols-3 gap-4">
                                    <div>
                                        <InputLabel htmlFor="bloc_id" value="Bloc" />
                                        <select
                                            id="bloc_id"
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                            value={data.bloc_id}
                                            onChange={(e) => setData('bloc_id', e.target.value)}
                                        >
                                            <option value="">-- Sélectionner un bloc --</option>
                                            {blocs.map((bloc) => (
                                                <option key={bloc.id} value={bloc.id}>{bloc.name}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.bloc_id} className="mt-2" />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="sector_id" value="Secteur" />
                                        <select
                                            id="sector_id"
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                            value={data.sector_id}
                                            onChange={(e) => setData('sector_id', e.target.value)}
                                        >
                                            <option value="">-- Sélectionner un secteur --</option>
                                            {sectors.map((sector) => (
                                                <option key={sector.id} value={sector.id}>{sector.name}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.sector_id} className="mt-2" />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="parcelle_id" value="Parcelle" />
                                        <select
                                            id="parcelle_id"
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                            value={data.parcelle_id}
                                            onChange={(e) => setData('parcelle_id', e.target.value)}
                                        >
                                            <option value="">-- Sélectionner une parcelle --</option>
                                            {parcelles.map((parcelle) => (
                                                <option key={parcelle.id} value={parcelle.id}>{parcelle.name}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.parcelle_id} className="mt-2" />
                                    </div>
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
                                <PrimaryButton disabled={processing}>Mettre à Jour l'Entrée</PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
