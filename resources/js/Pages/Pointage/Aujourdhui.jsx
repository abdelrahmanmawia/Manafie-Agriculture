import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';

// "Aujourd'hui": the morning view of the two pointage steps for one day — first who came, per
// équipe (each card opens that équipe's Grid on the day), then what they did, per division (the
// days still "à préciser", entered by headcount with « ≡ Répartir » or worker by worker).
const shift = (date, days) => {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const longDate = (date) => new Date(`${date}T00:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

function Kpi({ label, value, hint, tone = 'gray' }) {
    const tones = { gray: 'text-gray-900', green: 'text-green-700', red: 'text-red-600', amber: 'text-amber-600' };
    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
            <div className="text-[10px] font-black uppercase tracking-widest text-gray-400">{label}</div>
            <div className={`text-2xl sm:text-3xl font-black mt-1 whitespace-nowrap ${tones[tone]}`}>{value}</div>
            {hint && <div className="text-xs text-gray-500 mt-1">{hint}</div>}
        </div>
    );
}

export default function Aujourdhui({ auth, date, today, reference, equipes, divisions, totals }) {
    const go = (d) => router.get(route('pointage.today'), d === today ? {} : { date: d }, { preserveScroll: true });
    const pointees = equipes.filter(e => e.presents > 0).length;
    const aPreciserPeriode = divisions.reduce((n, d) => n + d.a_preciser_periode, 0);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 className="font-black text-xl text-gray-800 uppercase tracking-tighter">{date === today ? "Aujourd'hui" : 'Pointage du jour'}</h2>
                        <p className="text-sm font-bold text-gray-500 first-letter:uppercase">{longDate(date)}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={() => go(shift(date, -1))} className="px-3 py-2 rounded-lg border border-gray-200 bg-white font-bold text-gray-600 hover:bg-gray-50" aria-label="Jour précédent">‹</button>
                        <input type="date" value={date} onChange={e => e.target.value && go(e.target.value)} className="rounded-lg border-gray-200 text-sm font-bold" aria-label="Choisir le jour" />
                        <button type="button" onClick={() => go(shift(date, 1))} className="px-3 py-2 rounded-lg border border-gray-200 bg-white font-bold text-gray-600 hover:bg-gray-50" aria-label="Jour suivant">›</button>
                        {date !== today && (
                            <button type="button" onClick={() => go(today)} className="px-3 py-2 rounded-lg bg-primary-600 text-white text-sm font-bold hover:bg-primary-700">Aujourd'hui</button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Aujourd'hui" />

            <div className="py-8">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                    {!reference ? (
                        <div className="text-center py-12 bg-white rounded-2xl border-2 border-dashed border-gray-200">
                            <p className="text-gray-600 font-bold">Aucune période ne couvre ce jour.</p>
                            <Link href={route('pointage.quinzaines')} className="inline-block mt-3 text-primary-700 font-bold underline">Ouvrir une période</Link>
                        </div>
                    ) : (
                        <>
                            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                                <Kpi label="Présents" value={`${totals.presents} / ${totals.effectif}`} hint="ouvriers actifs pointés ce jour" tone={totals.presents ? 'green' : 'gray'} />
                                <Kpi label="Équipes pointées" value={`${pointees} / ${equipes.length}`} hint={pointees < equipes.length ? `${equipes.length - pointees} encore à pointer` : 'Toutes pointées'} tone={pointees < equipes.length ? 'amber' : 'green'} />
                                <Kpi label="À préciser ce jour" value={totals.a_preciser} hint="présents sans opération" tone={totals.a_preciser ? 'red' : 'green'} />
                                <Kpi label="À préciser (période)" value={aPreciserPeriode} hint={reference.label || 'période en cours'} tone={aPreciserPeriode ? 'red' : 'green'} />
                            </div>

                            <section>
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter mb-3">1. Présence par équipe</h3>
                                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                                    {equipes.map(e => {
                                        const done = e.presents > 0;
                                        const pct = e.effectif ? Math.round((e.presents / e.effectif) * 100) : 0;
                                        return (
                                            <div key={e.id} className={`bg-white rounded-2xl border-2 p-5 shadow-sm ${done ? 'border-green-100' : 'border-amber-200'}`}>
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <p className="font-black text-gray-900 truncate">{e.name}</p>
                                                        <p className="text-xs text-gray-500 truncate">
                                                            {e.chef ? `Chef : ${e.chef}` : 'Sans chef'}{e.vehicle ? ` · ${e.vehicle}` : ''}
                                                        </p>
                                                    </div>
                                                    <span className={`shrink-0 px-2 py-1 rounded text-[10px] font-black uppercase ${done ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-800'}`}>
                                                        {done ? 'Pointée' : 'À pointer'}
                                                    </span>
                                                </div>
                                                <div className="mt-4 flex items-end justify-between">
                                                    <div className="text-2xl font-black text-gray-900">{e.presents}<span className="text-sm text-gray-400"> / {e.effectif}</span></div>
                                                    {e.a_preciser > 0 && <div className="text-xs font-bold text-red-600">{e.a_preciser} à préciser</div>}
                                                </div>
                                                <div className="mt-2 h-2 rounded-full bg-gray-100 overflow-hidden">
                                                    <div className={`h-full ${done ? 'bg-green-500' : 'bg-amber-400'}`} style={{ width: `${pct}%` }} />
                                                </div>
                                                <Link href={route('pointage.grid', { quinzaine: reference.id, mode: 'equipe', equipe: e.id, jour: date })}
                                                    className={`mt-4 block text-center py-2 rounded-lg text-sm font-black ${done ? 'bg-primary-50 text-primary-700 border border-primary-200 hover:bg-primary-100' : 'bg-primary-600 text-white hover:bg-primary-700'}`}>
                                                    {done ? 'Ouvrir la grille' : "Pointer l'équipe"}
                                                </Link>
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>

                            <section>
                                <h3 className="text-lg font-black text-gray-800 uppercase tracking-tighter mb-1">2. Opérations par division</h3>
                                <p className="text-sm text-gray-500 mb-3">Les présents « à préciser » attendent leur opération : « ≡ Répartir » sous le jour pour les saisir par effectif, ou ouvrier par ouvrier.</p>
                                <div className="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
                                    {divisions.map(d => (
                                        <div key={d.quinzaine_id} className="px-5 py-4 flex flex-col md:flex-row md:items-center gap-3">
                                            <div className="md:w-64 font-black text-gray-800">
                                                {d.name}
                                                {d.is_closed && <span className="ml-2 bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded text-[9px] font-black uppercase">Clôturée</span>}
                                            </div>
                                            <div className="flex flex-wrap gap-x-8 gap-y-1 flex-1 text-sm">
                                                <span><span className="text-gray-400 font-bold">Présents : </span><strong>{d.presents}</strong></span>
                                                <span className={d.a_preciser ? 'text-red-600' : 'text-green-700'}><span className="text-gray-400 font-bold">À préciser ce jour : </span><strong>{d.a_preciser}</strong></span>
                                                <span className={d.a_preciser_periode ? 'text-red-600' : 'text-green-700'}><span className="text-gray-400 font-bold">Sur la période : </span><strong>{d.a_preciser_periode}</strong></span>
                                            </div>
                                            <Link href={route('pointage.grid', { quinzaine: d.quinzaine_id, jour: date })}
                                                className={`px-4 py-2 rounded-lg text-sm font-black text-center ${d.a_preciser ? 'bg-primary-600 text-white hover:bg-primary-700' : 'bg-gray-50 text-gray-700 border border-gray-200 hover:bg-gray-100'}`}>
                                                {d.a_preciser ? 'Préciser les opérations' : 'Grille de la division'}
                                            </Link>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
