// Shared French labels for the raw English enum values stored in the DB, used across every
// Stock page so the UI never leaks an English category/type/status to the user.

// Categories are now a real per-farm table (ProductCategory) with their own name typed in
// directly by the user — no fixed English-key-to-French-label map needed anymore.

export const UNIT_TYPE_LABELS = {
    kg: 'kg',
    liters: 'litres',
    units: 'unités',
    boxes: 'boîtes',
    bags: 'sacs',
};

export const ENTRY_TYPE_LABELS = {
    consumption: 'Consommation',
    transfer: 'Transfert',
    loss: 'Perte',
    theft: 'Vol',
    damage: 'Dommage',
    maintenance: 'Maintenance',
};

export const MOVEMENT_TYPE_LABELS = {
    in: { label: 'Entrée', className: 'bg-green-100 text-green-700' },
    production: { label: 'Production', className: 'bg-green-100 text-green-700' },
    out: { label: 'Sortie', className: 'bg-red-100 text-red-700' },
    transfer: { label: 'Transfert', className: 'bg-purple-100 text-purple-700' },
    loss: { label: 'Perte', className: 'bg-gray-200 text-gray-700' },
    adjustment: { label: 'Ajustement', className: 'bg-blue-100 text-blue-700' },
};

export const ALERT_TYPE_LABELS = {
    low_stock: 'Stock Faible',
    overstock: 'Surstock',
};

export const VEHICLE_TYPE_LABELS = {
    tractor: 'Tracteur',
    truck: 'Camion',
    van: 'Camionnette',
    car: 'Voiture',
    quad: 'Quad',
    other: 'Autre',
};

export const FUEL_TYPE_LABELS = {
    diesel: 'Diesel',
    gasoline: 'Essence',
    electric: 'Électrique',
    other: 'Autre',
};

export const FUEL_TRANSACTION_TYPE_LABELS = {
    fueling: 'Ravitaillement',
    transfer: 'Transfert',
    adjustment: 'Ajustement',
};

export const ASSET_TYPE_LABELS = {
    vehicle: 'Véhicule',
    equipment: 'Équipement',
};

export const ASSET_STATUS_LABELS = {
    operational: { label: 'Opérationnel', className: 'bg-green-100 text-green-700' },
    in_repair: { label: 'En Panne', className: 'bg-orange-100 text-orange-700' },
    retired: { label: 'Retiré', className: 'bg-gray-200 text-gray-600' },
};

export const EQUIPMENT_TYPE_LABELS = {
    pump: 'Pompe',
    generator: 'Générateur',
    sprayer: 'Pulvérisateur / Atomiseur',
    compressor: 'Compresseur',
    mulcher: 'Broyeur',
    plow: 'Charrue à Disque',
    mower: 'Faucheuse',
    leveler: 'Lame Niveleuse',
    roller: 'Rouleau Cover Crop',
    tool: 'Outil',
    other: 'Autre',
};
