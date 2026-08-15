<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public static function notify(User $user, string $title, string $type, ?string $body = null, ?ContentItem $related = null): void
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'type' => $type,
            'body' => $body,
            'related_type' => $related ? ContentItem::class : null,
            'related_id' => $related?->id,
            'is_read' => false,
        ]);
    }

    public static function notifyAssignedUsers(ContentItem $contentItem, string $title, string $type, ?string $body = null): void
    {
        $userIds = $contentItem->assignments->pluck('user_id')->unique();

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if ($user) {
                self::notify($user, $title, $type, $body, $contentItem);
            }
        }
    }

    /**
     * Notif ke Manager & SMO saat klien approve dari Portal Klien, supaya
     * antrean pengecekan internal (waiting_review + client_review_result
     * approved) nggak kelewat sebelum benar-benar dipindah ke Disetujui.
     */
    public static function notifyInternalCheckers(ContentItem $contentItem): void
    {
        $checkers = User::whereHas('role', fn ($q) => $q->whereIn('name', ['Manager', 'SMO']))
            ->where('status', 'active')
            ->get();

        foreach ($checkers as $checker) {
            self::notify(
                $checker,
                'Klien Sudah Setuju - Perlu Dicek',
                'client_approved',
                "\"{$contentItem->title}\" sudah disetujui klien, tunggu pengecekan internal sebelum ditandai Disetujui.",
                $contentItem
            );
        }
    }
}