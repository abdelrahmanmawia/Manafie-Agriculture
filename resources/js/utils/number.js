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

export function formatMAD(value, decimals = 2) {
    return `${formatNumber(value, decimals)} MAD`;
}
