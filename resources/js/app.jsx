import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// One-time cleanup for browsers that already installed the scan-station service worker before
// it was scoped to /pointage/scan-station — with the old site-wide scope, it kept intercepting
// every other page's requests (including this one) and could fail navigation entirely when it
// had no cached response to fall back to. Regular pages never load scan-station.js, so this is
// the only code path that runs for every user regardless of whether they revisit that page.
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then((registrations) => {
        registrations.forEach((registration) => {
            if (registration.active?.scriptURL.endsWith('/scan-station-sw.js') && !registration.scope.includes('/pointage/scan-station')) {
                registration.unregister();
            }
        });
    });
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
