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
                'top_factor' => $this->guessTopFactor($featureMap[$result['content_item_id']], $previousFeatures, $result['risk_level']),
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
            User::query()
                ->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->whereIn('name', [UserRole::CEO->value, UserRole::Manager->value]))
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
                ->whereHas('contentItem.workflow', fn($q) => $q->whereNotIn('current_status', \App\Support\WorkflowTransitions::INACTIVE_STATUSES))
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

        // "python3" tidak selalu ada di PATH - di Windows/beberapa environment
        // dev cuma ada "python". PYTHON_BIN di .env bisa override kalau perlu.
        $pythonBin = env('PYTHON_BIN', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');

        // Bentuk array (bukan string command) supaya path yang mengandung
        // spasi/tanda kurung (umum di direktori proyek Windows) tidak perlu
        // di-quote manual dan tidak salah di-parse oleh shell.
        $result = Process::input($payload)->run([$pythonBin, $scriptPath]);

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

    private function guessTopFactor(array $features, ?array $previousFeatures, string $riskLevel): string
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

        // Fallback: bukan lagi "lolos ambang atau diam" - tiap kandidat faktor
        // dibandingkan sebagai RASIO terhadap ambang wajarnya masing-masing,
        // supaya selalu ada satu faktor paling menonjol yang bisa ditunjuk
        // (bukan SHAP asli, cukup untuk konteks MVP). Rasio >= 1 berarti sudah
        // lewat ambang lama (dianggap signifikan); di bawah itu tetap ditunjuk
        // sebagai yang paling menonjol tapi dikasih kualifier "belum
        // mengkhawatirkan" biar tidak menyesatkan.
        $days = (int) round($features['days_in_current_status']);
        $candidates = [
            [
                'ratio' => $features['workload_pic_same_week'] / 8,
                'label' => 'beban kerja PIC',
                'signifikan' => 'Beban kerja PIC sedang tinggi',
                'belum' => "Beban kerja PIC ({$features['workload_pic_same_week']} task aktif) - belum mengkhawatirkan",
            ],
            [
                'ratio' => $features['revision_count'] / 2,
                'label' => 'jumlah revisi',
                'signifikan' => 'Sudah melalui beberapa ronde revisi',
                'belum' => "Jumlah revisi ({$features['revision_count']} ronde) - belum mengkhawatirkan",
            ],
            [
                'ratio' => $features['content_complexity'] / 3,
                'label' => 'kompleksitas konten',
                'signifikan' => 'Kompleksitas konten tinggi',
                'belum' => 'Kompleksitas konten masih tergolong ringan/sedang',
            ],
            [
                'ratio' => $features['days_in_current_status'] / 5,
                'label' => 'lama waktu di status saat ini',
                'signifikan' => 'Sudah lama berada di status saat ini',
                'belum' => "Sudah {$days} hari di status ini - belum mengkhawatirkan",
            ],
        ];

        usort($candidates, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);
        $top = $candidates[0];

        if ($top['ratio'] >= 1) {
            return $top['signifikan'];
        }

        // Risiko rendah dan tidak ada faktor yang menonjol sama sekali (semua
        // jauh di bawah ambang wajarnya) - lebih jujur bilang "aman" daripada
        // menunjuk faktor kecil yang sebenarnya tidak berarti apa-apa.
        if ($riskLevel === 'low') {
            return $top['ratio'] < 0.3 ? 'Tidak ada faktor risiko signifikan terdeteksi' : $top['belum'];
        }

        // Risiko medium/high tapi tidak ada satu faktor pun yang jelas lewat
        // ambang - qualifier "belum mengkhawatirkan" bakal kontradiktif kalau
        // dipasangkan ke skor yang sudah tinggi. Daripada diam generik,
        // sebutkan 2-3 faktor dengan kontribusi terbesar (bukan SHAP asli,
        // cuma urutan rasio) supaya tetap actionable buat PIC/manager.
        $contributingLabels = array_column(array_slice($candidates, 0, 3), 'label');
        $last = array_pop($contributingLabels);

        return $contributingLabels
            ? 'Kombinasi beberapa faktor: ' . implode(', ', $contributingLabels) . ' dan ' . $last
            : "Kombinasi beberapa faktor: {$last}";
    }
}