import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { formatNumber } from '@/Helpers/formatNumber';
import { peutSaisir } from '@/utils/acces';

const dh = (v) => `${formatNumber(v)} DH`;
const dateFr = (d) => (d ? d.slice(0, 10).split('-').reverse().join('/') : '');
const STATUT_CLASSES = { non_recue: 'bg-gray-200 text-gray-700', recue: 'bg-amber-100 text-amber-800', payee: 'bg-green-100 text-green-800' };

const champ = 'w-full rounded-lg border-gray-300 text-sm';
const Label = ({ htmlFor, children }) => <label htmlFor={htmlFor} className="block text-sm font-bold text-gray-700 mb-1">{children}</label>;
const Err = ({ children }) => (children ? <div className="text-red-600 text-xs mt-1">{children}</div> : null);

function Carte({ label, value, hint }) {
    return (
        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
            <div className="text-[11px] font-bold uppercase tracking-wide text-gray-500">{label}</div>
            <div className="text-xl sm:text-2xl font-black text-gray-900 mt-1">{value}</div>
            {hint && <div className="text-xs font-semibold text-gray-500 mt-1">{hint}</div>}
        </div>
    );
}

// Days sent against the contract's headcount × the period's days: figures the company itself
// sends, so this tells a gap in the numbers, not who really came.
function Jours({ jours, attendu, ecart }) {
    return (
        <div className="whitespace-nowrap">
            <div className="font-bold text-gray-900">{jours}</div>
            {attendu !== null && (
                <div className={`text-[11px] font-bold ${ecart === 0 ? 'text-green-700' : ecart < 0 ? 'text-amber-700' : 'text-red-700'}`}>
                    {ecart === 0 ? 'conforme' : `${ecart > 0 ? '+' : ''}${ecart} sur ${attendu}`}
                </div>
            )}
        </div>
    );
}

// What a charge should cost under these terms; null when they have no price for its days.
const selonContrat = (termes, nuit, jour) => {
    if (!termes) return null;
    if (termes.forfait !== null && termes.forfait !== undefined && (termes.mode_facturation ?? 'forfait') === 'forfait') return Number(termes.forfait);
    const [n, j] = [Number(nuit) || 0, Number(jour) || 0];
    const [pn, pj] = [termes.prix_nuit, termes.prix_jour];
    if (n + j === 0 || (n > 0 && pn === null) || (j > 0 && pj === null)) return null;
    return Math.round((n * (pn || 0) + j * (pj || 0)) * 100) / 100;
};

