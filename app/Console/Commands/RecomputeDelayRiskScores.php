<?php

namespace App\Console\Commands;

use App\Models\ContentWorkflow;
use App\Services\DelayRiskPredictionService;
use Illuminate\Console\Command;

class RecomputeDelayRiskScores extends Command
{
    protected $signature = 'workflow:recompute-delay-risk';
    protected $description = 'Hitung ulang skor Delay Risk untuk semua content item aktif';

    private array $doneStatuses = ['uploaded', 'cancelled'];

    public function handle(DelayRiskPredictionService $service): void
    {
        $itemIds = ContentWorkflow::whereNotIn('current_status', $this->doneStatuses)
            ->pluck('content_item_id')
            ->toArray();

        if (empty($itemIds)) {
            $this->info('Tidak ada content item aktif.');
            return;
        }

        // Batch per 50 item supaya request ke Python tidak terlalu besar sekaligus
        foreach (array_chunk($itemIds, 50) as $chunk) {
            $service->predictForItems($chunk);
        }

        $this->info(count($itemIds) . ' item berhasil dihitung ulang skor risikonya.');
    }
}