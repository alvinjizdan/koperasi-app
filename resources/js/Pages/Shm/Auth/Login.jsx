import React from "react";
import Checkbox from "@/Components/Checkbox";
import InputError from "@/Components/InputError";
import InputLabel from "@/Components/InputLabel";
import PasswordInput from "@/Components/PasswordInput";
import PrimaryButton from "@/Components/PrimaryButton";
import TextInput from "@/Components/TextInput";
import { Head, Link, useForm } from "@inertiajs/react";

/**
 * Pintu masuk aplikasi SHM.
 *
 * Formulir ini mengirim ke controller login yang SAMA dengan aplikasi koperasi
 * (`AuthenticatedSessionController@store`). Penanda `context: "shm"` hanya
 * menentukan tujuan setelah berhasil masuk, yaitu dashboard SHM. Tidak ada
 * backend, session, atau penyimpanan kredensial kedua.
 *
 * Komponen form memakai komponen yang sudah ada supaya tampilan tetap konsisten
 * dengan aplikasi koperasi; yang berbeda hanya identitas area SHM.
 */
export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: "",
        password: "",
        remember: false,
        context: "shm",
    });

    const submit = (event) => {
        event.preventDefault();

        post(route("shm.login.store"), {
            onFinish: () => reset("password"),
        });
    };

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-slate-100 px-4 py-10 font-sans antialiased">
            <Head title="Masuk SHM" />

            <div className="mb-6 flex items-center gap-3">
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-base font-bold text-white shadow-sm">
                    SHM
                </div>
                <div>
                    <p className="text-lg font-semibold tracking-tight text-slate-900">
                        Sistem Manajemen SHM
                    </p>
                    <p className="text-xs font-medium text-slate-500">
                        KSP Bima Sakti Mandiri
                    </p>
                </div>
            </div>

            <div className="w-full max-w-md overflow-hidden rounded-xl border border-slate-200 bg-white px-6 py-6 shadow-sm">
                {status && (
                    <div className="mb-4 text-sm font-medium text-green-600">
                        {status}
                    </div>
                )}

                <form onSubmit={submit}>
                    <div>
                        <InputLabel htmlFor="email" value="Email" />

                        <TextInput
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="mt-1 block w-full"
                            autoComplete="username"
                            isFocused={true}
                            onChange={(event) =>
                                setData("email", event.target.value)
                            }
                        />

                        <InputError
                            message={errors.email}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="password" value="Password" />

                        <PasswordInput
                            id="password"
                            name="password"
                            value={data.password}
                            className="mt-1 block w-full"
                            autoComplete="current-password"
                            onChange={(event) =>
                                setData("password", event.target.value)
                            }
                        />

                        <InputError
                            message={errors.password}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-4 block">
                        <label className="flex items-center">
                            <Checkbox
                                name="remember"
                                checked={data.remember}
                                onChange={(event) =>
                                    setData("remember", event.target.checked)
                                }
                            />
                            <span className="ms-2 text-sm text-gray-600">
                                Ingat saya
                            </span>
                        </label>
                    </div>

                    <div className="mt-6 flex items-center justify-end gap-3">
                        {canResetPassword && (
                            <Link
                                href={route("password.request")}
                                className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Lupa password?
                            </Link>
                        )}

                        <PrimaryButton disabled={processing}>
                            Masuk
                        </PrimaryButton>
                    </div>
                </form>

                <div className="mt-6 border-t border-slate-100 pt-4 text-xs text-slate-500">
                    <p>
                        Akun, role, dan password SHM sama dengan akun aplikasi
                        koperasi. Hubungi administrator koperasi bila akun Anda
                        belum memiliki akses SHM.
                    </p>
                    <Link
                        href={route("login")}
                        className="mt-2 inline-block font-semibold text-indigo-600 hover:underline"
                    >
                        Masuk sebagai pengguna koperasi
                    </Link>
                </div>
            </div>
        </div>
    );
}
