<?php

namespace App\Console\Commands;

use App\Models\ContentWorkflow;
use Illuminate\Console\Command;

class UpdateOverdueContentItems extends Command
{
    protected $signature = 'workflow:update-overdue';
    protected $description = 'Menandai content item sebagai overdue jika deadline sudah lewat dan belum selesai';

    // Status yang dianggap "selesai", tidak perlu ditandai overdue lagi
    private array $doneStatuses = ['approved', 'scheduled', 'uploaded', 'cancelled'];

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