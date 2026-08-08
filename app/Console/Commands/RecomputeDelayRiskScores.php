<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\ContentWorkflow;
use App\Models\Notification;
use App\Models\User;
use App\Services\DelayRiskPredictionService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class RecomputeDelayRiskScores extends Command
{
    protected $signature = 'workflow:recompute-delay-risk';
    protected $description = 'Hitung ulang skor Delay Risk untuk semua content item aktif';

    private array $doneStatuses = ['uploaded', 'cancelled'];
    private const PIPELINE_ALERT_TITLE = 'Prediksi AI Delay Risk Bermasalah';

    public function handle(DelayRiskPredictionService $service): void
    {
        $itemIds = ContentWorkflow::whereNotIn('current_status', $this->doneStatuses)
            ->pluck('content_item_id')
            ->toArray();

        if (empty($itemIds)) {
            $this->info('Tidak ada content item aktif.');
            return;
        }

        $succeeded = 0;

        // Batch per 50 item supaya request ke Python tidak terlalu besar sekaligus
        foreach (array_chunk($itemIds, 50) as $chunk) {
            $succeeded += $service->predictForItems($chunk);
        }

        $requested = count($itemIds);
        $this->info("{$succeeded} dari {$requested} item berhasil dihitung ulang skor risikonya.");

        // Kalau lebih dari separuh item yang diminta gagal dapat skor, pipeline AI
        // kemungkinan rusak (model hilang, python3 tidak ada, dsb) - kabarin, jangan
        // biarkan gagal senyap terus tiap jam.
        if ($succeeded < $requested * 0.5) {
            $this->alertPipelineFailure($succeeded, $requested);
        }
    }

    private function alertPipelineFailure(int $succeeded, int $requested): void
    {
        $alreadySent = Notification::where('title', self::PIPELINE_ALERT_TITLE)
            ->whereDate('created_at', now())
            ->exists();

        if ($alreadySent) {
            return;
        }

        $recipients = User::whereNull('client_id')
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('name', [UserRole::CEO->value, UserRole::Admin->value]))
            ->get();

        $body = "Prediksi AI Delay Risk cuma berhasil untuk {$succeeded} dari {$requested} item aktif. Kemungkinan model/script prediksi bermasalah - cek log server.";

        foreach ($recipients as $user) {
            NotificationService::notify($user, self::PIPELINE_ALERT_TITLE, 'system', $body);
        }
    }
}
