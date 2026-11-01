// What's wrong with a RIB as it's typed — the same check as App\Rules\RibMarocain on the server:
// 24 digits, the whole number divisible by 97. Empty is fine (no RIB: paid in cash).
export function ribEtat(valeur) {
    const rib = (valeur || '').replace(/\s+/g, '');
    if (rib === '') return null;
    if (!/^\d+$/.test(rib)) return { ok: false, message: 'Le RIB ne doit contenir que des chiffres.' };
    if (rib.length !== 24) return { ok: false, message: `${rib.length} / 24 chiffres` };
    // Digit by digit: 24 digits don't fit in a JavaScript number.
    const reste = [...rib].reduce((r, chiffre) => (r * 10 + Number(chiffre)) % 97, 0);
    return reste === 0
        ? { ok: true, message: '✓ RIB valide' }
        : { ok: false, message: 'RIB incorrect : la clé ne correspond pas, vérifiez les chiffres.' };
}

export default function RibHint({ value, error }) {
    const etat = ribEtat(value);
    // The server's refusal stays until the RIB is put right, not until the next save.
    if (error && !etat?.ok) return <div className="text-red-500 text-xs mt-1">{error}</div>;
    if (!etat) return null;
    return <div className={`text-xs mt-1 ${etat.ok ? 'text-green-700' : 'text-amber-700'}`}>{etat.message}</div>;
}
