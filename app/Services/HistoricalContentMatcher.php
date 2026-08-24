<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\InstagramMediaSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekonsiliasi HISTORIS ContentItem hasil "Content Planner Import" <->
 * InstagramMediaSnapshot lama - TERPISAH TOTAL dari ContentPublicationMatcher
 * (matcher operasional buat konten baru, JANGAN disentuh/diperlemah tolerance-nya).
 *
 * Kenapa perlu matcher terpisah: data planner lama TIDAK punya jam upload yang
 * presisi (kolom "Jam Upload" kosong utk sebagian besar baris), jadi
 * scheduled_upload_at hasil import sering default ke jam tertentu yang TIDAK
 * mencerminkan waktu publish Instagram asli - window ±120 menit
 * ContentPublicationMatcher nyaris tidak pernah match untuk data ini (dibuktikan
 * lewat audit: 0 HIGH/0 MEDIUM dari 27 snapshot TSA). Di sini toleransi tanggal
 * dilonggarkan jadi level KALENDER (same day / ±1 day), BUKAN menit, dan caption
 * similarity dipakai sebagai signal utama, bukan cuma tie-breaker.
 *
 * Deterministic murni (similar_text() native PHP) - TIDAK ada AI/LLM,
 * TIDAK pernah menulis apapun ke database (read-only, caller yang putuskan
 * mau ditulis atau tidak - saat ini caller cuma command --dry-run).
 */
class HistoricalContentMatcher
{
    // Skor per signal - dikalibrasi dari distribusi skor NYATA data TSA
    // (11 ContentItem x 27 snapshot unmatched, Agustus 2026): skor tertinggi
    // yang pernah muncul cuma 55 (caption promosi/giveaway panjang vs judul
    // planner pendek bikin similarity jarang tembus 40%) - makanya ambang
    // HIGH sengaja tinggi (70) dan sejauh ini TIDAK PERNAH tercapai oleh data
    // real, bukan berarti threshold-nya salah.
    private const SCORE_DATE_SAME_DAY = 40;
    private const SCORE_DATE_ONE_DAY = 15;
    private const SCORE_SIM_VERY_HIGH = 45; // similarity >= 70%
    private const SCORE_SIM_MEDIUM = 25;    // similarity 40-69%
    private const SCORE_SIM_WEAK = 10;      // similarity 20-39%
    private const SCORE_UNIQUE_DATE_CANDIDATE = 20;
    private const SCORE_FORMAT_COMPATIBLE = 5;

    private const SIM_THRESHOLD_VERY_HIGH = 70.0;
    private const SIM_THRESHOLD_MEDIUM = 40.0;
    private const SIM_THRESHOLD_WEAK = 20.0;

    // >1 hari cuma boleh tetap jadi kandidat kalau similarity SANGAT kuat
    // (Langkah 5A: "reject kecuali ada evidence sangat kuat lainnya").
    private const FAR_DATE_OVERRIDE_SIM_THRESHOLD = 70.0;

