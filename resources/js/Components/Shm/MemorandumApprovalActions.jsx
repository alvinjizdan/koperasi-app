import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import {
    Send,
    Play,
    CheckCircle2,
    XCircle,
    RotateCcw,
    AlertTriangle,
} from 'lucide-react';

export default function MemorandumApprovalActions({
    memorandum,
    can_submit,
    can_start_review,
    can_approve,
    can_reject,
}) {
    const [confirmModal, setConfirmModal] = useState({
        open: false,
        action: null, // 'submit' | 'start-review' | 'approve'
        title: '',
        message: '',
        notes: '',
    });

    const [rejectModal, setRejectModal] = useState({
        open: false,
        reason: '',
        error: '',
    });

    const [processing, setProcessing] = useState(false);

    const handleOpenConfirm = (action, title, message) => {
        setConfirmModal({
            open: true,
            action,
            title,
            message,
            notes: '',
        });
    };

    const handleCloseConfirm = () => {
        setConfirmModal({
            open: false,
            action: null,
            title: '',
            message: '',
            notes: '',
        });
    };

    const handleExecuteConfirm = (e) => {
        e.preventDefault();
        setProcessing(true);

        const routeName = `shm.memorandums.${confirmModal.action}`;
        router.post(
            route(routeName, memorandum.id),
            { notes: confirmModal.notes },
            {
                onFinish: () => {
                    setProcessing(false);
                    handleCloseConfirm();
                },
            }
        );
    };

    const handleOpenReject = () => {
        setRejectModal({
            open: true,
            reason: '',
            error: '',
        });
    };

    const handleCloseReject = () => {
        setRejectModal({
            open: false,
            reason: '',
            error: '',
        });
    };

    const handleExecuteReject = (e) => {
        e.preventDefault();
        if (!rejectModal.reason || rejectModal.reason.trim().length < 3) {
            setRejectModal((prev) => ({
                ...prev,
                error: 'Alasan penolakan wajib diisi minimal 3 karakter.',
            }));
            return;
        }

        setProcessing(true);
        router.post(
            route('shm.memorandums.reject', memorandum.id),
            { reason: rejectModal.reason },
            {
                onError: (errors) => {
                    setRejectModal((prev) => ({
                        ...prev,
                        error: errors.reason || 'Terjadi kesalahan saat memproses penolakan.',
                    }));
                },
                onFinish: () => {
                    setProcessing(false);
                    handleCloseReject();
                },
            }
        );
    };

    const hasAnyAction = can_submit || can_start_review || can_approve || can_reject;
    if (!hasAnyAction) return null;

    return (
        <>
            <div className="flex flex-wrap items-center gap-2">
                {/* 1. Tombol Submit (Draft -> Submitted) */}
                {can_submit && (
                    <PrimaryButton
                        onClick={() =>
                            handleOpenConfirm(
                                'submit',
                                memorandum.has_been_rejected ? 'Ajukan Ulang Memorandum' : 'Ajukan Memorandum',
                                memorandum.has_been_rejected
                                    ? 'Apakah Anda yakin ingin mengajukan ulang memorandum ini setelah perbaikan?'
                                    : 'Apakah Anda yakin ingin mengajukan memorandum ini untuk proses approval?'
                            )
                        }
                        className="inline-flex items-center gap-1.5 text-xs bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800"
                        disabled={processing}
                    >
                        {memorandum.has_been_rejected ? (
                            <RotateCcw className="h-3.5 w-3.5" />
                        ) : (
                            <Send className="h-3.5 w-3.5" />
                        )}
                        {memorandum.has_been_rejected ? 'Ajukan Ulang' : 'Ajukan Approval'}
                    </PrimaryButton>
                )}

                {/* 2. Tombol Start Review (Submitted -> Under Review) */}
                {can_start_review && (
                    <PrimaryButton
                        onClick={() =>
                            handleOpenConfirm(
                                'start-review',
                                'Mulai Pemeriksaan (Review)',
                                'Apakah Anda yakin ingin memulai pemeriksaan/review untuk memorandum ini?'
                            )
                        }
                        className="inline-flex items-center gap-1.5 text-xs bg-amber-600 hover:bg-amber-700 active:bg-amber-800"
                        disabled={processing}
                    >
                        <Play className="h-3.5 w-3.5" />
                        Mulai Review
                    </PrimaryButton>
                )}

                {/* 3. Tombol Approve (Under Review -> Approved) */}
                {can_approve && (
                    <PrimaryButton
                        onClick={() =>
                            handleOpenConfirm(
                                'approve',
                                'Setujui Memorandum',
                                'Apakah Anda yakin ingin menyetujui (Approve) memorandum ini? Tindakan ini bersifat final.'
                            )
                        }
                        className="inline-flex items-center gap-1.5 text-xs bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800"
                        disabled={processing}
                    >
                        <CheckCircle2 className="h-3.5 w-3.5" />
                        Setujui (Approve)
                    </PrimaryButton>
                )}

                {/* 4. Tombol Reject (Under Review -> Draft) */}
                {can_reject && (
                    <DangerButton
                        onClick={handleOpenReject}
                        className="inline-flex items-center gap-1.5 text-xs"
                        disabled={processing}
                    >
                        <XCircle className="h-3.5 w-3.5" />
                        Tolak (Reject)
                    </DangerButton>
                )}
            </div>

            {/* Modal Konfirmasi Tindakan (Submit, Start Review, Approve) */}
            <Modal show={confirmModal.open} onClose={handleCloseConfirm} maxWidth="md">
                <form onSubmit={handleExecuteConfirm} className="p-6">
                    <h3 className="text-base font-semibold text-slate-800 flex items-center gap-2">
                        {confirmModal.action === 'approve' ? (
                            <CheckCircle2 className="h-5 w-5 text-emerald-600" />
                        ) : confirmModal.action === 'start-review' ? (
                            <Play className="h-5 w-5 text-amber-600" />
                        ) : (
                            <Send className="h-5 w-5 text-indigo-600" />
                        )}
                        {confirmModal.title}
                    </h3>

                    <p className="mt-2 text-sm text-slate-600 leading-relaxed">
                        {confirmModal.message}
                    </p>

                    <div className="mt-4">
                        <InputLabel htmlFor="confirm_notes" value="Catatan Tambahan (Opsional)" className="text-xs" />
                        <textarea
                            id="confirm_notes"
                            value={confirmModal.notes}
                            onChange={(e) =>
                                setConfirmModal((prev) => ({ ...prev, notes: e.target.value }))
                            }
                            rows={3}
                            className="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="Tulis catatan jika diperlukan..."
                        />
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={handleCloseConfirm} disabled={processing} className="text-xs">
                            Batal
                        </SecondaryButton>
                        <PrimaryButton
                            type="submit"
                            disabled={processing}
                            className={`text-xs ${
                                confirmModal.action === 'approve'
                                    ? 'bg-emerald-600 hover:bg-emerald-700'
                                    : confirmModal.action === 'start-review'
                                    ? 'bg-amber-600 hover:bg-amber-700'
                                    : 'bg-indigo-600 hover:bg-indigo-700'
                            }`}
                        >
                            {processing ? 'Memproses...' : 'Lanjutkan'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            {/* Modal Penolakan (Reject) — Alasan Wajib */}
            <Modal show={rejectModal.open} onClose={handleCloseReject} maxWidth="md">
                <form onSubmit={handleExecuteReject} className="p-6">
                    <h3 className="text-base font-semibold text-rose-700 flex items-center gap-2">
                        <AlertTriangle className="h-5 w-5 text-rose-600" />
                        Tolak Memorandum
                    </h3>

                    <p className="mt-2 text-sm text-slate-600 leading-relaxed">
                        Memorandum akan dikembalikan ke status <strong>Draft</strong> untuk diperbaiki oleh pengaju.
                        Mohon sertakan alasan penolakan secara jelas.
                    </p>

                    <div className="mt-4">
                        <InputLabel htmlFor="reject_reason" value="Alasan Penolakan (Wajib)" className="text-xs font-semibold" />
                        <textarea
                            id="reject_reason"
                            value={rejectModal.reason}
                            onChange={(e) =>
                                setRejectModal((prev) => ({
                                    ...prev,
                                    reason: e.target.value,
                                    error: '',
                                }))
                            }
                            rows={4}
                            required
                            className="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm focus:border-rose-500 focus:ring-rose-500"
                            placeholder="Contoh: Klausul masa berlaku SHGB dan nama pemilik belum sesuai dengan sertifikat..."
                        />
                        {rejectModal.error && (
                            <InputError message={rejectModal.error} className="mt-1 text-xs" />
                        )}
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton onClick={handleCloseReject} disabled={processing} className="text-xs">
                            Batal
                        </SecondaryButton>
                        <DangerButton type="submit" disabled={processing} className="text-xs">
                            {processing ? 'Menyimpan...' : 'Konfirmasi Penolakan'}
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </>
    );
}

