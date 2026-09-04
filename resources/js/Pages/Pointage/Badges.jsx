import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

export default function Badges({ auth, employees, enterprises }) {
    const [selected, setSelected] = useState(new Set());

    const toggle = (id) => {
        setSelected(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const toggleAll = () => {
        setSelected(prev => prev.size === employees.length ? new Set() : new Set(employees.map(e => e.id)));
    };

    const printUrl = () => {
        const base = route('badges.print');
        return selected.size > 0 ? `${base}?employee_ids=${[...selected].join(',')}` : base;
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-1">
                    <h2 className="font-black text-xl text-gray-800 leading-tight tracking-tighter uppercase">
                        Badges de Pointage
                    </h2>
                    <span className="text-[10px] text-gray-400 font-black uppercase tracking-widest">
                        {employees.length} employés actifs
                    </span>
                </div>
            }
        >
            <Head title="Badges de Pointage" />

            <div className="py-6">
                <div className="max-w-5xl mx-auto sm:px-4 lg:px-8 space-y-4">
                    <div className="flex flex-wrap gap-3 items-center justify-between bg-white shadow-sm sm:rounded-2xl border-t-4 border-primary-600 p-4">
                        <p className="text-xs text-gray-500 font-bold">
                            Sélectionnez des employés pour n'imprimer que leurs badges, ou laissez la sélection vide pour tout imprimer.
                            Un badge déjà imprimé garde le même QR code à vie, même après une réimpression.
                        </p>
                        <div className="flex gap-2">
                            <a
                                href={route('pointage.scan-station')}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg font-bold text-xs uppercase tracking-wide"
                            >
                                Ouvrir la Station de Scan
                            </a>
                            <a
                                href={printUrl()}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-full font-bold text-xs uppercase tracking-wide"
                            >
                                Imprimer {selected.size > 0 ? `(${selected.size})` : '(Tous)'}
                            </a>
                        </div>
                    </div>

                    <section className="bg-white shadow-2xl sm:rounded-2xl border-t-8 border-primary-600 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50">
                                    <tr className="text-left text-xs font-bold uppercase tracking-wider text-gray-500">
                                        <th className="px-4 py-3">
                                            <input type="checkbox" checked={selected.size === employees.length && employees.length > 0} onChange={toggleAll} />
                                        </th>
                                        <th className="px-4 py-3">Matricule</th>
                                        <th className="px-4 py-3">Nom Complet</th>
                                        {auth.user.role === 'super_admin' && <th className="px-4 py-3">Division</th>}
                                        <th className="px-4 py-3">Badge</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {employees.map(emp => (
                                        <tr key={emp.id} className="hover:bg-gray-50">
                                            <td className="px-4 py-3">
                                                <input type="checkbox" checked={selected.has(emp.id)} onChange={() => toggle(emp.id)} />
                                            </td>
                                            <td className="px-4 py-3 font-medium text-gray-900">{emp.matricule}</td>
                                            <td className="px-4 py-3 font-bold text-gray-800">{emp.full_name}</td>
                                            {auth.user.role === 'super_admin' && (
                                                <td className="px-4 py-3 text-xs text-primary-600 font-bold">{emp.enterprise?.name || '-'}</td>
                                            )}
                                            <td className="px-4 py-3 text-xs">
                                                {emp.badge_uuid
                                                    ? <span className="text-green-700 font-bold">✓ Déjà imprimé</span>
                                                    : <span className="text-gray-400 italic">Pas encore imprimé</span>}
                                            </td>
                                        </tr>
                                    ))}
                                    {employees.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-12 text-center text-gray-400 italic">
                                                Aucun employé actif.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
