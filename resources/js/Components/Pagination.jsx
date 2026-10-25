import { Link } from '@inertiajs/react';

// Links of a Laravel paginator (paginate()->withQueryString(), so the filters stay on every
// page), with "1–50 sur 354" beside them.
export default function Pagination({ paginator }) {
    if (!paginator || !paginator.total) return null;

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-t border-gray-100">
            <span className="text-xs text-gray-500">
                {paginator.from}–{paginator.to} sur {paginator.total}
            </span>
            {paginator.last_page > 1 && (
                <div className="flex flex-wrap gap-1">
                    {paginator.links.map((link, key) => (
                        link.url === null ? (
                            <span
                                key={key}
                                className="px-3 py-1.5 text-xs text-gray-400 border border-gray-200 rounded-lg bg-gray-50"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <Link
                                key={key}
                                href={link.url}
                                preserveScroll
                                className={`px-3 py-1.5 text-xs border rounded-lg transition-colors ${
                                    link.active
                                        ? 'bg-primary-600 border-primary-600 text-white font-bold'
                                        : 'bg-white border-gray-200 text-gray-700 hover:bg-gray-50'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        )
                    ))}
                </div>
            )}
        </div>
    );
}
