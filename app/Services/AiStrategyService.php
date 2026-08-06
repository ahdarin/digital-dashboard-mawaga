<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContentMetric;
use App\Models\ContentType;
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
    /**
     * Periode yang dianalisis AI Strategy - 1 bulan kalender penuh
     * sebelumnya. Public & dipakai bareng sama AnalyticsController buat
     * nyimpen period_start/period_end di AiStrategyInsight - biar cuma ada
     * 1 tempat yang nentuin "periode sebelumnya" itu kapan (dulu dihitung
     * ulang terpisah di controller, riskan nggak sinkron kalau logic-nya
     * berubah di salah satu tempat doang).
     */
    public function analysisPeriod(): array
    {
        return [
            'start' => Carbon::now()->subMonthNoOverflow()->startOfMonth(),
            'end' => Carbon::now()->subMonthNoOverflow()->endOfMonth(),
        ];
    }

    public function buildPerformanceSummary(Client $client): array
    {
        $period = $this->analysisPeriod();
        $start = $period['start'];
        $end = $period['end'];
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

        // Berapa banyak ide konten yang AI harus generate - ngikutin kuota
        // bulanan paket client (content + design), bukan angka tetap. Ini
        // dipakai applyAiStrategy() buat nentuin jumlah draft ContentItem,
        // jadi jumlah ide yang diminta ke sini WAJIB nyamain itu - kalau
        // enggak, draft yang kebanyakan bakal jatuh ke placeholder generik
        // tanpa judul/brief/format asli dari AI.
        $activePackage = $client->activePackage;
        $targetItemCount = (($activePackage->monthly_content_quota ?? 0) + ($activePackage->monthly_design_quota ?? 0)) ?: 10;

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
            'target_content_count' => $targetItemCount,
        ];
    }

    /**
     * @return array{summary: string, action_items: array, suggested_split: array}
     * @throws \RuntimeException kalau API call gagal
     */
    public function generateStrategy(array $performanceSummary): array
    {
        $prompt = $this->buildPrompt($performanceSummary);
        $text = $this->callGemini($prompt, $this->outputTokenBudget($performanceSummary['target_content_count'] ?? 10));

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

    /**
     * Balesan diskusi/chat - AI jawab pertanyaan/masukan user tentang
     * insight yang udah digenerate, TETAP ngerujuk ke data performa asli
     * ($insight->performance_data) biar jawabannya nggak ngarang.
     *
     * @param array $conversationHistory [['role' => 'user'|'assistant', 'message' => '...'], ...]
     */
    public function chat(array $performanceData, array $previousResult, array $conversationHistory, string $userMessage): string
    {
        $dataJson = json_encode($performanceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $resultJson = json_encode($previousResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $historyText = collect($conversationHistory)
            ->map(fn ($m) => ($m['role'] === 'user' ? 'User' : 'AI').': '.$m['message'])
            ->implode("\n");

        $prompt = <<<PROMPT
Kamu adalah social media strategist yang lagi diskusi sama tim agensi
soal analisis performa konten yang udah kamu buat sebelumnya.

DATA PERFORMA ASLI (jadi dasar analisis awal):
{$dataJson}

ANALISIS AWAL YANG UDAH KAMU BERIKAN:
{$resultJson}

RIWAYAT DISKUSI SEJAUH INI:
{$historyText}

Pesan terbaru dari user:
{$userMessage}

Jawab pesan user itu secara natural, dalam Bahasa Indonesia, singkat aja
(maksimal 4-5 kalimat, kayak chat biasa bukan laporan formal). Tulis
dalam KALIMAT MENGALIR BIASA - JANGAN pakai markdown sama sekali (jangan
pakai **bold**, jangan pakai bullet point/list bernomor, jangan pakai
heading #). Kalau perlu nyebut beberapa poin, gabung jadi 1-2 kalimat
aja, bukan list. Kalau user ngasih info baru yang nggak ada di data
(misal "sebenarnya bulan itu kita ganti strategi tanggal 15"), akui itu
terus jelasin gimana itu bisa ngubah interpretasi analisisnya. Kalau
user nanya sesuatu yang datanya nggak ada di DATA PERFORMA ASLI di atas,
bilang terus terang datanya nggak tersedia, jangan ngarang angka. JANGAN
balas dalam format JSON, cukup teks biasa.
PROMPT;

        $text = $this->callGemini($prompt, 512);

        // Jaga-jaga kalau Gemini tetep ngasih markdown walau udah dilarang -
        // bersihin simbolnya biar nggak keliatan mentah di UI chat
        $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text); // **bold** -> bold
        $text = preg_replace('/^[\*\-]\s+/m', '', $text);      // bullet list -> teks biasa
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);      // # heading -> teks biasa

        return trim($text);
    }

    /**
     * Generate ULANG analisis terstruktur (summary/action_items/dst),
     * kali ini mempertimbangkan seluruh diskusi yang udah terjadi -
     * dipanggil pas user klik "Perbarui Analisis dari Diskusi Ini".
     */
    public function refineFromDiscussion(array $performanceData, array $previousResult, array $conversationHistory): array
    {
        $dataJson = json_encode($performanceData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $resultJson = json_encode($previousResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $targetCount = $performanceData['target_content_count'] ?? 10;
        $platformNames = collect($performanceData['performance_by_platform'] ?? [])->keys();
        $platformOptions = $platformNames->isNotEmpty() ? $platformNames->implode(', ') : 'Instagram, TikTok';

        $historyText = collect($conversationHistory)
            ->map(fn ($m) => ($m['role'] === 'user' ? 'User' : 'AI').': '.$m['message'])
            ->implode("\n");

        $prompt = <<<PROMPT
Kamu sebelumnya udah bikin analisis strategi konten berikut, berdasarkan
data performa ini:

DATA PERFORMA ASLI:
{$dataJson}

ANALISIS SEBELUMNYA:
{$resultJson}

Tim udah ngasih diskusi/masukan tambahan soal analisis ini:
{$historyText}

Sekarang PERBARUI analisisnya dengan mempertimbangkan masukan dari diskusi
di atas, tapi TETAP berdasarkan data performa asli (jangan ngarang angka
yang nggak ada). Kalau masukan dari user itu berupa konteks tambahan
(bukan angka), pakai itu buat nyempurnain rekomendasi strategi, bukan
buat ngubah angka performa yang udah ada.

Balas HANYA dalam format JSON valid, tanpa teks lain, struktur PERSIS
sama kayak sebelumnya:

{
  "summary": "2-3 kalimat ringkasan yang UDAH mempertimbangkan diskusi",
  "action_items": ["action item 1", "action item 2", "action item 3"],
  "suggested_split": [{"label": "nama pillar", "value": persentase_angka}],
  "top_pillars": [{"name": "...", "reasoning": "..."}],
  "content_ideas": [{"pillar": "...", "title": "...", "brief": "...", "type": "salah satu dari: {$this->contentTypeOptions()}", "platform": "salah satu dari: {$platformOptions}"}]
}

content_ideas WAJIB tetap {$targetCount} ide total (sama kayak sebelumnya,
ngikutin target_content_count), kecuali diskusi di atas eksplisit minta
jumlahnya diubah. Field "type" WAJIB diisi salah satu dari: {$this->contentTypeOptions()}.
Field "platform" WAJIB diisi salah satu dari: {$platformOptions}.
PROMPT;

        $text = $this->callGemini($prompt, $this->outputTokenBudget($targetCount));
        $parsed = $this->extractJson($text);

        if (! $parsed || ! isset($parsed['summary'], $parsed['action_items'])) {
            throw new \RuntimeException('Gagal parsing hasil pembaruan analisis.');
        }

        return [
            'summary' => $parsed['summary'],
            'action_items' => $parsed['action_items'],
            'suggested_split' => $parsed['suggested_split'] ?? [],
            'top_pillars' => $parsed['top_pillars'] ?? [],
            'content_ideas' => $parsed['content_ideas'] ?? [],
        ];
    }

    /**
     * Helper mentah - kirim prompt ke Gemini, balikin teks jawabannya.
     * Dipakai bareng sama generateStrategy(), chat(), dan refineFromDiscussion()
     * biar nggak duplikasi kode manggil API.
     */
    private function callGemini(string $prompt, int $maxOutputTokens): string
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('GEMINI_API_KEY belum di-set di .env');
        }

        $response = Http::timeout(60)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/'.self::MODEL.':generateContent?key='.$apiKey,
            [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'maxOutputTokens' => $maxOutputTokens,
                ],
            ]
        );

        if ($response->failed()) {
            Log::error('AiStrategyService: Gemini API gagal', ['body' => $response->body()]);
            throw new \RuntimeException('Gagal menghubungi Gemini API: '.$response->status().' - '.($response->json('error.message') ?? $response->body()));
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! $text) {
            $finishReason = $response->json('candidates.0.finishReason');
            throw new \RuntimeException('Response API kosong (finishReason: '.($finishReason ?? 'unknown').').');
        }

        return $text;
    }

    private function buildPrompt(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $targetCount = $data['target_content_count'] ?? 10;
        // performance_by_platform bisa Collection (baru dibangun di
        // buildPerformanceSummary(), belum lewat cast array dari DB) atau
        // array biasa (udah lewat AiStrategyInsight->performance_data) -
        // collect() aman buat dua-duanya.
        $platformNames = collect($data['performance_by_platform'] ?? [])->keys();
        $platformOptions = $platformNames->isNotEmpty() ? $platformNames->implode(', ') : 'Instagram, TikTok';

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
    {"pillar": "nama pillar (harus match salah satu label di suggested_split)", "title": "judul konten yang siap pakai, spesifik, bukan generik", "brief": "2-3 kalimat brief: sudut pandang/hook/poin utama yang harus disampaikan", "type": "salah satu dari: {$this->contentTypeOptions()}", "platform": "salah satu dari: {$platformOptions}"}
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
- content_ideas: WAJIB buatkan TEPAT {$targetCount} ide konten total (ini
  target_content_count di data di atas, ngikutin kuota konten+desain
  bulanan client - JANGAN dibulatkan ke bawah atau dikira-kira 8-10 kayak
  kebiasaan biasanya). Sebarannya ngikutin persentase di suggested_split
  (misal kalau Edukasi 40% dari {$targetCount} ide, bikin ~{$targetCount}
  x 40% ide buat pillar Edukasi, dibulatkan). Judul & brief harus konkret
  dan siap dipakai tim produksi, bukan placeholder generik kayak "Konten
  Edukasi #1". Kalau {$targetCount} kerasa banyak, tetap usahakan
  se-konkret mungkin per ide - boleh lebih ringkas per brief asal
  jumlahnya tetap {$targetCount}
- PENTING soal beban kerja tim produksi: field "type" di tiap content_ideas
  WAJIB diisi salah satu dari daftar ini, PERSIS namanya (case-sensitive):
  {$this->contentTypeOptions()}. Variasikan tipenya - JANGAN semua ide
  dikasih type yang sama (misal semua "Carousel"), karena itu numpuk beban
  ke 1 role produksi doang. Sebutkan juga formatnya di title/brief biar
  jelas, dan usahakan sebarannya proporsional lintas type
- field "platform" di tiap content_ideas WAJIB diisi salah satu dari
  daftar ini, PERSIS namanya: {$platformOptions} - pilih platform yang
  paling relevan buat ide itu berdasarkan performance_by_platform di data
PROMPT;
    }

    /**
     * Daftar nama ContentType yang beneran ada di sistem, buat dikasih ke
     * Gemini sebagai pilihan valid field "type" pada content_ideas - biar
     * pas di-apply ke Content Plan (applyAiStrategy()), tiap draft bisa
     * di-mapping ke content_type_id yang benar (termasuk kuota Design vs
     * Content per client_packages.monthly_design_quota).
     */
    private function contentTypeOptions(): string
    {
        $names = ContentType::pluck('name');

        return $names->isNotEmpty() ? $names->implode(', ') : 'Design, Video, Copywriting, Carousel';
    }

    /**
     * maxOutputTokens buat generate/refine - dulu fix 2048 (cukup buat
     * ~8-10 ide), sekarang jumlah ide ngikutin target_content_count yang
     * bisa jauh lebih banyak (kuota bulanan client), jadi budget token-nya
     * ikut discale biar nggak kepotong (finishReason: MAX_TOKENS) pas
     * client-nya punya kuota besar.
     */
    private function outputTokenBudget(int $targetItemCount): int
    {
        return min(8192, 1024 + ($targetItemCount * 140));
    }

    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        // Gemini sering bungkus JSON dalam ```json ... ``` walaupun udah
        // diminta jangan - kadang malah didahului teks pembuka kayak
        // "Berikut analisisnya:" sebelum blok fence-nya. Makanya dicari di
        // mana pun posisinya (bukan cuma di awal/akhir string).
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches)) {
            $text = $matches[1];
        }

        $decoded = json_decode($text, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Fallback terakhir: nggak ada fence sama sekali tapi masih ada
        // teks nyasar sebelum/sesudah objek JSON-nya - ambil dari '{'
        // pertama sampai '}' terakhir.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }
}