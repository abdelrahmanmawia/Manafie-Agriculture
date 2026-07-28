export default function StatCard({ label, value, icon, tone }) {
    const tones = {
        blue: { bg: 'bg-blue-100', text: 'text-blue-600' },
        red: { bg: 'bg-red-100', text: 'text-red-600' },
        gray: { bg: 'bg-gray-100', text: 'text-gray-600' },
        orange: { bg: 'bg-orange-100', text: 'text-orange-600' },
        green: { bg: 'bg-green-100', text: 'text-green-600' },
    };

    return (
        <div className="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm font-medium text-gray-500">{label}</p>
                    <p className={`text-2xl font-bold ${tone === 'blue' || tone === 'gray' ? 'text-gray-900' : tones[tone].text}`}>{value}</p>
                </div>
                <div className={`${tones[tone].bg} p-3 rounded-full`}>
                    <svg className={`w-6 h-6 ${tones[tone].text}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        {icon}
                    </svg>
                </div>
            </div>
        </div>
    );
}
