<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    /**
     * Daftar audit trail dengan filter dan pagination server-side.
     */
    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'action' => (string) $request->input('action', 'all'),
            'module' => (string) $request->input('module', 'all'),
            'actor' => $request->input('actor', 'all'),
            'date_from' => (string) $request->input('date_from', ''),
            'date_to' => (string) $request->input('date_to', ''),
        ];

        $logs = AuditLog::query()
            ->with('actor:id,name,email')
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $term = '%'.mb_strtolower($filters['search']).'%';

                $q->where(function ($inner) use ($term) {
                    $inner->whereRaw('LOWER(action) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(object_type) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(object_label, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$term]);
                });
            })
            ->when($filters['action'] !== 'all', fn ($q) => $q->where('action', $filters['action']))
            ->when($filters['module'] !== 'all', fn ($q) => $q->where('object_type', 'like', $filters['module'].'%'))
            ->when($filters['actor'] !== 'all' && $filters['actor'] !== null && $filters['actor'] !== '', fn ($q) => $q->where('actor_id', (int) $filters['actor']))
            ->when($filters['date_from'] !== '', fn ($q) => $q->where('created_at', '>=', $filters['date_from'].' 00:00:00'))
            ->when($filters['date_to'] !== '', fn ($q) => $q->where('created_at', '<=', $filters['date_to'].' 23:59:59'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AuditLog $log) => $this->toArray($log));

        $actions = AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $actors = AuditLog::query()
            ->whereNotNull('actor_id')
            ->distinct()
            ->with('actor:id,name,email')
            ->get('actor_id')
            ->map(fn (AuditLog $log) => $log->actor !== null
                ? ['id' => $log->actor->id, 'name' => $log->actor->name]
                : null)
            ->filter()
            ->unique('id')
            ->values();

        return Inertia::render('Pengaturan/AuditLog', [
            'logs' => $logs,
            'actions' => $actions,
            'actors' => $actors,
            'filters' => $filters,
        ]);
    }

    /**
     * Detail satu entri audit: nilai sebelum/sesudah dan referensi objek.
     */
    public function show(AuditLog $audit_log): Response
    {
        $audit_log->load('actor:id,name,email');

        return Inertia::render('Pengaturan/AuditLogDetail', [
            'log' => $this->toArray($audit_log),
        ]);
    }

    /**
     * Representasi aman satu entri audit untuk tampilan.
     *
     * @return array<string, mixed>
     */
    private function toArray(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'object_type' => $log->object_type,
            'object_id' => $log->object_id,
            'object_label' => $log->object_label,
            'description' => $log->description,
            'changes' => $log->changes,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            'actor' => $log->actor !== null
                ? ['id' => $log->actor->id, 'name' => $log->actor->name, 'email' => $log->actor->email]
                : null,
        ];
    }
}
