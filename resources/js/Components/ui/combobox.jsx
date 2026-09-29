import * as React from "react";
import * as ComboboxPrimitive from "@base-ui/react";
import { ChevronDown, X } from "lucide-react";
import { cn } from "@/lib/cn";
import { ScrollArea } from "@/Components/ui/scroll-area";

/**
 * Combobox berbasis Base UI mengikuti pola komponen ui shadcn (base-nova).
 *
 * Pemakaian:
 *   <Combobox items={items}>
 *     <ComboboxInput placeholder="Pilih..." />
 *     <ComboboxContent>
 *       <ComboboxEmpty>Tidak ada hasil.</ComboboxEmpty>
 *       <ComboboxList>
 *         {(item) => <ComboboxItem key={item} value={item}>{item}</ComboboxItem>}
 *       </ComboboxList>
 *     </ComboboxContent>
 *   </Combobox>
 *
 * Multi-select: `multiple` + <ComboboxChips><ComboboxValue>...</ComboboxValue>
 * <ComboboxChipsInput /></ComboboxChips> menggantikan <ComboboxInput>.
 */

/**
 * Helper untuk item berbentuk { value, label }: memetakan ke collection Base UI
 * agar input menampilkan LABEL (bukan value), autocomplete mencocokkan LABEL,
 * dan state form tetap menyimpan VALUE.
 *
 *   const col = toLabeledCollection(CATEGORIES);
 *   <Combobox items={col} value={data.category} ...>
 *
 * Untuk daftar string polos, cukup kirim array-nya langsung ke `items`.
 */
export function toLabeledCollection(options) {
    const data = options.map((o) =>
        typeof o === "string" ? { value: o, label: o } : o,
    );
    return ComboboxPrimitive.Combobox.createItems(data, {
        getValue: (item) => item.value,
        getLabel: (item) => item.label,
    });
}

const inputBase =
    "w-full rounded-md border border-slate-300 bg-white py-2 text-sm text-slate-900 shadow-sm transition-colors focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 data-[invalid]:border-rose-400 disabled:cursor-not-allowed disabled:bg-slate-50";
const inputPadding = "ps-3 pe-8";

function Combobox({
    multiple,
    itemToStringValue,
    className,
    children,
    ...props
}) {
    // Kenyamanan: jika pemakai hanya memberi itemToStringValue (value -> label),
    // pakai juga sebagai itemToStringLabel agar input menampilkan LABEL bukan value mentah.
    const { itemToStringLabel, ...rest } = props;
    return (
        <ComboboxPrimitive.Combobox.Root
            multiple={multiple}
            itemToStringValue={itemToStringValue}
            itemToStringLabel={itemToStringLabel ?? itemToStringValue}
            className={cn("relative w-full", className)}
            {...rest}
        >
            {children}
        </ComboboxPrimitive.Combobox.Root>
    );
}

function ComboboxInput({ className, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.Input
            className={(state) =>
                cn(
                    inputBase,
                    inputPadding,
                    typeof className === "function"
                        ? className(state)
                        : className,
                )
            }
            {...props}
        />
    );
}

function ComboboxTrigger({ className, children, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.Trigger
            className={(state) =>
                cn(
                    inputBase,
                    inputPadding,
                    "flex items-center justify-between text-start",
                    typeof className === "function"
                        ? className(state)
                        : className,
                )
            }
            {...props}
        >
            {children}
            <ComboboxPrimitive.Combobox.Icon className="pointer-events-none absolute end-3 text-slate-400">
                <ChevronDown size={16} />
            </ComboboxPrimitive.Combobox.Icon>
        </ComboboxPrimitive.Combobox.Trigger>
    );
}

function ComboboxContent({
    className,
    sideOffset = 4,
    align = "start",
    children,
    ...props
}) {
    return (
        <ComboboxPrimitive.Combobox.Portal>
            <ComboboxPrimitive.Combobox.Positioner
                sideOffset={sideOffset}
                align={align}
                className="z-50 outline-none"
            >
                <ComboboxPrimitive.Combobox.Popup
                    className={(state) =>
                        cn(
                            "min-w-[var(--anchor-width)] max-w-[var(--available-width)] rounded-md border border-slate-200 bg-white shadow-lg outline-none data-[closed]:opacity-0 data-[open]:opacity-100 transition-opacity",
                            typeof className === "function"
                                ? className(state)
                                : className,
                        )
                    }
                    {...props}
                >
                    <ScrollArea className="max-h-80">
                        {children}
                    </ScrollArea>
                </ComboboxPrimitive.Combobox.Popup>
            </ComboboxPrimitive.Combobox.Positioner>
        </ComboboxPrimitive.Combobox.Portal>
    );
}

