import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ShmLayout from '@/Layouts/ShmLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { formatRupiah } from '@/utils/format';
import {
    ChevronDown,
    FileSignature,
    FileText,
    Plus,
    Trash2,
} from 'lucide-react';

/**
 * Pengajuan Pinjaman SHM (Ticket 66) — SATU halaman, 11 section accordion.
 *
 * Kebijakan yang dipegang di halaman ini:
 * - Accordion murni state React lokal: membuka/menutup section TIDAK pernah
 *   melakukan request backend dan bukan navigasi route.
 * - Form disubmit sebagai SATU payload 11 section ke backend (Ticket 67)
 *   dengan validasi server-side; penyimpanan atomik dan error validasi
 *   ditampilkan kembali. Ticket 77 — foto jaminan kini diunggah persisten
 *   sebagai multipart/form-data (Inertia otomatis memakai FormData saat
 *   payload memuat berkas). Setelah berhasil, redirect ke halaman detail
 *   pengajuan.
 * - Data pribadi (Ticket 76-FIX) adalah DATA PENGAJUAN: tersimpan pada
 *   `shm_loan_applications`, bukan pada master nasabah (`shm_customers`).
 *   Pemilihan nasabah hanya menetapkan `shm_customer_id`; mengedit Data
 *   Pribadi tidak pernah mengubah master nasabah dan pengajuan berbeda untuk
 *   nasabah yang sama dapat memiliki nilai berbeda. Nasabah SHM tetap
 *   TERPISAH dari anggota koperasi (`members` tidak pernah dibaca).
 * - Rate (T82) = f(PRODUCT, TENOR) dari tier rate produk — halaman ini
 *   menurunkan tampilan rate dari props `products[].rate_tiers` (product +
 *   tenor) dan TIDAK PERNAH mengirim rate authoritative ke backend; backend
 *   resolve ulang. Universal 2% tidak dikirim/tidak ditampilkan. Tenor di
 *   luar range eksak produk (gap) ditolak backend dengan validation error.
 *   Pilihan metode angsuran tetap memakai opsi service simulasi existing.
 * - Perhitungan penghasilan/pengeluaran memakai bilangan bulat rupiah
 *   (integer arithmetic), bukan floating point sembrono. Sisa Penghasilan
 *   = total penghasilan − total pengeluaran. Saat ini sistem belum
 *   menerapkan ambang minimum penghasilan, DSCR, maupun aturan eligibility
 *   tambahan pada angka cashflow ini (CONFIRMED T100 — contract
 *   decision_domains.application_income_expense). Status fitur belum ditentukan: PENDING BUSINESS CONFIRMATION.
 */

/** 11 section halaman ini, berurutan sesuai referensi. */
const SECTIONS = [
    { key: 'pribadi', title: 'Data Pribadi' },
    { key: 'pengajuan', title: 'Pengajuan Pinjaman' },
    { key: 'cashflow', title: 'Data Penghasilan dan Pengeluaran Tiap Bulan' },
    { key: 'tambahan', title: 'Data Tambahan' },
    { key: 'jaminan', title: 'Jaminan Pinjaman' },
    { key: 'catatan', title: 'Keterangan / Catatan oleh Kasir / SC' },
    { key: 'analisa', title: 'Analisa Pinjaman' },
    { key: 'analisa-tambahan', title: 'Data Tambahan Analisa Pinjaman' },
    { key: 'kolektor', title: 'Kolektor Pinjaman' },
    { key: 'persetujuan', title: 'Persetujuan Pinjaman' },
    {
        key: 'persyaratan',
        title: 'Persyaratan Tambahan Jika Pinjaman Disetujui',
    },
];

const INCOME_FIELDS = [
    { key: 'tetap', label: 'Penghasilan Tetap' },
    { key: 'pasangan', label: 'Penghasilan Istri/Suami' },
    { key: 'usaha', label: 'Penghasilan Usaha/Lain-lain' },
];

const EXPENSE_FIELDS = [
    { key: 'potongan', label: 'Potongan Gaji/Angsuran Pinjaman' },
    { key: 'rumahTangga', label: 'Biaya Rumah Tangga' },
    { key: 'pendidikan', label: 'Biaya Pendidikan' },
    { key: 'sewa', label: 'Sewa/Kontrak Rumah' },
    { key: 'listrik', label: 'Biaya Listrik/Telepon/Air' },
    { key: 'transportasi', label: 'Transportasi' },
    { key: 'lain', label: 'Pengeluaran Lain-lain' },
];

/** Metode penyetoran/pembayaran angsuran pada form pengajuan (referensi UI). */
const DISBURSEMENT_METHODS = ['Tunai', 'Debet Tabungan', 'Bank'];

/** Keputusan approval pada referensi; keputusan sesungguhnya via modul Approval. */
const DECISION_STATUSES = ['BARU', 'DISETUJUI', 'DITANGGUHKAN', 'DITOLAK'];

const EMPTY_INCOME = { tetap: '', pasangan: '', usaha: '' };
const EMPTY_EXPENSE = {
    potongan: '',
    rumahTangga: '',
    pendidikan: '',
    sewa: '',
    listrik: '',
    transportasi: '',
    lain: '',
};

/**
 * Hanya digit yang dipertahankan untuk nilai rupiah; hasilnya dihitung sebagai
 * bilangan bulat (integer) sehingga penjumlahan finansial tidak menderu akibat
 * floating point. Batas aman bilangan bulat jauh di atas nilai rupiah normal.
 */
const toAmount = (value) => {
    const digits = String(value ?? '').replace(/[^0-9]/g, '');
    if (digits === '') return 0;
    const parsed = Number.parseInt(digits, 10);
    return Number.isSafeInteger(parsed) ? parsed : 0;
};

const groupDigits = (digits) =>
    digits === '' ? '' : digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

/**
 * Ticket 77 — sel foto jaminan: pratinjau berkas lokal (object URL) atau
 * foto tersimpan dari server, plus pemilih berkas untuk unggah/penggantian.
 * Path filesystem tidak pernah disentuh frontend — foto tersimpan disajikan
 * lewat route terotorisasi (`foto_url`).
 */
function FotoCell({ item, index, onPick }) {
    const [localUrl, setLocalUrl] = React.useState(null);

    React.useEffect(() => {
        if (item.foto instanceof File) {
            const url = URL.createObjectURL(item.foto);
            setLocalUrl(url);
            return () => URL.revokeObjectURL(url);
        }
        setLocalUrl(null);
        return undefined;
    }, [item.foto]);

    const src = localUrl ?? item.foto_url;

    return (
        <div className="flex items-center gap-2">
            {src ? (
                <img
                    src={src}
                    alt={`Foto ${item.nama}`}
                    className="h-10 w-10 shrink-0 rounded border border-slate-200 object-cover"
                />
            ) : (
                <span className="text-xs text-slate-400">Belum ada foto</span>
            )}
            <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(event) =>
                    onPick(index, event.target.files?.[0] ?? null)
                }
                className="w-28 text-xs text-slate-500"
                aria-label={`Pilih foto jaminan ${item.nama}`}
            />
        </div>
    );
}

/**
 * Sel dokumen PDF jaminan: menampilkan link/indikator dokumen tersimpan
 * atau file yang baru dipilih, plus pemilih berkas PDF untuk unggah/penggantian.
 */
