import { Link } from '@inertiajs/react';

// Literal, complete class names per color — Tailwind's JIT scans source text for exact class
// strings, so `text-${color}-500` would silently produce no CSS. Same lookup-table pattern as
// AppSidebar.jsx's COLORS / StatCard.jsx's tones.
const COLORS = {
    blue: { icon: 'text-blue-500', hoverBorder: 'hover:border-blue-300' },
    purple: { icon: 'text-purple-500', hoverBorder: 'hover:border-purple-300' },
    green: { icon: 'text-green-500', hoverBorder: 'hover:border-green-300' },
    orange: { icon: 'text-orange-500', hoverBorder: 'hover:border-orange-300' },
    // Matches AppSidebar's Stock section color — used for the Stock launchpad tile specifically,
    // not added to Pointage/Index.jsx's own decorative tile row (blue/purple/green/orange there
    // are pure visual variety, not domain-coded, and stay as they are).
    teal: { icon: 'text-teal-500', hoverBorder: 'hover:border-teal-300' },
};

function TileIcon({ d, colorClass }) {
    const paths = Array.isArray(d) ? d : [d];
    return (
        <svg className={`w-8 h-8 mb-2 ${colorClass}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
            {paths.map((p, i) => (
                <path key={i} strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={p} />
            ))}
        </svg>
    );
}

// Shared "launchpad into each zone" tile grid — extracted from FarmDashboard.jsx, which had the
// only calm version of this pattern; Admin/Dashboard.jsx had its own shouty rounded-full CTA
// pills for the same job. `items`: [{ href, label, icon: pathData, color }].
export default function QuickActions({ title = 'Accès Rapide', items }) {
    if (!items || items.length === 0) return null;

    return (
        <div className="bg-gradient-to-r from-gray-50 to-gray-100 rounded-2xl p-6 border border-gray-200">
            <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter mb-4">{title}</h3>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {items.map((item) => {
                    const palette = COLORS[item.color] || COLORS.blue;
                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={`flex flex-col items-center justify-center p-4 bg-white rounded-xl shadow-sm hover:shadow-md transition-shadow border border-gray-200 ${palette.hoverBorder}`}
                        >
                            <TileIcon d={item.icon} colorClass={palette.icon} />
                            <span className="text-sm font-medium text-gray-700">{item.label}</span>
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
