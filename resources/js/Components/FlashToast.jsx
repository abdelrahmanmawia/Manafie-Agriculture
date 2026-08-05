import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';

// Reads the `flash.success`/`flash.error` props HandleInertiaRequests shares on every
// visit — every controller already calls redirect()->back()->with('success'/'error', ...)
// for non-validation notices, this is what actually surfaces them to the user.
export default function FlashToast() {
    const { flash } = usePage().props;
    const [visible, setVisible] = useState(null);

    useEffect(() => {
        if (flash?.success) {
            setVisible({ type: 'success', message: flash.success });
        } else if (flash?.error) {
            setVisible({ type: 'error', message: flash.error });
        } else {
            return;
        }

        const timer = setTimeout(() => setVisible(null), 5000);
        return () => clearTimeout(timer);
    }, [flash?.success, flash?.error]);

    if (!visible) return null;

    const isSuccess = visible.type === 'success';

    return (
        <div className="fixed top-4 right-4 z-[100] max-w-sm">
            <div
                className={`flex items-start gap-3 rounded-xl shadow-lg border px-4 py-3 ${
                    isSuccess ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'
                }`}
            >
                <span className="mt-0.5">
                    {isSuccess ? (
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" /></svg>
                    ) : (
                        <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" /></svg>
                    )}
                </span>
                <p className="text-sm font-bold flex-1">{visible.message}</p>
                <button onClick={() => setVisible(null)} className="text-current opacity-50 hover:opacity-100 transition-opacity">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>
    );
}
