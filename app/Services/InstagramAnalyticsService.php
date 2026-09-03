<?php

namespace App\Services;

use App\Exceptions\InstagramApiException;
use App\Models\ApiIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Komunikasi ke Instagram API (Instagram Login - graph.instagram.com) untuk
 * 1 ApiIntegration (= 1 client yang sudah connect Instagram lewat OAuth,
 * lihat InstagramIntegrationController). Cuma tanggung jawab HTTP +
 * normalisasi data mentah API; simpan ke DB (content_metrics,
 * content_publications, dst) dilakukan oleh SyncInstagramAnalytics command,
 * bukan di sini - biar service ini bisa dites/dipakai ulang tanpa nyeret
 * Eloquent lebih jauh dari yang perlu.
 *
 * Token SELALU dari $integration->access_token (kolom terenkripsi di
 * api_integrations) - TIDAK ADA fallback ke config('services.instagram.access_token')
 * atau env('INSTAGRAM_ACCESS_TOKEN'). Multi-client berarti tiap client punya
 * ApiIntegration + token sendiri; token global di .env sudah dihapus dari
 * arsitektur (lihat instagram:migrate-env-token untuk migrasi satu-kali yang
 * dilakukan sebelum fallback ini dicabut).
 */
class InstagramAnalyticsService
{
    private const BASE_URL = 'https://graph.instagram.com';
    private const TIMEOUT = 20;

    // Batas aman jumlah halaman media yang diambil per sync - akun test
    // nggak butuh lebih dari ini, dan ini jalan synchronous di dalam 1
    // request/command jadi harus dibatasi biar nggak berpotensi timeout
    // atau muter tanpa henti kalau paging API kebetulan aneh.
    private const MAX_PAGES = 10;
    private const MEDIA_PER_PAGE = 25;

    // Metric per media_product_type - Instagram menolak SELURUH request
    // insights kalau ada 1 metric yang nggak didukung media itu, jadi kalau
    // daftar "preferred" gagal, dicoba ulang pakai daftar "safe" yang lebih
    // minim sebagai fallback (lihat fetchInsightsWithFallback()).
    private const PREFERRED_METRICS = [
        'IMAGE' => ['reach', 'likes', 'comments', 'shares', 'saved', 'total_interactions'],
        'CAROUSEL_ALBUM' => ['reach', 'likes', 'comments', 'shares', 'saved', 'total_interactions'],
        'FEED' => ['reach', 'likes', 'comments', 'shares', 'saved', 'total_interactions', 'views'],
        'REELS' => ['reach', 'likes', 'comments', 'shares', 'saved', 'total_interactions', 'views'],
        'VIDEO' => ['reach', 'likes', 'comments', 'shares', 'saved', 'total_interactions', 'views'],
    ];

    private const SAFE_METRICS = [
        'IMAGE' => ['reach', 'likes', 'comments'],
        'CAROUSEL_ALBUM' => ['reach', 'likes', 'comments'],
        'FEED' => ['reach', 'likes', 'comments', 'views'],
        'REELS' => ['reach', 'likes', 'comments', 'views'],
        'VIDEO' => ['reach', 'likes', 'comments', 'views'],
    ];

    // FINAL INSTAGRAM OPTIONAL INSIGHTS COMPLETENESS GATE - metric
    // OPSIONAL, DILUAR PREFERRED_METRICS/SAFE_METRICS SENGAJA (Part 4,
    // "core vs optional") - kegagalan metric-metric ini TIDAK PERNAH
    // memicu fallback SAFE_METRICS yang kehilangan shares/saved/
    // total_interactions yang SEBENARNYA valid. SEMUA diverifikasi
    // resmi ada per referensi Meta (developers.facebook.com/docs/
    // instagram-platform/reference/instagram-media/insights/, v25.0,
    // dicek 2026-09-03), scope instagram_business_manage_insights (SAMA
    // scope yang sudah dipakai app ini, TIDAK BUTUH scope baru).
    //
    // REELS ONLY (media_product_type=REELS) - SATU request batched
    // (Part 4, "do NOT make one HTTP request per optional metric"),
    // BUKAN 3 request terpisah:
    // - ig_reels_avg_watch_time: rata-rata waktu tonton (ms dari Meta).
    // - ig_reels_video_view_total_time: TOTAL waktu tonton agregat (ms) -
    //   metric TERPISAH dari rata-rata, kolom TERPISAH (Part 5, "jangan
    //   overload").
    // - reels_skip_rate: rasio skip (persentase).
    private const OPTIONAL_REELS_METRICS = ['ig_reels_avg_watch_time', 'ig_reels_video_view_total_time', 'reels_skip_rate'];

