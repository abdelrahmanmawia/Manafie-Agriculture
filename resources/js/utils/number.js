// European (French) number formatting: comma as decimal separator, space as thousands
// separator (ISO 80000). Used everywhere a quantity, cost, or price is displayed.

export function formatNumber(value, decimals = 2) {
    const num = typeof value === 'number' ? value : parseFloat(value);
    const safe = Number.isFinite(num) ? num : 0;
    return safe.toLocaleString('fr-FR', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

export function formatInt(value) {
    return formatNumber(value, 0);
}

// "DH" (not "MAD") to match the label already used everywhere outside Stock
// (Admin/Employees/Payroll/FarmDashboard) — this was the one function producing a
// different currency label for the exact same amounts depending on which page called it.
export function formatMAD(value, decimals = 2) {
    return `${formatNumber(value, decimals)} DH`;
}
