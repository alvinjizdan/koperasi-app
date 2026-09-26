/**
 * Format angka menjadi format Rupiah.
 * Contoh: 1500000 → "Rp 1.500.000"
 */
export function formatRupiah(amount) {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount;
    if (isNaN(num)) {
        return 'Rp 0';
    }

    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(num);
}

/**
 * Format angka dengan pemisah ribuan Indonesia.
 * Contoh: 1234 → "1.234"
 */
export function formatNumber(value) {
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (num === null || num === undefined || isNaN(num)) {
        return '0';
    }

    return new Intl.NumberFormat('id-ID').format(num);
}

/**
 * Format tanggal ke format lokal Indonesia.
 * Contoh: "2026-01-15" → "15 Januari 2026"
 */
export function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString + (dateString.length === 10 ? 'T00:00:00' : ''));
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

/**
 * Format datetime ke format lokal Indonesia.
 */
export function formatDateTime(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
