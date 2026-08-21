<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentPublication;

/**
 * Hasil 1 kali matching (lihat ContentPublicationMatcher::match()).
 *
 * - status 'matched' + $publication terisi -> publication SUDAH ADA
 *   (ketemu lewat external_post_id/permalink), tinggal dipakai.
 * - status 'matched' + $contentItem terisi (bukan $publication) -> ketemu
 *   lewat kandidat jadwal/caption, publication BELUM ADA, caller yang bikin
 *   baru (lihat SyncInstagramAnalytics::persistMedia()).
 * - status 'ambiguous' -> ada >1 kandidat, sengaja TIDAK auto-link.
 * - status 'unmatched' -> nggak ada kandidat sama sekali.
 */
final class ContentPublicationMatchResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?ContentPublication $publication = null,
        public readonly ?ContentItem $contentItem = null,
        public readonly ?string $matchedBy = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function matched(ContentPublication $publication, string $matchedBy): self
    {
        return new self('matched', publication: $publication, matchedBy: $matchedBy);
    }

    public static function matchedCandidate(ContentItem $contentItem, string $matchedBy, string $reason): self
    {
        return new self('matched', contentItem: $contentItem, matchedBy: $matchedBy, reason: $reason);
    }

    public static function ambiguous(string $reason): self
    {
        return new self('ambiguous', reason: $reason);
    }

    public static function unmatched(): self
    {
        return new self('unmatched');
    }

    public function resolvedContentItemId(): ?int
    {
        return $this->publication?->content_item_id ?? $this->contentItem?->id;
    }
}