    // FEED ONLY (IMAGE/CAROUSEL_ALBUM, media_product_type FEED) - metric
    // atribusi profil dari post SPESIFIK ini (BUKAN account_type/follower
    // total akun, itu domain berbeda - ApiIntegration/AudienceInsight).
    // Ketiganya "FEED, STORY" per referensi Meta - app ini tidak pernah
    // sync Story, jadi cuma FEED yang relevan. SATU request batched,
    // TERPISAH dari batch REELS di atas (Meta men-scope metric per media
    // type, tidak bisa dicampur 1 request).
    private const OPTIONAL_FEED_METRICS = ['profile_visits', 'profile_activity', 'follows'];

    public function __construct(
        private readonly ApiIntegration $integration,
    ) {
    }

    /**
     * GET profile akun yang diotorisasi. Dipakai buat "Test Connection" dan
     * identitas yang disimpan ke api_integrations.external_username/
     * external_account_id/external_display_name/external_avatar_url.
     *
     * PASS 1B - name/profile_picture_url DITAMBAH (field resmi Graph API,
     * scope instagram_business_basic yang sudah dipakai app ini, tidak ada
     * biaya API tambahan - masih 1 panggilan /me yang sama).
     */
    public function getProfile(): array
    {
        $response = $this->get($this->url('me'), [
            'fields' => 'id,username,name,account_type,media_count,profile_picture_url',
        ]);

        if ($response->failed()) {
            $this->throwApiError($response, 'Gagal mengambil profil Instagram');
        }

        return $response->json();
    }

