import { useState } from 'react';
import { Link } from '@inertiajs/react';

// Literal, complete class names per color — Tailwind's JIT scans source text for exact class
// strings, so `bg-${color}-600` would silently produce no CSS. Same lookup-table pattern as
// StatCard.jsx's `tones` map.
const COLORS = {
    blue: { text: 'text-blue-600', headerBg: 'bg-blue-50', activeBg: 'bg-blue-600', dot: 'bg-blue-600' },
    purple: { text: 'text-purple-600', headerBg: 'bg-purple-50', activeBg: 'bg-purple-600', dot: 'bg-purple-600' },
    gray: { text: 'text-gray-400', headerBg: 'bg-gray-50', activeBg: 'bg-gray-700', dot: 'bg-gray-400' },
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
    const [openIds, setOpenIds] = useState(() => new Set(sections.filter((s) => s.defaultOpen).map((s) => s.id)));

    const toggle = (id) => {
        setOpenIds((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    return (
        <aside className="hidden lg:flex lg:flex-col w-64 shrink-0 bg-white border-r border-gray-100 min-h-[calc(100vh-4rem)]">
            <div className="p-3.5 sticky top-0 flex flex-col gap-4">
                {homeItem && (
                    <Link
                        href={route(homeItem.href, homeItem.params)}
                        className={`flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-bold transition-colors ${
                            isItemActive(homeItem) ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
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
                                className={`flex items-center justify-between px-2.5 py-2 rounded-lg transition-colors ${isOpen ? palette.headerBg : 'hover:bg-gray-50'}`}
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
                                        const active = isItemActive(item);
                                        return (
                                            <Link
                                                key={item.href}
                                                href={route(item.href, item.params)}
                                                className={`group flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-bold transition-colors ${
                                                    active ? `${palette.activeBg} text-white shadow-sm` : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
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
