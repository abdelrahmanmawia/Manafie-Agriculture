// Matches the app's real "outlined" pattern (e.g. the "Annuler" button next to a danger
// confirm) — white background, primary-colored border/text, same pill shape as
// PrimaryButton — rather than Breeze's neutral gray-bordered default.
export default function SecondaryButton({ type = 'button', className = '', disabled, children, ...props }) {
    return (
        <button
            {...props}
            type={type}
            className={
                `inline-flex items-center px-6 py-2.5 bg-white border-2 border-primary-600 rounded-full font-black text-xs text-primary-600 uppercase tracking-widest hover:bg-primary-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
