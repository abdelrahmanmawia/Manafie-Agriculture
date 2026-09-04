import { Link } from '@inertiajs/react';

// Literal, complete class names per tone — Tailwind's JIT scans source text for exact class
// strings, so `border-${tone}-500` would silently produce no CSS. Same lookup-table pattern as
// AppSidebar.jsx's COLORS / QuickActions.jsx's COLORS.
const TONES = {
    blue: { border: 'border-l-blue-500', hoverBorder: 'group-hover:border-l-blue-600', icon: 'text-blue-600' },
    green: { border: 'border-l-green-500', hoverBorder: 'group-hover:border-l-green-600', icon: 'text-green-600' },
    red: { border: 'border-l-red-500', hoverBorder: 'group-hover:border-l-red-600', icon: 'text-red-600' },
    orange: { border: 'border-l-orange-500', hoverBorder: 'group-hover:border-l-orange-600', icon: 'text-orange-600' },
    gray: { border: 'border-l-gray-400', hoverBorder: 'group-hover:border-l-gray-500', icon: 'text-gray-500' },
};

const TREND_STYLES = {
    up: 'bg-green-50 text-green-700',
    down: 'bg-red-50 text-red-700',
    flat: 'bg-gray-100 text-gray-500',
    calm: 'bg-green-50 text-green-700',
};

function TrendIcon({ direction }) {
    if (direction === 'up') {
        return <svg className="w-[11px] h-[11px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="3"><path strokeLinecap="round" strokeLinejoin="round" d="M5 15l7-7 7 7" /></svg>;
    }
    if (direction === 'down') {
        return <svg className="w-[11px] h-[11px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="3"><path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" /></svg>;
    }
    if (direction === 'calm') {
        return <svg className="w-[11px] h-[11px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="3"><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>;
    }
    return null;
}

// `icon`: a <path> element (or array of them), same contract as before — rendered inside our own
// <svg>. `href`: makes the whole card a link (renders as Inertia's Link) and reveals a chevron on
// hover, instead of every caller wrapping <Link><StatCard/></Link> itself — that split is what
// caused a run of "this stat card isn't clickable like the others" bugs. `trend`: optional
// { direction: 'up'|'down'|'flat'|'calm', label }, only rendered when the caller actually has a
// real signal to show (no fabricated deltas).
export default function StatCard({ label, value, icon, tone = 'gray', href, trend }) {
    const palette = TONES[tone] || TONES.gray;
    const Wrapper = href ? Link : 'div';
    const wrapperProps = href ? { href } : {};
    const showFooter = Boolean(trend || href);

    return (
        <Wrapper
            {...wrapperProps}
            className={`group relative block bg-white rounded-2xl border border-gray-100 border-l-4 ${palette.border} shadow-sm p-5 ${href ? `${palette.hoverBorder} hover:shadow-md transition-all` : ''}`}
        >
            <div className="flex items-start justify-between gap-2">
                <span className="text-[11px] font-extrabold uppercase tracking-widest text-gray-400">{label}</span>
                {icon && (
                    <svg className={`w-[18px] h-[18px] shrink-0 ${palette.icon}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        {icon}
                    </svg>
                )}
            </div>

            <div className="mt-2.5 font-mono tabular-nums text-3xl font-semibold tracking-tight text-gray-900">
                {value}
            </div>

            {showFooter && (
                <div className="mt-3.5 flex items-center justify-between min-h-[22px]">
                    {trend ? (
                        <span className={`inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-full ${TREND_STYLES[trend.direction] || TREND_STYLES.flat}`}>
                            <TrendIcon direction={trend.direction} />
                            {trend.label}
                        </span>
                    ) : <span />}
                    {href && (
                        <svg
                            className="w-3.5 h-3.5 text-gray-300 opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 group-hover:text-gray-400 transition-all"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="2.5"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 18l6-6-6-6" />
                        </svg>
                    )}
                </div>
            )}
        </Wrapper>
    );
}
