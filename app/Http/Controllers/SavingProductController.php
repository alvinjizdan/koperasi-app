<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavingProductRequest;
use App\Http\Requests\UpdateSavingProductRequest;
use App\Models\SavingProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SavingProductController extends Controller
{
    /**
     * Tampilkan daftar produk simpanan.
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $products = SavingProduct::query()
            ->when($search, fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                ->orWhere('code', 'ilike', "%{$search}%"))
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Simpanan/Produk/Index', [
            'products' => $products,
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

        return redirect()
            ->route('simpanan-produk.index')
            ->with('success', 'Produk simpanan '.$savingProduct->name.' berhasil diperbarui.');
    }

    /**
     * Nonaktifkan produk simpanan. Tidak diizinkan jika sudah ada rekening terikat.
     */
    public function deactivate(SavingProduct $savingProduct): RedirectResponse
    {
        if ($savingProduct->hasAccounts()) {
            return redirect()
                ->back()
                ->with('error', 'Produk tidak dapat dinonaktifkan karena sudah ada rekening simpanan yang menggunakan produk ini.');
        }

        $savingProduct->update(['is_active' => false]);

        return redirect()
            ->back()
            ->with('success', 'Produk simpanan '.$savingProduct->name.' berhasil dinonaktifkan.');
    }
}
