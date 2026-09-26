<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Menonaktifkan akun sendiri. Akun tidak dihapus secara fisik agar
     * referensi transaksi dan audit historis tetap utuh.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Jaga agar minimal satu Superadmin aktif selalu tersedia.
        if ($user->isLastActiveSuperadmin()) {
            return Redirect::route('profile.edit')
                ->with('error', 'Akun Anda adalah satu-satunya Superadmin aktif dan tidak dapat dinonaktifkan.');
        }

        AuditLogger::log(
            'user.self_deactivated',
            'User',
            $user->id,
            $user->email,
            'Pengguna '.$user->name.' menonaktifkan akunnya sendiri.'
        );

        $user->update(['is_active' => false]);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
