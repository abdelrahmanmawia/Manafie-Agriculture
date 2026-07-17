import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Show({ auth, stockInventory }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Détails de l'Inventaire</h2>
                    <Link
                        href={route('stock.inventory.index')}
                        className="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-all flex items-center gap-2"
                    >
                        Retour à l'Inventaire
                    </Link>
                </div>
            }
        >
            <Head title={`Inventaire: ${stockInventory.product.name}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 className="text-xl font-bold mb-4 border-b pb-2">Inventaire pour: {stockInventory.product.name}</h3>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <p className="text-gray-600"><strong>Produit:</strong> {stockInventory.product.name}</p>
                                <p className="text-gray-600"><strong>Catégorie:</strong> {stockInventory.product.category}</p>
                                <p className="text-gray-600"><strong>Unité:</strong> {stockInventory.product.unit_type}</p>
                                <p className="text-gray-600"><strong>Coût Unitaire Moyen:</strong> {stockInventory.average_cost ? `${stockInventory.average_cost} MAD` : 'N/A'}</p>
                            </div>
                            <div>
                                <p className="text-gray-600"><strong>Quantité en Stock:</strong> {stockInventory.quantity_on_hand} {stockInventory.product.unit_type}</p>
                                <p className="text-gray-600"><strong>Quantité Réservée:</strong> {stockInventory.quantity_reserved} {stockInventory.product.unit_type}</p>
                                <p className="text-gray-600"><strong>Quantité Disponible:</strong> {stockInventory.quantity_available} {stockInventory.product.unit_type}</p>
                                <p className="text-gray-600"><strong>Niveau de Stock Minimum:</strong> {stockInventory.product.min_stock_level} {stockInventory.product.unit_type}</p>
                                <p className="text-gray-600"><strong>Dernier Réapprovisionnement:</strong> {stockInventory.last_restock_date || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Dernier Inventaire:</strong> {stockInventory.last_count_date || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Numéro de Lot:</strong> {stockInventory.batch_number || 'N/A'}</p>
                                <p className="text-gray-600"><strong>Date d'Expiration:</strong> {stockInventory.expiry_date || 'N/A'}</p>
                            </div>
                        </div>

                        <div className="mt-6">
                            <h4 className="text-lg font-bold mb-2">Historique des Mouvements de Stock</h4>
                            {stockInventory.product.stock_movements && stockInventory.product.stock_movements.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Date
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Type
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Quantité
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Coût Total
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Référence
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Effectué par
                                                </th>
                                                <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Notes
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {stockInventory.product.stock_movements.map((movement) => (
                                                <tr key={movement.id}>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{new Date(movement.date).toLocaleDateString()}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.movement_type}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.quantity} {stockInventory.product.unit_type}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.total_cost} MAD</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.reference_type} {movement.reference_id}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.performed_by?.name || 'N/A'}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{movement.notes || 'N/A'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <p className="text-gray-500 italic">Aucun mouvement de stock enregistré pour ce produit.</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
