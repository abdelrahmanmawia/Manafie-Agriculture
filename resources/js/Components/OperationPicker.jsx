import { useState } from 'react';

const labelOf = (op) => `${op.name.toUpperCase()}${op.abbreviation ? ` (${op.abbreviation.toUpperCase()})` : ''}`;

/**
 * The day form's operation: type part of a name or abbreviation, then click, or arrows + Enter;
 * "— Opération à préciser —" clears it (the day stays present, its operation entered later).
 * The list closes as soon as the field is left, so it never covers the fields under it.
 */
export default function OperationPicker({ id, operations, value, onChange, error, className = '' }) {
    const selected = operations.find((op) => String(op.id) === String(value));
    const [term, setTerm] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);

    const search = term.trim().toLowerCase();
    const matches = operations.filter((op) => !search
        || op.name.toLowerCase().includes(search)
        || (op.abbreviation || '').toLowerCase().includes(search));
    const options = [{ id: '', text: '— Opération à préciser —' }, ...matches.map((op) => ({ id: op.id, text: labelOf(op) }))];

    const choose = (option) => {
        onChange(option.id);
        setTerm('');
        setOpen(false);
    };

    const onKeyDown = (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setOpen(true);
            setActive((a) => Math.min(a + 1, options.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => Math.max(a - 1, 0));
        } else if (e.key === 'Enter' && open) {
            e.preventDefault(); // choose, don't submit the form
            choose(options[active] ?? options[0]);
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    };

    return (
        <div className="relative">
            <input
                id={id}
                type="text"
                autoComplete="off"
                className={className}
                value={open ? term : (selected ? labelOf(selected) : '')}
                placeholder={selected ? labelOf(selected) : 'Rechercher par nom ou abréviation...'}
                onFocus={() => { setOpen(true); setTerm(''); setActive(0); }}
                onBlur={() => setOpen(false)}
                onChange={(e) => { setTerm(e.target.value); setOpen(true); setActive(e.target.value.trim() ? 1 : 0); }}
                onKeyDown={onKeyDown}
            />
            {open && (
                <div className="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-xl shadow-lg max-h-56 overflow-y-auto">
                    {options.map((option, i) => (
                        <button
                            key={option.id || 'a-preciser'}
                            type="button"
                            // mousedown would blur the field first and close the list before the click
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => choose(option)}
                            onMouseEnter={() => setActive(i)}
                            className={`w-full text-left px-4 py-2.5 font-black text-sm transition-colors ${i === active ? 'bg-primary-50 text-primary-800' : 'text-gray-800'} ${option.id === '' ? 'text-amber-700 border-b border-gray-100' : ''}`}
                        >
                            {option.text}
                        </button>
                    ))}
                    {matches.length === 0 && <p className="px-4 py-3 text-xs font-bold text-gray-400">Aucune opération pour « {term} »</p>}
                </div>
            )}
            {error && <p className="mt-2 ml-1 text-xs font-bold text-red-600">{error}</p>}
        </div>
    );
}
