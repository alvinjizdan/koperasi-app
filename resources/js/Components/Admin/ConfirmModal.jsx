import React, { useEffect } from 'react';
import { AlertTriangle, X } from 'lucide-react';

export default function ConfirmModal({
    isOpen,
    onClose,
    onConfirm,
    title = 'Konfirmasi Tindakan',
    message = 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
    confirmText = 'Ya, Lanjutkan',
    cancelText = 'Batal',
    isProcessing = false,
}) {
    useEffect(() => {
        const handleKeyDown = (e) => {
            if (e.key === 'Escape' && isOpen && !isProcessing) {
                onClose();
            }
        };

        if (isOpen) {
            document.body.style.overflow = 'hidden';
            window.addEventListener('keydown', handleKeyDown);
        }

        return () => {
            document.body.style.overflow = 'unset';
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [isOpen, isProcessing, onClose]);

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
            {/* Backdrop */}
            <div
                className="fixed inset-0 bg-slate-900/60 backdrop-blur-xs transition-opacity"
                onClick={() => {
                    if (!isProcessing) onClose();
                }}
            />

            {/* Modal Box */}
            <div className="relative w-full max-w-md transform overflow-hidden rounded-xl bg-white p-6 text-left shadow-xl transition-all border border-slate-200">
                <button
                    type="button"
                    onClick={onClose}
                    disabled={isProcessing}
                    className="absolute top-4 right-4 text-slate-400 hover:text-slate-600 disabled:opacity-50 p-1 rounded-lg hover:bg-slate-100 transition-colors"
                >
                    <X className="h-5 w-5" />
                </button>

                <div className="flex items-start gap-4">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 sm:h-12 sm:w-12">
                        <AlertTriangle className="h-6 w-6" />
                    </div>

                    <div>
                        <h3 className="text-base font-semibold text-slate-900">
                            {title}
                        </h3>
                        <p className="mt-1 text-xs sm:text-sm text-slate-500">
                            {message}
                        </p>
                    </div>
                </div>

                <div className="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={isProcessing}
                        className="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 transition-colors active:scale-95"
                    >
                        {cancelText}
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={isProcessing}
                        className="inline-flex justify-center items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-xs sm:text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-50 transition-colors active:scale-95 shadow-xs"
                    >
                        {isProcessing ? 'Memproses...' : confirmText}
                    </button>
                </div>
            </div>
        </div>
    );
}
