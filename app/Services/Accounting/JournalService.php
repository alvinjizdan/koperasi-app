<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\AuditLogger;
use App\Support\DbTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Layanan jurnal umum double-entry (Ticket 18).
 *
 * Aturan inti:
 * - Jurnal minimal 2 baris; setiap baris tepat satu sisi bernilai (debit XOR
 *   kredit), nominal positif.
 * - Posting menuntut total debit = total kredit; dilakukan di dalam transaksi
 *   database dengan lock baris jurnal agar posting ganda/reversal ganda
 *   tidak mungkin terjadi pada kondisi race.
 * - Jurnal POSTED immutable: koreksi hanya via reversal yang menautkan jurnal
 *   pembalik ke asal (reversed_by_entry_id); jurnal asal tidak pernah diubah.
 * - Idempotensi sumber: (source_type, source_id, posting_type) unik — satu
 *   transaksi sumber hanya menghasilkan satu jurnal per jenis posting.
 */
class JournalService
{
    private const MAX_ATTEMPTS = 3;

    /**
     * Buat jurnal DRAFT dari baris-baris yang diberikan.
     *
     * @param  array<int, array{account_id: int, description?: ?string, debit: numeric, credit: numeric}>  $lines
     * @param  array{transaction_date: string, description?: ?string, source_type?: ?string, source_id?: int|string|null, posting_type?: ?string}  $data
     */
    public function createDraft(array $data, array $lines, int $userId): JournalEntry
    {
        $this->assertNoPartialDebitCredit($lines);

        return DbTransaction::run(function () use ($data, $lines, $userId) {
            $entry = JournalEntry::create([
                'journal_number' => $this->generateJournalNumber(),
                'transaction_date' => $data['transaction_date'],
                'description' => $data['description'] ?? null,
                'status' => JournalEntry::STATUS_DRAFT,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'posting_type' => $data['posting_type'] ?? 'manual',
                'created_by' => $userId,
            ]);

            $this->createLines($entry, $lines);

            AuditLogger::log(
                'accounting.journal.created',
                JournalEntry::class,
                $entry->id,
                $entry->journal_number,
                sprintf('Jurnal DRAFT %s dibuat (debit Rp %s, kredit Rp %s).', $entry->journal_number, number_format($entry->totalDebit(), 2, ',', '.'), number_format($entry->totalCredit(), 2, ',', '.')),
                ['transaction_date' => $entry->transaction_date->toDateString(), 'line_count' => count($lines)]
            );

            return $entry->fresh(['lines.account']);
        });
    }

