import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { ScrollArea, ScrollBar } from '@/Components/ui/scroll-area';
import EmptyState from '@/Components/Admin/EmptyState';
import { NotebookPen, PlusCircle } from 'lucide-react';

const STATUS_STYLES = {
    DRAFT: 'bg-slate-100 text-slate-600 border-slate-200',
    POSTED: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    REVERSED: 'bg-rose-50 text-rose-700 border-rose-200',
};

export default function Index({ entries }) {
    return (
        <AdminLayout title="Jurnal Umum" pageTitle="Akuntansi — Jurnal Umum">
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-slate-900">Jurnal Umum</h2>
                    <p className="mt-1 text-xs sm:text-sm text-slate-500">
                        Hanya jurnal berstatus POSTED yang memengaruhi buku besar. Jurnal DRAFT dapat diubah;
                        jurnal POSTED dikoreksi melalui reversal.
                    </p>
                </div>
                <Link
                    href={route('akuntansi.jurnal.create')}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-slate-800 transition-colors active:scale-95 shadow-xs"
                >
                    <PlusCircle className="h-4 w-4" />
                    <span>Buat Jurnal</span>
                </Link>
            </div>

            {entries.length === 0 ? (
                <EmptyState
                    icon={NotebookPen}
                    title="Belum Ada Jurnal"
                    description="Buat jurnal DRAFT pertama, lengkapi baris debit/kredit yang seimbang, lalu posting ke buku besar."
                />
            ) : (
                <ScrollArea className="rounded-xl border border-slate-200 bg-white shadow-xs">
<ScrollBar orientation="horizontal" />
                    <table className="min-w-full divide-y divide-slate-200 text-xs sm:text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Nomor Jurnal</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Tanggal</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Deskripsi</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Baris</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Sumber</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-500">Diposting</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {entries.map((entry) => (
                                <tr key={entry.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-mono font-semibold text-slate-900">
                                        <Link href={route('akuntansi.jurnal.show', entry.id)} className="hover:text-emerald-700 hover:underline">
                                            {entry.journal_number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-slate-600">{entry.transaction_date}</td>
                                    <td className="px-4 py-3 text-slate-600">{entry.description ?? '-'}</td>
                                    <td className="px-4 py-3 text-slate-600">{entry.line_count}</td>
                                    <td className="px-4 py-3 text-slate-500">
                                        {entry.source_type
                                            ? `${entry.source_type.split('\\').pop()}#${entry.source_id}`
                                            : 'manual'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={`rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${STATUS_STYLES[entry.status] ?? 'bg-slate-100 text-slate-600 border-slate-200'}`}>
                                            {entry.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-slate-500">{entry.posted_by ? `${entry.posted_by} · ${entry.posted_at}` : '-'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </ScrollArea>
            )}
        </AdminLayout>
    );
}
