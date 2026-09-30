import React, { useState } from "react";
import { Link, router } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { ScrollArea, ScrollBar } from "@/Components/ui/scroll-area";
import EmptyState from "@/Components/Admin/EmptyState";
import CashierSessionCard from "@/Components/Admin/CashierSessionCard";
import TableSortButton from "@/Components/Admin/TableSortButton";
import TablePagination from "@/Components/Admin/TablePagination";
import useTableSort from "@/hooks/useTableSort";
import {
    CheckCircle2,
    AlertCircle,
    Receipt,
    Filter,
    PlusCircle,
} from "lucide-react";
import { formatRupiah, formatDate, formatDateTime } from "@/utils/format";
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from "@/Components/ui/combobox";

const TYPE_OPTIONS = [
    { value: "all", label: "Semua Jenis" },
    { value: "INCOME", label: "Penerimaan" },
    { value: "EXPENSE", label: "Pengeluaran" },
    { value: "DEPOSIT", label: "Setoran Kasir" },
];

const STATUS_OPTIONS = [
    { value: "all", label: "Semua Status" },
    { value: "POSTED", label: "Berlaku" },
    { value: "REVERSED", label: "Dibatalkan" },
];

const CATEGORY_FILTER_OPTIONS = (categories) => [
    { value: "all", label: "Semua Kategori" },
    ...categories.map((c) => ({ value: c, label: c })),
];

const OPERATOR_OPTIONS = (operators) => [
    { value: "", label: "Semua Operator" },
    ...operators.map((o) => ({ value: String(o.id), label: o.name })),
];

