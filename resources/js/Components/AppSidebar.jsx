import { useState } from 'react';
import { Link } from '@inertiajs/react';

// Literal, complete class names per color — Tailwind's JIT scans source text for exact class
// strings, so `bg-${color}-600` would silently produce no CSS. Same lookup-table pattern as
// StatCard.jsx's `tones` map.
//
// Pointage was blue, Stock was purple — neither read as "the logo's app," so Pointage (the
// original/primary domain) now uses the brand green (`primary`). Stock stays a genuinely
// different hue on purpose — two green sections would be indistinguishable at a glance — teal
// was picked over the old purple for a calmer, more "storage/inventory" feel that still
// contrasts cleanly against green.
// headerBg is one step darker (100, not 50) than the sidebar's own bg-primary-50 shell below —
// otherwise an open Pointage section's highlight would be the same color as the shell itself and
// disappear.
const COLORS = {
    green: { text: 'text-primary-600', headerBg: 'bg-primary-100', activeBg: 'bg-primary-600', dot: 'bg-primary-600', hoverBg: 'hover:bg-primary-100/70' },
    teal: { text: 'text-teal-600', headerBg: 'bg-teal-100', activeBg: 'bg-teal-600', dot: 'bg-teal-600', hoverBg: 'hover:bg-teal-100/70' },
    gray: { text: 'text-gray-400', headerBg: 'bg-gray-100', activeBg: 'bg-gray-700', dot: 'bg-gray-400', hoverBg: 'hover:bg-gray-200/70' },
};

function isItemActive(item) {
    const patterns = Array.isArray(item.match) ? item.match : [item.match];
    return patterns.some((pattern) => route().current(pattern));
}

function NavIcon({ item, active, activeTextClass }) {
    const paths = Array.isArray(item.icon) ? item.icon : [item.icon];
    return (
        <svg
            className={`w-4 h-4 shrink-0 ${active ? 'text-white' : `text-gray-400 group-hover:${activeTextClass}`}`}
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            strokeWidth="2"
        >
            {paths.map((d, i) => (
                <path key={i} strokeLinecap="round" strokeLinejoin="round" d={d} />
            ))}
        </svg>
    );
}

// One persistent sidebar for the whole app instead of three that swap wholesale — sections stay
// visible for every domain the user has access to, with the one matching the current page open
// by default. Sections are independent (not an accordion): opening Stock while Pointage is
// already open is fine, so a multi-domain user can see both at once.
export default function AppSidebar({ homeItem, sections }) {
    const [openIds, setOpenIds] = useState(() => {
        const ids = sections.filter((s) => s.defaultOpen).map((s) => s.id);
        // A nested sub-group (item.children — e.g. "Gestion Transport" bundling several links
        // under Pointage) gets its own open/closed state in the same Set, namespaced by the
        // parent section's id so two sections could each have a same-named group with no clash.
        sections.forEach((s) => s.items?.forEach((item) => {
            if (item.children && item.defaultOpen) ids.push(`${s.id}:${item.id}`);
        }));
        return new Set(ids);
    });

    const toggle = (id) => {
        setOpenIds((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    return (
        <aside className="hidden lg:flex lg:flex-col w-64 shrink-0 bg-primary-50 border-r border-primary-100 min-h-[calc(100vh-4rem)]">
            <div className="p-3.5 sticky top-0 flex flex-col gap-4">
                {homeItem && (
                    <Link
                        href={route(homeItem.href, homeItem.params)}
                        className={`flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-bold transition-colors ${
                            isItemActive(homeItem) ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:bg-white/70 hover:text-gray-900'
                        }`}
                    >
                        <svg className="w-4 h-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d={homeItem.icon} />
                        </svg>
                        {homeItem.label}
                    </Link>
                )}

                {sections.map((section) => {
                    const palette = COLORS[section.color] || COLORS.gray;
                    const isOpen = openIds.has(section.id);
                    return (
                        <div key={section.id} className="flex flex-col gap-0.5">
                            <button
                                type="button"
                                onClick={() => toggle(section.id)}
                                className={`flex items-center justify-between px-2.5 py-2 rounded-lg transition-colors ${isOpen ? palette.headerBg : palette.hoverBg}`}
                            >
                                <span className="flex items-center gap-2">
                                    <span className={`w-1.5 h-1.5 rounded-full ${palette.dot}`} />
                                    <span className={`text-[10px] font-black uppercase tracking-widest ${palette.text}`}>{section.label}</span>
                                </span>
                                <svg
                                    className={`w-3 h-3 transition-transform ${palette.text} ${isOpen ? 'rotate-180' : ''}`}
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="2.5"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            {isOpen && (
                                <nav className="flex flex-col gap-0.5 pl-1 pt-0.5 pb-1">
                                    {section.items.map((item) => {
                                        if (item.children) {
                                            const groupId = `${section.id}:${item.id}`;
                                            const groupOpen = openIds.has(groupId);
                                            const groupActive = item.children.some(isItemActive);
                                            return (
                                                <div key={groupId} className="flex flex-col">
                                                    <button
                                                        type="button"
                                                        onClick={() => toggle(groupId)}
                                                        className={`group flex items-center justify-between gap-3 px-3 py-2.5 rounded-xl text-sm font-bold transition-colors ${
                                                            groupActive && !groupOpen ? `${palette.activeBg} text-white shadow-sm` : `text-gray-600 ${palette.hoverBg} hover:text-gray-900`
                                                        }`}
                                                    >
                                                        <span className="flex items-center gap-3">
                                                            {item.icon && <NavIcon item={item} active={groupActive && !groupOpen} activeTextClass={palette.text} />}
                                                            {item.label}
                                                        </span>
                                                        <svg
                                                            className={`w-3 h-3 shrink-0 transition-transform ${groupActive && !groupOpen ? 'text-white' : 'text-gray-400'} ${groupOpen ? 'rotate-180' : ''}`}
                                                            fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="2.5"
                                                        >
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                                        </svg>
                                                    </button>
                                                    {groupOpen && (
                                                        <nav className="flex flex-col gap-0.5 pl-4 pt-0.5 pb-1">
                                                            {item.children.map((child) => {
                                                                const active = isItemActive(child);
                                                                return (
                                                                    <Link
                                                                        key={child.href}
                                                                        href={route(child.href, child.params)}
                                                                        className={`group flex items-center gap-3 px-3 py-2 rounded-xl text-sm font-semibold transition-colors ${
                                                                            active ? `${palette.activeBg} text-white shadow-sm` : `text-gray-500 ${palette.hoverBg} hover:text-gray-900`
                                                                        }`}
                                                                    >
                                                                        {child.icon && <NavIcon item={child} active={active} activeTextClass={palette.text} />}
                                                                        {child.label}
                                                                    </Link>
                                                                );
                                                            })}
                                                        </nav>
                                                    )}
                                                </div>
                                            );
                                        }

                                        const active = isItemActive(item);
                                        return (
                                            <Link
                                                key={item.href}
                                                href={route(item.href, item.params)}
                                                className={`group flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-bold transition-colors ${
                                                    active ? `${palette.activeBg} text-white shadow-sm` : `text-gray-600 ${palette.hoverBg} hover:text-gray-900`
                                                }`}
                                            >
                                                {item.icon && <NavIcon item={item} active={active} activeTextClass={palette.text} />}
                                                {item.label}
                                            </Link>
                                        );
                                    })}
                                </nav>
                            )}
                        </div>
                    );
                })}
            </div>
        </aside>
    );
}
