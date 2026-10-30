import React from "react";
import Checkbox from "@/Components/Checkbox";
import InputError from "@/Components/InputError";
import InputLabel from "@/Components/InputLabel";
import PasswordInput from "@/Components/PasswordInput";
import PrimaryButton from "@/Components/PrimaryButton";
import TextInput from "@/Components/TextInput";
import { Head, Link, useForm } from "@inertiajs/react";

export default function Login({ status, canResetPassword }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: "",
        password: "",
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route("login"), {
            onFinish: () => reset("password"),
        });
    };

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-slate-100 px-4 py-10 font-sans antialiased selection:bg-bsm-accent selection:text-white">
            <Head title="Masuk BSM Mikro" />

            <div className="mb-6 flex items-center gap-3">
                <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white p-1.5 shadow-sm border border-slate-200">
                    <img
                        src="/images/logo.png"
                        alt="BSM Logo"
                        className="h-full w-full object-contain"
                    />
                </div>
                <div>
                    <p className="text-lg font-semibold tracking-tight text-slate-900">
                        BSM Mikro
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
                            onChange={(e) => setData("email", e.target.value)}
                        />

                        <InputError message={errors.email} className="mt-2" />
                    </div>

                    <div className="mt-4">
                        <InputLabel htmlFor="password" value="Password" />

                        <PasswordInput
                            id="password"
                            name="password"
                            value={data.password}
                            className="mt-1 block w-full"
                            autoComplete="current-password"
                            onChange={(e) => setData("password", e.target.value)}
                        />

                        <InputError message={errors.password} className="mt-2" />
                    </div>

                    <div className="mt-4 block">
                        <label className="flex items-center">
                            <Checkbox
                                name="remember"
                                checked={data.remember}
                                onChange={(e) =>
                                    setData("remember", e.target.checked)
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
                                className="rounded-md text-sm text-slate-600 underline hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-bsm-accent focus:ring-offset-2"
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
                    <Link
                        href={route("shm.login")}
                        className="inline-block font-semibold text-bsm-accent hover:underline"
                    >
                        Masuk sebagai pengguna BSM Makro
                    </Link>
                </div>
            </div>
        </div>
    );
}
