import { useState } from 'react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import AppSidebar from '@/Components/AppSidebar';
import FlashToast from '@/Components/FlashToast';
import { Link, usePage } from '@inertiajs/react';
import { t } from '@/Helpers/i18n';

export default function Authenticated({ user, header, children }) {
    const [showingNavigationDropdown, setShowingNavigationDropdown] = useState(false);
    const { activeFarm } = usePage().props;

    // A super_admin has no farm of their own — until they activate one (see FarmController),
    // every farm-scoped module (Pointage, Stock, Employés) would either bounce them straight
    // back here via the farm.selected middleware or, for Employés, silently show data across
    // every farm at once. Hiding those entry points until a farm is active avoids both.
    const needsFarmSelection = user.role === 'super_admin' && !activeFarm;

    // Only data_entry accounts are ever restricted — super_admin/farm_manager always see both
    // domains (see User::canAccessPointage()/canAccessStock() on the backend, mirrored here so
    // the nav doesn't offer an entry point the middleware would just 403 on).
    const canAccessPointage = user.role !== 'data_entry' || user.can_access_pointage;
    const canAccessStock = user.role !== 'data_entry' || user.can_access_stock;

    // A data_entry granted exactly one domain has no real use for the Hub — EnterpriseController
    // redirects them straight into that domain, so offering an "Accueil" link that just bounces
    // back out is confusing. A data_entry with both (or neither) flag still sees it normally.
    const isSingleDomainDataEntry = user.role === 'data_entry' && (canAccessPointage !== canAccessStock);

    // Only used to decide which sidebar section opens by default on this page load — the
    // sidebar itself is one persistent tree now, not three that swap (see AppSidebar).
    const isStockZone = route().current('stock.*');
    const isPointageZone = !isStockZone && (
        route().current('pointage.*') ||
        route().current('harvests.*') ||
        route().current('analytics.*') ||
        route().current('payroll.*') ||
        route().current('settings.*') ||
        route().current('badges.*') ||
        // Employees is Pointage-domain master data (see EmployeeController) — gated the same
        // way behind access.domain:pointage, so it groups visually under Pointage, not Accueil.
        route().current('employees.*')
    );
    const isAdminZone = !isStockZone && !isPointageZone && (route().current('farms.settings') || route().current('users.*'));
    const isHubZone = !isStockZone && !isPointageZone && !isAdminZone;

    const ICONS = {
        home: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        employees: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
        shield: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        clock: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        sun: 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l-.707.707M12 8a4 4 0 100 8 4 4 0 000-8z',
        chart: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        book: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
        sliders: 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4',
        grid: 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z',
        box: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4m0-10l8-4m-8 4L4 7m8 4v10',
        inventory: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
        swap: 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
        alert: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
        fuel: ['M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z', 'M15 11a3 3 0 11-6 0 3 3 0 016 0z'],
        pencil: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        truck: 'M3 13l1.5-5A2 2 0 016.4 6.5h5.2a2 2 0 011.9 1.5l1 4M3 13v4a1 1 0 001 1h1m14-5v4a1 1 0 01-1 1h-1m-12 0a2 2 0 104 0m-4 0a2 2 0 114 0m8 0a2 2 0 104 0m-4 0a2 2 0 114 0M3 13h15',
    };

    const farmSettingsItem = (user.farm_id || activeFarm)
        ? { label: t('system_settings') || 'Paramètres Système', href: 'farms.settings', params: user.farm_id || activeFarm.id, match: 'farms.settings', icon: ICONS.sliders }
        : null;

    const homeItem = { label: t('dashboard') || 'Accueil', href: 'dashboard', match: 'dashboard', icon: ICONS.home };

    const pointageItems = [
        { label: 'Tableau de Bord', href: 'pointage.index', match: 'pointage.index', icon: ICONS.grid },
        { label: 'Quinzaines', href: 'pointage.quinzaines', match: ['pointage.quinzaines', 'pointage.grid'], icon: ICONS.clock },
        ...(needsFarmSelection
            ? []
            : [{ label: t('employees') || 'Employés', href: 'employees.index', match: 'employees.*', icon: ICONS.employees }]),
        { label: t('harvests') || 'Récoltes', href: 'harvests.index', match: 'harvests.*', icon: ICONS.sun },
        { label: t('analyses_stats') || 'Analyses & Statistiques', href: 'analytics.index', match: 'analytics.*', icon: ICONS.chart },
        { label: t('payroll_history') || 'Historique Salaires', href: 'payroll.history', match: 'payroll.*', icon: ICONS.book },
        { label: 'Badges & Scan', href: 'badges.index', match: 'badges.*', icon: ICONS.grid },
    ];

    const stockItems = [
        { label: 'Tableau de Bord', href: 'stock.dashboard', match: 'stock.dashboard', icon: ICONS.grid },
        { label: 'Produits', href: 'stock.products.index', match: 'stock.products.*', icon: ICONS.box },
        { label: 'Inventaire', href: 'stock.inventory.index', match: 'stock.inventory.*', icon: ICONS.inventory },
        { label: 'Mouvements', href: 'stock.movements.index', match: 'stock.movements.*', icon: ICONS.swap },
        { label: 'Alertes', href: 'stock.alerts.index', match: 'stock.alerts.*', icon: ICONS.alert },
        { label: 'Véhicules & Matériel', href: 'stock.vehicles.index', match: 'stock.vehicles.*', icon: ICONS.truck },
        { label: 'Location', href: 'stock.vehicle-usage.index', match: 'stock.vehicle-usage.*', icon: ICONS.clock },
        { label: 'Carburant', href: 'stock.fuel-transactions.index', match: 'stock.fuel-transactions.*', icon: ICONS.fuel },
        { label: 'Sorties de Stock', href: 'stock.manual-entries.index', match: 'stock.manual-entries.*', icon: ICONS.pencil },
        { label: 'Rapports', href: 'stock.reports.index', match: 'stock.reports.*', icon: ICONS.chart },
    ];

    const adminItems = [
        ...(user.role === 'super_admin'
            ? [{ label: t('users') || 'Utilisateurs', href: 'users.index', match: 'users.*', icon: ICONS.shield }]
            : []),
        ...(farmSettingsItem ? [farmSettingsItem] : []),
    ];

    // One persistent tree instead of three that swap wholesale — every domain the user has
    // access to stays visible, with the section matching the current page open by default.
    const sections = [
        canAccessPointage && !needsFarmSelection
            ? { id: 'pointage', label: t('pointage') || 'Pointage', color: 'blue', items: pointageItems, defaultOpen: isPointageZone }
            : null,
        canAccessStock && !needsFarmSelection
            ? { id: 'stock', label: 'Gestion de Stock', color: 'purple', items: stockItems, defaultOpen: isStockZone }
            : null,
        adminItems.length > 0
            ? { id: 'admin', label: 'Administration', color: 'gray', items: adminItems, defaultOpen: isAdminZone }
            : null,
    ].filter(Boolean);

    return (
        <div className="min-h-screen bg-gray-100">
            <FlashToast />
            <nav className="bg-white border-b border-gray-100">
                <div className="max-w-full mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex justify-between h-16">
                        <div className="flex items-center">
                            <Link href="/" className="shrink-0 flex items-center gap-2.5">
                                <ApplicationLogo className="block h-8 w-auto fill-current text-gray-800" />
                                <span className="hidden sm:block font-black text-sm uppercase tracking-tight text-gray-800">Gestion Agricole</span>
                            </Link>
                        </div>

                        <div className="hidden sm:flex sm:items-center sm:gap-4">
                            {needsFarmSelection && (
                                <span className="flex items-center text-xs font-bold text-amber-600 uppercase tracking-wide bg-amber-50 border border-amber-200 rounded-lg px-3 py-1.5">
                                    <svg className="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                    Sélectionnez une ferme pour continuer
                                </span>
                            )}

                            <Dropdown>
                                <Dropdown.Trigger>
                                    <span className="inline-flex rounded-md">
                                        <button
                                            type="button"
                                            className="inline-flex items-center px-4 py-2 border-2 border-gray-100 text-sm leading-4 font-black rounded-xl text-gray-700 bg-white hover:bg-gray-50 hover:border-blue-200 focus:outline-none transition ease-in-out duration-150 shadow-sm uppercase tracking-tighter"
                                        >
                                            <div className="flex flex-col items-start mr-3 border-r pr-3 border-gray-100 hidden md:flex">
                                                <span className="text-[10px] font-black text-blue-600 uppercase tracking-widest leading-none">
                                                    {user.role === 'super_admin' ? (activeFarm?.name || 'Aucune Ferme') : (user.farm?.name || 'Globale')}
                                                </span>
                                                <span className="text-[8px] font-bold text-gray-400 uppercase tracking-tighter mt-1">{user.enterprise?.name || 'Accès Manager'}</span>
                                            </div>
                                            {user.name}

                                            <svg
                                                className="ms-2 -me-0.5 h-4 w-4 opacity-50"
                                                xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                            >
                                                <path
                                                    fillRule="evenodd"
                                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                    clipRule="evenodd"
                                                />
                                            </svg>
                                        </button>
                                    </span>
                                </Dropdown.Trigger>

                                <Dropdown.Content>
                                    <div className="px-4 py-2 border-b border-gray-100 mb-1">
                                        <p className="text-[10px] font-black text-gray-400 uppercase tracking-widest">{t('connected')}</p>
                                        <p className="text-xs font-bold text-blue-600 truncate">{user.email}</p>
                                    </div>
                                    <Dropdown.Link href={route('profile.edit')}>{t('profile')}</Dropdown.Link>
                                    {user.role === 'super_admin' && activeFarm && (
                                        <Dropdown.Link href={route('farms.deactivate')} method="post" as="button">
                                            Changer de Ferme
                                        </Dropdown.Link>
                                    )}
                                    <Dropdown.Link href={route('logout')} method="post" as="button" className="text-red-600">
                                        {t('logout')}
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>

                        {/* Must hide at the same breakpoint (lg) the desktop sidebar appears at, not sm —
                            otherwise there's a dead zone between 640px and 1023px (tablets, landscape
                            phones) where neither the sidebar nor this hamburger is visible. */}
                        <div className="-me-2 flex items-center lg:hidden">
                            <button
                                onClick={() => setShowingNavigationDropdown((previousState) => !previousState)}
                                className="inline-flex items-center justify-center p-3 rounded-xl text-gray-400 hover:text-blue-600 hover:bg-blue-50 focus:outline-none transition duration-150 ease-in-out border-2 border-transparent hover:border-blue-100"
                            >
                                <svg className="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path
                                        className={!showingNavigationDropdown ? 'block' : 'hidden'}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={showingNavigationDropdown ? 'block' : 'hidden'}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                {/* Mobile menu — the same homeItem/sections the desktop sidebar renders, flattened
                    (no expand/collapse: a hamburger menu is already a deliberate open, so there's
                    no reason to hide a domain's items behind a second tap). */}
                <div className={(showingNavigationDropdown ? 'block' : 'hidden') + ' lg:hidden bg-white border-t border-gray-100 shadow-2xl'}>
                    <div className="pt-2 pb-3">
                        {!isSingleDomainDataEntry && (
                            <ResponsiveNavLink href={route(homeItem.href, homeItem.params)} active={isHubZone} onClick={() => setShowingNavigationDropdown(false)}>
                                <div className="flex items-center">
                                    <svg className="w-5 h-5 mr-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={homeItem.icon} /></svg>
                                    {homeItem.label}
                                </div>
                            </ResponsiveNavLink>
                        )}

                        {needsFarmSelection && (
                            <div className="mx-4 my-2 flex items-center text-xs font-bold text-amber-600 uppercase tracking-wide bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                <svg className="w-4 h-4 mr-1.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                Sélectionnez une ferme pour continuer
                            </div>
                        )}

                        {sections.map((section) => (
                            <div key={section.id} className="border-t border-gray-100 mt-2 pt-2">
                                <p className="px-4 pb-1 text-[10px] font-black uppercase tracking-widest text-gray-400">{section.label}</p>
                                {section.items.map((item) => (
                                    <ResponsiveNavLink
                                        key={item.href}
                                        href={route(item.href, item.params)}
                                        active={route().current(Array.isArray(item.match) ? item.match[0] : item.match)}
                                        onClick={() => setShowingNavigationDropdown(false)}
                                    >
                                        {item.label}
                                    </ResponsiveNavLink>
                                ))}
                            </div>
                        ))}
                    </div>

                    <div className="pt-4 pb-1 border-t border-gray-200 bg-gray-50/50">
                        <div className="px-4">
                            <div className="font-medium text-base text-gray-800">{user.name}</div>
                            <div className="font-medium text-sm text-gray-500">{user.email}</div>
                        </div>

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>Profile</ResponsiveNavLink>
                            <ResponsiveNavLink method="post" href={route('logout')} as="button">
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <div className="flex">
                <AppSidebar homeItem={!isSingleDomainDataEntry ? homeItem : null} sections={sections} />

                <div className="flex-1 min-w-0">
                    {header && (
                        <header className="bg-white shadow">
                            <div className="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">{header}</div>
                        </header>
                    )}

                    <main>{children}</main>
                </div>
            </div>
        </div>
    );
}
