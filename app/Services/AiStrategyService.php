<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContentMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AiStrategyService — AI beneran (Google Gemini API, free tier), bukan
 * teks statis.
 *
 * Alurnya:
 * 1. Ambil data performa konten 1 client, 1 bulan kalender penuh
 *    sebelumnya (misal digenerate Agustus -> datanya bulan Juli), dari
 *    content_metrics (angka asli, bukan dummy)
 * 2. Ringkes jadi JSON kecil (total views, engagement, top 5 konten,
 *    breakdown per platform & pillar, arah tren)
 * 3. Kirim ke Gemini API, minta balikan terstruktur: narasi strategi,
 *    action items, suggested content split
 * 4. Simpan hasilnya ke tabel ai_strategy_insights
 *
 * Butuh GEMINI_API_KEY di .env - ambil gratis di
 * https://aistudio.google.com/app/apikey (nggak perlu kartu kredit).
 */
class AiStrategyService
{
    // Model gratis di free tier Gemini. Kalau nanti error "model not
    // found", cek daftar model terbaru di https://aistudio.google.com
    // dan ganti nilai ini.
    private const MODEL = 'gemini-flash-lite-latest';

    /**
     * Ambil data performa 1 BULAN KALENDER PENUH sebelumnya (bukan rolling
     * "30 hari terakhir dari hari ini"). Kenapa: rolling window bikin
     * periode geser tiap hari dan motong lintas bulan (misal 7 Juli - 5
     * Agustus), padahal Content Plan sistemnya per-bulan kalender. Dengan
     * ini, generate analisis kapan aja di bulan Agustus selalu ngasih
     * hasil yang sama: performa penuh bulan Juli. Nggak ada overlap
     * antar-bulan.
     */
    public function buildPerformanceSummary(Client $client): array
    {
        $end = Carbon::now()->subMonthNoOverflow()->endOfMonth();
        $start = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $days = $start->diffInDays($end) + 1;

        $prevMonthEnd = $start->copy()->subDay()->endOfMonth();
        $prevMonthStart = $start->copy()->subMonthNoOverflow()->startOfMonth();

        $metrics = ContentMetric::with(['contentItem.contentPillar', 'contentItem.contentType', 'platform'])
            ->whereHas('contentItem', fn ($q) => $q->where('client_id', $client->id))
            ->whereBetween('metric_date', [$start, $end])
            ->get();

        $prevMetrics = ContentMetric::whereHas('contentItem', fn ($q) => $q->where('client_id', $client->id))
            ->whereBetween('metric_date', [$prevMonthStart, $prevMonthEnd])
            ->get();

        $totalViews = (int) $metrics->sum('views');
        $prevTotalViews = (int) $prevMetrics->sum('views');
        $trendDirection = $prevTotalViews > 0
            ? round((($totalViews - $prevTotalViews) / $prevTotalViews) * 100, 1)
            : null;

        $byPillar = $metrics->groupBy(fn ($m) => $m->contentItem->contentPillar->name ?? 'Tanpa Pilar')
            ->map(fn ($rows) => [
                'total_views' => (int) $rows->sum('views'),
                'avg_engagement' => round($rows->avg('engagement_rate'), 2),
                'content_count' => $rows->pluck('content_item_id')->unique()->count(),
            ]);

        $byPlatform = $metrics->groupBy(fn ($m) => $m->platform->name ?? '-')
            ->map(fn ($rows) => ['total_views' => (int) $rows->sum('views')]);

        $topContent = $metrics->groupBy('content_item_id')
            ->map(fn ($rows) => [
                'title' => $rows->first()->contentItem->title ?? '-',
                'pillar' => $rows->first()->contentItem->contentPillar->name ?? '-',
                'type' => $rows->first()->contentItem->contentType->name ?? '-',
                'platform' => $rows->first()->platform->name ?? '-',
                'views' => (int) $rows->sum('views'),
                'engagement_rate' => round($rows->avg('engagement_rate'), 2),
            ])
            ->sortByDesc('views')
            ->take(5)
            ->values();

        return [
            'client_name' => $client->name,
            'period' => "{$start->format('d M Y')} - {$end->format('d M Y')}",
            'total_views' => $totalViews,
            'avg_engagement_rate' => $metrics->count() > 0 ? round($metrics->avg('engagement_rate'), 2) : 0,
            'trend_vs_previous_period_percent' => $trendDirection,
            'content_published_count' => $metrics->pluck('content_item_id')->unique()->count(),
            'tracked_days' => $metrics->pluck('metric_date')->unique()->count(),
            'period_days' => $days,
            'performance_by_pillar' => $byPillar,
            'performance_by_platform' => $byPlatform,
            'top_5_content' => $topContent,
        ];
    }