    /**
     * Perbarui jurnal DRAFT (aturan 5.C.7). Jurnal POSTED ditolak.
     *
     * @param  array<int, array{account_id: int, description?: ?string, debit: numeric, credit: numeric}>  $lines
     */
    public function updateDraft(JournalEntry $entry, array $data, array $lines): JournalEntry
    {
        if ($entry->status !== JournalEntry::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Hanya jurnal berstatus DRAFT yang dapat diubah. Jurnal yang sudah diposting dikoreksi melalui reversal.'],
            ]);
        }

        $this->assertNoPartialDebitCredit($lines);

        return DbTransaction::run(function () use ($entry, $data, $lines) {
            $entry->update([
                'transaction_date' => $data['transaction_date'] ?? $entry->transaction_date,
                'description' => $data['description'] ?? $entry->description,
            ]);

            $entry->lines()->delete();
            $this->createLines($entry, $lines);

            AuditLogger::log(
                'accounting.journal.updated',
                JournalEntry::class,
                $entry->id,
                $entry->journal_number,
                sprintf('Jurnal DRAFT %s diperbarui.', $entry->journal_number),
                ['line_count' => count($lines)]
            );

            return $entry->fresh(['lines.account']);
        });
    }

    /**
     * Posting jurnal: validasi keseimbangan + atomik + cegah posting ganda.
     */
    public function post(JournalEntry $entry, int $userId): JournalEntry
    {
        return DbTransaction::run(function () use ($entry, $userId) {
            $locked = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($locked->status !== JournalEntry::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya jurnal DRAFT yang dapat diposting (status saat ini: '.$locked->status.').'],
                ]);
            }

            $locked->load('lines.account');

            if ($locked->lines->count() < 2) {
                throw ValidationException::withMessages([
                    'lines' => ['Jurnal harus memiliki minimal dua baris debit/kredit.'],
                ]);
            }

            $totalDebit = $locked->totalDebit();
            $totalCredit = $locked->totalCredit();

            if ($totalDebit !== $totalCredit || $totalDebit <= 0.0) {
                throw ValidationException::withMessages([
                    'lines' => sprintf('Jurnal tidak seimbang: total debit Rp %s, total kredit Rp %s. Jurnal hanya dapat diposting bila seimbang.', number_format($totalDebit, 2, ',', '.'), number_format($totalCredit, 2, ',', '.')),
                ]);
            }

            foreach ($locked->lines as $line) {
                if ((float) $line->debit < 0 || (float) $line->credit < 0) {
                    throw ValidationException::withMessages([
                        'lines' => ['Nominal debit/kredit tidak boleh negatif.'],
                    ]);
                }

                if (((float) $line->debit > 0 && (float) $line->credit > 0)
                    || ((float) $line->debit === 0.0 && (float) $line->credit === 0.0)) {
                    throw ValidationException::withMessages([
                        'lines' => ['Setiap baris jurnal harus memiliki tepat satu sisi bernilai (debit atau kredit).'],
                    ]);
                }

                if (! $line->account->is_active) {
                    throw ValidationException::withMessages([
                        'lines' => ['Akun '.$line->account->code.' - '.$line->account->name.' berstatus nonaktif dan tidak dapat digunakan untuk jurnal baru.'],
                    ]);
                }
            }

            $locked->update([
                'status' => JournalEntry::STATUS_POSTED,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            AuditLogger::log(
                'accounting.journal.posted',
                JournalEntry::class,
                $locked->id,
                $locked->journal_number,
                sprintf('Jurnal %s diposting (debit = kredit = Rp %s).', $locked->journal_number, number_format($totalDebit, 2, ',', '.')),
                ['total_debit' => $totalDebit, 'total_credit' => $totalCredit]
            );

            return $locked->fresh(['lines.account']);
        });
    }

    /**
     * Reversal jurnal POSTED: buat jurnal pembalik yang menautkan asal.
     * Jurnal asal tetap immutable; hanya statusnya ditandai REVERSED.
     */
    public function reverse(JournalEntry $entry, int $userId, string $reason): JournalEntry
    {
        return DbTransaction::run(function () use ($entry, $userId, $reason) {
            $locked = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($locked->status !== JournalEntry::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya jurnal POSTED yang dapat direversi.'],
                ]);
            }

            if ($locked->reversed_by_entry_id !== null) {
                throw ValidationException::withMessages([
                    'status' => ['Jurnal ini sudah pernah direversi oleh '.$locked->reversedBy?->journal_number.'.'],
                ]);
            }

            $locked->load('lines');

            $reversal = JournalEntry::create([
                'journal_number' => $this->generateJournalNumber(),
                'transaction_date' => now()->format('Y-m-d'),
                'description' => 'Reversal '.$locked->journal_number.': '.$reason,
                'status' => JournalEntry::STATUS_DRAFT,
                'source_type' => $locked->source_type,
                'source_id' => $locked->source_id,
                // posting_type berbeda agar tidak bentrok dengan unique index sumber.
                'posting_type' => $locked->posting_type.'_reversal',
                'created_by' => $userId,
            ]);

            foreach ($locked->lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    'debit' => $line->credit, // dibalik
                    'credit' => $line->debit, // dibalik
                    'line_order' => $line->line_order,
                ]);
            }

            // Posting reversal langsung (ia seimbang by construction).
            $reversal = $this->post($reversal, $userId);

            // Jurnal asal: tandai REVERSED (isi tidak pernah diubah).
            $locked->forceFill([
                'status' => JournalEntry::STATUS_REVERSED,
                'reversed_by_entry_id' => $reversal->id,
            ])->save();

            AuditLogger::log(
                'accounting.journal.reversed',
                JournalEntry::class,
                $locked->id,
                $locked->journal_number,
                sprintf('Jurnal %s direversi oleh jurnal %s. Alasan: %s', $locked->journal_number, $reversal->journal_number, $reason),
                [
                    'reversal_journal_number' => $reversal->journal_number,
                    'total_debit' => $reversal->totalDebit(),
                    'total_credit' => $reversal->totalCredit(),
                    'reason' => $reason,
                ]
            );

            return $reversal;
        });
    }

    /**
     * Buku besar: baris-baris jurnal POSTED untuk akun/rentang tertentu,
     * dengan saldo berjalan berdasarkan saldo normal akun.
     *
     * @return array{account: ?ChartOfAccount, rows: array<int, array<string, mixed>>, totals: array{debit: float, credit: float, balance: float}}
     */
    public function ledger(?int $accountId, ?string $startDate, ?string $endDate): array
    {
        $account = $accountId !== null ? ChartOfAccount::findOrFail($accountId) : null;

        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->when($accountId !== null, fn ($q) => $q->where('journal_lines.account_id', $accountId))
            ->when($startDate !== null, fn ($q) => $q->where('journal_entries.transaction_date', '>=', $startDate))
            ->when($endDate !== null, fn ($q) => $q->where('journal_entries.transaction_date', '<=', $endDate))
            ->orderBy('journal_entries.transaction_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.line_order');

        $rows = [];
        $running = 0.0;
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($query->get() as $row) {
            $debit = round((float) $row->debit, 2);
            $credit = round((float) $row->credit, 2);
            $totalDebit = round($totalDebit + $debit, 2);
            $totalCredit = round($totalCredit + $credit, 2);

            $normal = $row->normal_balance === ChartOfAccount::NORMAL_DEBIT ? 1 : -1;
            $running = round($running + $normal * ($debit - $credit), 2);

            $rows[] = [
                'transaction_date' => $row->transaction_date,
                'journal_number' => $row->journal_number,
                'journal_entry_id' => $row->journal_entry_id,
                'account_id' => $row->account_id,
                'account_code' => $row->code,
                'account_name' => $row->name,
                'description' => $row->description ?? $row->description,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $running,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
            ];
        }

        return [
            'account' => $account,
            'rows' => $rows,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'balance' => $running,
            ],
        ];
    }

    /**
     * Idempotensi: kembalikan jurnal yang sudah ada untuk sumber yang sama,
     * atau null bila belum ada.
     */
    public function findForSource(Model $source, string $postingType = 'auto'): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->where('posting_type', $postingType)
            ->first();
    }

    private function createLines(JournalEntry $entry, array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => ['Jurnal harus memiliki minimal dua baris debit/kredit.'],
            ]);
        }

        $accounts = ChartOfAccount::query()
            ->whereIn('id', collect($lines)->pluck('account_id'))
            ->get()
            ->keyBy('id');

        foreach ($lines as $index => $line) {
            $accountId = (int) $line['account_id'];

            if (! $accounts->has($accountId)) {
                throw ValidationException::withMessages([
                    'lines' => ['Akun tidak ditemukan pada baris '.($index + 1).'.'],
                ]);
            }

            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit < 0 || $credit < 0) {
                throw ValidationException::withMessages([
                    'lines' => ['Nominal debit/kredit tidak boleh negatif.'],
                ]);
            }

            if (($debit > 0 && $credit > 0) || ($debit === 0.0 && $credit === 0.0)) {
                throw ValidationException::withMessages([
                    'lines' => ['Baris '.($index + 1).' harus memiliki tepat satu sisi bernilai (debit atau kredit).'],
                ]);
            }

            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $accountId,
                'description' => $line['description'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'line_order' => $index + 1,
            ]);
        }
    }

    private function assertNoPartialDebitCredit(array $lines): void
    {
        foreach ($lines as $index => $line) {
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages([
                    'lines' => ['Baris '.($index + 1).' tidak boleh memiliki debit dan kredit sekaligus.'],
                ]);
            }
        }
    }

    private function generateJournalNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid('', true), -8));

        return "JRNL-{$date}-{$random}";
    }
}
