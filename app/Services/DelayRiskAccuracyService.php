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

        $itemIds = $firstUploadedLogs->pluck('content_item_id');

        $items = ContentItem::whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        // Preload SEMUA skor sekaligus (1 query, bukan 1 query per item di
        // dalam loop - dulu 211 query buat ~223 total) - diurutkan ascending
        // by id per content_item_id, jadi "skor terakhir dengan created_at
        // <= changed_at" tinggal filter+last() di memori, hasilnya identik
        // dengan query lama (`where created_at <= .. -> latest('id')->first()`).
        $scoresByItem = DelayRiskScore::whereIn('content_item_id', $itemIds)
            ->orderBy('id')
            ->get()
            ->groupBy('content_item_id');

        foreach ($firstUploadedLogs as $log) {
            $item = $items->get($log->content_item_id);

            if (!$item || !$item->deadline_at) {
                continue;
            }

            $scoreBeforeUpload = ($scoresByItem->get($log->content_item_id) ?? collect())
                ->filter(fn ($score) => $score->created_at->lte($log->changed_at))
                ->last();

            if (!$scoreBeforeUpload) {
                continue;
            }

            // Dibandingkan dengan tanggal target TAYANG, bukan deadline
            // produksi. Sejak perombakan alur Content Plan, deadline_at
            // adalah deadline PENGERJAAN yang memang dihitung 2 hari SEBELUM
            // upload_deadline_at - jadi konten yang tayang tepat pada
            // jadwalnya otomatis "melewati deadline_at" dan SETIAP item
            // terhitung telat. Akibatnya precision High Risk selalu ~100%
            // dan angka ini berhenti berarti sebagai evaluasi model.
            //
            // upload_deadline_at baru ada untuk konten yang lewat alur
            // Atur Deadline; item lama (import planner) tetap dinilai
            // memakai deadline_at seperti sebelumnya.
            $targetDate = $item->upload_deadline_at ?? $item->deadline_at;

            $wasLate = $log->changed_at->greaterThan($targetDate);

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
