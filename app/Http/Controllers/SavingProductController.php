<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Requests\StoreSavingProductRequest;
use App\Http\Requests\UpdateSavingProductRequest;
use App\Models\SavingProduct;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SavingProductController extends Controller
{
    use InteractsWithSorting;

    /**
     * Tampilkan daftar produk simpanan.
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $query = SavingProduct::query()
            ->when($search, fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"));

        $sortStatus = $this->applySorting($query, $request, [
            'code' => 'code',
            'name' => 'name',
            'category' => 'category',
            'is_active' => 'is_active',
            'created_at' => 'created_at',
        ], [['code', 'asc']]);

        $products = $query->paginate($this->resolvePerPage($request))->withQueryString();

        return Inertia::render('Simpanan/Produk/Index', [
            'products' => $products,
            'sort' => $sortStatus,
            'filters' => ['search' => $search ?? ''],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
        ]);
    }

    /**
     * Tampilkan form tambah produk simpanan.
     */
    public function create(): Response
    {
        return Inertia::render('Simpanan/Produk/Create');
    }

    /**
     * Simpan produk simpanan baru.
     */
    public function store(StoreSavingProductRequest $request): RedirectResponse
    {
        $product = SavingProduct::create($request->validated());

        AuditLogger::log(
            'savings.product.created',
            SavingProduct::class,
            $product->id,
            $product->code.' - '.$product->name,
            'Produk simpanan baru dibuat.',
            ['category' => $product->category, 'is_active' => $product->is_active]
        );

        return redirect()
            ->route('simpanan-produk.index')
            ->with('success', 'Produk simpanan '.$product->name.' berhasil ditambahkan.');
    }

    /**
     * Tampilkan form edit produk simpanan.
     */
    public function edit(SavingProduct $savingProduct): Response
    {
        return Inertia::render('Simpanan/Produk/Edit', [
            'product' => $savingProduct,
        ]);
    }

    /**
     * Perbarui data produk simpanan.
     */
    public function update(UpdateSavingProductRequest $request, SavingProduct $savingProduct): RedirectResponse
    {
        $savingProduct->update($request->validated());

        // Referensi historis aman: transaksi/rekening merujuk ID produk;
        // perubahan tidak bersifat retroaktif terhadap nominal transaksi.
        AuditLogger::logChanges(
            $savingProduct,
            'savings.product.updated',
            ['code', 'name', 'category', 'description', 'is_active'],
            'Produk simpanan diperbarui.'
        );

        return redirect()
            ->route('simpanan-produk.index')
            ->with('success', 'Produk simpanan '.$savingProduct->name.' berhasil diperbarui.');
    }

    /**
     * Nonaktifkan produk simpanan (Ticket 20).
     *
     * Produk DENGAN rekening terikat TETAP BISA dinonaktifkan: rekening dan
     * histori lama tetap dapat dilihat, namun transaksi baru pada rekening
     * produk tersebut ditolak (divalidasi di SavingsTransactionService).
     * Penghapusan produk tidak tersedia agar referensi historis tetap utuh.
     */
    public function deactivate(SavingProduct $savingProduct): RedirectResponse
    {
        if (! $savingProduct->is_active) {
            return redirect()->back()->with('error', 'Produk sudah berstatus nonaktif.');
        }

        $savingProduct->update(['is_active' => false]);

        AuditLogger::log(
            'savings.product.deactivated',
            SavingProduct::class,
            $savingProduct->id,
            $savingProduct->code.' - '.$savingProduct->name,
            sprintf('Produk simpanan dinonaktifkan (%d rekening terikat). Transaksi baru pada rekening produk ini ditolak.', $savingProduct->savingsAccounts()->count()),
            ['linked_accounts' => $savingProduct->savingsAccounts()->count()]
        );

        return redirect()
            ->back()
            ->with('success', 'Produk simpanan '.$savingProduct->name.' dinonaktifkan. Rekening lama tetap dapat dilihat; transaksi baru ditolak.');
    }
}
