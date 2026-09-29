import * as React from "react";
import * as ScrollAreaPrimitive from "@base-ui/react";
import { cn } from "@/lib/cn";

/**
 * ScrollArea berbasis Base UI mengikuti pola komponen ui shadcn (base-nova).
 *
 * Pemakaian:
 *   <ScrollArea className="h-[200px] w-[350px] rounded-md border p-4">
 *     Konten yang dapat di-scroll.
 *   </ScrollArea>
 *
 * Scroll horizontal: tambahkan <ScrollBar orientation="horizontal" /> di dalam ScrollArea.
 */

function ScrollArea({ className, children, ...props }) {
    return (
        <ScrollAreaPrimitive.ScrollArea.Root
            className={cn("relative overflow-hidden", className)}
            {...props}
        >
            <ScrollAreaPrimitive.ScrollArea.Viewport className="size-full outline-none">
                {children}
            </ScrollAreaPrimitive.ScrollArea.Viewport>
            <ScrollBar />
            <ScrollAreaPrimitive.ScrollArea.Corner />
        </ScrollAreaPrimitive.ScrollArea.Root>
    );
}

function ScrollBar({ orientation = "vertical", className, ...props }) {
    return (
        <ScrollAreaPrimitive.ScrollArea.Scrollbar
            orientation={orientation}
            className={cn(
                "flex touch-none select-none rounded-full bg-slate-300/60 transition-colors hover:bg-slate-400/70 data-[scrolling]:bg-slate-400/70",
                orientation === "vertical"
                    ? "w-2 border-l border-l-transparent p-[2px]"
                    : "h-2 flex-col border-t border-t-transparent p-[2px]",
                className,
            )}
            {...props}
        >
            <ScrollAreaPrimitive.ScrollArea.Thumb className="relative flex-1 rounded-full bg-slate-500/70" />
        </ScrollAreaPrimitive.ScrollArea.Scrollbar>
    );
}

export { ScrollArea, ScrollBar };
