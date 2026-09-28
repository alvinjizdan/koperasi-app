<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot posisi saldo pinjaman (Ticket 25).
 *
 * Immutable: model menolak update/delete lewat jalur aplikasi biasa. Nilai
 * uang memakai decimal(15,2) — bukan floating-point. as_of_date (tanggal
 * posisi) dipisahkan dari created_at (waktu pengambilan aktual).
 */
class LoanBalanceSnapshot extends Model
{
    public const CONTEXT_MANUAL = 'manual';

    public const CONTEXT_BATCH = 'batch';

    protected $fillable = [
        'loan_id',
        'as_of_date',
        'context',
        'status',
        'outstanding_principal',
        'outstanding_interest',
        'outstanding_penalty',
        'total_owed',
        'total_paid',
        'schedule_count',
        'unpaid_schedule_count',
        'captured_by',
    ];

    protected $casts = [
        // as_of_date dipakai sebagai string Y-m-d agar pencocokan tanggal posisi
        // konsisten lintas driver (SQLite/PostgreSQL) tanpa geser zona waktu.
        'as_of_date' => 'string',
        'outstanding_principal' => 'decimal:2',
        'outstanding_interest' => 'decimal:2',
        'outstanding_penalty' => 'decimal:2',
        'total_owed' => 'decimal:2',
        'total_paid' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Immutable via jalur aplikasi biasa: update & delete ditolak.
        static::updating(function () {
            abort(405, 'Snapshot posisi pinjaman bersifat immutable dan tidak dapat diubah.');
        });

        static::deleting(function () {
            abort(405, 'Snapshot posisi pinjaman tidak dapat dihapus melalui jalur aplikasi.');
        });
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }
}
