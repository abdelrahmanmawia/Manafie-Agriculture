import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const clean = (values) => Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '' && v !== null && v !== undefined));

// Filters of a server-paginated list, kept in the URL: a select or a date applies at once, the
// search after a short pause; every change goes back to page 1.
export default function useListFilters(routeName, filters = {}) {
    const [values, setValues] = useState({ ...filters });
    const firstRender = useRef(true);

    const visit = (next) => router.get(route(routeName), clean(next), { preserveState: true, preserveScroll: true, replace: true });

    const set = (key, value) => {
        const next = { ...values, [key]: value };
        setValues(next);
        if (key !== 'search') visit(next);
    };

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return undefined;
        }
        const timer = setTimeout(() => visit(values), 400);
        return () => clearTimeout(timer);
    }, [values.search]);

    const reset = () => {
        setValues({});
        visit({});
    };

    return { values, set, reset, active: Object.keys(clean(values)).length > 0 };
}
