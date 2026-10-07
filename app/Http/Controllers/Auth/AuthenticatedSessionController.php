<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     *
     * Dipakai bersama oleh halaman login koperasi dan pintu masuk SHM
     * (Ticket 48). Permintaan masuk dari SHM menyertakan `context=shm` sehingga
     * tujuan bawaannya adalah dashboard SHM; tanpa penanda itu perilakunya
     * sama persis seperti sebelumnya. Otentikasinya tetap satu implementasi.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Catat waktu login terakhir untuk halaman keamanan akun.
        $request->user()?->forceFill(['last_login_at' => now()])->save();

        $fallback = $request->input('context') === 'shm'
            ? route('shm.dashboard', absolute: false)
            : route('dashboard', absolute: false);

        return redirect()->intended($fallback);
    }

    /**
     * Destroy an authenticated session.
     *
     * Satu implementasi untuk kedua area: permintaan keluar dari SHM
     * menyertakan `context=shm` sehingga pengguna dikembalikan ke pintu masuk
     * SHM, sedangkan keluar dari aplikasi koperasi tetap menuju halaman utama.
     * Tidak ada session atau guard kedua.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return $request->input('context') === 'shm'
            ? redirect()->route('shm.login')
            : redirect('/');
    }
}
