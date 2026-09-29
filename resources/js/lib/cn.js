import { clsx } from './clsx';

/**
 * Menggabungkan class Tailwind; kelas terakhir menang saat konflik
 * (pengganti ringan `tailwind-merge` untuk kebutuhan komponen UI).
 */
export function cn(...inputs) {
    return clsx(inputs);
}
