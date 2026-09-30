import { router } from '@inertiajs/react';

/**
 * Handler sorting bersama untuk tabel Inertia.
 *
 * Mengirim sort/direction sebagai query string dan menghapus `page` agar
 * pengguna selalu kembali ke halaman pertama setelah urutan berubah. Parameter
 * lain di URL dipertahankan supaya filter aktif tidak hilang.
 */
export default function useTableSort(routeName, routeParams = [], pageParam = 'page') {
    return (column) => {
        const params = new URLSearchParams(window.location.search);
        const current = params.get('sort');
        const currentDirection = params.get('direction');

        const direction =
            current === column && currentDirection === 'asc' ? 'desc' : 'asc';

        params.set('sort', column);
        params.set('direction', direction);
        params.delete(pageParam);

        router.get(route(routeName, ...routeParams), Object.fromEntries(params), {
            preserveState: true,
            replace: true,
        });
    };
}
