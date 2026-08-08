<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsSyncLog;
use App\Models\ContentItem;
use App\Models\Notification;

class NotificationController extends Controller
{
    public function markAllRead()
    {
        auth()->user()->notifications()->where('is_read', false)->update(['is_read' => true]);

        return back();
    }

    public function markRead(Notification $notification)
    {
        abort_unless($notification->user_id === auth()->id(), 403);

        $notification->update(['is_read' => true]);

        if ($notification->related_type === ContentItem::class && $notification->related_id) {
            // ai_insight = anomali performa (spike/drop views) dari
            // analytics:detect-anomalies - relevan buat dibawa ke halaman
            // performa (Analytics), bukan halaman produksi/workflow.
            $route = $notification->type === 'ai_insight' ? 'analytics.show' : 'content-items.show';

            return redirect()->route($route, $notification->related_id);
        }

        if ($notification->related_type === AnalyticsSyncLog::class) {
            return redirect()->route('settings.integrations');
        }

        return back();
    }
}
