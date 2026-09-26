import React from 'react';
import { useForm, Link } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Edit3, ArrowLeft, Save } from 'lucide-react';

export default function Edit({ member }) {
    const { data, setData, put, processing, errors } = useForm({
        member_number: member.member_number || '',
        full_name: member.full_name || '',
        identity_number: member.identity_number || '',
        phone: member.phone || '',
        address: member.address || '',
        joined_at: member.joined_at || '',
        status: member.status || 'active',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('anggota.update', member.id));
    };

    return (
        <AdminLayout
            title={`Edit ${member.full_name}`}
            pageTitle="Edit Data Anggota"
        >
            {/* Header Form */}
            <div className="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                <div>
                    <h2 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl flex items-center gap-2">
                        <Edit3 className="h-6 w-6 text-emerald-600" />
                        <span>Edit Data Anggota</span>
                    </h2>
                    <p className="mt-1 text-xs sm:text-sm text-slate-500">
                        Perbarui informasi keanggotaan {member.full_name} (
                        {member.member_number}).
                    </p>
                </div>

                <Link
                    href={route('anggota.show', member.id)}
                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors active:scale-95"
                >
                    <ArrowLeft className="h-4 w-4" />
                    <span>Kembali ke Detail</span>
                </Link>
            </div>

            {/* Form Card */}
            <div className="rounded-xl border border-slate-200 bg-white p-6 sm:p-8 shadow-xs max-w-4xl mx-auto">
                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Section 1: Identitas */}
                    <div>
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 pb-2 border-b border-slate-100">
                            Identitas Keanggotaan
                        </h3>

                        <div className="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {/* Nomor Anggota */}
                            <div>
                                <label
                                    htmlFor="member_number"
                                    className="block text-xs sm:text-sm font-semibold text-slate-900"
                                >
                                    Nomor Anggota{' '}
                                    <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="member_number"
                                    value={data.member_number}
                                    onChange={(e) =>
                                        setData('member_number', e.target.value)
                                    }
                                    className={`mt-1.5 w-full rounded-lg border px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:outline-none focus:ring-1 ${
                                        errors.member_number
                                            ? 'border-rose-400 bg-rose-50/30 focus:border-rose-600 focus:ring-rose-600'
                                            : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900'
                                    }`}
                                />
                                {errors.member_number && (
                                    <p className="mt-1 text-xs text-rose-600 font-medium">
                                        {errors.member_number}
                                    </p>
                                )}
                            </div>

                            {/* Nama Lengkap */}
                            <div>
                                <label
                                    htmlFor="full_name"
                                    className="block text-xs sm:text-sm font-semibold text-slate-900"
                                >
                                    Nama Lengkap{' '}
                                    <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="full_name"
                                    value={data.full_name}
                                    onChange={(e) =>
                                        setData('full_name', e.target.value)
                                    }
                                    className={`mt-1.5 w-full rounded-lg border px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:outline-none focus:ring-1 ${
                                        errors.full_name
                                            ? 'border-rose-400 bg-rose-50/30 focus:border-rose-600 focus:ring-rose-600'
                                            : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900'
                                    }`}
                                />
                                {errors.full_name && (
                                    <p className="mt-1 text-xs text-rose-600 font-medium">
                                        {errors.full_name}
                                    </p>
                                )}
                            </div>

                            {/* NIK / Nomor Identitas */}
                            <div>
                                <label
                                    htmlFor="identity_number"
                                    className="block text-xs sm:text-sm font-semibold text-slate-900"
                                >
                                    Nomor Identitas (NIK / KTP)
                                </label>
                                <input
                                    type="text"
                                    id="identity_number"
                                    value={data.identity_number}
                                    onChange={(e) =>
                                        setData(
                                            'identity_number',
                                            e.target.value
                                        )
                                    }
                                    placeholder="16 Digit NIK (Opsional)"
                                    className="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                                />
                                {errors.identity_number && (
                                    <p className="mt-1 text-xs text-rose-600 font-medium">
                                        {errors.identity_number}
                                    </p>
                                )}
                            </div>

                            {/* Nomor Telepon */}
                            <div>
                                <label
                                    htmlFor="phone"
                                    className="block text-xs sm:text-sm font-semibold text-slate-900"
                                >
                                    Nomor Telepon / HP
                                </label>
                                <input
                                    type="text"
                                    id="phone"
                                    value={data.phone}
                                    onChange={(e) =>
                                        setData('phone', e.target.value)
                                    }
                                    placeholder="Nomor HP aktif"
                                    className="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                                />
                                {errors.phone && (
                                    <p className="mt-1 text-xs text-rose-600 font-medium">
                                        {errors.phone}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Section 2: Alamat & Status */}
                    <div>
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-slate-500 pb-2 border-b border-slate-100">
                            Alamat & Status Keanggotaan
                        </h3>

                        <div className="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {/* Alamat */}
                            <div className="sm:col-span-2">
                                <label
                                    htmlFor="address"
                                    className="block text-xs sm:text-sm font-semibold text-slate-900"
                                >
                                    Alamat Tempat Tinggal
                                </label>
                                <textarea
                                    id="address"
                                    rows="3"
                                    value={data.address}
                                    onChange={(e) =>
                                        setData('address', e.target.value)
                                    }
                                    className="mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                                />
                                {errors.address && (
                                    <p className="mt-1 text-xs text-rose-600 font-medium">
                                        {errors.address}
                                    </p>
                                )}
                            </div>

                            {/* Tanggal Bergabung */}
                            <div>
                                <label
                                    htmlFor="joined_at"
                                    className="block text-xs sm:text-sm font-semibold text-slate-900"
                                >
                                    Tanggal Bergabung{' '}
                                    <span className="text-rose-500">*</span>
                                </label>
                                <input
                                    type="date"
                                    id="joined_at"
                                    value={data.joined_at}
                                    onChange={(e) =>
                                        setData('joined_at', e.target.value)
                                    }
                                    className={`mt-1.5 w-full rounded-lg border px-3.5 py-2 text-xs sm:text-sm text-slate-900 focus:outline-none focus:ring-1 ${
                                        errors.joined_at
                                            ? 'border-rose-400 bg-rose-50/30 focus:border-rose-600 focus:ring-rose-600'
                                            : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900'
                                    }`}
                                />
                                {errors.joined_at && (
                                    <p className="mt-1 text-xs text-rose-600 font-medium">
                                        {errors.joined_at}
                                    </p>
                                )}
                            </div>

                            {/* Status Anggota */}
                            <div>
                                <label className="block text-xs sm:text-sm font-semibold text-slate-900 mb-2">
                                    Status Keanggotaan{' '}
                                    <span className="text-rose-500">*</span>
                                </label>
                                <div className="flex items-center gap-6">
                                    <label className="inline-flex items-center gap-2 text-xs sm:text-sm font-medium text-slate-700 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="status"
                                            value="active"
                                            checked={data.status === 'active'}
                                            onChange={(e) =>
                                                setData(
                                                    'status',
                                                    e.target.value
                                                )
                                            }
                                            className="h-4 w-4 text-slate-900 focus:ring-slate-900 border-slate-300"
                                        />
                                        <span>Aktif</span>
                                    </label>
                                    <label className="inline-flex items-center gap-2 text-xs sm:text-sm font-medium text-slate-700 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="status"
                                            value="inactive"
                                            checked={data.status === 'inactive'}
                                            onChange={(e) =>
                                                setData(
                                                    'status',
                                                    e.target.value
                                                )
                                            }
                                            className="h-4 w-4 text-slate-900 focus:ring-slate-900 border-slate-300"
                                        />
                                        <span>Nonaktif</span>
                                    </label>
                                </div>
                                {errors.status && (
                                    <p className="mt-1 text-xs text-rose-600 font-medium">
                                        {errors.status}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Action Buttons */}
                    <div className="pt-6 border-t border-slate-200 flex items-center justify-end gap-3">
                        <Link
                            href={route('anggota.show', member.id)}
                            className="rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors active:scale-95"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-6 py-2.5 text-xs sm:text-sm font-semibold text-white shadow-xs hover:bg-slate-800 disabled:opacity-50 transition-all active:scale-95"
                        >
                            <Save className="h-4 w-4" />
                            <span>
                                {processing
                                    ? 'Memperbarui...'
                                    : 'Simpan Perubahan'}
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
