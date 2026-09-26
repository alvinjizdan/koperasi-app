<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Pusat pengaturan: ringkasan bagian yang dapat diakses pengguna.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Pengaturan/Index', [
            'can' => [
                'pengguna.view' => $user->can('pengguna.view'),
                'role.view' => $user->can('role.view'),
                'audit.view' => $user->can('audit.view'),
            ],
        ]);
    }

    /**
     * Halaman profil koperasi.
     */
    public function profile(): Response
    {
        return Inertia::render('Pengaturan/Profile', [
            'settings' => $this->settings->all(),
            'definitions' => $this->settings->definitions(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Halaman pengaturan operasional.
     */
    public function operational(): Response
    {
        return Inertia::render('Pengaturan/Operational', [
            'settings' => $this->settings->all(),
            'definitions' => $this->settings->definitions(),
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Simpan perubahan pengaturan (dipakai kedua halaman).
     */
    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $changes = $this->settings->updateMany($request->validated());

        if ($changes === []) {
            return redirect()
                ->back()
                ->with('success', 'Tidak ada perubahan pengaturan yang perlu disimpan.');
        }

        AuditLogger::log(
            'settings.updated',
            'Setting',
            null,
            'Pengaturan aplikasi',
            count($changes).' kunci pengaturan diperbarui.',
            $changes
        );

        return redirect()
            ->back()
            ->with('success', 'Pengaturan berhasil disimpan.');
    }

    /**
     * Halaman keamanan akun milik pengguna yang sedang login.
     */
    public function security(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Pengaturan/Security', [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
                'roles' => $user->roles()->pluck('display_name'),
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }
}
