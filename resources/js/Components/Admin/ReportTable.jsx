import React from "react";
import { Link } from "@inertiajs/react";
import EmptyState from "@/Components/Admin/EmptyState";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import { ChevronLeft, ChevronRight, Table2 } from "lucide-react";

const ALIGNMENTS = {
    left: "text-left",
    right: "text-right",
    center: "text-center",
};

/**
 * Tabel laporan bersama dengan empty state dan pagination server-side.
 *
 * Kolom didefinisikan sebagai { key, label, align, className, render }.
 */
export default function ReportTable({
    columns,
    rows,
    emptyIcon: EmptyIcon = Table2,
    emptyTitle = "Tidak ada data",
    emptyDescription = "Ubah filter untuk melihat data lainnya.",
    pagination = null,
}) {
    if (!rows || rows.length === 0) {
        return (
            <EmptyState
                icon={EmptyIcon}
                title={emptyTitle}
                description={emptyDescription}
            />
        );
    }

    return (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
            <ScrollArea className="w-full">
                <ScrollBar orientation="horizontal" />
                <table className="min-w-full divide-y divide-slate-200">
                    <thead className="bg-slate-50">
                        <tr>
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    scope="col"
                                    className={`whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500 ${
                                        ALIGNMENTS[column.align || "left"]
                                    }`}
                                >
                                    {column.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((row, index) => (
                            <tr
                                key={row.id ?? index}
                                className="transition-colors hover:bg-slate-50"
                            >
                                {columns.map((column) => (
                                    <td
                                        key={column.key}
                                        className={`px-4 py-3 text-sm ${
                                            ALIGNMENTS[column.align || "left"]
                                        } ${column.className || "text-slate-700"}`}
                                    >
                                        {column.render
                                            ? column.render(row)
                                            : (row[column.key] ?? "-")}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </ScrollArea>

            {pagination && pagination.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                    <p className="text-sm text-slate-500">
                        Menampilkan {pagination.from}–{pagination.to} dari{" "}
                        {pagination.total} baris
                    </p>
                    <div className="flex gap-1">
                        {pagination.prev_page_url && (
                            <Link
                                href={pagination.prev_page_url}
                                className="rounded p-1.5 text-slate-500 transition-colors hover:bg-slate-100"
                                aria-label="Halaman sebelumnya"
                            >
                                <ChevronLeft className="h-4 w-4" />
                            </Link>
                        )}
                        <span className="px-2 py-1 text-xs text-slate-500">
                            {pagination.current_page} / {pagination.last_page}
                        </span>
                        {pagination.next_page_url && (
                            <Link
                                href={pagination.next_page_url}
                                className="rounded p-1.5 text-slate-500 transition-colors hover:bg-slate-100"
                                aria-label="Halaman berikutnya"
                            >
                                <ChevronRight className="h-4 w-4" />
                            </Link>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
