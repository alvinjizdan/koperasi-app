import React from 'react';
import { useForm } from '@inertiajs/react';
import { Save, CheckCircle2, AlertCircle } from 'lucide-react';

/**
 * Form generik untuk halaman pengaturan: merender field berdasarkan definisi
 * dari backend (config/settings.php) dan menyimpannya ke satu endpoint update.
 */
export default function SettingsForm({ settings, definitions, updateRoute, groups }) {
    const { data, setData, put, processing, recentlySuccessful, errors } = useForm(
        Object.fromEntries(Object.keys(definitions).map((key) => [key, settings[key] ?? '']))
    );

    const handleSubmit = (e) => {
        e.preventDefault();
        put(updateRoute);
    };

    const renderField = (key, definition) => {
        const value = data[key] ?? '';
        const hasError = !!errors[key];

        const baseClass = `mt-1.5 w-full rounded-lg border px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:ring-1 sm:text-sm ${
            hasError
                ? 'border-rose-400 bg-rose-50/30 focus:border-rose-600 focus:ring-rose-600'
                : 'border-slate-300 bg-white focus:border-slate-900 focus:ring-slate-900'
        }`;

        return (
            <div key={key} className={definition.type === 'textarea' ? 'sm:col-span-2' : ''}>
                <label htmlFor={key} className="block text-xs font-semibold text-slate-900 sm:text-sm">
                    {definition.label}
                    {definition.required && <span className="text-rose-500"> *</span>}
                </label>

                {definition.type === 'textarea' && (
                    <textarea
                        id={key}
                        rows={2}
                        value={value}
                        onChange={(e) => setData(key, e.target.value)}
                        className={baseClass}
                    />
                )}

                {definition.type === 'select' && (
                    <select
                        id={key}
                        value={value}
                        onChange={(e) => setData(key, e.target.value)}
                        className={baseClass}
                    >
                        {Object.entries(definition.options || {}).map(([optionValue, label]) => (
                            <option key={optionValue} value={optionValue}>
                                {label}
                            </option>
                        ))}
                    </select>
                )}

                {(definition.type === 'text' || definition.type === 'email') && (
                    <input
                        id={key}
                        type={definition.type}
                        value={value}
                        onChange={(e) => setData(key, e.target.value)}
                        className={baseClass}
                    />
                )}

                {errors[key] && <p className="mt-1 text-xs font-medium text-rose-600">{errors[key]}</p>}
                {definition.help && !errors[key] && (
                    <p className="mt-1 text-[11px] text-slate-400">{definition.help}</p>
                )}
            </div>
        );
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            {groups.map((group) => {
                const keys = Object.entries(definitions).filter(([, d]) => d.group === group.key);

                if (keys.length === 0) return null;

                return (
                    <div key={group.key} className="rounded-xl border border-slate-200 bg-white p-6 shadow-xs sm:p-8">
                        <h3 className="border-b border-slate-100 pb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">
                            {group.label}
                        </h3>
                        <div className="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {keys.map(([key, definition]) => renderField(key, definition))}
                        </div>
                    </div>
                );
            })}

            <div className="flex items-center justify-end gap-3">
                {recentlySuccessful && (
                    <span className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700">
                        <CheckCircle2 className="h-4 w-4" />
                        Tersimpan
                    </span>
                )}
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-6 py-2.5 text-xs font-semibold text-white shadow-xs transition-all hover:bg-slate-800 disabled:opacity-50 active:scale-95 sm:text-sm"
                >
                    <Save className="h-4 w-4" />
                    <span>{processing ? 'Menyimpan...' : 'Simpan Pengaturan'}</span>
                </button>
            </div>

            {Object.keys(errors).length > 0 && (
                <div className="flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-800">
                    <AlertCircle className="h-5 w-5 shrink-0 text-rose-600" />
                    <span className="font-medium">Periksa kembali isian yang ditandai kesalahan.</span>
                </div>
            )}
        </form>
    );
}
