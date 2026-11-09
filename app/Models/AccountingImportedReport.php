<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingImportedReport extends Model
{
    protected $fillable = [
        'batch_id',
        'accounting_context',
        'report_type',
        'report_date',
        'period_start',
        'period_end',
        'section',
        'account_code',
        'account_name',
        'debit',
        'credit',
        'amount',
        'description',
        'extra_attributes',
        'row_index',
    ];

    protected $casts = [
        'report_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'amount' => 'decimal:2',
        'extra_attributes' => 'array',
        'row_index' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AccountingImportBatch::class, 'batch_id');
    }
}