    /**
     * Hitung kandidat ContentItem buat 1 snapshot, dari kumpulan ContentItem
     * yang sudah di-scope (client sama, belum ada ContentPublication Instagram)
     * di luar method ini oleh caller. Kembalikan array kandidat terurut skor
     * tertinggi -> terendah, HANYA yang punya minimal 1 signal (date atau sim).
     */
    public function candidatesForSnapshot(InstagramMediaSnapshot $snapshot, Collection $items): array
    {
        if (! $snapshot->published_at) {
            return [];
        }

        $candidates = [];

        foreach ($items as $item) {
            $candidate = $this->scorePair($item, $snapshot);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $candidates;
    }

    /**
     * Signal D (Langkah 5D): +20 kalau pasangan tanggal ini SATU-SATUNYA di
     * kedua sisi - dalam scope ContentItem & snapshot yang sedang diaudit,
     * tidak ada ContentItem lain di tanggal yang sama, DAN tidak ada snapshot
     * lain di tanggal yang sama. Dipanggil terpisah oleh command setelah
     * seluruh kandidat per-snapshot terkumpul (butuh visibility lintas
     * snapshot buat tahu "unik" beneran).
     */
    public function applyUniqueDateBonus(array $allCandidatesBySnapshot, Collection $items, Collection $snapshots): array
    {
        $itemsByDate = [];
        foreach ($items as $item) {
            if (! $item->scheduled_upload_at) {
                continue;
            }
            $date = $item->scheduled_upload_at->copy()->timezone('Asia/Jakarta')->toDateString();
            $itemsByDate[$date] = ($itemsByDate[$date] ?? 0) + 1;
        }

        $snapshotsByDate = [];
        foreach ($snapshots as $snap) {
            if (! $snap->published_at) {
                continue;
            }
            $date = $snap->published_at->copy()->timezone('Asia/Jakarta')->toDateString();
            $snapshotsByDate[$date] = ($snapshotsByDate[$date] ?? 0) + 1;
        }

        foreach ($allCandidatesBySnapshot as $snapshotId => &$candidates) {
            foreach ($candidates as &$candidate) {
                $date = $candidate['item_date'];
                if (($itemsByDate[$date] ?? 0) === 1 && ($snapshotsByDate[$date] ?? 0) === 1) {
                    $candidate['score'] += self::SCORE_UNIQUE_DATE_CANDIDATE;
                    $candidate['signals'][] = 'unique_date_candidate';
                }
            }
            unset($candidate);
            usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        }
        unset($candidates);

        return $allCandidatesBySnapshot;
    }

    /**
     * Klasifikasi HIGH/MEDIUM/AMBIGUOUS/NO_MATCH dari kandidat yang sudah
     * terurut skor tertinggi -> terendah (Langkah 7).
     */
    public function classify(array $sortedCandidates): array
    {
        if (empty($sortedCandidates)) {
            return ['status' => 'NO_MATCH', 'reason' => 'Tidak ada kandidat dengan signal apapun.'];
        }

        $top = $sortedCandidates[0];
        $second = $sortedCandidates[1] ?? null;
        $gap = $second ? $top['score'] - $second['score'] : null;

        if ($top['score'] >= 70
            && $top['diff_days'] <= 1
            && $top['sim_score'] >= self::SCORE_SIM_MEDIUM
            && ($second === null || $gap >= 25)
        ) {
            return ['status' => 'HIGH', 'reason' => "Skor {$top['score']}, kandidat tunggal jelas, evidence tanggal+teks kuat."];
        }

        if ($top['score'] >= 45 && ($second === null || $gap >= 20)) {
            return ['status' => 'MEDIUM', 'reason' => "Skor {$top['score']}, kandidat terdepan jelas tapi belum cukup kuat buat auto-link - butuh review manusia."];
        }

        if ($top['score'] >= 40 && $second !== null && $second['score'] >= 40 && $gap < 20) {
            return ['status' => 'AMBIGUOUS', 'reason' => "{$top['score']} vs {$second['score']}, >=2 kandidat plausible dengan skor berdekatan."];
        }

        return ['status' => 'NO_MATCH', 'reason' => "Skor tertinggi cuma {$top['score']}, evidence terlalu lemah."];
    }

    private function scorePair(ContentItem $item, InstagramMediaSnapshot $snapshot): ?array
    {
        if (! $item->scheduled_upload_at) {
            return null;
        }

        $itemDate = $item->scheduled_upload_at->copy()->timezone('Asia/Jakarta')->toDateString();
        $snapDate = $snapshot->published_at->copy()->timezone('Asia/Jakarta')->toDateString();
        $diffDays = (int) round(abs(Carbon::parse($itemDate)->diffInDays(Carbon::parse($snapDate))));

        $dateScore = 0;
        if ($diffDays === 0) {
            $dateScore = self::SCORE_DATE_SAME_DAY;
        } elseif ($diffDays === 1) {
            $dateScore = self::SCORE_DATE_ONE_DAY;
        }

        $similarity = $this->textSimilarity(
            trim($item->title.' '.($item->caption_draft ?? '')),
            $snapshot->caption ?? ''
        );

        $simScore = 0;
        if ($similarity >= self::SIM_THRESHOLD_VERY_HIGH) {
            $simScore = self::SCORE_SIM_VERY_HIGH;
        } elseif ($similarity >= self::SIM_THRESHOLD_MEDIUM) {
            $simScore = self::SCORE_SIM_MEDIUM;
        } elseif ($similarity >= self::SIM_THRESHOLD_WEAK) {
            $simScore = self::SCORE_SIM_WEAK;
        }

        // Gate: >1 hari selisih HANYA lolos jadi kandidat kalau similarity-nya
        // sangat kuat (Langkah 5A) - selain itu bukan kandidat sama sekali,
        // bukan cuma dikasih skor 0 (biar tidak membanjiri hasil dengan noise
        // 60 hari selisih yang kebetulan ada kata sama).
        if ($diffDays > 1 && $similarity < self::FAR_DATE_OVERRIDE_SIM_THRESHOLD) {
            return null;
        }

        if ($dateScore === 0 && $simScore === 0) {
            return null;
        }

        $formatCompatible = $this->formatCompatible($item, $snapshot);
        $formatScore = $formatCompatible ? self::SCORE_FORMAT_COMPATIBLE : 0;

        $signals = [];
        if ($dateScore > 0) $signals[] = $diffDays === 0 ? 'same_date' : 'date_plus_minus_1';
        if ($simScore > 0) $signals[] = 'title_caption_similarity';
        if ($formatCompatible) $signals[] = 'compatible_format';

        return [
            'item' => $item,
            'snapshot' => $snapshot,
            'item_date' => $itemDate,
            'snap_date' => $snapDate,
            'diff_days' => $diffDays,
            'similarity' => round($similarity, 1),
            'date_score' => $dateScore,
            'sim_score' => $simScore,
            'format_score' => $formatScore,
            'score' => $dateScore + $simScore + $formatScore,
            'signals' => $signals,
        ];
    }

    /**
     * Signal C (Langkah 5C) - supporting only, TIDAK pernah jadi alasan
     * penolakan, cuma bonus kecil kalau taxonomy internal & Instagram
     * kebetulan searah. TIDAK menganggap taxonomy internal identik dengan
     * taxonomy Instagram (Langkah eksplisit).
     */
    private function formatCompatible(ContentItem $item, InstagramMediaSnapshot $snapshot): bool
    {
        $typeName = $item->contentType?->name;

        if ($typeName === 'Video') {
            return $snapshot->media_type === 'VIDEO' || $snapshot->media_product_type === 'REELS';
        }

        if ($typeName === 'Desain') {
            return in_array($snapshot->media_type, ['IMAGE', 'CAROUSEL_ALBUM'], true);
        }

        return false;
    }

    /**
     * Normalisasi: lowercase, whitespace, punctuation, emoji, hashtag umum -
     * SEMATA buat perbandingan (Langkah 5B: "Jangan mengubah data asli").
     * similar_text() native PHP, deterministic - BUKAN Gemini/LLM.
     */
    private function textSimilarity(string $a, string $b): float
    {
        $a = $this->normalizeText($a);
        $b = $this->normalizeText($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return $percent;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text);
        $text = preg_replace('/#\S+/u', '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