export default function Index({
    transactions,
    sort,
    filters,
    category_options,
    operators,
    active_session,
    flash,
}) {
    const handleSort = useTableSort("kasir.transaksi.index");
    const [filterState, setFilterState] = useState({
        date_from: filters.date_from || "",
        date_to: filters.date_to || "",
        type: filters.type || "all",
        category: filters.category || "all",
        status: filters.status || "all",
        operator_id: filters.operator_id || "",
        session_id: filters.session_id || "",
    });

    const applyFilters = (next) => {
        setFilterState(next);
        router.get(route("kasir.transaksi.index"), next, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        applyFilters(filterState);
    };

    const handleReset = () => {
        applyFilters({
            date_from: "",
            date_to: "",
            type: "all",
            category: "all",
            status: "all",
            operator_id: "",
            session_id: "",
        });
    };

    return (
        <AdminLayout title="Transaksi Kas" pageTitle="Transaksi Kas">
            <div className="space-y-6">
                {flash?.success && (
                    <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        <AlertCircle className="h-4 w-4 flex-shrink-0" />
                        {flash.error}
                    </div>
                )}

                <CashierSessionCard session={active_session} />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-sm font-semibold text-slate-900">
                        Daftar Transaksi Kas
                    </h1>
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href={route("kasir.penerimaan.create")}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-emerald-800 active:scale-95"
                        >
                            <PlusCircle className="h-3.5 w-3.5" />
                            Penerimaan
                        </Link>
                        <Link
                            href={route("kasir.pengeluaran.create")}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-rose-700 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-rose-800 active:scale-95"
                        >
                            <PlusCircle className="h-3.5 w-3.5" />
                            Pengeluaran
                        </Link>
                        <Link
                            href={route("kasir.setoran.create")}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-blue-800 active:scale-95"
                        >
                            <PlusCircle className="h-3.5 w-3.5" />
                            Setoran
                        </Link>
                    </div>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div>
                        <label className="block text-xs font-medium text-slate-600">
                            Tanggal Mulai
                        </label>
                        <input
                            type="date"
                            value={filterState.date_from}
                            onChange={(e) =>
                                setFilterState({
                                    ...filterState,
                                    date_from: e.target.value,
                                })
                            }
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">
                            Tanggal Akhir
                        </label>
                        <input
                            type="date"
                            value={filterState.date_to}
                            onChange={(e) =>
                                setFilterState({
                                    ...filterState,
                                    date_to: e.target.value,
                                })
                            }
                            className="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">
                            Jenis Transaksi
                        </label>
                        <Combobox
                            items={TYPE_OPTIONS.map((o) => o.value)}
                            value={filterState.type}
                            onValueChange={(v) =>
                                setFilterState({
                                    ...filterState,
                                    type: v ?? "all",
                                })
                            }
                            itemToStringValue={(v) =>
                                TYPE_OPTIONS.find((o) => o.value === v)
                                    ?.label ?? v
                            }
                        >
                            <ComboboxInput aria-label="Filter jenis transaksi" />
                            <ComboboxContent>
                                <ComboboxEmpty>Tidak ada jenis.</ComboboxEmpty>
                                <ComboboxList>
                                    {(v) => (
                                        <ComboboxItem key={v} value={v}>
                                            {
                                                TYPE_OPTIONS.find(
                                                    (o) => o.value === v,
                                                )?.label
                                            }
                                        </ComboboxItem>
                                    )}
                                </ComboboxList>
                            </ComboboxContent>
                        </Combobox>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">
                            Kategori
                        </label>
                        <Combobox
                            items={CATEGORY_FILTER_OPTIONS(category_options)}
                            value={filterState.category}
                            onValueChange={(v) =>
                                setFilterState({
                                    ...filterState,
                                    category: v ?? "all",
                                })
                            }
                            itemToStringValue={(v) =>
                                v === "all"
                                    ? "Semua Kategori"
                                    : (CATEGORY_FILTER_OPTIONS(
                                          category_options,
                                      ).find((o) => o.value === v)?.label ?? v)
                            }
                        >
                            <ComboboxInput aria-label="Filter kategori" />
                            <ComboboxContent>
                                <ComboboxEmpty>
                                    Kategori tidak ditemukan.
                                </ComboboxEmpty>
                                <ComboboxList>
                                    {(option) => (
                                        <ComboboxItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </ComboboxItem>
                                    )}
                                </ComboboxList>
                            </ComboboxContent>
                        </Combobox>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">
                            Operator
                        </label>
                        <Combobox
                            items={OPERATOR_OPTIONS(operators)}
                            value={filterState.operator_id}
                            onValueChange={(v) =>
                                setFilterState({
                                    ...filterState,
                                    operator_id: v ?? "",
                                })
                            }
                            itemToStringValue={(v) =>
                                operators.find(
                                    (o) => String(o.id) === String(v),
                                )?.name ?? "Semua Operator"
                            }
                        >
                            <ComboboxInput aria-label="Filter operator" />
                            <ComboboxContent>
                                <ComboboxEmpty>
                                    Operator tidak ditemukan.
                                </ComboboxEmpty>
                                <ComboboxList>
                                    {(option) => (
                                        <ComboboxItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </ComboboxItem>
                                    )}
                                </ComboboxList>
                            </ComboboxContent>
                        </Combobox>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">
                            Status
                        </label>
                        <Combobox
                            items={STATUS_OPTIONS.map((o) => o.value)}
                            value={filterState.status}
                            onValueChange={(v) =>
                                setFilterState({
                                    ...filterState,
                                    status: v ?? "all",
                                })
                            }
                            itemToStringValue={(v) =>
                                STATUS_OPTIONS.find((o) => o.value === v)
                                    ?.label ?? v
                            }
                        >
                            <ComboboxInput aria-label="Filter status transaksi" />
                            <ComboboxContent>
                                <ComboboxEmpty>Tidak ada status.</ComboboxEmpty>
                                <ComboboxList>
                                    {(v) => (
                                        <ComboboxItem key={v} value={v}>
                                            {
                                                STATUS_OPTIONS.find(
                                                    (o) => o.value === v,
                                                )?.label
                                            }
                                        </ComboboxItem>
                                    )}
                                </ComboboxList>
                            </ComboboxContent>
                        </Combobox>
                    </div>
                    <div className="flex items-end gap-2 sm:col-span-2">
                        <button
                            type="submit"
                            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-2 text-xs font-semibold text-white transition-all hover:bg-slate-900 active:scale-95"
                        >
                            <Filter className="h-3.5 w-3.5" />
                            Terapkan Filter
                        </button>
                        <button
                            type="button"
                            onClick={handleReset}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                        >
                            Reset
                        </button>
                    </div>
                </form>

                {transactions.data.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title="Belum ada transaksi kas"
                        description="Transaksi penerimaan, pengeluaran, dan setoran akan tercatat di sini."
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                        <ScrollArea>
                            <ScrollBar orientation="horizontal" />
                            <table className="min-w-full divide-y divide-slate-200">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left">
                                            <TableSortButton column="transaction_number" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>
                                                No. Transaksi
                                            </TableSortButton>
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            <TableSortButton column="transaction_date" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>
                                                Waktu
                                            </TableSortButton>
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            <TableSortButton column="type" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>
                                                Jenis
                                            </TableSortButton>
                                        </th>
                                        <th className="px-4 py-3 text-left">
                                            <TableSortButton column="category" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>
                                                Kategori
                                            </TableSortButton>
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Operator
                                        </th>
                                        <th className="px-4 py-3 text-right">
                                            <TableSortButton column="amount" sort={sort?.key} direction={sort?.direction} onSort={handleSort}>
                                                Nominal
                                            </TableSortButton>
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Status
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Aksi
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {transactions.data.map((transaction) => (
                                        <tr
                                            key={transaction.id}
                                            className="transition-colors hover:bg-slate-50"
                                        >
                                            <td className="px-4 py-3 font-mono text-xs font-medium text-slate-900">
                                                {transaction.transaction_number}
                                                {transaction.source_label && (
                                                    <span className="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">
                                                        {
                                                            transaction.source_label
                                                        }
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-slate-500">
                                                <p>
                                                    {formatDate(
                                                        transaction.transaction_date,
                                                    )}
                                                </p>
                                                <p className="text-[11px] text-slate-400">
                                                    {formatDateTime(
                                                        transaction.transacted_at,
                                                    )}
                                                </p>
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-700">
                                                {transaction.type_label}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600">
                                                {transaction.category || "-"}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-600">
                                                {transaction.operator?.name ||
                                                    "-"}
                                            </td>
                                            <td
                                                className={`px-4 py-3 text-right text-sm font-semibold tabular-nums ${
                                                    transaction.increases_cash
                                                        ? "text-emerald-700"
                                                        : "text-rose-700"
                                                }`}
                                            >
                                                {transaction.increases_cash
                                                    ? "+"
                                                    : "-"}
                                                {formatRupiah(
                                                    transaction.amount,
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                        transaction.status ===
                                                        "POSTED"
                                                            ? "bg-emerald-100 text-emerald-800"
                                                            : "bg-slate-200 text-slate-600"
                                                    }`}
                                                >
                                                    {transaction.status ===
                                                    "POSTED"
                                                        ? "BERLAKU"
                                                        : "DIBATALKAN"}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    href={route(
                                                        "kasir.transaksi.show",
                                                        transaction.id,
                                                    )}
                                                    className="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
                                                >
                                                    Detail
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </ScrollArea>

                        <TablePagination paginator={transactions} itemLabel="transaksi" routeName="kasir.transaksi.index" />
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
