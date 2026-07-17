import { router } from '@inertiajs/react';

export default function FarmFilter({ farms, selectedFarmId, routeName, extraParams = {} }) {
    if (!farms || farms.length === 0) {
        return null;
    }

    const handleChange = (e) => {
        const farmId = e.target.value;
        router.get(route(routeName), { ...extraParams, farm_id: farmId || undefined }, { preserveState: true, preserveScroll: true });
    };

    return (
        <div className="flex items-center justify-end gap-4 mb-4">
            <label htmlFor="farm_filter_select" className="block text-sm font-medium text-gray-700 whitespace-nowrap">
                Filtrer par Ferme:
            </label>
            <select
                id="farm_filter_select"
                className="block w-auto min-w-[200px] pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md shadow-sm"
                value={selectedFarmId || ''}
                onChange={handleChange}
            >
                <option value="">Toutes les Fermes</option>
                {farms.map((farm) => (
                    <option key={farm.id} value={farm.id}>
                        {farm.name}
                    </option>
                ))}
            </select>
        </div>
    );
}
