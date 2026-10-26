import { Link } from '@inertiajs/react';

// "À surveiller": what needs someone's attention right now, each line opening its list
// ({ href, text }); "Rien à signaler" when there is nothing.
export default function AttentionList({ points, title = 'À surveiller' }) {
    return (
        <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
            <h3 className="text-sm font-bold text-gray-500 uppercase tracking-widest mb-3">{title}</h3>
            {points.length === 0 ? (
                <p className="text-sm text-gray-500">Rien à signaler.</p>
            ) : (
                <ul className="divide-y divide-gray-100">
                    {points.map((point) => (
                        <li key={point.text}>
                            <Link href={point.href} className="flex items-center justify-between py-2.5 text-sm text-gray-800 hover:text-primary-700">
                                <span className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full bg-amber-500 shrink-0" />
                                    {point.text}
                                </span>
                                <span className="text-gray-400">→</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
