import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';

// Shared between Admin/Employees.jsx (the list) and Admin/Employees/Show.jsx (the detail page).
// EmployeeController::destroy() always redirects to the list on success (not back() — from the
// Show page, "back" would just land on that same now-deleted employee's 404'd URL), so this
// needs no page-specific redirect handling of its own.
export default function DeleteEmployeeModal({ employee, onClose }) {
    const { delete: destroy, processing } = useForm();

    const submit = (e) => {
        e.preventDefault();
        destroy(route('employees.destroy', employee.id), {
            preserveScroll: true,
            onFinish: onClose,
        });
    };

    return (
        <Modal show={employee !== null} onClose={onClose}>
            <form onSubmit={submit} className="p-8">
                <div className="flex items-center gap-4 mb-4">
                    <div className="h-12 w-12 bg-red-100 rounded-full flex items-center justify-center">
                        <svg className="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h2 className="text-xl font-black text-gray-900 uppercase tracking-tighter">
                        Supprimer l'employé
                    </h2>
                </div>
                <p className="text-gray-600 mb-6">
                    Êtes-vous sûr de vouloir supprimer définitivement <strong>{employee?.full_name}</strong> ? Cette action est irréversible.
                </p>
                <div className="flex justify-end gap-3">
                    <SecondaryButton type="button" onClick={onClose}>Annuler</SecondaryButton>
                    <DangerButton className="rounded-xl" disabled={processing}>
                        {processing ? 'Suppression...' : "Supprimer l'Employé"}
                    </DangerButton>
                </div>
            </form>
        </Modal>
    );
}
