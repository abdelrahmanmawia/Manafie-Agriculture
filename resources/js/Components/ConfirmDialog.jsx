import { useCallback, useRef, useState } from 'react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';

const ICONE = 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z';

/**
 * The app's own confirmation dialog, in place of the browser's confirm() — never use that one.
 *
 *   const [confirmer, confirmDialog] = useConfirm();
 *   if (!(await confirmer({ titre: 'Supprimer', message: '…', bouton: 'Supprimer', danger: true }))) return;
 *   …
 *   {confirmDialog}  // once in the component's JSX; inside a Modal's content when asked from one
 */
export function useConfirm() {
    const [demande, setDemande] = useState(null);
    // What's shown stays while the dialog fades out.
    const derniere = useRef({});

    const confirmer = useCallback((options) => new Promise((resolve) => {
        derniere.current = typeof options === 'string' ? { message: options } : options;
        setDemande({ resolve });
    }), []);

    const repondre = (ok) => {
        demande?.resolve(ok);
        setDemande(null);
    };

    const { titre = 'Confirmer', message, bouton = 'Confirmer', danger = false } = derniere.current;
    const Bouton = danger ? DangerButton : PrimaryButton;

    const dialog = (
        <Modal show={demande !== null} onClose={() => repondre(false)} maxWidth="md">
            <div className="p-6 sm:p-8">
                <div className="flex items-center gap-4 mb-4">
                    <div className={`h-12 w-12 shrink-0 rounded-full flex items-center justify-center ${danger ? 'bg-red-100' : 'bg-amber-100'}`}>
                        <svg className={`h-6 w-6 ${danger ? 'text-red-600' : 'text-amber-600'}`} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={ICONE} />
                        </svg>
                    </div>
                    <h2 className="text-lg sm:text-xl font-black text-gray-900 tracking-tight">{titre}</h2>
                </div>
                {message && <p className="text-gray-600 mb-6 whitespace-pre-line">{message}</p>}
                <div className="flex flex-wrap justify-end gap-3">
                    <SecondaryButton type="button" onClick={() => repondre(false)}>Annuler</SecondaryButton>
                    <Bouton type="button" onClick={() => repondre(true)}>{bouton}</Bouton>
                </div>
            </div>
        </Modal>
    );

    return [confirmer, dialog];
}