function ChargeForm({ show, onClose, ligne, initial, prestataires, periodes, statuts }) {
    const { data, setData, post, transform, processing, errors, clearErrors, reset } = useForm({
        prestataire_id: '', start_date: '', end_date: '', jours_nuit: '', jours_jour: '', montant: '',
        statut_facture: 'non_recue', facture_numero: '', facture_date: '', notes: '', document: null, remove_document: false,
    });

    useEffect(() => {
        if (!show) return;
        clearErrors();
        setData(ligne ? {
            prestataire_id: ligne.prestataire_id, start_date: ligne.start_date, end_date: ligne.end_date,
            jours_nuit: ligne.jours_nuit, jours_jour: ligne.jours_jour, montant: ligne.montant,
            statut_facture: ligne.statut_facture, facture_numero: ligne.facture_numero || '', facture_date: ligne.facture_date || '',
            notes: ligne.notes || '', document: null, remove_document: false,
        } : {
            prestataire_id: initial?.prestataire_id || prestataires.find(p => p.is_active)?.id || '',
            start_date: initial?.start_date || '', end_date: initial?.end_date || '', jours_nuit: '', jours_jour: '', montant: '',
            statut_facture: 'non_recue', facture_numero: '', facture_date: '', notes: '', document: null, remove_document: false,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, ligne?.id, initial]);

    const prest = prestataires.find(p => p.id == data.prestataire_id);
    // An entered charge keeps the terms it was entered under.
    const termes = ligne ? { ...ligne, mode_facturation: ligne.forfait !== null ? 'forfait' : 'par_jour' } : prest;
    const contrat = selonContrat(termes, data.jours_nuit, data.jours_jour);
    const periodeKey = `${data.start_date}_${data.end_date}`;

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
        if (ligne) {
            // A file can't travel on a real PUT: POST with a spoofed method (see EmployeeFormModal).
            transform((d) => ({ ...d, _method: 'put' }));
            post(route('pointage.charges.update', ligne.id), options);
        } else {
            transform((d) => d);
            post(route('pointage.charges.store'), options);
        }
    };

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6 sm:p-8 space-y-4">
                <h3 className="text-xl font-bold text-gray-800 border-b pb-4">{ligne ? 'Modifier la charge' : 'Ajouter une charge'}</h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <Label htmlFor="ch_prestataire">Prestataire</Label>
                        <select id="ch_prestataire" disabled={!!ligne} className={champ} value={data.prestataire_id} onChange={e => setData('prestataire_id', e.target.value)}>
                            <option value="">Choisir…</option>
                            {prestataires.filter(p => p.is_active || p.id == data.prestataire_id).map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                        </select>
                        <Err>{errors.prestataire_id}</Err>
                    </div>
                    <div>
                        <Label htmlFor="ch_periode">Période</Label>
                        <select id="ch_periode" className={champ} value={periodes.some(p => p.key === periodeKey) ? periodeKey : ''}
                            onChange={e => { const p = periodes.find(x => x.key === e.target.value); if (p) setData(d => ({ ...d, start_date: p.start_date, end_date: p.end_date })); }}>
                            <option value="">Autres dates…</option>
                            {periodes.map(p => <option key={p.key} value={p.key}>{p.label}</option>)}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="ch_debut">Du</Label>
                        <input id="ch_debut" type="date" className={champ} value={data.start_date} onChange={e => setData('start_date', e.target.value)} />
                        <Err>{errors.start_date}</Err>
                    </div>
                    <div>
                        <Label htmlFor="ch_fin">Au</Label>
                        <input id="ch_fin" type="date" className={champ} value={data.end_date} onChange={e => setData('end_date', e.target.value)} />
                        <Err>{errors.end_date}</Err>
                    </div>
                    <div>
                        <Label htmlFor="ch_nuit">Jours de nuit (total)</Label>
                        <input id="ch_nuit" type="number" min="0" step="1" className={champ} value={data.jours_nuit} onChange={e => setData('jours_nuit', e.target.value)} />
                        <Err>{errors.jours_nuit}</Err>
                    </div>
                    <div>
                        <Label htmlFor="ch_jour">Jours de jour (total)</Label>
                        <input id="ch_jour" type="number" min="0" step="1" className={champ} value={data.jours_jour} onChange={e => setData('jours_jour', e.target.value)} />
                        <Err>{errors.jours_jour}</Err>
                    </div>
                    <div className="sm:col-span-2">
                        <Label htmlFor="ch_montant">Montant à payer (DH)</Label>
                        <input id="ch_montant" type="number" min="0" step="0.01" className={`${champ} font-bold`} placeholder={contrat !== null ? `Selon le contrat : ${formatNumber(contrat)}` : 'Montant de la facture'}
                            value={data.montant} onChange={e => setData('montant', e.target.value)} />
                        <p className="text-xs text-gray-500 mt-1">
                            {contrat !== null ? `Selon le contrat : ${dh(contrat)}. Laissez vide pour le reprendre, ou saisissez le montant de la facture.` : 'Saisissez les jours pour le calcul selon le contrat, ou le montant de la facture.'}
                        </p>
                        <Err>{errors.montant}</Err>
                    </div>
                    <div>
                        <Label htmlFor="ch_statut">Facture</Label>
                        <select id="ch_statut" className={champ} value={data.statut_facture} onChange={e => setData('statut_facture', e.target.value)}>
                            {Object.entries(statuts).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <div>
                            <Label htmlFor="ch_numero">N°</Label>
                            <input id="ch_numero" type="text" maxLength={100} className={champ} value={data.facture_numero} onChange={e => setData('facture_numero', e.target.value)} />
                        </div>
                        <div>
                            <Label htmlFor="ch_fdate">Date</Label>
                            <input id="ch_fdate" type="date" className={champ} value={data.facture_date} onChange={e => setData('facture_date', e.target.value)} />
                        </div>
                    </div>
                    <div className="sm:col-span-2">
                        <Label htmlFor="ch_document">Fichier de la société (optionnel)</Label>
                        {ligne?.document_name && !data.remove_document && (
                            <p className="text-xs text-gray-600 mb-1">{ligne.document_name}{' '}
                                <button type="button" className="font-bold text-red-600 underline" onClick={() => setData('remove_document', true)}>Retirer</button>
                            </p>
                        )}
                        <input id="ch_document" type="file" accept=".xlsx,.xls,.csv,.pdf,.jpg,.jpeg,.png" className="w-full text-xs" onChange={e => setData('document', e.target.files[0] || null)} />
                        <Err>{errors.document}</Err>
                    </div>
                    <div className="sm:col-span-2">
                        <Label htmlFor="ch_notes">Note (optionnel)</Label>
                        <input id="ch_notes" type="text" maxLength={500} className={champ} value={data.notes} onChange={e => setData('notes', e.target.value)} />
                    </div>
                </div>
                <div className="flex justify-end gap-3 pt-4 border-t">
                    <SecondaryButton type="button" onClick={onClose}>Annuler</SecondaryButton>
                    <PrimaryButton disabled={processing}>Enregistrer</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

function PrestataireForm({ show, onClose, prestataire }) {
    const vide = { name: '', service: 'Gardiennage', mode_facturation: 'par_jour', prix_nuit: '', prix_jour: '', forfait: '', effectif_nuit: '', effectif_jour: '', contrat_debut: '', contrat_fin: '', is_active: true };
    const { data, setData, post, put, processing, errors, clearErrors } = useForm(vide);

    useEffect(() => {
        if (!show) return;
        clearErrors();
        setData(prestataire ? Object.fromEntries(Object.keys(vide).map(k => [k, prestataire[k] ?? ''])) : vide);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show, prestataire?.id]);

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (prestataire) put(route('pointage.prestataires.update', prestataire.id), options);
        else post(route('pointage.prestataires.store'), options);
    };
    const nombre = (id, label, key, step = '0.01') => (
        <div>
            <Label htmlFor={id}>{label}</Label>
            <input id={id} type="number" min="0" step={step} className={champ} value={data[key]} onChange={e => setData(key, e.target.value)} />
            <Err>{errors[key]}</Err>
        </div>
    );

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <form onSubmit={submit} className="p-6 sm:p-8 space-y-4">
                <h3 className="text-xl font-bold text-gray-800 border-b pb-4">{prestataire ? `Contrat — ${prestataire.name}` : 'Ajouter un prestataire'}</h3>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <Label htmlFor="pr_name">Société</Label>
                        <input id="pr_name" type="text" className={champ} placeholder="Ex : ETOILE GARD" value={data.name} onChange={e => setData('name', e.target.value)} />
                        <Err>{errors.name}</Err>
                    </div>
                    <div>
                        <Label htmlFor="pr_service">Service</Label>
                        <input id="pr_service" type="text" className={champ} value={data.service} onChange={e => setData('service', e.target.value)} />
                        <Err>{errors.service}</Err>
                    </div>
                    <div className="sm:col-span-2">
                        <Label htmlFor="pr_mode">Facturation</Label>
                        <select id="pr_mode" className={champ} value={data.mode_facturation} onChange={e => setData('mode_facturation', e.target.value)}>
                            <option value="par_jour">Par jour de service</option>
                            <option value="forfait">Forfait par période</option>
                        </select>
                    </div>
                    {data.mode_facturation === 'forfait'
                        ? <div className="sm:col-span-2">{nombre('pr_forfait', 'Forfait par période (DH)', 'forfait')}</div>
                        : <>{nombre('pr_prix_nuit', 'Prix par jour de nuit (DH)', 'prix_nuit')}{nombre('pr_prix_jour', 'Prix par jour de jour (DH)', 'prix_jour')}</>}
                    {nombre('pr_eff_nuit', 'Effectif prévu la nuit (personnes)', 'effectif_nuit', '1')}
                    {nombre('pr_eff_jour', 'Effectif prévu le jour (personnes)', 'effectif_jour', '1')}
                    <div>
                        <Label htmlFor="pr_debut">Début du contrat</Label>
                        <input id="pr_debut" type="date" className={champ} value={data.contrat_debut} onChange={e => setData('contrat_debut', e.target.value)} />
                        <Err>{errors.contrat_debut}</Err>
                    </div>
                    <div>
                        <Label htmlFor="pr_fin">Fin du contrat</Label>
                        <input id="pr_fin" type="date" className={champ} value={data.contrat_fin} onChange={e => setData('contrat_fin', e.target.value)} />
                        <Err>{errors.contrat_fin}</Err>
                    </div>
                    {prestataire && (
                        <label className="sm:col-span-2 flex items-center gap-2 text-sm font-semibold text-gray-700 cursor-pointer">
                            <input type="checkbox" className="rounded border-gray-300 text-primary-600" checked={!!data.is_active} onChange={e => setData('is_active', e.target.checked)} />
                            Contrat actif (décoché : plus de rappel « non saisi », son historique reste)
                        </label>
                    )}
                </div>
                <p className="text-xs text-gray-500">Un nouveau prix s'applique aux prochaines charges : celles déjà saisies gardent le leur.</p>
                <div className="flex justify-end gap-3 pt-4 border-t">
                    <SecondaryButton type="button" onClick={onClose}>Annuler</SecondaryButton>
                    <PrimaryButton disabled={processing}>Enregistrer</PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}

// Companies under contract: one charge per period from the totals they send, followed over a year.
export default function ChargesExternes({ auth, suivi, filtres, prestataires, periodes, statuts, ajouter = null }) {
    const { props } = usePage();
    const pageErrors = props.errors || {};
    const canManage = peutSaisir(auth.user, 'pointage');

    const periodeInitiale = ajouter ? periodes.find(p => p.key === ajouter) : null;
    const [charge, setCharge] = useState(periodeInitiale && canManage && prestataires.length ? { initial: periodeInitiale } : null);
    const [contrat, setContrat] = useState(null);
    const [supprimer, setSupprimer] = useState(null);
    const [busy, setBusy] = useState(false);

    const filtrer = (changes) => router.get(route('pointage.charges.index'), Object.fromEntries(Object.entries({ annee: suivi.annee, ...filtres, ...changes }).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });
    const services = [...new Set(prestataires.map(p => p.service))];
    const { totaux, precedente } = suivi;
    const ecartAnnee = precedente && precedente.montant > 0 ? ((totaux.montant - precedente.montant) / precedente.montant) * 100 : null;

    const exportHref = route('pointage.charges.export', Object.fromEntries(Object.entries({ annee: suivi.annee, ...filtres }).filter(([, v]) => v)));

    const confirmerSuppression = () => {
        setBusy(true);
        router.delete(route('pointage.charges.destroy', supprimer.id), { preserveScroll: true, onFinish: () => { setBusy(false); setSupprimer(null); } });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-wrap justify-between items-center gap-3">
                    <div>
                        <h2 className="font-semibold text-xl text-gray-800 leading-tight">Charges externes</h2>
                        <p className="text-sm text-gray-500 mt-0.5">Les sociétés sous contrat : leurs totaux par période, pas leur personnel.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <a href={exportHref} target="_blank" rel="noreferrer" className="inline-flex items-center px-4 py-2 rounded-lg border border-green-300 bg-green-50 text-green-800 text-sm font-bold hover:bg-green-100">Exporter en Excel</a>
                        {canManage && prestataires.length > 0 && (
                            <button type="button" onClick={() => setCharge({})} className="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-bold shadow text-sm">+ Ajouter une charge</button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Charges externes" />

            <div className="py-6 sm:py-10">
                <div className="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8 space-y-6">
                    {pageErrors.prestataire && <div role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{pageErrors.prestataire}</div>}

                    <div className="grid grid-cols-1 sm:flex sm:flex-wrap sm:items-center sm:justify-end gap-3">
                        <select aria-label="Année" className="text-sm rounded-md border-gray-300" value={suivi.annee} onChange={e => filtrer({ annee: e.target.value })}>
                            {suivi.annees.map(a => <option key={a} value={a}>{a}</option>)}
                        </select>
                        {prestataires.length > 1 && (
                            <select aria-label="Prestataire" className="text-sm rounded-md border-gray-300" value={filtres.prestataire_id || ''} onChange={e => filtrer({ prestataire_id: e.target.value })}>
                                <option value="">Tous les prestataires</option>
                                {prestataires.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        )}
                        {services.length > 1 && (
                            <select aria-label="Service" className="text-sm rounded-md border-gray-300" value={filtres.service || ''} onChange={e => filtrer({ service: e.target.value })}>
                                <option value="">Tous les services</option>
                                {services.map(s => <option key={s} value={s}>{s}</option>)}
                            </select>
                        )}
                    </div>

                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <Carte label={`Total ${suivi.annee}`} value={dh(totaux.montant)} hint={ecartAnnee !== null
                            ? `${ecartAnnee >= 0 ? '+' : ''}${ecartAnnee.toFixed(1)} % par rapport à ${precedente.annee} à la même date (${dh(precedente.montant)})`
                            : `${totaux.periodes} période(s) saisie(s)`} />
                        <Carte label="Jours de nuit" value={formatNumber(totaux.jours_nuit, 0)} />
                        <Carte label="Jours de jour" value={formatNumber(totaux.jours_jour, 0)} />
                        <Carte label="Coût moyen / jour" value={totaux.cout_jour !== null ? dh(totaux.cout_jour) : '—'} />
                    </div>

                    {(suivi.manquantes.length > 0 || suivi.contrats.length > 0) && (
                        <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 space-y-2">
                            {suivi.contrats.map(c => (
                                <p key={c.prestataire} className="font-semibold">{c.expire ? `Le contrat de ${c.prestataire} a pris fin le ${dateFr(c.contrat_fin)}.` : `Le contrat de ${c.prestataire} prend fin le ${dateFr(c.contrat_fin)}.`}</p>
                            ))}
                            {suivi.manquantes.length > 0 && <p className="font-bold">Non saisi (pas dans le total) :</p>}
                            <ul className="space-y-1">
                                {suivi.manquantes.map(m => (
                                    <li key={`${m.prestataire_id}-${m.periode}`} className="flex flex-wrap items-center gap-2">
                                        <span>{m.prestataire} — {m.label}</span>
                                        {canManage && (
                                            <button type="button" className="font-bold underline" onClick={() => setCharge({ initial: { ...periodes.find(p => p.key === m.periode), prestataire_id: m.prestataire_id } })}>Saisir</button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {suivi.parPrestataire.length > 1 && (
                        <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                            <h3 className="text-sm font-black text-gray-800 mb-2">Par prestataire</h3>
                            <ul className="divide-y divide-gray-100 text-sm">
                                {suivi.parPrestataire.map(p => (
                                    <li key={p.prestataire} className="py-2 flex justify-between gap-4">
                                        <span className="font-semibold text-gray-800">{p.prestataire} <span className="font-normal text-gray-500">· {p.service}</span></span>
                                        <span className="font-bold whitespace-nowrap">{dh(p.montant)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-gray-50 text-left text-xs font-bold uppercase tracking-wide text-gray-600">
                                    <tr>
                                        <th className="px-4 py-3">Période</th>
                                        <th className="px-4 py-3">Prestataire</th>
                                        <th className="px-4 py-3">Nuit</th>
                                        <th className="px-4 py-3">Jour</th>
                                        <th className="px-4 py-3 text-right">Montant</th>
                                        <th className="px-4 py-3 text-right">Coût / jour</th>
                                        <th className="px-4 py-3">Facture</th>
                                        <th className="px-4 py-3">Fichier</th>
                                        {canManage && <th className="px-4 py-3" />}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {suivi.lignes.map(l => (
                                        <tr key={l.id} className="align-top">
                                            <td className="px-4 py-3 font-bold text-gray-900 whitespace-nowrap">{l.periode}</td>
                                            <td className="px-4 py-3"><div className="font-semibold text-gray-800">{l.prestataire}</div><div className="text-xs text-gray-500">{l.service}</div></td>
                                            <td className="px-4 py-3"><Jours jours={l.jours_nuit} attendu={l.attendu_nuit} ecart={l.ecart_nuit} /></td>
                                            <td className="px-4 py-3"><Jours jours={l.jours_jour} attendu={l.attendu_jour} ecart={l.ecart_jour} /></td>
                                            <td className="px-4 py-3 text-right whitespace-nowrap">
                                                <div className="font-black text-gray-900">{dh(l.montant)}</div>
                                                {l.ecart_montant !== null && Math.abs(l.ecart_montant) >= 0.01 && (
                                                    <div className={`text-[11px] font-bold ${l.ecart_montant > 0 ? 'text-red-700' : 'text-amber-700'}`} title={`Selon le contrat : ${dh(l.montant_contrat)}`}>
                                                        {l.ecart_montant > 0 ? '+' : ''}{formatNumber(l.ecart_montant)} / contrat
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right whitespace-nowrap">{l.cout_jour !== null ? formatNumber(l.cout_jour) : '—'}</td>
                                            <td className="px-4 py-3">
                                                <span className={`px-2 py-0.5 rounded text-xs font-bold whitespace-nowrap ${STATUT_CLASSES[l.statut_facture]}`}>{statuts[l.statut_facture]}</span>
                                                {(l.facture_numero || l.facture_date) && <div className="text-xs text-gray-500 mt-1 whitespace-nowrap">{l.facture_numero}{l.facture_numero && l.facture_date ? ' · ' : ''}{dateFr(l.facture_date)}</div>}
                                            </td>
                                            <td className="px-4 py-3">
                                                {l.document_name
                                                    ? <a href={route('pointage.charges.document', l.id)} title={l.document_name} className="text-xs font-bold text-primary-700 hover:underline">Télécharger</a>
                                                    : <span className="text-gray-400">—</span>}
                                            </td>
                                            {canManage && (
                                                <td className="px-4 py-3 whitespace-nowrap text-right">
                                                    <button type="button" onClick={() => setCharge({ ligne: l })} className="text-xs font-bold text-primary-700 hover:underline mr-3">Modifier</button>
                                                    <button type="button" onClick={() => setSupprimer(l)} className="text-xs font-bold text-red-600 hover:underline">Supprimer</button>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                    {suivi.lignes.length === 0 && (
                                        <tr><td colSpan={canManage ? 9 : 8} className="px-4 py-10 text-center text-gray-500 italic">Aucune charge saisie pour {suivi.annee}.</td></tr>
                                    )}
                                </tbody>
                                {suivi.lignes.length > 0 && (
                                    <tfoot className="bg-gray-50 font-black text-gray-900">
                                        <tr>
                                            <td className="px-4 py-3" colSpan={2}>Total {suivi.annee}</td>
                                            <td className="px-4 py-3">{formatNumber(totaux.jours_nuit, 0)}</td>
                                            <td className="px-4 py-3">{formatNumber(totaux.jours_jour, 0)}</td>
                                            <td className="px-4 py-3 text-right whitespace-nowrap">{dh(totaux.montant)}</td>
                                            <td className="px-4 py-3 text-right whitespace-nowrap">{totaux.cout_jour !== null ? formatNumber(totaux.cout_jour) : '—'}</td>
                                            <td colSpan={canManage ? 3 : 2} />
                                        </tr>
                                    </tfoot>
                                )}
                            </table>
                        </div>
                    </div>
                    <p className="text-xs text-gray-500 px-1">Le contrôle compare les chiffres envoyés par la société à son contrat : il ne prouve pas qui était présent.</p>

                    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-6">
                        <div className="flex flex-wrap justify-between items-center gap-3 mb-3">
                            <h3 className="text-base font-black text-gray-800">Prestataires et contrats</h3>
                            {canManage && <button type="button" onClick={() => setContrat({})} className="px-3 py-2 rounded-lg border border-primary-300 text-primary-700 text-sm font-bold hover:bg-primary-50">+ Ajouter un prestataire</button>}
                        </div>
                        {prestataires.length === 0 && <p className="text-sm text-gray-500 italic py-4 text-center">Aucun prestataire. Ajoutez la société et son contrat pour commencer.</p>}
                        <ul className="divide-y divide-gray-100">
                            {prestataires.map(p => (
                                <li key={p.id} className="py-3 flex flex-wrap items-center justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="font-bold text-gray-900">{p.name} <span className="font-normal text-gray-500">· {p.service}</span>{!p.is_active && <span className="ml-2 bg-gray-200 text-gray-700 px-1.5 py-0.5 rounded text-[11px] font-bold">Inactif</span>}</div>
                                        <div className="text-xs text-gray-500 mt-0.5">
                                            {p.mode_facturation === 'forfait'
                                                ? `Forfait : ${p.forfait !== null ? dh(p.forfait) : 'non indiqué'} par période`
                                                : `Prix : nuit ${p.prix_nuit !== null ? dh(p.prix_nuit) : '—'} · jour ${p.prix_jour !== null ? dh(p.prix_jour) : '—'}`}
                                            {' · '}Effectif : nuit {p.effectif_nuit ?? '—'} · jour {p.effectif_jour ?? '—'}
                                            {(p.contrat_debut || p.contrat_fin) && ` · Contrat : ${dateFr(p.contrat_debut) || '…'} → ${dateFr(p.contrat_fin) || '…'}`}
                                        </div>
                                    </div>
                                    {canManage && (
                                        <div className="flex gap-3 shrink-0">
                                            <button type="button" onClick={() => setContrat({ prestataire: p })} className="text-xs font-bold text-primary-700 hover:underline">Modifier</button>
                                            {p.charges_count === 0 && (
                                                <button type="button" onClick={() => router.delete(route('pointage.prestataires.destroy', p.id), { preserveScroll: true })} className="text-xs font-bold text-red-600 hover:underline">Supprimer</button>
                                            )}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>

            <ChargeForm show={charge !== null} onClose={() => setCharge(null)} ligne={charge?.ligne ?? null} initial={charge?.initial ?? null} prestataires={prestataires} periodes={periodes} statuts={statuts} />
            <PrestataireForm show={contrat !== null} onClose={() => setContrat(null)} prestataire={contrat?.prestataire ?? null} />

            <Modal show={supprimer !== null} onClose={() => setSupprimer(null)}>
                {supprimer && (
                    <div className="p-8">
                        <h2 className="text-xl font-black text-gray-900 mb-4">Supprimer la charge</h2>
                        <p className="text-gray-600 mb-6">Supprimer {supprimer.prestataire} — {supprimer.periode} ({dh(supprimer.montant)}) ? Son fichier est supprimé avec elle.</p>
                        <div className="flex justify-end gap-3">
                            <SecondaryButton type="button" onClick={() => setSupprimer(null)}>Annuler</SecondaryButton>
                            <DangerButton disabled={busy} onClick={confirmerSuppression}>Supprimer</DangerButton>
                        </div>
                    </div>
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
