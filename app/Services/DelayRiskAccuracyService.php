<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentStatusLog;
use App\Models\DelayRiskScore;

/**
 * Feedback loop: untuk tiap content item yang sudah pernah uploaded, bandingkan
 * level risiko terakhir SEBELUM upload dengan status telat/tidak aktualnya.
 * Dipakai buat ukur seberapa bisa dipercaya prediksi AI Delay Risk - bukan cuma
 * ditampilkan mentah tanpa validasi. Dipakai bareng oleh TeamPerformanceController
 * (detail lengkap) dan DashboardController (ringkasan), biar query-nya nggak dobel.
 *
 * "Telat" ditentukan dari ContentStatusLog (to_status='uploaded', changed_at
 * pertama kali) dibanding deadline_at - BUKAN dari content_workflows.updated_at
 * yang shared/tidak reliable (bisa kesentuh perubahan lain).
 */
class DelayRiskAccuracyService
{
    public function calculate(): array
    {
        $breakdown = [
            'high' => ['total' => 0, 'late' => 0],
            'medium' => ['total' => 0, 'late' => 0],
            'low' => ['total' => 0, 'late' => 0],
        ];

        $firstUploadedLogs = ContentStatusLog::whereHas('contentItem')
            ->where('to_status', 'uploaded')
            ->orderBy('changed_at')
            ->get()
            ->unique('content_item_id');

        if ($firstUploadedLogs->isEmpty()) {
            return [
                'breakdown' => $breakdown,
                'total_evaluated' => 0,
                'high_risk_precision' => null,
                'high_risk_recall' => null,
            ];
        }

        $items = ContentItem::whereIn('id', $firstUploadedLogs->pluck('content_item_id'))
            ->get()
            ->keyBy('id');

        foreach ($firstUploadedLogs as $log) {
            $item = $items->get($log->content_item_id);

            if (!$item || !$item->deadline_at) {
                continue;
            }

            $scoreBeforeUpload = DelayRiskScore::where('content_item_id', $item->id)
                ->where('created_at', '<=', $log->changed_at)
                ->latest('id')
                ->first();

            if (!$scoreBeforeUpload) {
                continue;
            }

            $wasLate = $log->changed_at->greaterThan($item->deadline_at);

            $breakdown[$scoreBeforeUpload->risk_level]['total']++;
            if ($wasLate) {
                $breakdown[$scoreBeforeUpload->risk_level]['late']++;
            }
        }

        $totalEvaluated = array_sum(array_column($breakdown, 'total'));
        $totalLate = array_sum(array_column($breakdown, 'late'));

        return [
            'breakdown' => $breakdown,
            'total_evaluated' => $totalEvaluated,
            // Precision: dari yang diprediksi High Risk, berapa persen yang benar-benar
            // terlambat. Ini BUKAN "akurasi" - false negative (item diprediksi
            // medium/low tapi ternyata telat) tidak masuk hitungan ini, lihat
            // high_risk_recall untuk sisi sebaliknya.
            'high_risk_precision' => $breakdown['high']['total'] > 0
                ? round($breakdown['high']['late'] / $breakdown['high']['total'] * 100)
                : null,
            // Recall: dari SEMUA konten yang ternyata telat (di semua bucket risiko),
            // berapa persen yang sebelumnya sudah diprediksi High Risk. Melengkapi
            // precision di atas supaya false negative kelihatan juga.
            'high_risk_recall' => $totalLate > 0
                ? round($breakdown['high']['late'] / $totalLate * 100)
                : null,
        ];
    }
}
