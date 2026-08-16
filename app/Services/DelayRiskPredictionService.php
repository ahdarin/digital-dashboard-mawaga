<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Models\ContentWorkflow;
use App\Models\DelayRiskScore;
use App\Models\Notification;
use App\Models\User;
use App\Support\ContentComplexityCalculator;
use App\Support\WorkflowTransitions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Carbon;

class DelayRiskPredictionService
{
    private array $doneStatuses = WorkflowTransitions::DONE_STATUSES;

    /**
     * @return int Jumlah item yang berhasil dapat skor baru (dipakai RecomputeDelayRiskScores
     * untuk deteksi kalau pipeline prediksi rusak masal, lihat callPredictScript()).
     */
    public function predictForItems(array $contentItemIds): int
    {
        $items = ContentItem::with(['client.category', 'contentPillar', 'contentType', 'workflow.currentPic'])
            ->whereIn('id', $contentItemIds)
            ->whereHas('workflow')
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $payloadItems = [];
        $featureMap = [];
        $itemMap = [];

        foreach ($items as $item) {
            $features = $this->buildFeatures($item);
            $featureMap[$item->id] = $features;
            $itemMap[$item->id] = $item;
            $payloadItems[] = array_merge(['content_item_id' => $item->id], $features);
        }

        $results = $this->callPredictScript($payloadItems);

        // Ambil snapshot fitur sebelumnya PER ITEM sebelum row baru dibuat, biar
        // guessTopFactor() bisa jelasin apa yang BERUBAH, bukan cuma ambang statis.
        $previousFeatureMap = DelayRiskScore::whereIn('content_item_id', array_keys($featureMap))
            ->get()
            ->groupBy('content_item_id')
            ->map(fn ($group) => $group->sortByDesc('id')->first()->features_snapshot);

        foreach ($results as $result) {
            $previousFeatures = $previousFeatureMap[$result['content_item_id']] ?? null;

            $score = DelayRiskScore::create([
                'content_item_id' => $result['content_item_id'],
                'risk_score' => $result['risk_score'],
                'risk_level' => $result['risk_level'],
                'top_factor' => $this->guessTopFactor($featureMap[$result['content_item_id']], $previousFeatures),
                'features_snapshot' => $featureMap[$result['content_item_id']],
            ]);

            if ($score->risk_level === 'high') {
                $this->notifyHighRisk($itemMap[$result['content_item_id']], $score);
            }
        }

        return count($results);
    }

    /**
     * Notifikasi proaktif ke PIC yang pegang item + role CEO/Admin, sekali per hari
     * per item (dedup) supaya tidak spam tiap kali cron jam-an jalan.
     */
    private function notifyHighRisk(ContentItem $item, DelayRiskScore $score): void
    {
        $alreadySent = Notification::where('related_type', ContentItem::class)
            ->where('related_id', $item->id)
            ->where('type', 'delay_risk_alert')
            ->whereDate('created_at', now())
            ->exists();

        if ($alreadySent) {
            return;
        }

        $title = 'Risiko Keterlambatan Tinggi';
        $body = "Konten '{$item->title}' ({$item->client->name}) berisiko tinggi terlambat ({$score->risk_score}%). Faktor utama: {$score->top_factor}.";

        $recipients = collect();

        if ($item->workflow->currentPic) {
            $recipients->push($item->workflow->currentPic);
        }

        $recipients = $recipients->merge(
            User::whereNull('client_id')
                ->where('status', 'active')
                ->whereHas('role', fn ($q) => $q->whereIn('name', [UserRole::CEO->value, UserRole::Manager->value]))
                ->get()
        )->unique('id');

        foreach ($recipients as $user) {
            NotificationService::notify($user, $title, 'delay_risk_alert', $body, $item);
        }
    }

    private function buildFeatures(ContentItem $item): array
    {
        $workflow = $item->workflow;

        $complexity = ContentComplexityCalculator::fromContentItem(
            $item->estimated_duration_seconds,
            $item->estimated_slide_count,
            $item->contentType->name ?? 'Video'
        );

        $currentPicId = $workflow->current_pic_id;
        $workload = $currentPicId
            ? ContentItemAssignment::where('user_id', $currentPicId)
                ->whereHas('contentItem.workflow', fn($q) => $q->whereNotIn('current_status', $this->doneStatuses))
                ->count()
            : 0;

        $revisionCount = ContentRevision::where('content_item_id', $item->id)->count();

        $lastStatusChange = ContentStatusLog::where('content_item_id', $item->id)
            ->where('to_status', $workflow->current_status)
            ->latest('changed_at')
            ->first();

        $daysInStatus = $lastStatusChange
            ? Carbon::parse($lastStatusChange->changed_at)->diffInDays(now())
            : Carbon::parse($workflow->created_at)->diffInDays(now());

        return [
            'client_category' => $item->client->category->name ?? 'UMKM',
            'pillar' => $item->contentPillar->name ?? 'Unknown',
            'content_complexity' => $complexity,
            'workload_pic_same_week' => $workload,
            'current_status' => $workflow->current_status,
            'revision_count' => $revisionCount,
            'days_in_current_status' => $daysInStatus,
        ];
    }

