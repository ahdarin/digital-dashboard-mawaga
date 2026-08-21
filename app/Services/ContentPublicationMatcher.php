<?php

namespace App\Services;

use App\Models\ApiIntegration;
use App\Models\ContentItem;
use App\Models\ContentPublication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cocokkan 1 media Instagram (hasil InstagramAnalyticsService::getMedia())
 * ke ContentPublication/ContentItem internal - rule-based, deterministic,
 * TIDAK ada ML/LLM. Dipakai SyncInstagramAnalytics per media di dalam sync,
 * dan (nanti) endpoint manual-link buat nampilin kandidat.
 *
 * Urutan prioritas (semua discan berurutan, berhenti begitu ketemu):
 * 1. external_post_id existing - paling kuat, sync berikutnya lewat sini.
 * 2. permalink exact (dinormalisasi) - link post lama yang sudah dicatat
 *    manual via Publishing Tracker tapi belum py external_post_id.
 * 3. Kandidat jadwal: client+platform sama, scheduled_upload_at deket
 *    timestamp media, BELUM ada publication ber-external_post_id. Auto-match
 *    HANYA kalau kandidatnya tepat 1 (unambiguous).
 * 4. Tie-breaker caption/title similarity DI DALAM kandidat #3 yang ambigu -
 *    bukan pencarian independen, cuma buat coba mempersempit kalau ada
 *    persis 1 kandidat yang similarity-nya jauh lebih tinggi dari yang lain.
 *
 * CATATAN (per audit ulang Agustus 2026): scheduled_upload_at BUKAN dead
 * field - ada alur nyata (Content Item > status "approved" > isi jadwal >
 * "JADWALKAN UPLOAD", wajib diisi lewat WorkflowStatusService::transition()).
 * Gap-nya murni karena 3 client yang sudah connect Instagram itu akun BARU
 * dihubungkan dengan histori lama, belum ada konten baru yang lewat alur
 * internal ini. Priority 3/4 akan mulai menghasilkan kandidat begitu ada
 * konten yang benar-benar dijadwalkan lewat fitur itu.
 *
 * Toleransi jadwal dibaca dari config/analytics.php
 * (instagram_schedule_match_tolerance_minutes) - BELUM dikalibrasi dari data
 * operasional nyata (belum ada datanya), tinjau ulang kalau sudah ada pola.
 */
class ContentPublicationMatcher
{
    private const CAPTION_SIMILARITY_THRESHOLD = 60.0; // persen, dari similar_text()

    public function match(ApiIntegration $integration, array $media): ContentPublicationMatchResult
    {
        $byExternalId = $this->matchByExternalPostId($integration, $media);
        if ($byExternalId) {
            return ContentPublicationMatchResult::matched($byExternalId, 'external_post_id');
        }

        $byPermalink = $this->matchByPermalink($integration, $media);
        if ($byPermalink) {
            return ContentPublicationMatchResult::matched($byPermalink, 'permalink');
        }

        return $this->matchByScheduleCandidate($integration, $media);
    }

    private function matchByExternalPostId(ApiIntegration $integration, array $media): ?ContentPublication
    {
        return ContentPublication::where('platform_id', $integration->platform_id)
            ->where('external_post_id', $media['id'])
            ->whereHas('contentItem', fn ($q) => $q->where('client_id', $integration->client_id))
            ->first();
    }

    /**
     * Exact-match URL yang dinormalisasi (trailing slash + query string
     * dibuang) - BUKAN fuzzy matching, sesuai Langkah 4 Priority 2.
     */
    private function matchByPermalink(ApiIntegration $integration, array $media): ?ContentPublication
    {
        if (! $media['permalink']) {
            return null;
        }

        $target = $this->normalizeUrl($media['permalink']);

        return ContentPublication::where('platform_id', $integration->platform_id)
            ->whereNull('external_post_id')
            ->whereNotNull('post_url')
            ->whereHas('contentItem', fn ($q) => $q->where('client_id', $integration->client_id))
            ->get()
            ->first(fn (ContentPublication $p) => $this->normalizeUrl($p->post_url) === $target);
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $path = rtrim($parts['path'] ?? '', '/');

        return strtolower(($parts['host'] ?? '').$path);
    }

    private function matchByScheduleCandidate(ApiIntegration $integration, array $media): ContentPublicationMatchResult
    {
        if (! $media['timestamp']) {
            return ContentPublicationMatchResult::unmatched();
        }

        $toleranceMinutes = config('analytics.instagram_schedule_match_tolerance_minutes');
        $mediaTime = Carbon::parse($media['timestamp']);
        $windowStart = $mediaTime->copy()->subMinutes($toleranceMinutes);
        $windowEnd = $mediaTime->copy()->addMinutes($toleranceMinutes);

        // Kandidat: client+platform sama, ada jadwal dalam window, DAN
        // belum terhubung ke post Instagram manapun (tidak punya publication
        // ber-external_post_id di platform ini) - content_item yang sudah
        // pasti terhubung ke post lain nggak boleh direbut.
        $candidates = ContentItem::where('client_id', $integration->client_id)
            ->whereBetween('scheduled_upload_at', [$windowStart, $windowEnd])
            ->whereDoesntHave('publications', fn ($q) => $q->where('platform_id', $integration->platform_id)->whereNotNull('external_post_id'))
            ->get();

        if ($candidates->count() === 1) {
            return ContentPublicationMatchResult::matchedCandidate(
                $candidates->first(), 'schedule',
                "1 kandidat unik dalam window ±{$toleranceMinutes} menit dari scheduled_upload_at"
            );
        }

        if ($candidates->count() > 1) {
            $narrowed = $this->narrowByCaptionSimilarity($candidates, $media['caption'] ?? null);
            if ($narrowed) {
                return ContentPublicationMatchResult::matchedCandidate(
                    $narrowed, 'caption_date',
                    'Tie-breaker caption similarity di antara '.$candidates->count().' kandidat jadwal yang ambigu'
                );
            }

            return ContentPublicationMatchResult::ambiguous(
                $candidates->count().' kandidat ditemukan dalam window jadwal, tidak unambiguous: content_item id '
                .$candidates->pluck('id')->implode(', ')
            );
        }

        return ContentPublicationMatchResult::unmatched();
    }

    /**
     * Tie-breaker SEKUNDER, cuma jalan di dalam kandidat jadwal yang sudah
     * ambigu (bukan pencarian independen) - similar_text() native PHP,
     * bukan ML. Pilih HANYA kalau satu kandidat similarity-nya jelas
     * menonjol (>= threshold) DAN tidak ada kandidat lain yang menyamai.
     */
    private function narrowByCaptionSimilarity(Collection $candidates, ?string $caption): ?ContentItem
    {
        if (! $caption) {
            return null;
        }

        $normalizedCaption = $this->normalizeText($caption);
        $scored = $candidates->map(function (ContentItem $item) use ($normalizedCaption) {
            $text = $this->normalizeText($item->title.' '.($item->caption_draft ?? ''));
            similar_text($normalizedCaption, $text, $percent);

            return ['item' => $item, 'score' => $percent];
        })->sortByDesc('score')->values();

        $top = $scored->first();
        $second = $scored->get(1);

        if ($top && $top['score'] >= self::CAPTION_SIMILARITY_THRESHOLD
            && (! $second || $second['score'] < self::CAPTION_SIMILARITY_THRESHOLD)) {
            return $top['item'];
        }

        return null;
    }

    private function normalizeText(string $text): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $text)));
    }
}
