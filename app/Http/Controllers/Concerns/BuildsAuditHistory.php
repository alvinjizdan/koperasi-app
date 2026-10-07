<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Collection;

/**
 * Riwayat audit sebuah objek untuk ditampilkan pada halaman detail.
 *
 * Ini memakai satu sumber timeline yang sudah ada, yaitu tabel audit_logs.
 * Tidak ada timeline kedua: halaman pengajuan maupun halaman credit checking
 * membaca event audit yang sama.
 */
trait BuildsAuditHistory
{
    /**
     * Riwayat audit terbaru sebuah objek.
     *
     * @return Collection<int, AuditLog>
     */
    protected function auditHistory(string $objectType, int $objectId, int $limit = 50)
    {
        return AuditLog::query()
            ->where('object_type', $objectType)
            ->where('object_id', $objectId)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
