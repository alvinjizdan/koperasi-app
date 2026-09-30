import React from "react";
import { Link } from "@inertiajs/react";
import EmptyState from "@/Components/Admin/EmptyState";
import TableSortButton from "@/Components/Admin/TableSortButton";
import TablePagination from "@/Components/Admin/TablePagination";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import { Table2 } from "lucide-react";

const ALIGNMENTS = {
    left: "text-left",
    right: "text-right",
    center: "text-center",
};

/**
 * Tabel laporan bersama dengan empty state, header sortable, dan pagination
 * server-side.
 *
 * Kolom didefinisikan sebagai { key, label, align, className, render, sortable }.
 * Ketika `sort` (status dari server) dan `onSort` disediakan, kolom dengan
 * `sortable !== false` dirender sebagai tombol pengurut; kolom lain tetap
 * teks biasa.
 */
export default function ReportTable({
    columns,
    rows,
    emptyIcon: EmptyIcon = Table2,
    emptyTitle = "Tidak ada data",
    emptyDescription = "Ubah filter untuk melihat data lainnya.",
    pagination = null,
    sort = null,
    onSort = null,
    routeName = null,
    routeParams = [],
}) {
    const renderHeader = (column) => {
        if (sort && onSort && column.sortable !== false) {
            return (
                <TableSortButton
                    column={column.key}
                    sort={sort?.key}
                    direction={sort?.direction}
                    onSort={onSort}
                >
                    {column.label}
                </TableSortButton>
            );
        }

        return column.label;
    };

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
                                    className={`whitespace-nowrap px-4 py-3 ${
                                        ALIGNMENTS[column.align || "left"]
                                    }`}
                                >
                                    {renderHeader(column)}
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

            {pagination && (
                <TablePagination
                    paginator={pagination}
                    itemLabel="baris"
                    routeName={routeName}
                    routeParams={routeParams}
                />
            )}
        </div>
    );
}
