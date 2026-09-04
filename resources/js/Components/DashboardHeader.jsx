// Shared header for the three dashboard pages (SuperDashboard doesn't use this — it's a farm
// picker, not a stats dashboard). Settles the typography drift between FarmDashboard's
// `font-semibold ... tracking-widest` and Admin/Dashboard's `font-black ... tracking-tighter` on
// one standard, and gives every dashboard the same optional right-side action-button slot.
export default function DashboardHeader({ title, actions }) {
    return (
        <div className="flex justify-between items-center w-full">
            <h2 className="font-semibold text-xl text-gray-800 leading-tight uppercase tracking-widest">{title}</h2>
            {actions && <div className="flex gap-2">{actions}</div>}
        </div>
    );
}
