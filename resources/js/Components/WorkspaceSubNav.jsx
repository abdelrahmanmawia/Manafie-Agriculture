import { Link } from '@inertiajs/react';

export default function WorkspaceSubNav({ items, colorClass = 'bg-blue-600', label }) {
    const isActive = (item) => {
        const patterns = Array.isArray(item.match) ? item.match : [item.match];
        return patterns.some((pattern) => route().current(pattern));
    };

    return (
        <aside className="hidden lg:flex lg:flex-col w-60 shrink-0 bg-white border-r border-gray-100 min-h-[calc(100vh-4rem)]">
            <div className="p-4 sticky top-0">
                {label && (
                    <h3 className="text-[10px] font-black uppercase tracking-widest text-gray-400 px-3 mb-3">{label}</h3>
                )}
                <nav className="space-y-1">
                    {items.map((item) => {
                        const active = isActive(item);
                        const paths = Array.isArray(item.icon) ? item.icon : [item.icon];
                        return (
                            <Link
                                key={item.href}
                                href={route(item.href, item.params)}
                                className={`flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-bold transition-colors ${
                                    active
                                        ? `${colorClass} text-white shadow-sm`
                                        : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                }`}
                            >
                                {item.icon && (
                                    <svg className="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="2">
                                        {paths.map((d, i) => (
                                            <path key={i} strokeLinecap="round" strokeLinejoin="round" d={d} />
                                        ))}
                                    </svg>
                                )}
                                {item.label}
                            </Link>
                        );
                    })}
                </nav>
            </div>
        </aside>
    );
}
