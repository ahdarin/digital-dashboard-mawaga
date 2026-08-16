<?php

namespace App\Console\Commands;

use App\Models\ContentItem;
use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\WorkflowTransitions;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendDelayRiskNotifications extends Command
{
    protected $signature = 'workflow:send-delay-risk-notifications';
    protected $description = 'Kirim notifikasi H-7/5/3/1 sebelum deadline dan reminder harian untuk item overdue';

    private array $doneStatuses = WorkflowTransitions::DONE_STATUSES;
    private array $reminderDays = [7, 5, 3, 1];

    public function handle(): void
    {
        $this->sendDeadlineReminders();
        $this->sendOverdueReminders();
    }

    private function sendDeadlineReminders(): void
    {
        $today = Carbon::today();
        $count = 0;

        foreach ($this->reminderDays as $daysBefore) {
            $targetDate = $today->copy()->addDays($daysBefore);

            $items = ContentItem::with(['workflow.currentPic', 'client'])
                ->whereDate('deadline_at', $targetDate)
                ->whereHas('workflow', fn($q) => $q->whereNotIn('current_status', $this->doneStatuses))
                ->get();

            foreach ($items as $item) {
                if (!$item->workflow->currentPic) {
                    continue;
                }

                $alreadySent = Notification::where('related_type', ContentItem::class)
                    ->where('related_id', $item->id)
                    ->where('type', 'deadline_reminder')
                    ->whereDate('created_at', $today)
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                NotificationService::notify(
                    $item->workflow->currentPic,
                    "Deadline {$daysBefore} Hari Lagi",
                    'deadline_reminder',
                    "Konten '{$item->title}' ({$item->client->name}) deadline-nya {$daysBefore} hari lagi ({$item->deadline_at->format('d M Y')}).",
                    $item
                );

                $count++;
            }
        }

        $this->info("{$count} reminder deadline terkirim.");
    }

    private function sendOverdueReminders(): void
    {
        $today = Carbon::today();
        $count = 0;

        $overdueItems = ContentItem::with(['workflow.currentPic', 'client'])
            ->whereHas('workflow', fn($q) => $q->where('is_overdue', true))
            ->get();

        foreach ($overdueItems as $item) {
            if (!$item->workflow->currentPic) {
                continue;
            }

            $alreadySent = Notification::where('related_type', ContentItem::class)
                ->where('related_id', $item->id)
                ->where('type', 'overdue_reminder')
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $daysLate = $item->deadline_at->diffInDays(now());

            NotificationService::notify(
                $item->workflow->currentPic,
                'Konten Terlambat',
                'overdue_reminder',
                "Konten '{$item->title}' ({$item->client->name}) sudah terlambat {$daysLate} hari dari deadline.",
                $item
            );

            $count++;
        }

        $this->info("{$count} reminder overdue terkirim.");
    }
}