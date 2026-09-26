/**
 * Badge status generik untuk halaman admin (warna mengikuti status COMPLETED,
 * FAILED, RUNNING, PENDING, dsb).
 *
 * Komponen ini sengaja berada di Components/Admin dan bukan di dalam sebuah
 * halaman Inertia: halaman Inertia dimuat sebagai entry terpisah pada build
 * Vite (lihat resources/views/app.blade.php), sehingga halaman yang menjadi
 * sumber impor lintas-halaman akan kehilangan entri manifest-nya.
 */
const STATUS_STYLES = {
    COMPLETED: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    FAILED: 'bg-red-50 text-red-700 border-red-200',
    RUNNING: 'bg-amber-50 text-amber-700 border-amber-200',
    PENDING: 'bg-slate-100 text-slate-600 border-slate-200',
};

export default function StatusBadge({ status }) {
    return (
        <span
            className={`rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_STYLES[status] || 'bg-slate-100 text-slate-600 border-slate-200'}`}
        >
            {status}
        </span>
    );
}
