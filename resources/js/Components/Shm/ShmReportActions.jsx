import React from 'react';
import { FileSpreadsheet } from 'lucide-react';

export default function ShmReportActions({
    exportRoute,
    exportParams = {},
}) {
    const handleExport = () => {
        window.location.href = route(exportRoute, exportParams);
    };

    return (
        <div className="flex items-center gap-2">
            {exportRoute && (
                <button
                    type="button"
                    onClick={handleExport}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs transition-colors"
                >
                    <FileSpreadsheet className="h-4 w-4 text-emerald-600" />
                    <span>Export XLSX</span>
                </button>
            )}
        </div>
    );
}
