import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { ShieldAlert, Loader2 } from 'lucide-react';

/**
 * Modal & Action Button untuk Otorisasi Completion Bypass (Ticket CC-11 D-01).
 *
 * Hanya berhak diakses oleh:
 * - Presiden Direktur (Direktur Utama Makro) sebagai otoritas bisnis.
 * - Superadmin sebagai akses teknis administrasi / troubleshooting.
 */
export default function CompletionBypassModal({
    memorandum,
    creditCheck,
    activeException,
    canBypassCompletion,
}) {
    const [open, setOpen] = useState(false);
    const [internalJustification, setInternalJustification] = useState('');
    const [officialDisclosureSummary, setOfficialDisclosureSummary] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    // Kondisi visibilitas tombol:
    // 1. Pengguna berwenang (canBypassCompletion === true).
    // 2. Credit check belum completed (atau belum ada).
    // 3. Belum ada exception aktif yang dapat digunakan.
    const isCcCompleted = creditCheck?.status === 'completed';
    const hasActiveException = Boolean(
        activeException && (activeException.status === 'authorized' || activeException.exception_number)
    );

    const shouldShowButton = canBypassCompletion && !isCcCompleted && !hasActiveException;

    if (!shouldShowButton) {
        return null;
    }

    const handleOpen = () => {
        setInternalJustification('');
        setOfficialDisclosureSummary('');
        setErrors({});
        setOpen(true);
    };

    const handleClose = () => {
        if (processing) return;
        setOpen(false);
        setErrors({});
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        // Validasi frontend
        const newErrors = {};
        if (internalJustification.trim().length < 20) {
            newErrors.internal_justification = 'Justifikasi internal rahasia wajib diisi minimal 20 karakter.';
        }
        if (officialDisclosureSummary.trim().length === 0) {
            newErrors.official_disclosure_summary = 'Ringkasan keterbukaan resmi wajib diisi.';
        }

        if (Object.keys(newErrors).length > 0) {
            setErrors(newErrors);
            return;
        }

        setProcessing(true);
        router.post(
            route('shm.memorandums.completion-bypass', memorandum.id),
            {
                internal_justification: internalJustification,
                official_disclosure_summary: officialDisclosureSummary,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setProcessing(false);
                    setOpen(false);
                },
                onError: (errs) => {
                    setProcessing(false);
                    setErrors(errs);
                },
            }
        );
    };

    return (
        <>
            <button
                type="button"
                onClick={handleOpen}
                className="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 shadow-xs transition-colors hover:bg-amber-100 active:scale-95"
            >
                <ShieldAlert className="h-3.5 w-3.5 text-amber-600" />
                Beri Dispensasi Completion Bypass
            </button>

            <Modal show={open} onClose={handleClose} maxWidth="lg">
                <form onSubmit={handleSubmit} className="p-6">
                    <div className="flex items-center gap-2 border-b border-slate-100 pb-3">
                        <ShieldAlert className="h-5 w-5 text-amber-600" />
                        <h3 className="text-base font-semibold text-slate-800">
                            Otorisasi Dispensasi: Completion Bypass
                        </h3>
                    </div>

                    <p className="mt-3 text-xs leading-relaxed text-slate-600">
                        Dispensasi ini memberikan pengecualian khusus bagi pengajuan pembiayaan untuk dapat
                        melanjutkan proses pengajuan Memorandum meskipun Credit Checking belum berstatus Completed.
                        Masa berlaku dispensasi adalah <strong>14 hari kalender</strong> sejak diterbitkan.
                    </p>

                    <div className="mt-4 space-y-4 text-xs">
                        {/* 1. Justifikasi Internal Rahasia */}
                        <div>
                            <InputLabel
                                htmlFor="internal_justification"
                                value="A. Justifikasi Internal (Rahasia Direksi/Sistem — Min. 20 Karakter)"
                                className="text-xs font-semibold text-slate-700"
                            />
                            <textarea
                                id="internal_justification"
                                rows={3}
                                value={internalJustification}
                                onChange={(e) => {
                                    setInternalJustification(e.target.value);
                                    if (errors.internal_justification) {
                                        setErrors((prev) => ({ ...prev, internal_justification: undefined }));
                                    }
                                }}
                                disabled={processing}
                                placeholder="Jelaskan alasan internal/teknis mengapa dispensasi ini diberikan (hanya dapat dibaca oleh pemegang otoritas)..."
                                className="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-xs focus:border-amber-500 focus:ring-amber-500 disabled:opacity-50"
                            />
                            <p className="mt-1 text-[11px] text-slate-400">
                                Bersifat rahasia internal. Tidak akan ditampilkan pada informasi publik atau dokumen memorandum nasabah.
                            </p>
                            {errors.internal_justification && (
                                <InputError message={errors.internal_justification} className="mt-1" />
                            )}
                        </div>

                        {/* 2. Ringkasan Keterbukaan Resmi */}
                        <div>
                            <InputLabel
                                htmlFor="official_disclosure_summary"
                                value="B. Ringkasan Keterbukaan Resmi (Ditampilkan pada Naskah Memorandum)"
                                className="text-xs font-semibold text-slate-700"
                            />
                            <textarea
                                id="official_disclosure_summary"
                                rows={2}
                                value={officialDisclosureSummary}
                                onChange={(e) => {
                                    setOfficialDisclosureSummary(e.target.value);
                                    if (errors.official_disclosure_summary) {
                                        setErrors((prev) => ({ ...prev, official_disclosure_summary: undefined }));
                                    }
                                }}
                                disabled={processing}
                                placeholder="Tuliskan ringkasan resmi dispensasi yang akan tercantum pada naskah memorandum pembiayaan..."
                                className="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-xs focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-50"
                            />
                            {errors.official_disclosure_summary && (
                                <InputError message={errors.official_disclosure_summary} className="mt-1" />
                            )}
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-2 border-t border-slate-100 pt-3">
                        <SecondaryButton onClick={handleClose} disabled={processing} className="text-xs">
                            Batal
                        </SecondaryButton>
                        <PrimaryButton
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-1.5 bg-amber-600 text-xs hover:bg-amber-700 active:bg-amber-800"
                        >
                            {processing ? (
                                <>
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                    Menerbitkan...
                                </>
                            ) : (
                                <>
                                    <ShieldAlert className="h-3.5 w-3.5" />
                                    Terbitkan Dispensasi
                                </>
                            )}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </>
    );
}
