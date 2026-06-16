import fr from '@/Lang/fr';

const LOCALE = 'fr';

const locales = {
    fr,
};

export function t(key, fallback = '') {
    const dict = locales[LOCALE] || {};
    return dict[key] ?? fallback ?? key;
}

export default { t };