function DokumenCell({ item, index, onPick }) {
    const fileName =
        item.dokumen instanceof File
            ? item.dokumen.name
            : item.dokumen_name;

    return (
        <div className="flex flex-col gap-1">
            {item.dokumen_url && !(item.dokumen instanceof File) ? (
                <a
                    href={item.dokumen_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-800 underline truncate max-w-[180px]"
                    title={fileName || 'Lihat Dokumen PDF'}
                >
                    <FileText className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">{fileName || 'Lihat PDF'}</span>
                </a>
            ) : item.dokumen instanceof File ? (
                <span className="inline-flex items-center gap-1 text-xs text-emerald-600 font-medium truncate max-w-[180px]" title={fileName}>
                    <FileText className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">{fileName}</span>
                </span>
            ) : (
                <span className="text-xs text-slate-400">Belum ada dokumen</span>
            )}
            <input
                type="file"
                accept="application/pdf"
                onChange={(event) =>
                    onPick(index, event.target.files?.[0] ?? null)
                }
                className="w-28 text-xs text-slate-500"
                aria-label={`Pilih dokumen PDF jaminan ${item.nama}`}
            />
        </div>
    );
}

function Field({ label, children, hint }) {
    return (
        <div>
            <label className="text-xs font-semibold text-slate-700">
                {label}
            </label>
            {children}
            {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
        </div>
    );
}

/** Satu section accordion: judul selalu terlihat, isi muncul saat dibuka. */
function AccordionSection({ index, section, isOpen, onToggle, children }) {
    const panelId = `pengajuan-section-${section.key}`;

    return (
        <section className="overflow-hidden rounded-lg bg-white shadow">
            <h2>
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={isOpen}
                    aria-controls={panelId}
                    className="flex w-full items-center justify-between gap-3 px-5 py-4 text-left transition-colors hover:bg-slate-50"
                >
                    <span className="flex items-center gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-800 text-[11px] font-bold text-white">
                            {index}
                        </span>
                        <span className="text-sm font-semibold text-slate-800">
                            {section.title}
                        </span>
                    </span>
                    <ChevronDown
                        className={`h-4 w-4 shrink-0 text-slate-400 transition-transform duration-200 ${
                            isOpen ? 'rotate-180' : ''
                        }`}
                    />
                </button>
            </h2>
            {isOpen && (
                <div id={panelId} className="border-t border-slate-100 px-5 py-5">
                    {children}
                </div>
            )}
        </section>
    );
}

export default function Pengajuan({ today, products, customers, options, application = null, formData = null }) {
    // Accordion: murni state lokal. Tidak ada request backend saat toggle.
    const [openSections, setOpenSections] = useState(
        () => new Set([SECTIONS[0].key]),
    );

    const toggleSection = (key) =>
        setOpenSections((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });

    const openAllSections = () =>
        setOpenSections(new Set(SECTIONS.map((section) => section.key)));

    const closeAllSections = () => setOpenSections(new Set());

    // State form: kosong saat create; terisi data existing saat mode edit
    // (Ticket 68 — komponen yang sama, payload identik ke create/update).
    const buildEmptyForm = () => ({
        pribadi: {
            customer_id: '',
            // Ticket 76-FIX — Data Pribadi adalah DATA PENGAJUAN (snapshot per
            // pengajuan di `shm_loan_applications`), bukan data master nasabah.
            unit_kerja: '',
            nip: '',
            telepon_rumah: '',
            tempat_lahir: '',
            tanggal_lahir: '',
            jumlah_tanggungan: '',
            saldo_modal: '',
            saldo_non_modal: '',
            batas_hutang: '',
        },
        pengajuan: {
            product_id: '',
            principal: '',
            tenor_months: '',
            // T82 — jatuh tempo pertama dipilih Marketing (input date).
            first_due_date: '',
            purpose: '',
            loan_sequence: '',
            disbursement_method: '',
        },
        income: { ...EMPTY_INCOME },
        expense: { ...EMPTY_EXPENSE },
        tambahan: {
            koperasi_loan_balance: '',
            other_facility: '',
            institution_name: '',
        },
        agunan: [],
        jaminan: [],
        catatan: { date: today, name: '', notes: '' },
        analisa: {
            analyst_code: '',
            analyst_office: '',
            officer_code: '',
            officer_office: '',
            date: today,
            value: '',
            notes: '',
        },
        kolektor: { name: '' },
        persetujuan: {
            approved_amount: '',
            provision_fee: '',
            administration_fee: '',
            notary_fee: '',
            method: '',
            notes: '',
        },
        persyaratan: [],
    });

    const [form, setForm] = useState(() =>
        formData ? JSON.parse(JSON.stringify(formData)) : buildEmptyForm(),
    );

    const setGroup = (group, key, value) =>
        setForm((prev) => ({ ...prev, [group]: { ...prev[group], [key]: value } }));

    const setRoot = (key, value) => setForm((prev) => ({ ...prev, [key]: value }));

    // --- Perhitungan pengeluaran/penghasilan (bilangan bulat) --------------
    const totalPenghasilan = INCOME_FIELDS.reduce(
        (sum, field) => sum + toAmount(form.income[field.key]),
        0,
    );
    const totalPengeluaran = EXPENSE_FIELDS.reduce(
        (sum, field) => sum + toAmount(form.expense[field.key]),
        0,
    );
    const sisaPenghasilan = totalPenghasilan - totalPengeluaran;

    const selectedCustomer = customers.find(
        (customer) => String(customer.id) === String(form.pribadi.customer_id),
    );
    const selectedProduct = products.find(
        (product) => String(product.id) === String(form.pengajuan.product_id),
    );

    // T82 — turunkan rate dari tier produk (product + tenor) untuk
    // TAMPILAN saja; sumber kebenaran resolusi tetap di backend.
    const selectedTier = selectedProduct?.rate_tiers?.find(
        (tier) =>
            Number(form.pengajuan.tenor_months) >=
                tier.min_tenor_months &&
            Number(form.pengajuan.tenor_months) <= tier.max_tenor_months,
    );
    const validTenorRanges = (selectedProduct?.rate_tiers || []).map(
        (tier) =>
            tier.min_tenor_months === tier.max_tenor_months
                ? `${tier.min_tenor_months} bulan`
                : `${tier.min_tenor_months}–${tier.max_tenor_months} bulan`,
    );

    // --- Data Tambahan: daftar barang agunan kredit (lokal) ----------------
    const [draftAgunan, setDraftAgunan] = useState({
        jenis: '',
        nomor: '',
        tahun: '',
        nilai: '',
    });

    const addAgunan = () => {
        if (draftAgunan.jenis.trim() === '') return;
        setRoot('agunan', [...form.agunan, { ...draftAgunan }]);
        setDraftAgunan({ jenis: '', nomor: '', tahun: '', nilai: '' });
    };

    const removeAgunan = (index) =>
        setRoot(
            'agunan',
            form.agunan.filter((_, i) => i !== index),
        );

    // --- Jaminan Pinjaman: daftar + form tambah (lokal) --------------------
    const EMPTY_JAMINAN = {
        tanggal: today,
        jenis: '',
        nama: '',
        keterangan: '',
        nilai_perkiraan: '',
        status_pengikatan: '',
        tanggal_pengikatan: '',
        nilai_hak_tanggungan: '',
        // Ticket 77 — `foto` memegang objek File yang dipilih (null = tanpa
        // foto); `foto_url` diisi server untuk foto yang sudah tersimpan.
        foto: null,
        foto_url: null,
        dokumen: null,
        dokumen_url: null,
        dokumen_name: null,
        picked: false,
    };
    const [showJaminanForm, setShowJaminanForm] = useState(false);
    const [draftJaminan, setDraftJaminan] = useState(EMPTY_JAMINAN);

    const addJaminan = () => {
        if (draftJaminan.jenis.trim() === '' || draftJaminan.nama.trim() === '') {
            return;
        }
        setRoot('jaminan', [...form.jaminan, { ...draftJaminan }]);
        setDraftJaminan({ ...EMPTY_JAMINAN, tanggal: today });
        setShowJaminanForm(false);
    };

    const toggleJaminanPick = (index) =>
        setRoot(
            'jaminan',
            form.jaminan.map((item, i) =>
                i === index ? { ...item, picked: !item.picked } : item,
            ),
        );

    const removeJaminan = (index) =>
        setRoot(
            'jaminan',
            form.jaminan.filter((_, i) => i !== index),
        );

    // Ticket 77 — pilih/ganti foto pada baris jaminan yang sudah ada.
    const setJaminanFoto = (index, file) =>
        setRoot(
            'jaminan',
            form.jaminan.map((item, i) =>
                i === index ? { ...item, foto: file } : item,
            ),
        );

    // Dokumen jaminan (PDF) — pilih/ganti dokumen pada baris jaminan yang sudah ada.
    const setJaminanDokumen = (index, file) =>
        setRoot(
            'jaminan',
            form.jaminan.map((item, i) =>
                i === index ? { ...item, dokumen: file } : item,
            ),
        );

    // --- Persyaratan tambahan (lokal, daftar bebas ala dokumen existing) ---
    const [draftSyarat, setDraftSyarat] = useState('');

    const addSyarat = () => {
        if (draftSyarat.trim() === '') return;
        setRoot('persyaratan', [
            ...form.persyaratan,
            { text: draftSyarat.trim(), done: false },
        ]);
        setDraftSyarat('');
    };

    const toggleSyarat = (index) =>
        setRoot(
            'persyaratan',
            form.persyaratan.map((item, i) =>
                i === index ? { ...item, done: !item.done } : item,
            ),
        );

    const removeSyarat = (index) =>
        setRoot(
            'persyaratan',
            form.persyaratan.filter((_, i) => i !== index),
        );

    const inputClass =
        'mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-colors shadow-2xs';
    const readonlyClass = `${inputClass} bg-slate-50 text-slate-600 shadow-none`;
    const moneyClass = `${inputClass} text-right tabular-nums`;

    // --- Submit ke backend (Ticket 67) ---------------------------------
    // Inertia router.post mengikuti pattern existing; state `processing`
    // men-gate double-click sehingga tidak ada request berulang, dan error
    // validasi backend (dotted keys per section) ditampilkan kembali.
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    const err = (key) => errors[key] ?? null;

    const submitPayload = () => ({
        ...form,
        // Ticket 77 & Dokumen Jaminan — berkas dikirim sebagai File (Inertia
        // otomatis memakai multipart/form-data saat payload memuat File).
        // URL dan nama lama server tidak dikirim ulang sebagai data simpan.
        jaminan: form.jaminan.map(({ foto_url, dokumen_url, dokumen_name, ...rest }) => ({
            ...rest,
            foto: rest.foto instanceof File ? rest.foto : null,
            dokumen: rest.dokumen instanceof File ? rest.dokumen : null,
        })),
    });

    const submit = (event) => {
        event.preventDefault();
        if (processing) return; // guard double-click / submit berulang

        setErrors({});
        // Mode edit (Ticket 68): komponen & payload sama, hanya endpoint
        // yang berbeda (PUT update vs POST store).
        const options = {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (validationErrors) => setErrors(validationErrors ?? {}),
            preserveScroll: true,
        };

        if (application) {
            const payload = submitPayload();
            const hasFiles = form.jaminan.some(
                (item) => item.foto instanceof File || item.dokumen instanceof File,
            );

            if (hasFiles) {
                // PHP hanya mem-parsing berkas multipart pada POST; unggah
                // foto pada mode edit memakai method-spoofing POST +
                // _method=PUT (pola Laravel — route tetap PUT).
                router.post(
                    route('shm.loans.pengajuan.update', application.id),
                    { ...payload, _method: 'put' },
                    options,
                );
            } else {
                router.put(
                    route('shm.loans.pengajuan.update', application.id),
                    payload,
                    options,
                );
            }
        } else {
            router.post(route('shm.loans.pengajuan.store'), submitPayload(), options);
        }
    };

    const renderMoney = (group, key) => (
        <input
            type="text"
            inputMode="numeric"
            value={groupDigits(String(form[group][key]).replace(/[^0-9]/g, ''))}
            onChange={(event) =>
                setGroup(
                    group,
                    key,
                    event.target.value.replace(/[^0-9]/g, '').slice(0, 16),
                )
            }
            placeholder="0"
            className={moneyClass}
        />
    );

    return (
        <ShmLayout title="Pengajuan Pinjaman BSM Makro">
            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold text-gray-800">
                            <FileSignature className="h-5 w-5 text-slate-400" />
                            {application
                                ? 'Edit Pengajuan Pinjaman'
                                : 'Pengajuan Pinjaman BSM Makro'}
                        </h1>
                        <p className="text-sm text-gray-500">
                            {application && (
                                <span className="font-medium text-slate-700">
                                    {application.application_number} ·{' '}
                                </span>
                            )}
                            Satu formulir dengan 11 bagian. Rate bunga/jasa
                            mengikuti produk & tenor yang dipilih — lihat
                            bagian Pengajuan Pinjaman.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            onClick={openAllSections}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                        >
                            Buka Semua
                        </button>
                        <button
                            type="button"
                            onClick={closeAllSections}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                        >
                            Tutup Semua
                        </button>
                    </div>
                </div>

                {Object.keys(errors).length > 0 && (
                    <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                        <p className="mb-2 font-semibold">
                            Data belum dapat disimpan:
                        </p>
                        <ul className="list-inside list-disc space-y-1">
                            {Object.entries(errors).map(([key, message]) => (
                                <li key={key}>{message}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="space-y-3">
                    {SECTIONS.map((section, index) => (
                        <AccordionSection
                            key={section.key}
                            index={index + 1}
                            section={section}
                            isOpen={openSections.has(section.key)}
                            onToggle={() => toggleSection(section.key)}
                        >
                            {section.key === 'pribadi' && (
                                <div className="space-y-4">
                                    <Field label="Pilih Nasabah BSM Makro">
                                        <select
                                            value={form.pribadi.customer_id}
                                            onChange={(event) =>
                                                setGroup(
                                                    'pribadi',
                                                    'customer_id',
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                        >
                                            <option value="">
                                                — Pilih nasabah BSM Makro —
                                            </option>
                                            {customers.map((customer) => (
                                                <option
                                                    key={customer.id}
                                                    value={customer.id}
                                                >
                                                    {customer.customer_number} —{' '}
                                                    {customer.name}
                                                </option>
                                            ))}
                                        </select>
                                        {err('pribadi.customer_id') && (
                                            <p className="mt-1 text-xs text-rose-600">
                                                {err('pribadi.customer_id')}
                                            </p>
                                        )}
                                    </Field>

                                    {selectedCustomer ? (
                                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                            <Field label="Nomor Nasabah (Nomor Anggota SHM)">
                                                <input
                                                    type="text"
                                                    readOnly
                                                    value={
                                                        selectedCustomer.customer_number
                                                    }
                                                    className={readonlyClass}
                                                />
                                            </Field>
                                            <Field label="Tanggal Pengajuan">
                                                <input
                                                    type="date"
                                                    readOnly
                                                    value={today}
                                                    className={readonlyClass}
                                                />
                                            </Field>
                                            <Field label="Nama Lengkap">
                                                <input
                                                    type="text"
                                                    readOnly
                                                    value={selectedCustomer.name}
                                                    className={readonlyClass}
                                                />
                                            </Field>
                                            <Field label="No. Identitas (NIK)">
                                                <input
                                                    type="text"
                                                    readOnly
                                                    value={
                                                        selectedCustomer.nik ??
                                                        '—'
                                                    }
                                                    className={readonlyClass}
                                                />
                                            </Field>
                                            <Field label="No. Ponsel">
                                                <input
                                                    type="text"
                                                    readOnly
                                                    value={
                                                        selectedCustomer.phone ??
                                                        '—'
                                                    }
                                                    className={readonlyClass}
                                                />
                                            </Field>
                                            <Field label="Status">
                                                <input
                                                    type="text"
                                                    readOnly
                                                    value={
                                                        selectedCustomer.is_active
                                                            ? 'Aktif'
                                                            : 'Nonaktif'
                                                    }
                                                    className={readonlyClass}
                                                />
                                            </Field>
                                            <Field label="Alamat">
                                                <input
                                                    type="text"
                                                    readOnly
                                                    value={
                                                        selectedCustomer.address ??
                                                        '—'
                                                    }
                                                    className={readonlyClass}
                                                />
                                            </Field>
                                            <Field label="Tanggal Menjadi Nasabah BSM Makro">
                                                <input
                                                    type="date"
                                                    readOnly
                                                    value={
                                                        selectedCustomer.registered_at ??
                                                        ''
                                                    }
                                                    className={readonlyClass}
                                                />
                                            </Field>
                                        </div>
                                    ) : (
                                        <p className="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                                            Pilih nasabah SHM untuk mengisi data
                                            pribadi dari master yang sudah ada.
                                        </p>
                                    )}

                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <Field label="Unit Kerja">
                                            <input
                                                type="text"
                                                value={form.pribadi.unit_kerja ?? ''}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pribadi',
                                                        'unit_kerja',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            {err('pribadi.unit_kerja') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pribadi.unit_kerja')}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="NIP">
                                            <input
                                                type="text"
                                                value={form.pribadi.nip ?? ''}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pribadi',
                                                        'nip',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            {err('pribadi.nip') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pribadi.nip')}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="Telepon Rumah">
                                            <input
                                                type="text"
                                                value={form.pribadi.telepon_rumah ?? ''}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pribadi',
                                                        'telepon_rumah',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            {err('pribadi.telepon_rumah') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pribadi.telepon_rumah')}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="Tempat Lahir">
                                            <input
                                                type="text"
                                                value={form.pribadi.tempat_lahir ?? ''}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pribadi',
                                                        'tempat_lahir',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            {err('pribadi.tempat_lahir') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pribadi.tempat_lahir')}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="Tanggal Lahir">
                                            <input
                                                type="date"
                                                value={form.pribadi.tanggal_lahir ?? ''}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pribadi',
                                                        'tanggal_lahir',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            {err('pribadi.tanggal_lahir') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pribadi.tanggal_lahir')}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="Jumlah Tanggungan">
                                            <input
                                                type="number"
                                                min="0"
                                                value={form.pribadi.jumlah_tanggungan ?? ''}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pribadi',
                                                        'jumlah_tanggungan',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            {err('pribadi.jumlah_tanggungan') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pribadi.jumlah_tanggungan')}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="Saldo Modal (Rp)">
                                            <input
                                                type="text"
                                                inputMode="decimal"
                                                value={form.pribadi.saldo_modal ?? ''}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pribadi',
                                                        'saldo_modal',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            {err('pribadi.saldo_modal') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pribadi.saldo_modal')}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="Saldo Non Modal (Rp)">
                                            <input
                                                type="text"
                                                inputMode="decimal"
                                                value={form.pribadi.saldo_non_modal ?? ''}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pribadi',
                                                        'saldo_non_modal',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            {err('pribadi.saldo_non_modal') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pribadi.saldo_non_modal')}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="Batas Hutang (Rp)">
                                            <input
                                                type="text"
                                                inputMode="decimal"
                                                value={form.pribadi.batas_hutang ?? ''}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pribadi',
                                                        'batas_hutang',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            {err('pribadi.batas_hutang') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pribadi.batas_hutang')}
                                                </p>
                                            )}
                                        </Field>
                                    </div>
                                </div>
                            )}

                            {section.key === 'pengajuan' && (
                                <div className="space-y-4">
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <Field label="Produk Pinjaman">
                                            <select
                                                value={form.pengajuan.product_id}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pengajuan',
                                                        'product_id',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            >
                                                <option value="">
                                                    — Pilih produk —
                                                </option>
                                                {products.map((product) => (
                                                    <option
                                                        key={product.id}
                                                        value={product.id}
                                                    >
                                                        {product.name} (
                                                        {product.code})
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <Field label="Besar Pinjaman (Rp)">
                                            {renderMoney('pengajuan', 'principal')}
                                            {err('pengajuan.principal') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pengajuan.principal')}
                                                </p>
                                            )}
                                        </Field>                                        <Field label="Jangka Waktu (kali angsuran / bulan)">
                                            <input
                                                type="number"
                                                min="1"
                                                value={
                                                    form.pengajuan.tenor_months
                                                }
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pengajuan',
                                                        'tenor_months',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="mis. 48"
                                                className={inputClass}
                                            />
                                            {err('pengajuan.tenor_months') && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err('pengajuan.tenor_months')}
                                                </p>
                                            )}
                                            {validTenorRanges.length > 0 && (
                                                <p className="mt-1 text-xs text-slate-500">
                                                    Tenor tersedia:{' '}
                                                    {validTenorRanges.join(
                                                        ', ',
                                                    )}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="Rate Bunga (per bulan, flat)">
                                            <input
                                                type="text"
                                                readOnly
                                                value={
                                                    selectedTier
                                                        ? `${selectedTier.rate_percent}% / bulan (flat)`
                                                        : '—'
                                                }
                                                className={readonlyClass}
                                            />
                                            <p className="mt-1 text-xs text-slate-500">
                                                Rate ditentukan produk + tenor
                                                (divalidasi ulang oleh server).
                                            </p>
                                        </Field>
                                        <Field label="Jatuh Tempo Pertama">
                                            <input
                                                type="date"
                                                value={
                                                    form.pengajuan
                                                        .first_due_date
                                                }
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pengajuan',
                                                        'first_due_date',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                            <p className="mt-1 text-xs text-slate-500">
                                                Dipilih Marketing — wajib
                                                diisi sebelum pencairan.
                                            </p>
                                            {err(
                                                'pengajuan.first_due_date',
                                            ) && (
                                                <p className="mt-1 text-xs text-rose-600">
                                                    {err(
                                                        'pengajuan.first_due_date',
                                                    )}
                                                </p>
                                            )}
                                        </Field>
                                        <Field label="Tujuan Pinjaman">
                                            <input
                                                type="text"
                                                value={
                                                    form.pengajuan.purpose
                                                }
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pengajuan',
                                                        'purpose',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="mis. Modal usaha"
                                                className={inputClass}
                                            />
                                        </Field>
                                        <Field label="Pinjaman yang ke-">
                                            <input
                                                type="number"
                                                min="1"
                                                value={
                                                    form.pengajuan.loan_sequence
                                                }
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pengajuan',
                                                        'loan_sequence',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="mis. 1"
                                                className={inputClass}
                                            />
                                        </Field>
                                        <Field label="Metode penyetoran/pembayaran angsuran">
                                            <select
                                                value={
                                                    form.pengajuan
                                                        .disbursement_method
                                                }
                                                onChange={(event) =>
                                                    setGroup(
                                                        'pengajuan',
                                                        'disbursement_method',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            >
                                                <option value="">
                                                    — Pilih metode —
                                                </option>
                                                {DISBURSEMENT_METHODS.map(
                                                    (method) => (
                                                        <option
                                                            key={method}
                                                            value={method}
                                                        >
                                                            {method}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </Field>
                                    </div>
                                </div>
                            )}

                            {section.key === 'cashflow' && (
                                <div className="space-y-5">
                                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                                        <div className="space-y-3 rounded-lg border border-slate-200 p-4">
                                            <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-600">
                                                Penghasilan
                                            </h3>
                                            {INCOME_FIELDS.map((field) => (
                                                <Field
                                                    key={field.key}
                                                    label={field.label}
                                                >
                                                    {renderMoney(
                                                        'income',
                                                        field.key,
                                                    )}
                                                </Field>
                                            ))}
                                            <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                                                <span className="font-semibold text-slate-700">
                                                    Total
                                                </span>
                                                <span className="font-bold tabular-nums text-slate-900">
                                                    {formatRupiah(
                                                        totalPenghasilan,
                                                    )}
                                                </span>
                                            </div>
                                        </div>

                                        <div className="space-y-3 rounded-lg border border-slate-200 p-4">
                                            <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-600">
                                                Pengeluaran
                                            </h3>
                                            {EXPENSE_FIELDS.map((field) => (
                                                <Field
                                                    key={field.key}
                                                    label={field.label}
                                                >
                                                    {renderMoney(
                                                        'expense',
                                                        field.key,
                                                    )}
                                                </Field>
                                            ))}
                                            <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                                                <span className="font-semibold text-slate-700">
                                                    Total
                                                </span>
                                                <span className="font-bold tabular-nums text-slate-900">
                                                    {formatRupiah(
                                                        totalPengeluaran,
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-between rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3">
                                        <span className="text-sm font-semibold text-indigo-900">
                                            Sisa Penghasilan
                                        </span>
                                        <span
                                            className={`text-base font-bold tabular-nums ${
                                                sisaPenghasilan < 0
                                                    ? 'text-rose-600'
                                                    : 'text-indigo-900'
                                            }`}
                                        >
                                            {formatRupiah(sisaPenghasilan)}
                                        </span>
                                    </div>
                                </div>
                            )}

                            {section.key === 'tambahan' && (
                                <div className="space-y-5">
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <Field
                                            label="Saldo pinjaman koperasi saat ini (Rp)"
                                            hint="Diisi manual oleh petugas; sistem tidak membaca data pinjaman koperasi."
                                        >
                                            {renderMoney('tambahan', 'koperasi_loan_balance')}
                                        </Field>
                                        <Field label="Nama lembaga lain">
                                            <input
                                                type="text"
                                                value={form.tambahan.institution_name}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'tambahan',
                                                        'institution_name',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>
                                        <div className="md:col-span-2">
                                            <Field label="Fasilitas pinjaman dari lembaga lain">
                                                <input
                                                    type="text"
                                                    value={
                                                        form.tambahan
                                                            .other_facility
                                                    }
                                                    onChange={(event) =>
                                                        setGroup(
                                                            'tambahan',
                                                            'other_facility',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className={inputClass}
                                                />
                                            </Field>
                                        </div>
                                    </div>

                                    <div className="space-y-3">
                                        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-600">
                                            Data barang yang dapat diserahkan
                                            sebagai agunan kredit
                                        </h3>

                                        {form.agunan.length > 0 && (
                                            <div className="overflow-x-auto rounded-lg border border-slate-200">
                                                <table className="w-full text-sm">
                                                    <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                                        <tr>
                                                            <th className="px-3 py-2">
                                                                Jenis barang
                                                            </th>
                                                            <th className="px-3 py-2">
                                                                Nomor
                                                            </th>
                                                            <th className="px-3 py-2">
                                                                Tahun
                                                            </th>
                                                            <th className="px-3 py-2 text-right">
                                                                Nilai perkiraan
                                                            </th>
                                                            <th className="px-3 py-2" />
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {form.agunan.map(
                                                            (item, index) => (
                                                                <tr
                                                                    key={`${item.jenis}-${index}`}
                                                                    className="border-t border-slate-100"
                                                                >
                                                                    <td className="px-3 py-2">
                                                                        {
                                                                            item.jenis
                                                                        }
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {item.nomor ||
                                                                            '—'}
                                                                    </td>
                                                                    <td className="px-3 py-2">
                                                                        {item.tahun ||
                                                                            '—'}
                                                                    </td>
                                                                    <td className="px-3 py-2 text-right tabular-nums">
                                                                        {formatRupiah(
                                                                            toAmount(
                                                                                item.nilai,
                                                                            ),
                                                                        )}
                                                                    </td>
                                                                    <td className="px-3 py-2 text-right">
                                                                        <button
                                                                            type="button"
                                                                            onClick={() =>
                                                                                removeAgunan(
                                                                                    index,
                                                                                )
                                                                            }
                                                                            className="text-rose-500 hover:text-rose-700"
                                                                            aria-label={`Hapus agunan ${item.jenis}`}
                                                                        >
                                                                            <Trash2 className="h-4 w-4" />
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            ),
                                                        )}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}

                                        <div className="grid grid-cols-1 gap-3 rounded-lg border border-dashed border-slate-300 p-4 md:grid-cols-4">
                                            <Field label="Jenis barang">
                                                <input
                                                    type="text"
                                                    value={draftAgunan.jenis}
                                                    onChange={(event) =>
                                                        setDraftAgunan({
                                                            ...draftAgunan,
                                                            jenis: event.target
                                                                .value,
                                                        })
                                                    }
                                                    className={inputClass}
                                                />
                                            </Field>
                                            <Field label="Nomor">
                                                <input
                                                    type="text"
                                                    value={draftAgunan.nomor}
                                                    onChange={(event) =>
                                                        setDraftAgunan({
                                                            ...draftAgunan,
                                                            nomor: event.target
                                                                .value,
                                                        })
                                                    }
                                                    className={inputClass}
                                                />
                                            </Field>
                                            <Field label="Tahun">
                                                <input
                                                    type="text"
                                                    inputMode="numeric"
                                                    value={draftAgunan.tahun}
                                                    onChange={(event) =>
                                                        setDraftAgunan({
                                                            ...draftAgunan,
                                                            tahun: event.target
                                                                .value
                                                                .replace(
                                                                    /[^0-9]/g,
                                                                    '',
                                                                )
                                                                .slice(0, 4),
                                                        })
                                                    }
                                                    className={inputClass}
                                                />
                                            </Field>
                                            <Field label="Nilai perkiraan (Rp)">
                                                <input
                                                    type="text"
                                                    inputMode="numeric"
                                                    value={groupDigits(
                                                        draftAgunan.nilai,
                                                    )}
                                                    onChange={(event) =>
                                                        setDraftAgunan({
                                                            ...draftAgunan,
                                                            nilai: event.target
                                                                .value
                                                                .replace(
                                                                    /[^0-9]/g,
                                                                    '',
                                                                )
                                                                .slice(0, 16),
                                                        })
                                                    }
                                                    className={moneyClass}
                                                />
                                            </Field>
                                            <div className="md:col-span-4">
                                                <button
                                                    type="button"
                                                    onClick={addAgunan}
                                                    className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                                >
                                                    <Plus className="h-3.5 w-3.5" />
                                                    Tambah Barang Agunan
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {section.key === 'jaminan' && (
                                <div className="space-y-4">
                                    {Object.entries(errors)
                                        .filter(([key]) =>
                                            key.startsWith('jaminan.'),
                                        )
                                        .map(([key, message]) => (
                                            <p
                                                key={key}
                                                className="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700"
                                            >
                                                {message}
                                            </p>
                                        ))}
                                    <div className="overflow-x-auto rounded-lg border border-slate-200">
                                        <table className="w-full text-sm">
                                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                                <tr>
                                                    <th className="px-3 py-2">
                                                        No.
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Jaminan
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Foto
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Dokumen (PDF)
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Keterangan
                                                    </th>
                                                    <th className="px-3 py-2">
                                                        Pilih Jaminan
                                                    </th>
                                                    <th className="px-3 py-2" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {form.jaminan.length === 0 && (
                                                    <tr>
                                                        <td
                                                            colSpan="7"
                                                            className="px-3 py-6 text-center text-slate-400"
                                                        >
                                                            Belum ada jaminan
                                                            ditambahkan.
                                                        </td>
                                                    </tr>
                                                )}
                                                {form.jaminan.map(
                                                    (item, index) => (
                                                        <tr
                                                            key={`${item.nama}-${index}`}
                                                            className="border-t border-slate-100"
                                                        >
                                                            <td className="px-3 py-2">
                                                                {index + 1}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {item.jenis} —{' '}
                                                                {item.nama}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <FotoCell
                                                                    item={
                                                                        item
                                                                    }
                                                                    index={
                                                                        index
                                                                    }
                                                                    onPick={
                                                                        setJaminanFoto
                                                                    }
                                                                />
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <DokumenCell
                                                                    item={
                                                                        item
                                                                    }
                                                                    index={
                                                                        index
                                                                    }
                                                                    onPick={
                                                                        setJaminanDokumen
                                                                    }
                                                                />
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                {item.keterangan ||
                                                                    '—'}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <input
                                                                    type="checkbox"
                                                                    checked={
                                                                        item.picked
                                                                    }
                                                                    onChange={() =>
                                                                        toggleJaminanPick(
                                                                            index,
                                                                        )
                                                                    }
                                                                    aria-label={`Pilih jaminan ${item.nama}`}
                                                                />
                                                            </td>
                                                            <td className="px-3 py-2 text-right">
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        removeJaminan(
                                                                            index,
                                                                        )
                                                                    }
                                                                    className="text-rose-500 hover:text-rose-700"
                                                                    aria-label={`Hapus jaminan ${item.nama}`}
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>

                                    <button
                                        type="button"
                                        onClick={() =>
                                            setShowJaminanForm((prev) => !prev)
                                        }
                                        className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        Tambah Jaminan
                                    </button>

                                    {showJaminanForm && (
                                        <div className="grid grid-cols-1 gap-4 rounded-lg border border-dashed border-slate-300 p-4 md:grid-cols-3">
                                            <Field label="Tanggal">
                                                <input
                                                    type="date"
                                                    value={draftJaminan.tanggal}
                                                    onChange={(event) =>
                                                        setDraftJaminan({
                                                            ...draftJaminan,
                                                            tanggal:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    className={inputClass}
                                                />
                                            </Field>
                                            <Field
                                                label="Jenis Jaminan"
                                                hint="Master jenis jaminan belum tersedia — diisi bebas."
                                            >
                                                <input
                                                    type="text"
                                                    value={draftJaminan.jenis}
                                                    onChange={(event) =>
                                                        setDraftJaminan({
                                                            ...draftJaminan,
                                                            jenis: event.target
                                                                .value,
                                                        })
                                                    }
                                                    className={inputClass}
                                                />
                                            </Field>
                                            <Field label="Nomor Nasabah / Customer">
                                                <input
                                                    type="text"
                                                    readOnly
                                                    value={
                                                        selectedCustomer?.customer_number ??
                                                        '— pilih nasabah di Data Pribadi —'
                                                    }
                                                    className={readonlyClass}
                                                />
                                            </Field>
                                            <Field label="Nama Jaminan">
                                                <input
                                                    type="text"
                                                    value={draftJaminan.nama}
                                                    onChange={(event) =>
                                                        setDraftJaminan({
                                                            ...draftJaminan,
                                                            nama: event.target
                                                                .value,
                                                        })
                                                    }
                                                    className={inputClass}
                                                />
                                            </Field>
                                            <Field label="Nilai Perkiraan (Rp)">
                                                <input
                                                    type="text"
                                                    inputMode="numeric"
                                                    value={groupDigits(
                                                        draftJaminan.nilai_perkiraan,
                                                    )}
                                                    onChange={(event) =>
                                                        setDraftJaminan({
                                                            ...draftJaminan,
                                                            nilai_perkiraan:
                                                                event.target.value
                                                                    .replace(
                                                                        /[^0-9]/g,
                                                                        '',
                                                                    )
                                                                    .slice(
                                                                        0,
                                                                        16,
                                                                    ),
                                                        })
                                                    }
                                                    className={moneyClass}
                                                />
                                            </Field>
                                            <Field
                                                label="Status Pengikatan"
                                                hint="Master status pengikatan belum tersedia — diisi bebas."
                                            >
                                                <input
                                                    type="text"
                                                    value={
                                                        draftJaminan.status_pengikatan
                                                    }
                                                    onChange={(event) =>
                                                        setDraftJaminan({
                                                            ...draftJaminan,
                                                            status_pengikatan:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    className={inputClass}
                                                />
                                            </Field>
                                            <Field label="Tanggal Pengikatan">
                                                <input
                                                    type="date"
                                                    value={
                                                        draftJaminan.tanggal_pengikatan
                                                    }
                                                    onChange={(event) =>
                                                        setDraftJaminan({
                                                            ...draftJaminan,
                                                            tanggal_pengikatan:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    className={inputClass}
                                                />
                                            </Field>
                                            <Field label="Nilai Hak Tanggungan (Rp)">
                                                <input
                                                    type="text"
                                                    inputMode="numeric"
                                                    value={groupDigits(
                                                        draftJaminan.nilai_hak_tanggungan,
                                                    )}
                                                    onChange={(event) =>
                                                        setDraftJaminan({
                                                            ...draftJaminan,
                                                            nilai_hak_tanggungan:
                                                                event.target.value
                                                                    .replace(
                                                                        /[^0-9]/g,
                                                                        '',
                                                                    )
                                                                    .slice(
                                                                        0,
                                                                        16,
                                                                    ),
                                                        })
                                                    }
                                                    className={moneyClass}
                                                />
                                            </Field>
                                            <Field
                                                label="Foto"
                                                hint="Format: JPG, JPEG, PNG, atau WebP — maksimal 2 MB. Foto tersimpan permanen bersama pengajuan."
                                            >
                                                <input
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/webp"
                                                    onChange={(event) =>
                                                        setDraftJaminan({
                                                            ...draftJaminan,
                                                            foto:
                                                                event.target
                                                                    .files?.[0] ??
                                                                null,
                                                        })
                                                    }
                                                    className="mt-1 block w-full text-xs text-slate-500"
                                                />
                                                {draftJaminan.foto instanceof
                                                    File && (
                                                    <img
                                                        src={URL.createObjectURL(
                                                            draftJaminan.foto,
                                                        )}
                                                        alt="Pratinjau foto jaminan"
                                                        className="mt-2 h-20 rounded border border-slate-200 object-cover"
                                                    />
                                                )}
                                            </Field>
                                            <Field
                                                label="Dokumen Jaminan (PDF)"
                                                hint="Format: PDF — maksimal 5 MB. Dokumen legalitas/perjanjian tersimpan permanen bersama jaminan."
                                            >
                                                <input
                                                    type="file"
                                                    accept="application/pdf"
                                                    onChange={(event) =>
                                                        setDraftJaminan({
                                                            ...draftJaminan,
                                                            dokumen:
                                                                event.target
                                                                    .files?.[0] ??
                                                                null,
                                                        })
                                                    }
                                                    className="mt-1 block w-full text-xs text-slate-500"
                                                />
                                                {draftJaminan.dokumen instanceof
                                                    File && (
                                                    <div className="mt-2 flex items-center gap-1.5 text-xs font-medium text-emerald-600">
                                                        <FileText className="h-4 w-4 shrink-0" />
                                                        <span className="truncate">
                                                            {draftJaminan.dokumen.name}
                                                        </span>
                                                    </div>
                                                )}
                                            </Field>
                                            <div className="md:col-span-3">
                                                <Field label="Keterangan">
                                                    <textarea
                                                        rows="3"
                                                        value={
                                                            draftJaminan.keterangan
                                                        }
                                                        onChange={(event) =>
                                                            setDraftJaminan({
                                                                ...draftJaminan,
                                                                keterangan:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        className={inputClass}
                                                    />
                                                </Field>
                                            </div>
                                            <div className="flex gap-2 md:col-span-3">
                                                <button
                                                    type="button"
                                                    onClick={addJaminan}
                                                    className="rounded-lg bg-slate-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition-colors hover:bg-slate-700"
                                                >
                                                    Simpan Jaminan
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setDraftJaminan({
                                                            ...EMPTY_JAMINAN,
                                                            tanggal: today,
                                                        });
                                                        setShowJaminanForm(
                                                            false,
                                                        );
                                                    }}
                                                    className="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                                >
                                                    Batal
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            {section.key === 'catatan' && (
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <Field label="Tanggal">
                                        <input
                                            type="date"
                                            value={form.catatan.date}
                                            onChange={(event) =>
                                                setGroup(
                                                    'catatan',
                                                    'date',
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                        />
                                    </Field>
                                    <Field label="Nama">
                                        <input
                                            type="text"
                                            value={form.catatan.name}
                                            onChange={(event) =>
                                                setGroup(
                                                    'catatan',
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                        />
                                    </Field>
                                    <div className="md:col-span-2">
                                        <Field label="Catatan / keterangan oleh Kasir / SC">
                                            <textarea
                                                rows="8"
                                                value={form.catatan.notes}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'catatan',
                                                        'notes',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>
                                    </div>
                                </div>
                            )}

                            {section.key === 'analisa' && (
                                <div className="space-y-4">
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <Field label="Kode Analis">
                                            <input
                                                type="text"
                                                value={
                                                    form.analisa.analyst_code
                                                }
                                                onChange={(event) =>
                                                    setGroup(
                                                        'analisa',
                                                        'analyst_code',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>
                                        <Field label="Kantor (Analis)">
                                            <input
                                                type="text"
                                                value={
                                                    form.analisa.analyst_office
                                                }
                                                onChange={(event) =>
                                                    setGroup(
                                                        'analisa',
                                                        'analyst_office',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>
                                        <Field label="Kode Account Officer">
                                            <input
                                                type="text"
                                                value={form.analisa.officer_code}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'analisa',
                                                        'officer_code',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>
                                        <Field label="Kantor (Account Officer)">
                                            <input
                                                type="text"
                                                value={
                                                    form.analisa.officer_office
                                                }
                                                onChange={(event) =>
                                                    setGroup(
                                                        'analisa',
                                                        'officer_office',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>
                                        <Field label="Tanggal">
                                            <input
                                                type="date"
                                                value={form.analisa.date}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'analisa',
                                                        'date',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>
                                        <Field label="Nilai Pinjaman (Rp)">
                                            {renderMoney('analisa', 'value')}
                                        </Field>
                                    </div>
                                    <Field label="Keterangan / hasil analisa">
                                        <textarea
                                            rows="8"
                                            value={form.analisa.notes}
                                            onChange={(event) =>
                                                setGroup(
                                                    'analisa',
                                                    'notes',
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                        />
                                    </Field>
                                </div>
                            )}

                            {section.key === 'analisa-tambahan' && (
                                <div className="space-y-3">
                                    <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-400">
                                        Belum ada field yang dikonfirmasi untuk
                                        bagian ini.
                                    </div>
                                </div>
                            )}

                            {section.key === 'kolektor' && (
                                <div className="space-y-3">
                                    <Field
                                        label="Nama Kolektor Pinjaman"
                                        hint="Diisi manual; tidak ada master kolektor pada aplikasi saat ini."
                                    >
                                        <input
                                            type="text"
                                            value={form.kolektor.name}
                                            onChange={(event) =>
                                                setGroup(
                                                    'kolektor',
                                                    'name',
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                        />
                                    </Field>
                                </div>
                            )}

                            {section.key === 'persetujuan' && (
                                <div className="space-y-5">
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <Field label="Produk Pinjaman">
                                            <input
                                                type="text"
                                                readOnly
                                                value={
                                                    selectedProduct
                                                        ? `${selectedProduct.name} (${selectedProduct.code})`
                                                        : '—'
                                                }
                                                className={readonlyClass}
                                            />
                                        </Field>
                                        <Field label="Jangka waktu">
                                            <input
                                                type="text"
                                                readOnly
                                                value={
                                                    form.pengajuan.tenor_months
                                                        ? `${form.pengajuan.tenor_months} kali angsuran`
                                                        : '—'
                                                }
                                                className={readonlyClass}
                                            />
                                        </Field>
                                        <Field label="Pengajuan Pinjaman Sebesar (Rp)">
                                            <input
                                                type="text"
                                                readOnly
                                                value={formatRupiah(
                                                    toAmount(
                                                        form.pengajuan.principal,
                                                    ),
                                                )}
                                                className={readonlyClass}
                                            />
                                        </Field>
                                        <Field label="Analisa Pinjaman Sebesar (Rp)">
                                            <input
                                                type="text"
                                                readOnly
                                                value={formatRupiah(
                                                    toAmount(
                                                        form.analisa.value,
                                                    ),
                                                )}
                                                className={readonlyClass}
                                            />
                                        </Field>
                                        <Field label="Persetujuan Pinjaman Sebesar (Rp)">
                                            {renderMoney(
                                                'persetujuan',
                                                'approved_amount',
                                            )}
                                        </Field>
                                        <Field label="Jasa / Rate Produk">
                                            <input
                                                type="text"
                                                readOnly
                                                value={
                                                    selectedTier
                                                        ? `${selectedTier.rate_percent}% / bulan`
                                                        : '—'
                                                }
                                                className={readonlyClass}
                                            />
                                        </Field>
                                        <Field label="Jatuh Tempo Pertama">
                                            <input
                                                type="text"
                                                readOnly
                                                value={
                                                    form.pengajuan
                                                        .first_due_date || '—'
                                                }
                                                className={readonlyClass}
                                            />
                                        </Field>
                                        <Field label="Biaya Provisi (Rp)">
                                            {renderMoney(
                                                'persetujuan',
                                                'provision_fee',
                                            )}
                                        </Field>
                                        <Field label="Biaya Administrasi (Rp)">
                                            {renderMoney(
                                                'persetujuan',
                                                'administration_fee',
                                            )}
                                        </Field>
                                        <Field
                                            label="Biaya Notaris (Rp)"
                                            hint="Custom fillable — nominal non-negatif; 0 bila tidak ada biaya notaris."
                                        >
                                            {renderMoney(
                                                'persetujuan',
                                                'notary_fee',
                                            )}
                                        </Field>
                                        <div className="md:col-span-2">
                                            <Field
                                                label="Metode perhitungan"
                                                hint="Wajib dipilih secara eksplisit — baseline resmi mengikuti metode flat dengan periode bulanan (kontrak T79); pilihan ini menjadi input jadwal angsuran."
                                            >
                                                <select
                                                    value={
                                                        form.persetujuan.method
                                                    }
                                                    onChange={(event) =>
                                                        setGroup(
                                                            'persetujuan',
                                                            'method',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className={inputClass}
                                                >
                                                    <option value="">
                                                        — Pilih metode —
                                                    </option>
                                                    {Object.entries(
                                                        options.methods,
                                                    ).map(([value, label]) => (
                                                        <option
                                                            key={value}
                                                            value={value}
                                                        >
                                                            {label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </Field>
                                        </div>
                                    </div>

                                    <div className="space-y-3 rounded-lg border border-slate-200 p-4">
                                        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-600">
                                            Keputusan
                                        </h3>
                                        <div className="flex flex-wrap gap-2">
                                            {DECISION_STATUSES.map(
                                                (status) => (
                                                    <span
                                                        key={status}
                                                        aria-disabled="true"
                                                        className={`rounded-full border px-3 py-1.5 text-xs font-semibold ${
                                                            status === 'BARU'
                                                                ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
                                                                : 'border-slate-200 bg-slate-50 text-slate-400'
                                                        }`}
                                                    >
                                                        {status}
                                                    </span>
                                                ),
                                            )}
                                        </div>
                                        <Field label="Keterangan keputusan">
                                            <textarea
                                                rows="4"
                                                value={form.persetujuan.notes}
                                                onChange={(event) =>
                                                    setGroup(
                                                        'persetujuan',
                                                        'notes',
                                                        event.target.value,
                                                    )
                                                }
                                                className={inputClass}
                                            />
                                        </Field>
                                    </div>
                                </div>
                            )}

                            {section.key === 'persyaratan' && (
                                <div className="space-y-4">
                                    <div className="flex gap-2">
                                        <input
                                            type="text"
                                            value={draftSyarat}
                                            onChange={(event) =>
                                                setDraftSyarat(
                                                    event.target.value,
                                                )
                                            }
                                            onKeyDown={(event) => {
                                                if (event.key === 'Enter') {
                                                    event.preventDefault();
                                                    addSyarat();
                                                }
                                            }}
                                            placeholder="Tulis persyaratan tambahan…"
                                            className={inputClass}
                                        />
                                        <button
                                            type="button"
                                            onClick={addSyarat}
                                            className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            Tambah
                                        </button>
                                    </div>

                                    {form.persyaratan.length === 0 ? (
                                        <p className="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-400">
                                            Belum ada persyaratan tambahan.
                                        </p>
                                    ) : (
                                        <ul className="space-y-2">
                                            {form.persyaratan.map(
                                                (item, index) => (
                                                    <li
                                                        key={`${item.text}-${index}`}
                                                        className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2"
                                                    >
                                                        <label className="flex items-center gap-2 text-sm text-slate-700">
                                                            <input
                                                                type="checkbox"
                                                                checked={
                                                                    item.done
                                                                }
                                                                onChange={() =>
                                                                    toggleSyarat(
                                                                        index,
                                                                    )
                                                                }
                                                            />
                                                            <span
                                                                className={
                                                                    item.done
                                                                        ? 'text-slate-400 line-through'
                                                                        : ''
                                                                }
                                                            >
                                                                {item.text}
                                                            </span>
                                                        </label>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                removeSyarat(
                                                                    index,
                                                                )
                                                            }
                                                            className="text-rose-500 hover:text-rose-700"
                                                            aria-label={`Hapus persyaratan ${item.text}`}
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </button>
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </div>
                            )}
                        </AccordionSection>
                    ))}
                </div>

                {/* Aksi: Simpan mengirim satu payload 11 section (create
                    maupun edit) — processing men-gate double submit. */}
                <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow">
                    <Link
                        href={
                            application
                                ? route('shm.loans.pengajuan.show', application.id)
                                : route('shm.loans.index')
                        }
                    >
                        <SecondaryButton type="button">Kembali</SecondaryButton>
                    </Link>
                    <div className="flex items-center gap-3">
                        <p className="hidden text-xs text-slate-500 sm:block">
                            Pengajuan disimpan ke server; keputusan approval
                            tetap melalui modul Approval SHM existing.
                        </p>
                        <PrimaryButton
                            type="button"
                            disabled={processing}
                            onClick={submit}
                        >
                            {processing
                                ? 'Menyimpan…'
                                : application
                                  ? 'Simpan Perubahan'
                                  : 'Simpan'}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </ShmLayout>
    );
}