    /**
     * @return array{summary: string, action_items: array, suggested_split: array}
     * @throws \RuntimeException kalau API call gagal
     */
    public function generateStrategy(array $performanceSummary): array
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('GEMINI_API_KEY belum di-set di .env');
        }

        $prompt = $this->buildPrompt($performanceSummary);

        $response = Http::timeout(60)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'.self::MODEL.':generateContent?key='.$apiKey,
            [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'maxOutputTokens' => 2048,
                ],
            ]
        );

        if ($response->failed()) {
            Log::error('AiStrategyService: Gemini API gagal', ['body' => $response->body()]);
            throw new \RuntimeException('Gagal menghubungi Gemini API: '.$response->status().' - '.($response->json('error.message') ?? $response->body()));
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! $text) {
            // Kadang Gemini nolak jawab karena safety filter - cek alasannya
            $finishReason = $response->json('candidates.0.finishReason');
            throw new \RuntimeException('Response API kosong (finishReason: '.($finishReason ?? 'unknown').').');
        }

        $parsed = $this->extractJson($text);

        if (! $parsed || ! isset($parsed['summary'], $parsed['action_items'])) {
            throw new \RuntimeException('Gagal parsing hasil AI jadi format terstruktur.');
        }

        return [
            'summary' => $parsed['summary'],
            'action_items' => $parsed['action_items'],
            'suggested_split' => $parsed['suggested_split'] ?? [],
            'top_pillars' => $parsed['top_pillars'] ?? [],
            'content_ideas' => $parsed['content_ideas'] ?? [],
        ];
    }

    private function buildPrompt(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Kamu adalah social media strategist untuk agensi kreatif. Di bawah ini data
performa konten asli 1 client, {$data['period']} (dari database, bukan
contoh):

{$json}

Analisis data ini dan berikan rekomendasi strategi konten untuk periode
berikutnya. Dasarkan rekomendasi HANYA pada angka yang diberikan (pillar
mana yang performanya terbaik, platform mana yang paling efektif, tren
naik/turun) - jangan mengarang data yang tidak ada di JSON di atas. Kalau
data yang tersedia terlalu sedikit untuk suatu kesimpulan, katakan itu
secara eksplisit di summary, jangan dipaksakan.

Balas HANYA dalam format JSON valid, tanpa teks lain di luar JSON, tanpa
markdown code block, dengan struktur persis seperti ini:

{
  "summary": "2-3 kalimat ringkasan kondisi & rekomendasi strategi utama, dalam Bahasa Indonesia",
  "action_items": ["action item 1 yang spesifik & bisa dieksekusi", "action item 2", "action item 3"],
  "suggested_split": [{"label": "nama pillar/format", "value": persentase_angka}],
  "top_pillars": [
    {"name": "nama pillar dari performance_by_pillar di atas", "reasoning": "1 kalimat kenapa pillar ini masuk ranking, sebut angka konkretnya"}
  ],
  "content_ideas": [
    {"pillar": "nama pillar (harus match salah satu label di suggested_split)", "title": "judul konten yang siap pakai, spesifik, bukan generik", "brief": "2-3 kalimat brief: sudut pandang/hook/poin utama yang harus disampaikan"}
  ]
}

Aturan tambahan:
- suggested_split harus total 100, isinya pillar YANG ADA di
  performance_by_pillar (jangan bikin pillar baru yang nggak ada di data)
- top_pillars maksimal 3, diurutkan dari performa terbaik, WAJIB nyebut
  angka asli (views/engagement) dari data di atas di bagian reasoning,
  jangan cuma bilang "bagus" tanpa angka
- Kalau performance_by_pillar di data cuma punya 1 atau 2 pillar,
  top_pillars ya isi sejumlah yang ada aja, jangan dipaksa jadi 3
- content_ideas: buatkan 8-10 ide konten total, sebarannya ngikutin
  persentase di suggested_split (misal kalau Edukasi 40%, bikin ~4 ide
  buat pillar Edukasi). Judul & brief harus konkret dan siap dipakai tim
  produksi, bukan placeholder generik kayak "Konten Edukasi #1"
PROMPT;
    }

    private function extractJson(string $text): ?array
    {
        // Gemini sering bungkus JSON dalam ```json ... ``` walaupun udah
        // diminta jangan - bersihin dulu biar aman
        $text = trim($text);
        $text = preg_replace('/^```(json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}