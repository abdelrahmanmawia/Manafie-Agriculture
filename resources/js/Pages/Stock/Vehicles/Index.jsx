import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';

export default function Index({ auth, vehicles, types, fuelTypes, employees }) {
    const [isCreating, setIsCreating] = useState(false);

    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        plate_number: '',
        type: types.length > 0 ? types[0] : '',
        brand: '',
        model: '',
        year: '',
        fuel_type: fuelTypes.length > 0 ? fuelTypes[0] : '',
        fuel_capacity_liters: '',
        default_driver_id: '',
        current_location: '',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('stock.vehicles.store'), {
            onSuccess: () => {
                reset();
                setIsCreating(false);
            },
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Gestion des Véhicules</h2>
                    {auth.user.role !== 'data_entry' && (
                        <button
                            onClick={() => setIsCreating(true)}
                            className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                        >
                            <span>+</span> Ajouter un Véhicule
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Véhicules" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <h3 className="text-lg font-bold mb-6 border-b pb-2">Liste des Véhicules</h3>

                            {vehicles.length === 0 ? (
                                <div className="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-200">
                                    <p className="text-gray-500 italic">Aucun véhicule disponible. Cliquez sur "+ Ajouter un Véhicule" pour commencer.</p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Nom
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Plaque d'Immatriculation
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Type
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Carburant
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Conducteur par Défaut
                                                </th>
                                                <th scope="col" className="relative px-6 py-3">
                                                    <span className="sr-only">Actions</span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {vehicles.map((vehicle) => (
                                                <tr key={vehicle.id}>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                        {vehicle.name}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {vehicle.plate_number}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {vehicle.type}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {vehicle.fuel_type}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        {vehicle.default_driver?.full_name || 'N/A'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <Link href={route('stock.vehicles.show', vehicle.id)} className="text-indigo-600 hover:text-indigo-900 mr-4">
                                                            Voir
                                                        </Link>
                                                        <Link href={route('stock.vehicles.edit', vehicle.id)} className="text-blue-600 hover:text-blue-900">
                                                            Modifier
                                                        </Link>
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
            </div>

            {/* CREATE VEHICLE MODAL */}
            <Modal show={isCreating} onClose={() => setIsCreating(false)}>
                <div className="p-8">
                    <h3 className="text-xl font-bold mb-6 text-gray-800 border-b pb-4">Ajouter un Nouveau Véhicule</h3>
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
                            <SecondaryButton onClick={() => setIsCreating(false)}>Annuler</SecondaryButton>
                            <PrimaryButton disabled={processing}>Ajouter le Véhicule</PrimaryButton>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
