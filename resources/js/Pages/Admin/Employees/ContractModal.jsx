import { useEffect, useState } from 'react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';

// Shared between Admin/Employees.jsx (the list) and Admin/Employees/Show.jsx (the detail page).
export default function ContractModal({ employee, onClose }) {
    const [contractDate, setContractDate] = useState('');

    useEffect(() => {
        if (employee) {
            setContractDate(employee.hire_date || new Date().toISOString().slice(0, 10));
        }
    }, [employee]);

    return (
        <Modal show={employee !== null} onClose={onClose}>
            <div className="p-8">
                <div className="flex items-center gap-4 mb-4">
                    <div className="h-12 w-12 bg-primary-100 rounded-full flex items-center justify-center">
                        <svg className="h-6 w-6 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div>
                        <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter">Générer un Contrat</h2>
                        <p className="text-sm text-gray-500">{employee?.full_name}</p>
                    </div>
                </div>

                {(!employee?.cin || !employee?.address || !employee?.cnss_number) && (
                    <p className="text-xs font-bold text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-4">
                        CIN, adresse ou n° CNSS manquant(s) sur cette fiche — le contrat sera généré avec des blancs à compléter à la main.
                    </p>
                )}

                <label htmlFor="contract_date" className="block text-[10px] font-black uppercase text-gray-400 tracking-widest mb-1.5">
                    Date de début du contrat
                </label>
                <input
                    id="contract_date"
                    type="date"
                    value={contractDate}
                    onChange={(e) => setContractDate(e.target.value)}
                    className="block w-full rounded-xl border-gray-200 text-sm focus:border-primary-500 focus:ring-primary-500"
                />
                <p className="text-xs text-gray-400 mt-1.5">Pré-remplie avec la date d'embauche — modifiable pour un renouvellement saisonnier.</p>

                <div className="flex justify-end gap-3 mt-6">
                    <SecondaryButton type="button" onClick={onClose}>Annuler</SecondaryButton>
                    <a
                        href={employee ? route('employees.contract', { employee: employee.id, start_date: contractDate }) : '#'}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center px-6 py-2.5 bg-primary-600 border border-transparent rounded-full font-black text-xs text-white uppercase tracking-widest shadow-sm hover:bg-primary-700 transition"
                    >
                        Télécharger le Contrat
                    </a>
                </div>
            </div>
        </Modal>
    );
}
