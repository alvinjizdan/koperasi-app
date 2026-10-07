<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman masuk aplikasi SHM (Ticket 48).
 *
 * Controller ini HANYA menyiapkan tampilan pintu masuk SHM. TIDAK ada backend
 * otentikasi kedua: formulirnya mengirim ke route `login` yang sudah ada
 * (AuthenticatedSessionController@store), sehingga verifikasi kredensial,
 * pembatasan percobaan, penolakan akun nonaktif, regenerasi session, dan
 * pencatatan waktu login terakhir seluruhnya memakai implementasi yang sama.
 *
 * Satu-satunya penanda tambahan dari halaman ini adalah input `context=shm`
 * pada formulir, yang dipakai controller login bersama untuk menentukan
 * halaman tujuan setelah berhasil masuk.
 */
class ShmLoginController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Shm/Auth/Login', [
            // Pemulihan password memakai alur yang sudah ada; SHM tidak punya
            // alur sendiri dan tidak menyimpan kredensial terpisah.
            'canResetPassword' => Route::has('password.request'),
            'status' => $request->session()->get('status'),
        ]);
    }
}
