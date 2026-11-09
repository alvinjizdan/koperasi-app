<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingImportBatch extends Model
{
    public const TYPE_JOURNAL = 'journal';

    public const TYPE_REPORT = 'report';

    public const REPORT_BALANCE_SHEET = 'balance_sheet';

    public const REPORT_PERIOD_PROFIT_LOSS = 'period_profit_loss';

    public const REPORT_CUMULATIVE_PROFIT_LOSS = 'cumulative_profit_loss';

    public const REPORT_TRIAL_BALANCE = 'trial_balance';

    public const REPORT_DAILY_JOURNAL = 'daily_journal';

    protected $fillable = [
        'accounting_context',
        'import_type',
        'report_type',
        'filename',
        'checksum_hash',
        'imported_by',
        'row_count',
        'success_count',
        'failed_count',
        'status',
        'period_start',
        'period_end',
        'summary_meta',
    ];

    protected $casts = [
        'summary_meta' => 'array',
        'period_start' => 'date',
        'period_end' => 'date',
        'row_count' => 'integer',
        'success_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'import_batch_id');
    }

    public function importedReports(): HasMany
    {
        return $this->hasMany(AccountingImportedReport::class, 'batch_id');
    }
}