function ComboboxEmpty({ className, children = "Tidak ada hasil.", ...props }) {
    return (
        <ComboboxPrimitive.Combobox.Empty
            className={cn("px-3 py-2 text-sm text-slate-500", className)}
            {...props}
        >
            {children}
        </ComboboxPrimitive.Combobox.Empty>
    );
}

function ComboboxList({ className, children, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.List
            className={cn("scroll-py-1 outline-none", className)}
            {...props}
        >
            {children}
        </ComboboxPrimitive.Combobox.List>
    );
}

function ComboboxItem({ className, children, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.Item
            className={(state) =>
                cn(
                    "flex cursor-default select-none items-center justify-between gap-2 rounded-sm px-3 py-1.5 text-sm text-slate-900 outline-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[highlighted]:bg-indigo-50 data-[highlighted]:text-indigo-900 data-[selected]:font-medium",
                    typeof className === "function"
                        ? className(state)
                        : className,
                )
            }
            {...props}
        >
            {children}
            <ComboboxPrimitive.Combobox.ItemIndicator className="text-indigo-600">
                <svg
                    width="14"
                    height="14"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    aria-hidden="true"
                >
                    <path d="M20 6 9 17l-5-5" />
                </svg>
            </ComboboxPrimitive.Combobox.ItemIndicator>
        </ComboboxPrimitive.Combobox.Item>
    );
}

function ComboboxGroup({ className, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.Group className={className} {...props} />
    );
}

function ComboboxGroupLabel({ className, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.GroupLabel
            className={cn(
                "px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500",
                className,
            )}
            {...props}
        />
    );
}

function ComboboxCollection(props) {
    return <ComboboxPrimitive.Combobox.Collection {...props} />;
}

function ComboboxSeparator({ className, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.Separator
            className={cn("-mx-1 my-1 h-px bg-slate-100", className)}
            {...props}
        />
    );
}

function ComboboxStatus({ className, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.Status
            className={cn("px-3 py-1.5 text-xs text-slate-400", className)}
            {...props}
        />
    );
}

function ComboboxChips({ className, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.Chips
            className={cn(
                "flex min-h-10 w-full flex-wrap items-center gap-1 rounded-md border border-slate-300 bg-white p-1 shadow-sm focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500",
                className,
            )}
            {...props}
        />
    );
}

function ComboboxChipsInput({ className, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.ChipsInput
            className={cn(
                "min-w-16 flex-1 border-none bg-transparent px-2 py-0.5 text-sm outline-none",
                className,
            )}
            {...props}
        />
    );
}

function ComboboxValue(props) {
    return <ComboboxPrimitive.Combobox.Value {...props} />;
}

function ComboboxChip({ className, children, ...props }) {
    return (
        <ComboboxPrimitive.Combobox.Chip
            className={cn(
                "flex items-center gap-1 rounded bg-indigo-50 py-0.5 ps-2 pe-1 text-xs font-medium text-indigo-700",
                className,
            )}
            {...props}
        >
            {children}
            <ComboboxPrimitive.Combobox.ChipRemove
                aria-label="Hapus"
                className="rounded-sm p-0.5 text-indigo-400 transition-colors hover:bg-indigo-100 hover:text-indigo-700"
            >
                <X size={12} />
            </ComboboxPrimitive.Combobox.ChipRemove>
        </ComboboxPrimitive.Combobox.Chip>
    );
}

export {
    Combobox,
    ComboboxInput,
    ComboboxTrigger,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxList,
    ComboboxItem,
    ComboboxGroup,
    ComboboxGroupLabel,
    ComboboxCollection,
    ComboboxSeparator,
    ComboboxStatus,
    ComboboxChips,
    ComboboxChipsInput,
    ComboboxValue,
    ComboboxChip,
};
