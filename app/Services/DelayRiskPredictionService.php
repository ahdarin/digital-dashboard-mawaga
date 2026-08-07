<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Models\ContentWorkflow;
use App\Models\DelayRiskScore;
use App\Support\ContentComplexityCalculator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Carbon;

class DelayRiskPredictionService
{
    private array $doneStatuses = ['uploaded', 'cancelled'];

    public function predictForItems(array $contentItemIds): void
    {
        $items = ContentItem::with(['client.category', 'contentPillar', 'contentType', 'workflow'])
            ->whereIn('id', $contentItemIds)
            ->whereHas('workflow')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $payloadItems = [];
        $featureMap = [];

        foreach ($items as $item) {
            $features = $this->buildFeatures($item);
            $featureMap[$item->id] = $features;
            $payloadItems[] = array_merge(['content_item_id' => $item->id], $features);
        }

        $results = $this->callPredictScript($payloadItems);

        foreach ($results as $result) {
            DelayRiskScore::create([
                'content_item_id' => $result['content_item_id'],
                'risk_score' => $result['risk_score'],
                'risk_level' => $result['risk_level'],
                'top_factor' => $this->guessTopFactor($featureMap[$result['content_item_id']]),
                'features_snapshot' => $featureMap[$result['content_item_id']],
            ]);
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
        $payload = json_encode(['items' => $items]);

        $result = Process::input($payload)->run("python3 {$scriptPath}");

        if (!$result->successful()) {
            Log::error('Delay Risk prediction script failed', ['error' => $result->errorOutput()]);
            return [];
        }

        return json_decode($result->output(), true) ?? [];
    }

    private function guessTopFactor(array $features): string
    {
        // Penjelasan sederhana (bukan SHAP), cukup untuk konteks MVP:
        // ambil fitur numerik dengan nilai relatif paling "ekstrem"
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