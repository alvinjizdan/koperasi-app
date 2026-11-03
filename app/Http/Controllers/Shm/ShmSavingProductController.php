<?php

namespace App\Http\Controllers\Shm;

use App\Http\Controllers\Concerns\InteractsWithSorting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shm\StoreShmSavingProductRequest;
use App\Http\Requests\Shm\UpdateShmSavingProductRequest;
use App\Models\Shm\ShmSavingProduct;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShmSavingProductController extends Controller
{
    use InteractsWithSorting;

    /**
     * Tampilkan daftar produk simpanan SHM.
     */
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $query = ShmSavingProduct::query()
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

        return Inertia::render('Shm/Savings/Products/Index', [
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
     * Tampilkan form tambah produk simpanan SHM.
     */
    public function create(): Response
    {
        return Inertia::render('Shm/Savings/Products/Create', [
            'categories' => ShmSavingProduct::CATEGORIES,
        ]);
    }

    /**
     * Simpan produk simpanan SHM baru.
     */
    public function store(StoreShmSavingProductRequest $request): RedirectResponse
    {
        $product = ShmSavingProduct::create($request->validated());

        AuditLogger::log(
            'shm.savings.product.created',
            ShmSavingProduct::class,
            $product->id,
            $product->code.' - '.$product->name,
            'Produk simpanan SHM baru dibuat.',
            ['category' => $product->category, 'is_active' => $product->is_active]
        );

        return redirect()
            ->route('shm.savings.products.index')
            ->with('success', 'Produk simpanan SHM '.$product->name.' berhasil ditambahkan.');
    }

    /**
     * Tampilkan form edit produk simpanan SHM.
     */
    public function edit(ShmSavingProduct $product): Response
    {
        return Inertia::render('Shm/Savings/Products/Edit', [
            'product' => $product,
            'categories' => ShmSavingProduct::CATEGORIES,
        ]);
    }

    /**
     * Perbarui data produk simpanan SHM.
     */
    public function update(UpdateShmSavingProductRequest $request, ShmSavingProduct $product): RedirectResponse
    {
        $product->update($request->validated());

        AuditLogger::logChanges(
            $product,
            'shm.savings.product.updated',
            ['code', 'name', 'category', 'description', 'is_active'],
            'Produk simpanan SHM diperbarui.'
        );

        return redirect()
            ->route('shm.savings.products.index')
            ->with('success', 'Produk simpanan SHM '.$product->name.' berhasil diperbarui.');
    }

    /**
     * Nonaktifkan produk simpanan SHM secara aman (soft deactivate).
     */
    public function deactivate(ShmSavingProduct $product): RedirectResponse
    {
        if (! $product->is_active) {
            return redirect()->back()->with('error', 'Produk sudah berstatus nonaktif.');
        }

        $product->update(['is_active' => false]);

        AuditLogger::log(
            'shm.savings.product.deactivated',
            ShmSavingProduct::class,
            $product->id,
            $product->code.' - '.$product->name,
            sprintf('Produk simpanan SHM dinonaktifkan (%d rekening terikat). Transaksi baru pada rekening produk ini ditolak.', $product->savingsAccounts()->count()),
            ['linked_accounts' => $product->savingsAccounts()->count()]
        );

        return redirect()
            ->back()
            ->with('success', 'Produk simpanan SHM '.$product->name.' dinonaktifkan. Rekening lama tetap dapat dilihat; transaksi baru ditolak.');
    }
}
