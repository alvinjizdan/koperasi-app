import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0">
            <div>
                <Link href="/" className="flex flex-col items-center gap-2">
                    <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-white p-2 shadow-sm border border-slate-200">
                        <ApplicationLogo className="h-full w-full" />
                    </div>
                    <span className="text-base font-semibold tracking-tight text-slate-800">
                        BSM Mikro
                    </span>
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg">
                {children}
            </div>
        </div>
    );
}