    /**
     * Ambil media akun (dengan pagination), field paling dasar dulu -
     * insights-nya diambil terpisah per media di getMediaInsights().
     *
     * $since/$until dikirim sebagai parameter `since`/`until` (unix
     * timestamp) - DIBUKTIKAN LANGSUNG ke API asli (bukan asumsi dari
     * dokumentasi) bahwa Instagram Graph API filter ini di SISI SERVER,
     * jadi halaman yang balik sudah otomatis dalam rentang, tidak perlu
     * client-side early-stop dengan inspeksi timestamp per halaman.
     * MAX_PAGES tetap dipertahankan sebagai pengaman defense-in-depth
     * (mis. kalau since/until entah kenapa diabaikan API).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMedia(?Carbon $since = null, ?Carbon $until = null): array
    {
        $media = [];
        $url = $this->url('me/media');
        $params = [
            // PASS 1B - shortcode DITAMBAH (field resmi Graph API, scope
            // instagram_business_basic yang sama, kode pendek permalink
            // yang stabil permanen - tidak ada biaya tambahan, 1 request
            // media list yang sama).
            'fields' => 'id,caption,media_type,media_product_type,permalink,timestamp,username,media_url,thumbnail_url,shortcode',
            'limit' => self::MEDIA_PER_PAGE,
        ];

        if ($since) {
            $params['since'] = $since->timestamp;
        }
        if ($until) {
            $params['until'] = $until->timestamp;
        }

        $page = 0;
        while ($url && $page < self::MAX_PAGES) {
            $page++;

            $response = $params !== null
                ? $this->get($url, $params)
                : $this->get($url); // halaman berikutnya: paging.next sudah bawa query string sendiri (termasuk since/until)

            if ($response->failed()) {
                $this->throwApiError($response, 'Gagal mengambil daftar media Instagram');
            }

            $body = $response->json();
            foreach (($body['data'] ?? []) as $item) {
                $media[] = $item;
            }

            $url = $body['paging']['next'] ?? null;
            $params = null; // paging.next udah full URL + access_token + cursor + since/until, jangan dobel di-append
        }

        return $media;
    }

    /**
     * Insights 1 media, defensive per Langkah 6: kalau metric preferred
     * ditolak API (media type nggak support salah satu), coba ulang pakai
     * daftar metric yang lebih aman. Kalau tetap gagal, balikin semua
     * metric null + pesan error - JANGAN throw, biar media lain di sync
     * tetap lanjut diproses (Test 5).
     *
     * Audit sync horizon (Langkah 6) - $category dipisah dari $error
     * (string manusia) supaya caller (refreshKnownMedia()) bisa BERTINDAK
     * beda per kategori, bukan cuma logging: 'authentication' (token
     * invalid/expired - integration butuh reconnect, BUKAN "content tidak
     * tersedia"), 'content_unavailable' (media dihapus/insight memang
     * tidak ada), 'unsupported_metric' (STORY - permanen, tidak akan
     * pernah berhasil ditanya ulang), 'transient_api_error' (network/5xx/
     * rate limit - coba lagi rotasi berikutnya). null = sukses.
     *
     * @return array{metrics: array<string, int|float|null>, error: string|null, category: string|null}
     */
    public function getMediaInsights(string $mediaId, ?string $mediaProductType): array
    {
        // Story cuma tayang ~24 jam dan nggak punya field like/comment/share
        // lewat endpoint ini - di luar cakupan pipeline content_metrics
        // sekarang (konten yang dijadwalkan lewat Content Plan/Item, bukan
        // story ephemeral), jadi sengaja dilewatin insight-nya.
        if ($mediaProductType === 'STORY') {
            return ['metrics' => $this->emptyMetrics(), 'error' => 'Media type STORY tidak didukung untuk insights di tahap ini', 'category' => 'unsupported_metric'];
        }

        $type = $mediaProductType ?? 'FEED';
        $preferred = self::PREFERRED_METRICS[$type] ?? self::PREFERRED_METRICS['FEED'];
        $safe = self::SAFE_METRICS[$type] ?? self::SAFE_METRICS['FEED'];

        try {
            $result = $this->fetchInsightsWithFallback($mediaId, $preferred, $safe);
        } catch (InstagramApiException $e) {
            // HANYA authentication yang throw sampai sini (lihat
            // fetchInsightsWithFallback) - percuma coba fallback metric set
            // kalau tokennya sendiri yang rusak, gagal identik. Kategori
            // lain (network/5xx/dst dari $this->get()) TETAP di-swallow +
            // coba fallback di dalam fetchInsightsWithFallback, TIDAK
            // pernah sampai sini.
            return ['metrics' => $this->emptyMetrics(), 'error' => $e->getMessage(), 'category' => InstagramApiException::AUTHENTICATION];
        }

        // PASS 1B (Langkah "VERIFY ITEM-LEVEL TRANSIENT RETRY") - kategori
        // rate_limited/transient_api_error BUKAN kegagalan final sampai
        // 1x bounded retry gagal juga (job-level $tries TIDAK PERNAH
        // menjangkau kegagalan per-media yang ke-catch lokal di caller,
        // lihat InstagramAnalyticsSyncService::saveMetricSafely()/
        // refreshKnownMedia() - method ini yang jadi satu-satunya titik
        // retry genuine buat 1 media). content_unavailable/unsupported_metric
        // (definitif) DAN authentication (sudah throw di atas) TIDAK
        // pernah masuk sini - retry buta buat itu cuma buang API call
        // buat hasil yang pasti identik.
        if (in_array($result['category'], ['rate_limited', 'transient_api_error'], true)) {
            if ($result['category'] === 'rate_limited') {
                usleep(1_500_000); // provider-aware backoff pendek sebelum retry
            }

            Log::info('Instagram insights: retry bounded 1x setelah kegagalan transient', [
                'media_id' => $mediaId,
                'category' => $result['category'],
            ]);

            try {
                $result = $this->fetchInsightsWithFallback($mediaId, $preferred, $safe);
            } catch (InstagramApiException $e) {
                return ['metrics' => $this->emptyMetrics(), 'error' => $e->getMessage(), 'category' => InstagramApiException::AUTHENTICATION];
            }
        }

        if ($result['data'] === null) {
            return ['metrics' => $this->emptyMetrics(), 'error' => 'Insights tidak tersedia untuk media ini (mungkin sudah dihapus atau metric tidak didukung)', 'category' => $result['category']];
        }

        $metrics = $this->normalizeMetrics($result['data']);

        // FINAL INSTAGRAM OPTIONAL INSIGHTS COMPLETENESS GATE - metric
        // opsional, request TERPISAH (SATU batch per media type - Part 4,
        // "do NOT make one HTTP request per optional metric") SETELAH
        // core metrics di atas SUDAH berhasil - kegagalan di sini TIDAK
        // PERNAH mengubah $error/$category return value (masih null/null,
        // core metrics tetap dilaporkan sukses apa adanya).
        if ($mediaProductType === 'REELS') {
            $metrics = [...$metrics, ...$this->getOptionalReelsMetrics($mediaId)];
        } elseif (in_array($mediaProductType, ['FEED', 'IMAGE', 'CAROUSEL_ALBUM'], true) || $mediaProductType === null) {
            $metrics = [...$metrics, ...$this->getOptionalFeedMetrics($mediaId)];
        }

        return ['metrics' => $metrics, 'error' => null, 'category' => null];
    }

