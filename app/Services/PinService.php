<?php

namespace App\Services;

use App\Exceptions\PinException;
use App\Models\ContentItem;
use App\Models\Pin;
use App\Models\User;
use App\Support\WorkflowTransitions;
use Illuminate\Support\Collection;

/**
 * Pin personal - beda dari ContentItem::is_urgent (yang disetel sistem dan
 * kelihatan semua orang), pin cuma disetel & kelihatan oleh user yang
 * nge-pin sendiri. Dibatasi jumlahnya (MAX_PINS) supaya tetap berarti
 * sebagai "fokus saya sekarang", bukan jadi bookmark tanpa batas.
 */
class PinService
{
    public const MAX_PINS = 8;

    public function isPinned(User $user, ContentItem $contentItem): bool
    {
        return Pin::where('user_id', $user->id)
            ->where('pinnable_type', ContentItem::class)
            ->where('pinnable_id', $contentItem->id)
            ->exists();
    }

    public function pin(User $user, ContentItem $contentItem): void
    {
        if ($this->isPinned($user, $contentItem)) {
            return;
        }

        // Konten yang sudah tayang/dibatalkan nggak relevan lagi buat
        // "fokus saya sekarang" - dan nggak akan pernah lewat transisi
        // status lagi (WorkflowTransitions::DONE_STATUSES nggak punya
        // tujuan lanjutan), jadi kalau boleh di-pin dia bakal nyangkut
        // permanen sampai dilepas manual.
        if (in_array($contentItem->workflow?->current_status, WorkflowTransitions::DONE_STATUSES, true)) {
            throw new PinException('Konten yang sudah selesai (tayang/dibatalkan) tidak bisa di-pin.');
        }

        $count = Pin::where('user_id', $user->id)
            ->where('pinnable_type', ContentItem::class)
            ->count();

        if ($count >= self::MAX_PINS) {
            throw new PinException(
                'Maksimal ' . self::MAX_PINS . ' konten yang bisa di-pin sekaligus. Lepas salah satu dulu sebelum menambah.'
            );
        }

        Pin::create([
            'user_id' => $user->id,
            'pinnable_type' => ContentItem::class,
            'pinnable_id' => $contentItem->id,
        ]);
    }

    public function unpin(User $user, ContentItem $contentItem): void
    {
        Pin::where('user_id', $user->id)
            ->where('pinnable_type', ContentItem::class)
            ->where('pinnable_id', $contentItem->id)
            ->delete();
    }

    /**
     * ID content item yang di-pin user, dipakai controller buat highlight +
     * urutkan-ke-atas di tabel Task/Produksi tanpa query N+1 per baris.
     */
    public function pinnedContentItemIds(User $user): Collection
    {
        return Pin::where('user_id', $user->id)
            ->where('pinnable_type', ContentItem::class)
            ->pluck('pinnable_id');
    }

    /**
     * Konten yang di-pin user, terbaru di-pin duluan - dipakai widget
     * "Fokus Saya" di Beranda.
     */
    public function pinnedContentItems(User $user): Collection
    {
        $ids = Pin::where('user_id', $user->id)
            ->where('pinnable_type', ContentItem::class)
            ->orderByDesc('created_at')
            ->pluck('pinnable_id');

        $items = ContentItem::whereIn('id', $ids)
            ->with(['client', 'workflow'])
            ->get()
            ->keyBy('id');

        return $ids->map(fn ($id) => $items->get($id))->filter()->values();
    }

    /**
     * Dipanggil WorkflowStatusService saat konten masuk status 'uploaded' -
     * konten yang sudah tayang otomatis lepas dari daftar pin semua orang,
     * biar daftar pin nggak numpuk sampah konten yang udah kelar.
     */
    public function unpinForAllUsers(ContentItem $contentItem): void
    {
        Pin::where('pinnable_type', ContentItem::class)
            ->where('pinnable_id', $contentItem->id)
            ->delete();
    }
}
