// The app's real primary-action style (confirmed against the Hub/Pointage/Stock CTAs
// already in production: rounded-full pill, bold uppercase, primary blue) — not Breeze's
// original gray/indigo scaffolding default, which nothing in the app actually matches.
export default function PrimaryButton({ className = '', disabled, children, ...props }) {
    return (
        <button
            {...props}
            className={
                `inline-flex items-center px-6 py-2.5 bg-primary-600 border border-transparent rounded-full font-black text-xs text-white uppercase tracking-widest shadow-sm hover:bg-primary-700 focus:bg-primary-700 active:bg-primary-800 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 transition ease-in-out duration-150 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
