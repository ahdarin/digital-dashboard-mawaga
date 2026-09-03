<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Analytics V2 Phase B - 1 subjob teknis (instagram_content/
 * instagram_audience/tiktok_content, reuse string
 * AnalyticsSyncOrchestrator::SUBJOB_*) di dalam 1 AnalyticsSyncRun.
 *
 * Progress counter (discovered/processed/success/unavailable/skipped/
 * failed) HARUS dijaga konsisten lewat method di sini (incrementX()), JANGAN
 * di-update kolom mentah dari sync service - biar last_progress_at SELALU
 * ikut ter-touch bareng progress genuine (Langkah "Progress Semantics",
 * "track started_at + last_progress_at").
 */
class AnalyticsSyncTask extends Model
{
    protected $fillable = [
        'analytics_sync_run_id', 'api_integration_id', 'subjob', 'status', 'stage',
        'discovered_count', 'processed_count', 'success_count', 'unavailable_count',
        'skipped_count', 'failed_count', 'reconciled',
        'started_at', 'last_progress_at', 'finished_at', 'attempt',
    ];

    protected $casts = [
        'reconciled' => 'boolean',
        'started_at' => 'datetime',
        'last_progress_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run() { return $this->belongsTo(AnalyticsSyncRun::class, 'analytics_sync_run_id'); }
    public function integration() { return $this->belongsTo(ApiIntegration::class, 'api_integration_id'); }
    public function failures() { return $this->hasMany(AnalyticsSyncFailure::class); }

    public function markRunning(?string $stage = null): void
    {
        $this->update([
            'status' => 'running',
            'stage' => $stage,
            'started_at' => $this->started_at ?? now(),
            'last_progress_at' => now(),
        ]);
        $this->run?->recomputeStatus();
    }

    /**
     * Dipanggil begitu pagination/discovery selesai & workload SEBENARNYA
     * diketahui (Langkah "Progress Semantics" - SEBELUM ini, UI tetap pakai
     * $stage indeterminate, TIDAK PERNAH mengarang persentase dari elapsed
     * time).
     *
     * PASS 4 (Langkah 2, "PAGINATION/COMPLETENESS") - BUG NYATA ditemukan
     * lewat live QA Instagram sungguhan: instagram_content/tiktok_content
     * SATU task yang sama dipakai 2 FASE berurutan dalam 1 job
     * (sync()/refreshKnownMedia() atau sync()/refreshKnownVideos(), lihat
     * SyncInstagramAnalyticsJob/SyncTikTokAnalyticsJob) - versi lama method
     * ini SET (overwrite) discovered_count tiap dipanggil, jadi fase kedua
     * DIAM-DIAM MENIMPA discovered_count fase pertama sementara
     * processed/success/dst TETAP AKUMULASI dari kedua fase - hasilnya
     * discovered_count < processed_count (invariant reconciliation
     * pecah, contoh live: discovered=11 processed=22). SEKARANG akumulatif
     * (+=) - fase pertama dari 0 (default kolom) tetap identik hasilnya
     * (0+N=N), fase kedua MENAMBAHKAN ke total yang sudah ada (BUKAN
     * mengganti), jadi discovered_count = TOTAL workload genuine lintas
     * kedua fase, invariant discovered = success+unavailable+skipped+failed
     * kembali terjaga.
     */
    public function recordDiscovered(int $count, ?string $stage = null): void
    {
        $this->update([
            'discovered_count' => $this->discovered_count + $count,
            'stage' => $stage ?? $this->stage,
            'last_progress_at' => now(),
        ]);
    }

    /**
     * SYNC PROGRESS UX pass - discovered_count ABSOLUTE (bukan akumulatif
     * seperti recordDiscovered()), dipakai KHUSUS oleh jalur progresif
     * (planProgressiveRun()) yang menelepon paginasi provider SATU kali per
     * task: dipanggil berulang selagi $stage tetap 'discovering_media'/
     * 'discovering_videos' (UI: "N konten ditemukan sejauh ini", TANPA
     * persentase - total belum diketahui), lalu SATU KALI TERAKHIR dengan
     * total definitif (discovery + known-refresh) begitu planning selesai,
     * yang JUGA menggeser stage ke fase processing pertama. TIDAK PERNAH
     * dipakai jalur monolitik lama (sync()/refreshKnownMedia()) - itu tetap
     * pakai recordDiscovered() akumulatif dua-fase seperti sebelumnya, jadi
     * method ini TIDAK BOLEH menggantikannya di sana.
     */
    public function touchDiscoveryProgress(int $discoveredSoFar, ?string $stage = null): void
    {
        $this->update([
            'discovered_count' => $discoveredSoFar,
            'stage' => $stage ?? $this->stage,
            'last_progress_at' => now(),
        ]);
    }

    public function incrementSuccess(): void
    {
        $this->increment('success_count');
        $this->increment('processed_count');
        $this->update(['last_progress_at' => now()]);
    }

    public function incrementUnavailable(int $by = 1): void
    {
        if ($by <= 0) {
            return;
        }
        $this->increment('unavailable_count', $by);
        $this->increment('processed_count', $by);
        $this->update(['last_progress_at' => now()]);
    }

    public function incrementSkipped(): void
    {
        $this->increment('skipped_count');
        $this->increment('processed_count');
        $this->update(['last_progress_at' => now()]);
    }

    public function incrementFailed(): void
    {
        $this->increment('failed_count');
        $this->increment('processed_count');
        $this->update(['last_progress_at' => now()]);
    }

    /**
     * Invariant reconciliation (Langkah "RECONCILIATION"): discovered =
     * success + unavailable + skipped + failed. discovered_count=0 (belum
     * pernah di-set - discovery sendiri gagal sebelum item ID diketahui)
     * dianggap TIDAK reconciled - JANGAN dilaporkan clean success kalau kita
     * bahkan tidak tahu berapa yang seharusnya diproses.
     */
    public function isReconciled(): bool
    {
        if ($this->discovered_count <= 0 && $this->processed_count <= 0) {
            // Task benar2 tidak menemukan apapun (mis. akun baru, belum ada
            // konten sama sekali) - reconciled TRIVIALLY true (0=0), BUKAN
            // sinyal masalah.
            return true;
        }

        return $this->discovered_count === ($this->success_count + $this->unavailable_count + $this->skipped_count + $this->failed_count);
    }

    /**
     * Urutan severity dipakai finish() (Instagram Content Task = 2 fase
     * berurutan, sync() lalu refreshKnownMedia(), SATU task yang sama -
     * lihat SyncInstagramAnalyticsJob/SyncTikTokAnalyticsJob) - finish()
     * kedua TIDAK BOLEH diam-diam menimpa hasil fase pertama yang lebih
     * buruk dengan status "success sempurna" dari fase kedua yang
     * kebetulan tidak menemukan apapun buat dikerjakan.
     */
    private const STATUS_SEVERITY = ['success' => 0, 'partial' => 1, 'failed' => 2, 'needs_reconnect' => 3];

    /**
     * PROGRESSIVE SYNC ENGINE - FINAL CLOSURE GATE (Langkah 1): satu-
     * satunya string 'stage' yang boleh terpasang begitu task mencapai
     * status terminal manapun (success/partial/failed/needs_reconnect) -
     * 'processing_recent'/'processing_previous'/'processing_older'/
     * 'discovering_media'/dst adalah stage IN-PROGRESS, TIDAK PERNAH
     * bermakna begitu finished_at terisi. STAGE_LABELS (JS) HARUS punya
     * entry 'completed' yang match persis string ini.
     */
    public const STAGE_COMPLETED = 'completed';

    /**
     * Finalisasi task - status ditentukan CALLER (sync service yang tahu
     * konteks penuh: auth failure -> needs_reconnect, dst). Dipanggil >1
     * kali dalam 1 run (multi-fase) AMAN - status akhir SELALU yang PALING
     * BURUK di antara seluruh pemanggilan, never accidentally downgraded
     * ke "success" oleh fase berikutnya yang trivial.
     *
     * FINAL CLOSURE GATE (Langkah 1) - stage SELALU disetel ke
     * STAGE_COMPLETED di sini, TERLEPAS dari status akhirnya
     * (success/partial/failed/needs_reconnect SEMUA "selesai mencoba",
     * bukan lagi "processing_older" dkk yang cuma valid selagi task masih
     * berjalan). Ini titik SATU-SATUNYA tempat finish() pernah dipanggil
     * (progressive maupun legacy monolithic path, lihat
     * InstagramAnalyticsSyncService::finalizeProgressiveRun()/sync()/
     * refreshKnownMedia() - SEMUA lewat sini), jadi fix ini otomatis
     * berlaku ke KEDUA jalur tanpa perlu diubah satu-satu di caller.
     */
    public function finish(string $status): void
    {
        $previous = $this->finished_at ? $this->status : null;
        $worse = $previous !== null && (self::STATUS_SEVERITY[$previous] ?? 0) > (self::STATUS_SEVERITY[$status] ?? 0)
            ? $previous
            : $status;

        $this->update([
            'status' => $worse,
            'stage' => self::STAGE_COMPLETED,
            'reconciled' => $this->isReconciled(),
            'finished_at' => now(),
        ]);
        $this->run?->recomputeStatus();
    }
}
