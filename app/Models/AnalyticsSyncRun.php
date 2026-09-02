<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Analytics V2 Phase B - 1 operasi refresh (manual/scheduled/retry/initial)
 * buat 1 client, bisa mencakup >1 AnalyticsSyncTask (multi-platform
 * sekaligus). status kolom SELALU rollup dari task-task di dalamnya (lihat
 * recomputeStatus()) - JANGAN diset manual dari luar method ini, supaya
 * satu-satunya sumber kebenaran rollup tetap konsisten.
 */
class AnalyticsSyncRun extends Model
{
    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_RETRY = 'retry';

    public const TRIGGER_INITIAL = 'initial';

    protected $fillable = [
        'client_id', 'trigger', 'initiated_by', 'status', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function initiatedBy() { return $this->belongsTo(User::class, 'initiated_by'); }
    public function tasks() { return $this->hasMany(AnalyticsSyncTask::class); }

    /**
     * Rollup run.status dari SELURUH task di dalamnya - dipanggil setiap
     * kali 1 task berubah status (lihat AnalyticsSyncTask::transitionTo()).
     * Prioritas identik dengan AnalyticsSyncOrchestrator::computeOverallStatus()
     * (running > queued > needs_reconnect-semua > needs_reconnect-sebagian
     * (partial) > semua-success > semua-failed > campuran (partial)) - SAMA
     * PERSIS filosofinya biar overall run TIDAK PERNAH mengklaim "success"
     * kalau ada task yang butuh reconnect.
     */
    public function recomputeStatus(): void
    {
        $statuses = $this->tasks()->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $status = match (true) {
            $statuses->contains('running') => 'running',
            $statuses->contains('queued') => 'queued',
            $statuses->every(fn ($s) => $s === 'needs_reconnect') => 'needs_reconnect',
            $statuses->contains('needs_reconnect') => 'partial',
            $statuses->every(fn ($s) => $s === 'success') => 'success',
            $statuses->every(fn ($s) => $s === 'failed') => 'failed',
            default => 'partial',
        };

        $finished = ! in_array($status, ['running', 'queued'], true);

        $this->update([
            'status' => $status,
            'finished_at' => $finished ? ($this->finished_at ?? now()) : null,
        ]);
    }
}
