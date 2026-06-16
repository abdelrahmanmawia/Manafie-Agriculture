export function formatNumber(value, decimals = 2) {
    const number = Number(value ?? 0);
    const formatter = new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });

    return Number.isNaN(number) ? formatter.format(0) : formatter.format(number);
}
