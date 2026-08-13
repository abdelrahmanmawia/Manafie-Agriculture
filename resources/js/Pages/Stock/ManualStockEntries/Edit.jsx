import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { UNIT_TYPE_LABELS } from '@/utils/stockLabels';

export default function Edit({ auth, manualStockEntry, products, employees, vehicles, blocs, sectors, parcelles, operations, vehicleMaintenanceLogs }) {
    const { data, setData, put, processing, errors } = useForm({
        product_id: manualStockEntry.product_id,
        entry_type: manualStockEntry.entry_type,
        quantity: manualStockEntry.quantity,
        employee_id: manualStockEntry.employee_id || '',
        vehicle_id: manualStockEntry.vehicle_id || '',
        maintenance_log_id: manualStockEntry.maintenance_log_id || '',
        pointage_record_id: manualStockEntry.pointage_record_id || '',
        operation_id: manualStockEntry.operation_id || '',
        bloc_id: manualStockEntry.bloc_id || '',
        sector_id: manualStockEntry.sector_id || '',
        parcelle_id: manualStockEntry.parcelle_id || '',
        date: manualStockEntry.date,
        notes: manualStockEntry.notes || '',
        odometer_km: manualStockEntry.odometer_km || '',
    });

    const selectedProduct = products.find((p) => String(p.id) === String(data.product_id));
    const isVehicleConsumable = ['fuel', 'vehicle_needs'].includes(selectedProduct?.category);
    const selectedVehicle = vehicles.find((v) => String(v.id) === String(data.vehicle_id));
    const hidesFieldContext = selectedVehicle && ['car', 'van'].includes(selectedVehicle.type);

    const filteredSectors = data.bloc_id
        ? sectors.filter((sector) => String(sector.bloc_id) === String(data.bloc_id))
        : [];
    const filteredParcelles = data.sector_id
        ? parcelles.filter((parcelle) => String(parcelle.sector_id) === String(data.sector_id))
        : [];

    const formatLogOption = (log) => {
        const date = new Date(log.performed_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
        return `${date} — ${log.description.slice(0, 60)}`;
    };
    const vehicleLogsFor = (vehicleId) => vehicleMaintenanceLogs.filter((log) => String(log.vehicle_id) === String(vehicleId));

    const submit = (e) => {
        e.preventDefault();
        put(route('stock.manual-entries.update', manualStockEntry.id));
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-black text-xl text-gray-800 uppercase tracking-tighter leading-tight">Modifier la Sortie de Stock</h2>
                    <Link
                        href={route('stock.manual-entries.index')}
                        className="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-xl font-black uppercase tracking-widest shadow-md transition-all flex items-center gap-2"
                    >
                        Retour aux Sorties de Stock
                    </Link>
                </div>
            }
        >
            <Head title={`Modifier Sortie: ${manualStockEntry.id}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-2xl border border-gray-100 p-6">
                        <h3 className="text-xl font-black mb-6 text-gray-800 uppercase tracking-tighter border-b pb-4">Modifier la Sortie #{manualStockEntry.id}</h3>
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
                                            <option key={product.id} value={product.id}>{product.name} ({UNIT_TYPE_LABELS[product.unit_type] || product.unit_type})</option>
                                        ))}
                                    </select>
                                    <InputError message={errors.product_id} className="mt-2" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="entry_type" value="Type de Sortie *" />
                                    <select
                                        id="entry_type"
                                        className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                        value={data.entry_type}
                                        onChange={(e) => setData((prev) => ({
                                            ...prev,
                                            entry_type: e.target.value,
                                            maintenance_log_id: e.target.value === 'maintenance' ? prev.maintenance_log_id : '',
                                        }))}
                                        required
                                    >
                                        <option value="consumption">Consommation</option>
                                        <option value="transfer">Transfert</option>
                                        <option value="loss">Perte</option>
                                        <option value="theft">Vol</option>
                                        <option value="damage">Dommage</option>
                                        <option value="maintenance">Maintenance</option>
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

                                {isVehicleConsumable && (
                                    <div>
                                        <InputLabel htmlFor="vehicle_id" value="Véhicule" />
                                        <select
                                            id="vehicle_id"
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                            value={data.vehicle_id}
                                            onChange={(e) => setData((prev) => ({ ...prev, vehicle_id: e.target.value, maintenance_log_id: '' }))}
                                        >
                                            <option value="">-- Sélectionner un véhicule --</option>
                                            {vehicles.map((vehicle) => (
                                                <option key={vehicle.id} value={vehicle.id}>{vehicle.name}{(vehicle.plate_number || vehicle.serial_number) ? ` (${vehicle.plate_number || vehicle.serial_number})` : ''}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.vehicle_id} className="mt-2" />
                                    </div>
                                )}

                                {isVehicleConsumable && data.vehicle_id && (
                                    <div>
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
                                    </div>
                                )}

                                {isVehicleConsumable && data.vehicle_id && data.entry_type === 'maintenance' && (
                                    <div>
                                        <InputLabel htmlFor="maintenance_log_id" value="Intervention de Maintenance Liée" />
                                        <select
                                            id="maintenance_log_id"
                                            className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                                            value={data.maintenance_log_id}
                                            onChange={(e) => setData('maintenance_log_id', e.target.value)}
                                        >
                                            <option value="">-- Aucune (pièce hors intervention) --</option>
                                            {vehicleLogsFor(data.vehicle_id).map((log) => (
                                                <option key={log.id} value={log.id}>{formatLogOption(log)}</option>
                                            ))}
                                        </select>
                                        <InputError message={errors.maintenance_log_id} className="mt-2" />
                                    </div>
                                )}

                                {!hidesFieldContext && (
                                    <>
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
                                                    onChange={(e) => setData((prev) => ({ ...prev, bloc_id: e.target.value, sector_id: '', parcelle_id: '' }))}
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
                                                    className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm disabled:bg-gray-100 disabled:text-gray-400"
                                                    value={data.sector_id}
                                                    onChange={(e) => setData((prev) => ({ ...prev, sector_id: e.target.value, parcelle_id: '' }))}
                                                    disabled={!data.bloc_id}
                                                >
                                                    <option value="">{data.bloc_id ? '-- Sélectionner un secteur --' : '-- Choisir un bloc d\'abord --'}</option>
                                                    {filteredSectors.map((sector) => (
                                                        <option key={sector.id} value={sector.id}>{sector.name}</option>
                                                    ))}
                                                </select>
                                                <InputError message={errors.sector_id} className="mt-2" />
                                            </div>
                                            <div>
                                                <InputLabel htmlFor="parcelle_id" value="Parcelle" />
                                                <select
                                                    id="parcelle_id"
                                                    className="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm disabled:bg-gray-100 disabled:text-gray-400"
                                                    value={data.parcelle_id}
                                                    onChange={(e) => setData('parcelle_id', e.target.value)}
                                                    disabled={!data.sector_id}
                                                >
                                                    <option value="">{data.sector_id ? '-- Sélectionner une parcelle --' : '-- Choisir un secteur d\'abord --'}</option>
                                                    {filteredParcelles.map((parcelle) => (
                                                        <option key={parcelle.id} value={parcelle.id}>{parcelle.name}</option>
                                                    ))}
                                                </select>
                                                <InputError message={errors.parcelle_id} className="mt-2" />
                                            </div>
                                        </div>
                                    </>
                                )}

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
                                <PrimaryButton disabled={processing}>Mettre à Jour la Sortie</PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
