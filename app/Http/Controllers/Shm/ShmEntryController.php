<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pintu masuk aplikasi SHM (Ticket 48).
 *
 * `/shm` adalah boundary UI, BUKAN aplikasi terpisah: otentikasi tetap memakai
 * guard `web`, session, dan audit yang sama dengan aplikasi koperasi. Controller
 * ini hanya menentukan tujuan:
 *
 * - belum login            -> /shm/login
 * - login tanpa akses SHM  -> 403
 * - login dengan akses SHM -> /shm/dashboard
 *
 * Permintaan ulang permission dilakukan di middleware setiap route SHM; route
 * ini sengaja tidak memakai middleware `auth` supaya tamu dapat diarahkan ke
 * pintu masuk SHM, bukan ke halaman login koperasi.
 *
 * Batas akses memakai permission `shm.view` yang SUDAH ADA, supaya tidak ada
 * permission atau role baru hanya demi sebuah shell. Role kasir, pengawas, dan
 * role lain tanpa permission SHM tetap tidak memperoleh akses.
 */
class ShmEntryController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user() === null) {
            return redirect()->route('shm.login');
        }

        abort_unless(
            Gate::forUser($request->user())->allows('shm.view'),
            403,
            'Anda tidak memiliki akses ke aplikasi SHM.'
        );

        return redirect()->route('shm.dashboard');
    }
}
