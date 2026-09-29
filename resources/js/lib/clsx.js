/**
 * Utility classnames ringan (pengganti `clsx`).
 * Mendukung string, number, array (bersarang), dan object { kelas: kondisi }.
 */
export function clsx(...inputs) {
    const out = [];

    const walk = (input) => {
        if (!input && input !== 0) return;
        if (typeof input === 'string' || typeof input === 'number') {
            out.push(String(input));
            return;
        }
        if (Array.isArray(input)) {
            input.forEach(walk);
            return;
        }
        if (typeof input === 'object') {
            for (const [key, value] of Object.entries(input)) {
                if (value) out.push(key);
            }
        }
    };

    inputs.forEach(walk);
    return out.join(' ');
}