    /**
     * SATU request batched buat ketiga metric opsional REELS
     * (OPTIONAL_REELS_METRICS) - BUKAN 3 request terpisah (Part 4).
     * ig_reels_avg_watch_time & ig_reels_video_view_total_time balik
     * dalam MILIDETIK dari Meta - dikonversi ke DETIK (avg_watch_time
     * satu semantik persis sama dengan kolom content_metrics.
     * watch_time_avg yang sudah dipakai TikTok, Part 7 "jangan overload
     * kolom dengan makna beda" - ini makna yang SAMA, cuma provider
     * beda, bukan overload; watch_time_total kolom TERPISAH, metric
     * TERPISAH - total agregat, BUKAN rata-rata). reels_skip_rate
     * sudah dalam bentuk rate/persentase, disimpan apa adanya (CURRENT_RATE,
     * TIDAK PERNAH di-delta - lihat docblock InstagramAnalyticsSyncService::
     * saveMetric()).
     *
     * Kegagalan APAPUN (metric belum tersedia buat media ini, rate limit,
     * dst) -> SEMUA null, TIDAK PERNAH exception yang bocor ke caller,
     * TIDAK PERNAH mempengaruhi core metrics.
     *
     * @return array{watch_time_avg: ?int, watch_time_total: ?int, skip_rate: ?float}
     */
    private function getOptionalReelsMetrics(string $mediaId): array
    {
        $empty = ['watch_time_avg' => null, 'watch_time_total' => null, 'skip_rate' => null];

        try {
            $response = $this->get($this->url("{$mediaId}/insights"), [
                'metric' => implode(',', self::OPTIONAL_REELS_METRICS),
            ]);

            if (! $response->successful()) {
                return $empty;
            }

            $flat = $this->flattenInsightValues($response->json('data') ?? []);
            $avgMs = $flat['ig_reels_avg_watch_time'] ?? null;
            $totalMs = $flat['ig_reels_video_view_total_time'] ?? null;
            $skipRate = $flat['reels_skip_rate'] ?? null;

            return [
                'watch_time_avg' => $avgMs !== null ? (int) round(((float) $avgMs) / 1000) : null,
                'watch_time_total' => $totalMs !== null ? (int) round(((float) $totalMs) / 1000) : null,
                'skip_rate' => $skipRate !== null ? (float) $skipRate : null,
            ];
        } catch (\Throwable $e) {
            Log::info('Instagram: metric opsional Reels tidak tersedia (diabaikan, core metrics tidak terpengaruh)', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }

    /**
     * SATU request batched buat ketiga metric opsional FEED
     * (OPTIONAL_FEED_METRICS) - atribusi profil dari POST SPESIFIK ini
     * (BUKAN total akun - itu domain AudienceInsight/ApiIntegration,
     * TIDAK disentuh di sini).
     *
     * LIVE VERIFICATION NOTE (dicek langsung terhadap integration real,
     * 2026-09-04) - asumsi awal (profile_activity balik sebagai
     * breakdown, sama pola dengan demografis di
     * InstagramAudienceInsightsService) TERBUKTI SALAH begitu dicek
     * live: response NYATA-nya SAMA PERSIS bentuknya dengan
     * profile_visits/follows - angka tunggal di `values[0].value`, BUKAN
     * `total_value.breakdowns`. Kode DIPERBAIKI mengikuti bukti live ini
     * (bukan asumsi dokumentasi yang tidak terverifikasi) - pakai
     * flattenInsightValues() yang sama, TIDAK ADA parsing breakdown
     * khusus lagi.
     *
     * Kegagalan APAPUN -> SEMUA null, TIDAK PERNAH mempengaruhi core
     * metrics. 0 genuine (SUDAH terbukti live: follows/profile_activity
     * bisa balik 0 asli) TETAP dibedakan dari null (metric tidak ada di
     * response sama sekali).
     *
     * @return array{profile_visits: ?int, profile_activity: ?int, attributed_follows: ?int}
     */
    private function getOptionalFeedMetrics(string $mediaId): array
    {
        $empty = ['profile_visits' => null, 'profile_activity' => null, 'attributed_follows' => null];

        try {
            $response = $this->get($this->url("{$mediaId}/insights"), [
                'metric' => implode(',', self::OPTIONAL_FEED_METRICS),
            ]);

            if (! $response->successful()) {
                return $empty;
            }

            $flat = $this->flattenInsightValues($response->json('data') ?? []);

            return [
                'profile_visits' => isset($flat['profile_visits']) ? (int) $flat['profile_visits'] : null,
                'profile_activity' => isset($flat['profile_activity']) ? (int) $flat['profile_activity'] : null,
                'attributed_follows' => isset($flat['follows']) ? (int) $flat['follows'] : null,
            ];
        } catch (\Throwable $e) {
            Log::info('Instagram: metric opsional FEED tidak tersedia (diabaikan, core metrics tidak terpengaruh)', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }

    /**
     * @return array{data: ?array, category: ?string}
     */
    private function fetchInsightsWithFallback(string $mediaId, array $preferred, array $safe): array
    {
        $lastCategory = 'content_unavailable';

        foreach ([$preferred, $safe] as $metricSet) {
            try {
                $response = $this->get($this->url("{$mediaId}/insights"), [
                    'metric' => implode(',', $metricSet),
                ]);

                if ($response->successful()) {
                    return ['data' => $this->flattenInsightValues($response->json('data') ?? []), 'category' => null];
                }

                // Auth invalid - SATU-SATUNYA kategori yang throw di sini
                // (bukan swallow+fallback seperti kategori lain), reuse
                // signal PERSIS sama dengan throwApiError() (401/code 190) -
                // jangan buat deteksi kedua yang bisa drift.
                $code = $response->json('error.code');
                if ($response->status() === 401 || $code === 190) {
                    $message = $response->json('error.message') ?? "HTTP {$response->status()}";
                    throw new InstagramApiException(
                        "Token Instagram tidak valid atau kadaluarsa. ({$message})",
                        InstagramApiException::AUTHENTICATION
                    );
                }

                // PASS 1B - rate_limited (429) DIPISAH dari transient_api_error
                // (5xx) biar getMediaInsights() bisa terapkan backoff HANYA
                // buat rate limit (retry instan pas 5xx blip masuk akal,
                // retry instan pas rate-limited PERCUMA - window-nya belum
                // lewat).
                if ($response->status() === 429) {
                    $lastCategory = 'rate_limited';
                } elseif ($response->status() >= 500) {
                    $lastCategory = 'transient_api_error';
                }

                Log::warning('Instagram insights gagal, coba fallback metric set', [
                    'media_id' => $mediaId,
                    'metric_set' => $metricSet,
                    'status' => $response->status(),
                    'error' => $response->json('error.message'),
                ]);
            } catch (InstagramApiException $e) {
                if ($e->category === InstagramApiException::AUTHENTICATION) {
                    throw $e; // propagate - jangan ditelan, caller butuh tahu ini auth
                }
                // NETWORK/SERVER_ERROR/RATE_LIMIT dari $this->get() sendiri -
                // transient, tetap swallow+fallback seperti semula.
                $lastCategory = $e->category === InstagramApiException::RATE_LIMIT ? 'rate_limited' : 'transient_api_error';
                Log::warning('Instagram insights exception, coba fallback metric set', [
                    'media_id' => $mediaId,
                    'metric_set' => $metricSet,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $lastCategory = 'transient_api_error';
                Log::warning('Instagram insights exception, coba fallback metric set', [
                    'media_id' => $mediaId,
                    'metric_set' => $metricSet,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['data' => null, 'category' => $lastCategory];
    }

    /**
     * Response /insights bentuknya array of {name, values: [{value}]} -
     * diratakan jadi ['reach' => 123, 'likes' => 45, ...] biar gampang
     * dipetakan ke kolom content_metrics.
     */
    private function flattenInsightValues(array $data): array
    {
        $flat = [];
        foreach ($data as $metric) {
            $name = $metric['name'] ?? null;
            if (! $name) {
                continue;
            }
            $flat[$name] = $metric['values'][0]['value'] ?? $metric['total_value']['value'] ?? null;
        }

        return $flat;
    }

    /**
     * Mapping Instagram API -> kolom content_metrics (Langkah 7). Metric
     * yang nggak ada di response API SENGAJA null, bukan 0 - beda makna
     * antara "nggak didukung/nggak kebaca" vs "kebaca dan nilainya nol".
     */
    private function normalizeMetrics(array $raw): array
    {
        return [
            'views' => $raw['views'] ?? $raw['plays'] ?? null,
            'reach' => $raw['reach'] ?? null,
            'impressions' => $raw['impressions'] ?? null,
            'likes' => $raw['likes'] ?? null,
            'comments' => $raw['comments'] ?? null,
            'shares' => $raw['shares'] ?? null,
            'saves' => $raw['saved'] ?? null,
            'total_interactions' => $raw['total_interactions'] ?? null,
        ];
    }

    private function emptyMetrics(): array
    {
        return [
            'views' => null, 'reach' => null, 'impressions' => null,
            'likes' => null, 'comments' => null, 'shares' => null,
            'saves' => null, 'total_interactions' => null,
        ];
    }

    /**
     * Orkestrasi profile -> media -> insights per media. Murni pengambilan
     * & normalisasi data, TIDAK menyentuh database sama sekali - persistensi
     * (matching content_item, upsert content_metrics, dst) ada di
     * SyncInstagramAnalytics::handle().
     *
     * $since/$until diteruskan ke getMedia() - null berarti semua media
     * dalam batas MAX_PAGES (dipakai historical sync yang sudah tahu
     * rentang exact-nya lewat since/until juga, bukan berarti "tanpa batas").
     *
     * @return array{profile: array, media: array<int, array>}
     */
    public function sync(?Carbon $since = null, ?Carbon $until = null): array
    {
        $profile = $this->getProfile();
        $mediaList = $this->getMedia($since, $until);

        $results = [];
        foreach ($mediaList as $item) {
            $insight = $this->getMediaInsights($item['id'], $item['media_product_type'] ?? null);

            $results[] = [
                'id' => $item['id'],
                'caption' => $item['caption'] ?? null,
                'media_type' => $item['media_type'] ?? null,
                'media_product_type' => $item['media_product_type'] ?? null,
                'permalink' => $item['permalink'] ?? null,
                'timestamp' => $item['timestamp'] ?? null,
                // thumbnail_url cuma ada di media VIDEO - fallback ke
                // media_url (dipakai IMAGE/CAROUSEL sebagai preview-nya
                // sendiri). Dua-duanya CDN URL bertanda-tangan Meta yang
                // bisa kadaluarsa - dicache apa adanya, refresh otomatis
                // tiap media ini muncul lagi di sync berikutnya.
                'thumbnail_url' => $item['thumbnail_url'] ?? $item['media_url'] ?? null,
                // PASS 1B - shortcode stabil permanen (beda dari thumbnail_url
                // di atas yang TTL-nya terbatas).
                'shortcode' => $item['shortcode'] ?? null,
                'metrics' => $insight['metrics'],
                'insights_error' => $insight['error'],
            ];
        }

        return ['profile' => $profile, 'media' => $results];
    }

    /**
     * Token cuma dari $integration->access_token (encrypted cast di model
     * ApiIntegration, decrypt otomatis pas dibaca) - jangan pernah ikut
     * ke-log, baik di sini maupun di throwApiError()/Log::warning() lain
     * di file ini yang cuma nge-log status/error body, bukan request-nya.
     */
    private function client()
    {
        $token = $this->integration->access_token;

        if (! filled($token)) {
            throw new InstagramApiException('Instagram integration has no access token.', InstagramApiException::AUTHENTICATION);
        }

        return Http::timeout(self::TIMEOUT)->withToken($token);
    }

    /**
     * Wrapper GET terpusat - satu-satunya titik yang benar-benar ngirim
     * request HTTP ke Instagram, biar penanganan ConnectionException
     * (timeout/network, RETRYABLE) konsisten di semua caller tanpa
     * duplikasi try/catch. x-app-usage (rate-limit usage header nyata dari
     * Meta, dibuktikan lewat tes langsung ke API - bukan asumsi) ikut
     * di-log tiap request buat visibilitas, bukan dipakai sebagai counter
     * proaktif (belum ada data kalibrasi threshold-nya).
     */
    private function get(string $url, array $params = []): Response
    {
        try {
            $response = empty($params) ? $this->client()->get($url) : $this->client()->get($url, $params);
        } catch (ConnectionException $e) {
            Log::error('Instagram API network/timeout error', ['url' => $url, 'error' => $e->getMessage()]);

            throw new InstagramApiException(
                'Tidak bisa menghubungi Instagram API (timeout/jaringan). Coba lagi beberapa saat lagi.',
                InstagramApiException::NETWORK,
                $e
            );
        }

        if ($usage = $response->header('x-app-usage')) {
            Log::info('Instagram API usage', ['x-app-usage' => $usage, 'url' => $url]);
        }

        return $response;
    }

    private function url(string $path): string
    {
        $version = config('services.instagram.api_version');

        return $version
            ? self::BASE_URL."/{$version}/{$path}"
            : self::BASE_URL."/{$path}";
    }

    /**
     * Lempar InstagramApiException dengan kategori yang benar (dipakai Job
     * buat memutuskan retryable atau nggak) dan pesan aman ditampilkan ke
     * user (nggak ada token/credential) - detail lengkap tetap di-log.
     * Kategori HANYA dari status/code yang benar-benar dikembalikan API,
     * tidak ada kode error Instagram yang dikarang.
     */
    private function throwApiError(Response $response, string $context): never
    {
        $errorBody = $response->json('error');
        $status = $response->status();

        Log::error($context, [
            'status' => $status,
            'error' => $errorBody,
            'x-app-usage' => $response->header('x-app-usage'),
        ]);

        $type = $errorBody['type'] ?? null;
        $code = $errorBody['code'] ?? null;
        $message = $errorBody['message'] ?? "HTTP {$status}";

        if ($status === 401 || $code === 190) {
            throw new InstagramApiException(
                "Token Instagram tidak valid atau kadaluarsa. Hubungkan ulang akun di Client Detail. ({$message})",
                InstagramApiException::AUTHENTICATION
            );
        }

        if ($status === 429) {
            throw new InstagramApiException(
                "Rate limit Instagram API tercapai, coba lagi beberapa saat lagi. ({$message})",
                InstagramApiException::RATE_LIMIT
            );
        }

        if ($status >= 500) {
            throw new InstagramApiException(
                "Instagram API sedang bermasalah di sisi mereka (HTTP {$status}). Coba lagi beberapa saat lagi.",
                InstagramApiException::SERVER_ERROR
            );
        }

        throw new InstagramApiException(
            "{$context}: {$message}".($type ? " [{$type}]" : ''),
            InstagramApiException::UNKNOWN
        );
    }
}
