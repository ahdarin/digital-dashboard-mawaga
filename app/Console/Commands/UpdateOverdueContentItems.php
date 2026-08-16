<?php

namespace App\Console\Commands;

use App\Models\ContentWorkflow;
use App\Support\WorkflowTransitions;
use Illuminate\Console\Command;

class UpdateOverdueContentItems extends Command
{
    protected $signature = 'workflow:update-overdue';
    protected $description = 'Menandai content item sebagai overdue jika deadline sudah lewat dan belum selesai';

    // Status yang dianggap "selesai" - approved/scheduled SENGAJA tidak
    // termasuk, karena kalau deadline lewat sebelum benar-benar tayang itu
    // tetap dianggap terlambat (disamakan dengan WorkflowTransitions::DONE_STATUSES
    // yang dipakai semua pembaca flag ini, biar tidak ada lagi definisi ganda).
    private array $doneStatuses = WorkflowTransitions::DONE_STATUSES;

    public function handle(): void
    {
        $overdueCount = ContentWorkflow::whereNotIn('current_status', $this->doneStatuses)
            ->whereHas('contentItem', function ($query) {
                $query->where('deadline_at', '<', now());
            })
            ->update(['is_overdue' => true]);

        // Reset kembali is_overdue jadi false kalau ternyata deadline masih di masa depan
        // (misal deadline diubah manual jadi lebih panjang)
        $resetCount = ContentWorkflow::where('is_overdue', true)
            ->whereHas('contentItem', function ($query) {
                $query->where('deadline_at', '>=', now());
            })
            ->update(['is_overdue' => false]);

        $this->info("Overdue diperbarui: {$overdueCount} ditandai, {$resetCount} direset.");
    }
}