    private function callPredictScript(array $items): array
    {
        $scriptPath = storage_path('ai/delay_risk/predict_batch.py');
        $modelPath = storage_path('ai/delay_risk/delay_risk_model.pkl');

        if (! file_exists($modelPath)) {
            Log::error("Delay Risk prediction dibatalkan: model file tidak ditemukan di {$modelPath}");
            return [];
        }

        $payload = json_encode(['items' => $items]);

        $result = Process::input($payload)->run("python3 {$scriptPath}");

        if (!$result->successful()) {
            Log::error('Delay Risk prediction script failed', ['error' => $result->errorOutput()]);
            return [];
        }

        $decoded = json_decode($result->output(), true) ?? [];

        return $this->filterValidResults($decoded);
    }

    /**
     * Payload dari script Python tidak divalidasi di sisi PHP - kalau risk_level
     * yang dikembalikan bukan salah satu bucket yang dikenal, dia bisa dipakai
     * langsung sebagai array key (lihat DelayRiskAccuracyService::calculate())
     * dan mengotori struktur breakdown. Buang hasil yang tidak valid, jangan
     * dipercaya begitu saja - konsisten dengan pola log+skip di atas kalau
     * script/model gagal.
     */
    private function filterValidResults(array $results): array
    {
        $allowedRiskLevels = ['high', 'medium', 'low'];

        return array_values(array_filter($results, function ($item) use ($allowedRiskLevels) {
            if (! is_array($item) || ! isset($item['risk_level']) || ! in_array($item['risk_level'], $allowedRiskLevels, true)) {
                Log::warning('Delay Risk prediction script mengembalikan risk_level tidak valid, skor dilewati', [
                    'content_item_id' => $item['content_item_id'] ?? null,
                    'risk_level' => $item['risk_level'] ?? null,
                ]);
                return false;
            }
            return true;
        }));
    }

    private function guessTopFactor(array $features, ?array $previousFeatures = null): string
    {
        // Kalau ada histori sebelumnya, prioritaskan penjelasan berbasis PERUBAHAN
        // (lebih actionable daripada ambang statis) - "kenapa naik", bukan cuma "tinggi".
        if ($previousFeatures) {
            if ($features['revision_count'] > $previousFeatures['revision_count']) {
                return "Revisi bertambah dari {$previousFeatures['revision_count']} ke {$features['revision_count']} ronde";
            }
            if ($features['workload_pic_same_week'] > $previousFeatures['workload_pic_same_week']
                && $features['workload_pic_same_week'] > 5) {
                return "Beban kerja PIC naik dari {$previousFeatures['workload_pic_same_week']} ke {$features['workload_pic_same_week']} task aktif";
            }
            if ($features['current_status'] === $previousFeatures['current_status']
                && $features['days_in_current_status'] > $previousFeatures['days_in_current_status']
                && $features['days_in_current_status'] >= 5) {
                $days = (int) round($features['days_in_current_status']);
                return "Sudah {$days} hari di status yang sama tanpa progres";
            }
        }

        // Fallback: penjelasan sederhana berbasis ambang statis (bukan SHAP, cukup
        // untuk konteks MVP) - dipakai untuk prediksi pertama kali, atau kalau tidak
        // ada perubahan signifikan dibanding cek sebelumnya.
        if ($features['workload_pic_same_week'] > 8) {
            return 'Beban kerja PIC sedang tinggi';
        }
        if ($features['revision_count'] >= 2) {
            return 'Sudah melalui beberapa ronde revisi';
        }
        if ($features['content_complexity'] === 3) {
            return 'Kompleksitas konten tinggi';
        }
        if ($features['days_in_current_status'] >= 5) {
            return 'Sudah lama berada di status saat ini';
        }
        return 'Kombinasi beberapa faktor';
    }
}