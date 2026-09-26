import React from 'react';
import { Inbox } from 'lucide-react';

export default function EmptyState({
    title = 'Belum Ada Data Available',
    description = 'Data transaksi dan aktivitas terbaru akan tampil otomatis setelah modul bisnis diaktifkan.',
    icon: Icon = Inbox,
    action = null,
}) {
    return (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50/50 px-6 py-10 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500 mb-3">
                <Icon className="h-6 w-6" />
            </div>
            <h4 className="text-sm font-semibold text-slate-900">{title}</h4>
            <p className="mt-1 text-xs text-slate-500 max-w-sm">
                {description}
            </p>
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
