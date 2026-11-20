<?php

namespace App\Http\Middleware;

use App\Enums\EntityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveEntityContext
{
    /**
     * Resolusi dan tetapkan context entitas backend pada request.
     * Context tidak pernah mempercayai input atau header dari client browser.
     */
    public function handle(Request $request, Closure $next, ?string $explicitContext = null): Response
    {
        $resolved = null;

        if ($explicitContext !== null) {
            $resolved = EntityContext::tryFrom($explicitContext);
        }

        if ($resolved === null) {
            $resolved = $this->resolveFromRoute($request);
        }

        if ($resolved === null) {
            // Fail closed: Jika route terproteksi tidak memiliki context yang sah, tolak akses (403)
            abort(403, 'Akses ditolak: Konteks entitas rute tidak valid atau tidak terdefinisi.');
        }

        // Ikat instance EntityContext aktif ke container dan request attribute
        app()->instance(EntityContext::class, $resolved);
        $request->attributes->set('entity_context', $resolved);

        return $next($request);
    }

    /**
     * Tentukan context secara deterministik dari path pattern rute.
     */
    protected function resolveFromRoute(Request $request): ?EntityContext
    {
        $path = trim($request->path(), '/');

        // 1. Modul BSM Makro (SHM & aktivitas marketing lapangan)
        if ($path === 'shm' || str_starts_with($path, 'shm/')) {
            return EntityContext::Makro;
        }

        // 2. Modul Shared (Autentikasi, Profil, Dashboard pengantar, Pengaturan Umum, Users, Roles, Audit)
        if (
            $path === ''
            || $path === 'dashboard'
            || $path === 'profile' || str_starts_with($path, 'profile/')
            || $path === 'pengaturan' || str_starts_with($path, 'pengaturan/')
            || $path === 'login' || $path === 'logout'
            || $path === 'forgot-password' || $path === 'reset-password' || str_starts_with($path, 'reset-password/')
            || $path === 'confirm-password' || $path === 'password'
            || $path === 'verify-email' || str_starts_with($path, 'verify-email/') || $path === 'email/verification-notification'
        ) {
            return EntityContext::Shared;
        }

        // 3. Modul BSM Mikro (Koperasi, Simpanan, Pinjaman Reguler, Angsuran, Kasir, Akuntansi Koperasi, Laporan, Rekonsiliasi, Titik Lokasi Anggota)
        $mikroPrefixes = [
            'anggota',
            'simpanan',
            'pinjaman',
            'angsuran',
            'kasir',
            'akuntansi',
            'laporan',
            'rekonsiliasi',
            'marketing/anggota', // update lokasi anggota koperasi
        ];

        foreach ($mikroPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return EntityContext::Mikro;
            }
        }

        return null;
    }
}
