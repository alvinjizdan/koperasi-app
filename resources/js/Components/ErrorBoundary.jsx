import React from 'react';

/**
 * Error boundary global untuk aplikasi Inertia/React.
 *
 * Tanpa boundary, satu error render (mis. Ziggy error karena nama route
 * salah) membuat seluruh halaman menjadi layar putih. Dengan boundary,
 * pengguna tetap melihat UI dengan pesan yang jelas dan bisa mencoba
 * merender ulang atau membuka dashboard.
 */
export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { error: null };
    }

    static getDerivedStateFromError(error) {
        return { error };
    }

    componentDidCatch(error, info) {
        // Log ke console agar tetap bisa didiagnosis lewat devtools.
        console.error('ErrorBoundary menangkap error render:', error, info?.componentStack);
    }

    handleReload = () => {
        window.location.reload();
    };

    handleReset = () => {
        this.setState({ error: null });
    };

    render() {
        const { error } = this.state;

        if (!error) {
            return this.props.children;
        }

        // Ekstrak pesan inti (mis. "Ziggy error: route 'x' is not in the route list.")
        const rawMessage = error?.message || String(error);
        const isZiggy = /Ziggy/i.test(rawMessage);
        const friendlyMessage = isZiggy
            ? 'Ada nama route yang tidak dikenali pada menu atau halaman ini. Periksa kembali nama route di komponen navigasi.'
            : rawMessage;

        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
                <div className="w-full max-w-lg rounded-xl border border-red-200 bg-white p-6 shadow-sm">
                    <div className="flex items-start gap-4">
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                className="h-5 w-5"
                                aria-hidden="true"
                            >
                                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />
                                <path d="M12 9v4" />
                                <path d="M12 17h.01" />
                            </svg>
                        </div>
                        <div className="min-w-0">
                            <h1 className="text-base font-semibold text-slate-900">
                                Terjadi kesalahan saat menampilkan halaman
                            </h1>
                            <p className="mt-1 text-sm text-slate-600">
                                {friendlyMessage}
                            </p>
                            {import.meta.env.DEV && (
                                <pre className="mt-3 max-h-40 overflow-auto rounded-lg bg-slate-100 p-3 text-xs leading-relaxed text-slate-700">
                                    {rawMessage}
                                </pre>
                            )}
                        </div>
                    </div>

                    <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={this.handleReload}
                            className="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 active:scale-95"
                        >
                            Muat Ulang Halaman
                        </button>
                        <button
                            type="button"
                            onClick={this.handleReset}
                            className="inline-flex justify-center rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white shadow-xs transition-colors hover:bg-slate-700 active:scale-95"
                        >
                            Coba Tampilkan Kembali
                        </button>
                    </div>
                </div>
            </div>
        );
    }
}
