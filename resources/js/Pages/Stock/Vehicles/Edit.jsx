import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

export default function Edit({ auth, vehicle, types, fuelTypes, employees }) {
    const { data, setData, put, processing, errors } = useForm({
        name: vehicle.name,
        plate_number: vehicle.plate_number,
        type: vehicle.type,
        brand: vehicle.brand || '',
        model: vehicle.model || '',
        year: vehicle.year || '',
        fuel_type: vehicle.fuel_type,
        fuel_capacity_liters: vehicle.fuel_capacity_liters || '',
        default_driver_id: vehicle.default_driver_id || '',
        current_location: vehicle.current_location || '',
        is_active: vehicle.is_active,
        notes: vehicle.notes || '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('stock.vehicles.update', vehicle.id));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Modifier le Véhicule</h2>
                    <Link
                        href={route('stock.vehicles.index')}
                        className="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                    >
                        Retour aux Véhicules
                    </Link>
                </div>
            }
        >
            <Head title={`Modifier: ${vehicle.name}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">Modifier les Détails du Véhicule: {vehicle.name}</h3>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="space-y-4">
                                <div>
                                    <InputLabel htmlFor="name" value="Nom du Véhicule *" />
                                    <TextInput
                                        id="name"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        required
                                        autoFocus
                                    />
                                    <InputError message={errors.name} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="plate_number" value="Plaque d'Immatriculation *" />
                                    <TextInput
                                        id="plate_number"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={data.plate_number}
                                        onChange={(e) => setData('plate_number', e.target.value)}
                                        required
                                    />
                                    <InputError message={errors.plate_number} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="type" value="Type de Véhicule *" />
                                    <select
                                        id="type"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.type}
                                        onChange={(e) => setData('type', e.target.value)}
                                        required
                                    >
                                        {types.map((type) => (
                                            <option key={type} value={type}>{type}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.type} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="brand" value="Marque" />
                                    <TextInput
                                        id="brand"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={data.brand}
                                        onChange={(e) => setData('brand', e.target.value)}
                                    />
                                    <InputError message={errors.brand} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="model" value="Modèle" />
                                    <TextInput
                                        id="model"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={data.model}
                                        onChange={(e) => setData('model', e.target.value)}
                                    />
                                    <InputError message={errors.model} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="year" value="Année" />
                                    <TextInput
                                        id="year"
                                        type="number"
                                        className="mt-1 block w-full"
                                        value={data.year}
                                        onChange={(e) => setData('year', e.target.value)}
                                    />
                                    <InputError message={errors.year} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="fuel_type" value="Type de Carburant *" />
                                    <select
                                        id="fuel_type"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.fuel_type}
                                        onChange={(e) => setData('fuel_type', e.target.value)}
                                        required
                                    >
                                        {fuelTypes.map((fuel) => (
                                            <option key={fuel} value={fuel}>{fuel}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.fuel_type} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="fuel_capacity_liters" value="Capacité du Réservoir (Litres)" />
                                    <TextInput
                                        id="fuel_capacity_liters"
                                        type="number"
                                        step="0.01"
                                        className="mt-1 block w-full"
                                        value={data.fuel_capacity_liters}
                                        onChange={(e) => setData('fuel_capacity_liters', e.target.value)}
                                    />
                                    <InputError message={errors.fuel_capacity_liters} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="default_driver_id" value="Conducteur par Défaut" />
                                    <select
                                        id="default_driver_id"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.default_driver_id}
                                        onChange={(e) => setData('default_driver_id', e.target.value)}
                                    >
                                        <option value="">-- Sélectionner un conducteur --</option>
                                        {employees.map((employee) => (
                                            <option key={employee.id} value={employee.id}>{employee.full_name}</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.default_driver_id} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="current_location" value="Localisation Actuelle" />
                                    <TextInput
                                        id="current_location"
                                        type="text"
                                        className="mt-1 block w-full"
                                        value={data.current_location}
                                        onChange={(e) => setData('current_location', e.target.value)}
                                    />
                                    <InputError message={errors.current_location} className="mt-2" />
                                </div>

                                <div className="flex items-center">
                                    <input
                                        id="is_active"
                                        type="checkbox"
                                        className="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                        checked={data.is_active}
                                        onChange={(e) => setData('is_active', e.target.checked)}
                                    />
                                    <InputLabel htmlFor="is_active" value="Actif" className="ml-2" />
                                    <InputError message={errors.is_active} className="mt-2" />
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
                                <PrimaryButton disabled={processing}>Mettre à Jour le Véhicule</PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
