<?php

namespace App\Services;

use App\Exceptions\InstagramApiException;
use App\Models\ApiIntegration;
use App\Models\AnalyticsSyncLog;
use App\Models\AudienceInsight;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Account-level Audience Insights (bukan post-level - itu tetap tanggung
 * jawab InstagramAnalyticsService/content_metrics, TIDAK disentuh di sini).
 * Dibuat file terpisah (bukan nambah method ke InstagramAnalyticsService)
 * biar HTTP client-nya sendiri, nggak menyeret risiko ke pipeline Content
 * Analytics yang sudah stabil - kelas ini murni baca-baca, tulis ke
 * audience_insights, sync() dipanggil dari SyncInstagramAudienceJob.
 *
 * Semua angka di sini DIBUKTIKAN lewat panggilan API nyata ke 3 akun test
 * (523 Studio/Metro/TSA) sebelum coding - lihat audit "Instagram Audience
 * Insights". Ringkasan temuan yang bentuk kode ini:
 * - follower_count di /me = TOTAL followers saat ini (bukan delta harian
 *   dari metric insights follower_count time_series - itu SENGAJA tidak
 *   dipakai, lihat getCurrentFollowerCount()).
 * - reach (account, time_series) & online_followers (lifetime, array 24
 *   jam) tersedia & terbukti historis s/d 180 hari ke belakang.
 * - follower_demographics terbukti konsisten tersedia di semua akun test.
 *   reached_audience_demographics & engaged_audience_demographics TIDAK
 *   dijamin selalu tersedia - re-verifikasi live (2026-08-31, akun Metro/
 *   @metrosoftware, 758 followers) balikin HTTP 200 + results KOSONG untuk
 *   reached di kedua timeframe yang didukung (this_month & this_week), dan
 *   untuk engaged: this_month juga 200+kosong, this_week eksplisit error
 *   code 3006 "Not enough users". Baik "200 + kosong" maupun "3006" adalah
 *   kondisi VALID (Meta memang tidak punya/tidak mengizinkan breakdown ini
 *   untuk akun tersebut saat itu), BUKAN bug - keduanya di-skip diam-diam
 *   jadi "unavailable", tidak pernah ditebak jadi 0 (lihat
 *   fetchDemographicBreakdown() & sync()).
 * - Action-metrics (profile_views/likes/comments/shares/saves/replies/
 *   website_clicks/accounts_engaged/dst) balikin HTTP 200 tapi data: []
 *   kosong di KETIGA akun real, konsisten di semua ukuran akun - diduga
 *   keterbatasan produk Instagram Login API (graph.instagram.com) untuk
 *   metric ini, BUKAN diimplementasikan sampai ada bukti lain.
 * - Demografi TIDAK punya versi historis (metric_type=total_value tidak
 *   menerima since/until; parameter timeframe pada follower_demographics
 *   terbukti tidak mengubah hasil - itu snapshot "sekarang", bukan
 *   windowed) - makanya sync() cuma simpan snapshot HARI INI, tidak ada
 *   month-picker/historical demographics.
 */
class InstagramAudienceInsightsService
{
    private const BASE_URL = 'https://graph.instagram.com';
    private const TIMEOUT = 20;

    // Dipakai reached/engaged demographics (representasi "audience yang
    // reach/engaged DALAM window ini") - dibuktikan lewat test langsung
    // bahwa nilai timeframe TIDAK mengubah follower_demographics (snapshot
    // current, bukan windowed), tapi tetap wajib dikirim buat reached/
    // engaged. Nilai lama (last_30_days/last_14_days/last_90_days) sudah
    // TIDAK didukung Instagram API - diganti this_month/this_week (bulan
    // dicoba dulu karena window lebih lebar = lebih besar peluang lolos
    // threshold minimum audience). Live-verified 2026-08-31 (akun Metro):
    // reached kosong (200) di kedua nilai, engaged kosong (200) di
    // this_month & eksplisit 3006 "Not enough users" di this_week - lihat
    // docblock class ini.
    private const DEMOGRAPHICS_TIMEFRAME_FALLBACK = ['this_month', 'this_week'];

    private const BREAKDOWNS = ['gender', 'age', 'country', 'city'];

    // Batas retensi historis reach yang SUDAH DIBUKTIKAN lewat test nyata
    // (180 hari dites eksplisit, sukses) - dipakai backfillReachHistory(),
    // bukan angka dari dokumentasi.
    public const REACH_BACKFILL_DAYS = 180;

    public function __construct(
        private readonly ApiIntegration $integration,
    ) {
    }

    /**
     * TOTAL followers akun saat ini - field langsung di /me, BUKAN lewat
     * /insights metric follower_count (itu DELTA harian net gain/loss,
     * beda makna, sengaja tidak dipakai untuk kolom follower_count).
     */
    public function getCurrentFollowerCount(): ?int
    {
        $response = $this->get($this->url('me'), ['fields' => 'followers_count']);

        if (! $response || $response->failed()) {
            if ($response) $this->logSoftFailure('followers_count', $response);
            return null;
        }

        return $response->json('followers_count');
    }

    /**
     * Reach account-level HARI INI - dipakai daily sync (bukan backfill).
     * Ambil 2 hari terakhir dan pakai value paling akhir yang datanya
     * benar-benar ada (hari ini kadang belum lengkap/kosong di tengah hari).
     */
    public function getTodayReach(): ?int
    {
        $history = $this->getReachHistory(Carbon::today()->subDay(), Carbon::today());

        if (empty($history)) {
            return null;
        }

        return end($history);
    }

    /**
     * Time-series reach harian, dipakai daily sync (window pendek) MAUPUN
     * one-time initial backfill (window s/d 180 hari, lihat
     * backfillReachHistory()) - endpoint & bentuk respons sama, cuma
     * since/until beda.
     *
     * @return array<string, int> tanggal (Y-m-d) => reach
     */
    public function getReachHistory(Carbon $since, Carbon $until): array
    {
        $response = $this->get($this->url("{$this->igUserId()}/insights"), [
            'metric' => 'reach',
            'period' => 'day',
            'metric_type' => 'time_series',
            'since' => $since->startOfDay()->timestamp,
            'until' => $until->endOfDay()->timestamp,
        ]);

        if (! $response || $response->failed()) {
            if ($response) $this->logSoftFailure('reach', $response);
            return [];
        }

        $values = $response->json('data.0.values') ?? [];
        $byDate = [];
        foreach ($values as $point) {
            if (! isset($point['end_time'], $point['value'])) {
                continue;
            }
            // end_time = akhir HARI yang diwakili (bukan hari berikutnya) -
            // dibuktikan lewat test: since=X hari lalu s/d sekarang balikin
            // persis X titik, end_time paling lama = tanggal since itu sendiri.
            $byDate[Carbon::parse($point['end_time'])->subDay()->toDateString()] = (int) $point['value'];
        }

        return $byDate;
    }

    /**
     * Sebaran follower online per jam (0-23) - metric online_followers,
     * period=lifetime, balikin array of {value: [24 angka], end_time}.
     * Kadang entry PALING BARU kosong (hari belum selesai) - ambil entry
     * TERAKHIR yang array value-nya beneran terisi.
     *
     * @return array<int, int>|null [0 => n, 1 => n, ..., 23 => n]
     */
    public function getOnlineFollowers(): ?array
    {
        $response = $this->get($this->url("{$this->igUserId()}/insights"), [
            'metric' => 'online_followers',
            'period' => 'lifetime',
        ]);

        if (! $response || $response->failed()) {
            if ($response) $this->logSoftFailure('online_followers', $response);
            return null;
        }

        $points = $response->json('data.0.values') ?? [];
        for ($i = count($points) - 1; $i >= 0; $i--) {
            $hours = $points[$i]['value'] ?? null;
            if (is_array($hours) && count($hours) === 24) {
                return array_combine(range(0, 23), array_map('intval', $hours));
            }
        }

        return null;
    }

    /**
     * @return array{gender_breakdown: ?array, age_breakdown: ?array, top_locations: ?array, top_countries: ?array}
     */
    public function getFollowerDemographics(): array
    {
        return $this->fetchAllBreakdowns('follower_demographics', withTimeframe: false);
    }

    /**
     * Bisa balikin semua null kalau Meta belum meng-compute breakdown ini
     * untuk akun/window tersebut (HTTP 200, results kosong, tanpa error
     * code) - TERBUKTI nyata di akun Metro (live-verified 2026-08-31) di
     * this_month MAUPUN this_week, jadi TIDAK boleh dianggap "selalu ada".
     *
     * @return array{gender_breakdown: ?array, age_breakdown: ?array, top_locations: ?array, top_countries: ?array}
     */
    public function getReachedDemographics(): array
    {
        return $this->fetchAllBreakdowns('reached_audience_demographics', withTimeframe: true);
    }

    /**
     * Bisa balikin semua null kalau kena threshold "Not enough users"
     * (error code 3006, TERBUKTI nyata di 523 Studio & Metro) - itu
     * kondisi valid, BUKAN error yang menggagalkan sync keseluruhan.
     *
     * @return array{gender_breakdown: ?array, age_breakdown: ?array, top_locations: ?array, top_countries: ?array}
     */
    public function getEngagedDemographics(): array
    {
        return $this->fetchAllBreakdowns('engaged_audience_demographics', withTimeframe: true);
    }

    /**
     * Orkestrasi harian: ambil semua metric (independen, 1 gagal nggak
     * menggagalkan yang lain - Langkah 13/17), upsert ke audience_insights.
     * demographic_type=summary SELALU diupsert (biar "Last Audience Sync"
     * punya jejak walau semua metric kebetulan unavailable hari itu).
     * demographic_type=follower/reached/engaged CUMA diupsert kalau
     * minimal 1 breakdown-nya berhasil - row kosong total tidak dibuat
     * sama sekali (Langkah 8: "jangan create fake breakdown").
     *
     * @return array{summary_saved: bool, demographics_saved: array<int, string>, demographics_unavailable: array<int, string>}
     */
    public function sync(AnalyticsSyncLog $syncLog): array
    {
        $today = Carbon::today()->toDateString();
        $result = ['summary_saved' => false, 'demographics_saved' => [], 'demographics_unavailable' => []];

        $followerCount = $this->safeCall(fn () => $this->getCurrentFollowerCount());
        $reach = $this->safeCall(fn () => $this->getTodayReach());
        $activeHours = $this->safeCall(fn () => $this->getOnlineFollowers());

        AudienceInsight::updateOrCreate(
            [
                'client_id' => $this->integration->client_id,
                'platform_id' => $this->integration->platform_id,
                'snapshot_date' => $today,
                'source' => AudienceInsight::SOURCE_API,
                'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            ],
            array_filter([
                'follower_count' => $followerCount,
                'reach' => $reach,
                'active_hours' => $activeHours,
            ], fn ($v) => $v !== null)
        );
        $result['summary_saved'] = true;

        $demographicFetchers = [
            AudienceInsight::TYPE_FOLLOWER => fn () => $this->getFollowerDemographics(),
            AudienceInsight::TYPE_REACHED => fn () => $this->getReachedDemographics(),
            AudienceInsight::TYPE_ENGAGED => fn () => $this->getEngagedDemographics(),
        ];

        foreach ($demographicFetchers as $type => $fetcher) {
            $breakdowns = $this->safeCall($fetcher) ?? [];
            $hasAnyData = collect($breakdowns)->filter(fn ($v) => $v !== null)->isNotEmpty();

            if (! $hasAnyData) {
                $result['demographics_unavailable'][] = $type;
                continue;
            }

            AudienceInsight::updateOrCreate(
                [
                    'client_id' => $this->integration->client_id,
                    'platform_id' => $this->integration->platform_id,
                    'snapshot_date' => $today,
                    'source' => AudienceInsight::SOURCE_API,
                    'demographic_type' => $type,
                ],
                array_filter($breakdowns, fn ($v) => $v !== null)
            );
            $result['demographics_saved'][] = $type;
        }

        $syncLog->update([
            'status' => 'success',
            'synced_count' => 1 + count($result['demographics_saved']),
            'skipped_count' => count($result['demographics_unavailable']),
        ]);

        return $result;
    }

    /**
     * Dipanggil job saat sync() gagal non-retryable - pola sama persis
     * dengan InstagramAnalyticsSyncService::markFailed() di pipeline
     * content: integration cuma ditandai 'inactive' (butuh reconnect
     * manual) kalau kategorinya BENAR-BENAR AUTHENTICATION (token rusak).
     * Kegagalan non-retryable LAIN (mis. UNKNOWN dari Throwable generik di
     * failed()) TIDAK berarti koneksinya rusak - status dibiarkan apa
     * adanya, cuma last_error yang diisi. Dibedakan supaya UI Client Detail
     * (Langkah 8, "Instagram connection needs attention" vs "Sinkronisasi
     * Audience terakhir gagal") bisa nunjukin pesan yang tepat, bukan
     * selalu minta reconnect padahal tokennya masih sah.
     */
    public function markFailed(AnalyticsSyncLog $syncLog, string $message, string $category = InstagramApiException::UNKNOWN): void
    {
        $syncLog->update([
            'status' => 'failed',
            'synced_count' => 0,
            'skipped_count' => 0,
            'error_message' => $message,
        ]);

        $updates = ['last_error' => $message];
        if ($category === InstagramApiException::AUTHENTICATION) {
            $updates['status'] = 'inactive';
        }

        $this->integration->update($updates);
    }

    /**
     * One-time backfill (dipanggil terpisah dari sync() harian, TIDAK
     * pernah otomatis di daily scheduler - lihat SyncInstagramAudienceJob)
     * saat integration baru pertama kali connect. Reach TERBUKTI py
     * history valid s/d 180 hari; follower total/demografi/active_hours
     * historis TIDAK dibackfill sama sekali (tidak ada representasi valid
     * dari API untuk itu - Langkah 14, "jangan mengarang historical totals").
     *
     * @return int jumlah hari yang berhasil disimpan
     */
    public function backfillReachHistory(): int
    {
        $since = Carbon::today()->subDays(self::REACH_BACKFILL_DAYS - 1);
        $until = Carbon::today();

        $history = $this->getReachHistory($since, $until);

        foreach ($history as $date => $reach) {
            AudienceInsight::updateOrCreate(
                [
                    'client_id' => $this->integration->client_id,
                    'platform_id' => $this->integration->platform_id,
                    'snapshot_date' => $date,
                    'source' => AudienceInsight::SOURCE_API,
                    'demographic_type' => AudienceInsight::TYPE_SUMMARY,
                ],
                ['reach' => $reach]
            );
        }

        return count($history);
    }

    /**
     * @return array{gender_breakdown: ?array, age_breakdown: ?array, top_locations: ?array, top_countries: ?array}
     */
    private function fetchAllBreakdowns(string $metric, bool $withTimeframe): array
    {
        return [
            'gender_breakdown' => $this->normalizeSimpleBreakdown($this->fetchDemographicBreakdown($metric, 'gender', $withTimeframe), $this->genderKeyMap()),
            'age_breakdown' => $this->normalizeSimpleBreakdown($this->fetchDemographicBreakdown($metric, 'age', $withTimeframe), []),
            'top_locations' => $this->normalizeLocationBreakdown($this->fetchDemographicBreakdown($metric, 'city', $withTimeframe), 'city'),
            'top_countries' => $this->normalizeLocationBreakdown($this->fetchDemographicBreakdown($metric, 'country', $withTimeframe), 'country'),
        ];
    }

    /**
     * 1 metric + 1 breakdown = 1 API call, independen dari breakdown lain
     * (Langkah 13: satu breakdown gagal tidak boleh menggagalkan yang
     * lain). "Not enough users" (code 3006, threshold engaged demographics
     * yang terbukti nyata) & error lain DIAM-DIAM balikin null, tidak throw.
     *
     * TERBUKTI lewat test langsung: 1 nilai timeframe TUNGGAL kadang balikin
     * status 200 tapi breakdown TANPA "results" sama sekali (bukan error,
     * bukan threshold - kemungkinan window itu belum ke-compute di sisi
     * Meta untuk akun ini) walau timeframe lain di hari yang SAMA berhasil.
     * Jadi coba beberapa timeframe berurutan, pola sama seperti fallback
     * preferred/safe metric di InstagramAnalyticsService (media insights).
     *
     * @return array<int, array{dimension_values: array<int, string>, value: int}>|null
     */
    private function fetchDemographicBreakdown(string $metric, string $breakdown, bool $withTimeframe): ?array
    {
        $timeframes = $withTimeframe ? self::DEMOGRAPHICS_TIMEFRAME_FALLBACK : [null];

        foreach ($timeframes as $timeframe) {
            $params = [
                'metric' => $metric,
                'period' => 'lifetime',
                'metric_type' => 'total_value',
                'breakdown' => $breakdown,
            ];
            if ($timeframe) {
                $params['timeframe'] = $timeframe;
            }

            $response = $this->get($this->url("{$this->igUserId()}/insights"), $params);

            if (! $response || $response->failed()) {
                if ($response) $this->logSoftFailure("{$metric}:{$breakdown}:{$timeframe}", $response);
                continue;
            }

            $results = $response->json('data.0.total_value.breakdowns.0.results');
            if (! empty($results)) {
                return $results;
            }
        }

        return null;
    }

    /**
     * Formula normalisasi count -> persentase (Langkah 7, DIDOKUMENTASIKAN
     * karena eksplisit diminta): persentase = value_dimensi / total_semua_
     * dimensi_di_breakdown_ini * 100, dibulatkan 2 desimal. Dimensi bernilai
     * 0 di-skip (bukan "unavailable", tapi API memang mengembalikan daftar
     * luas termasuk yang nol - percuma ditampilkan di UI persentase).
     * Kalau total = 0 (semua dimensi nol / breakdown kosong) -> null,
     * BUKAN array kosong/persentase 0 semua.
     */
    private function normalizeSimpleBreakdown(?array $results, array $keyMap): ?array
    {
        if (empty($results)) {
            return null;
        }

        $total = array_sum(array_map(fn ($r) => (int) ($r['value'] ?? 0), $results));
        if ($total <= 0) {
            return null;
        }

        $map = [];
        foreach ($results as $row) {
            $rawKey = $row['dimension_values'][0] ?? null;
            $value = (int) ($row['value'] ?? 0);
            if ($rawKey === null || $value <= 0) {
                continue;
            }
            $key = $keyMap[$rawKey] ?? $rawKey;
            $map[$key] = round($value / $total * 100, 2);
        }

        if (empty($map)) {
            return null;
        }

        arsort($map);
        return $map;
    }

    /**
     * Sama seperti normalizeSimpleBreakdown() tapi shape-nya array of
     * object (samain ke top_locations existing: [{"city": ..., "percentage": ...}])
     * bukan map asosiatif - country pakai kode ISO dari API, dikonversi ke
     * nama tampilan pakai intl (Locale::getDisplayRegion), fallback ke kode
     * mentah kalau intl nggak kenal.
     */
    private function normalizeLocationBreakdown(?array $results, string $label): ?array
    {
        if (empty($results)) {
            return null;
        }

        $total = array_sum(array_map(fn ($r) => (int) ($r['value'] ?? 0), $results));
        if ($total <= 0) {
            return null;
        }

        $rows = [];
        foreach ($results as $row) {
            $rawKey = $row['dimension_values'][0] ?? null;
            $value = (int) ($row['value'] ?? 0);
            if ($rawKey === null || $value <= 0) {
                continue;
            }

            $displayName = $label === 'country' ? $this->countryDisplayName($rawKey) : $rawKey;

            $rows[] = [$label => $displayName, 'percentage' => round($value / $total * 100, 2)];
        }

        if (empty($rows)) {
            return null;
        }

        usort($rows, fn ($a, $b) => $b['percentage'] <=> $a['percentage']);
        return array_slice($rows, 0, 10);
    }

    private function countryDisplayName(string $isoCode): string
    {
        $name = \Locale::getDisplayRegion("und-{$isoCode}", 'id');

        return $name && $name !== $isoCode ? $name : $isoCode;
    }

    /** API pakai kode M/F/U - schema/UI existing pakai male/female/other (lihat _audience-section.blade.php). */
    private function genderKeyMap(): array
    {
        return ['M' => 'male', 'F' => 'female', 'U' => 'other'];
    }

    /**
     * Bungkus 1 pemanggilan metric - AUTHENTICATION dibiarkan lempar ke
     * atas (job perlu tahu supaya integration ditandai inactive, sama
     * seperti alur content sync), kegagalan lain (metric ditolak/threshold/
     * network sesaat) diredam jadi null biar metric lain tetap lanjut.
     */
    private function safeCall(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (InstagramApiException $e) {
            if ($e->category === InstagramApiException::AUTHENTICATION) {
                throw $e;
            }
            Log::warning('Instagram audience metric gagal (non-auth), dilewati', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * `external_account_id` nullable di skema (diisi saat OAuth connect
     * selesai) - integration yang belum lengkap tersambung (mis. baris lama/
     * OAuth belum pernah selesai) TIDAK BOLEH bikin sync ini crash dengan
     * TypeError mentah. Dilempar sebagai InstagramApiException(AUTHENTICATION)
     * - kategori yang sudah ada, non-retryable, ditangani gagal terkontrol
     * (markFailed + job fail()) oleh pemanggil, bukan exception tak tertangani.
     */
    private function igUserId(): string
    {
        $accountId = $this->integration->external_account_id;

        if ($accountId === null) {
            throw new InstagramApiException(
                "Integration #{$this->integration->id} belum punya external_account_id - OAuth belum selesai tersambung.",
                InstagramApiException::AUTHENTICATION
            );
        }

        return $accountId;
    }

    private function logSoftFailure(string $metric, Response $response): void
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');

        // Code 3006 = "Not enough users" (threshold demografi engaged,
        // TERBUKTI nyata di test) - level info, bukan warning, karena ini
        // kondisi yang DIHARAPKAN terjadi untuk akun kecil, bukan kegagalan.
        $level = $code === 3006 ? 'info' : 'warning';

        Log::$level("Instagram audience metric '{$metric}' tidak tersedia", [
            'status' => $response->status(),
            'code' => $code,
            'message' => $message,
            'api_integration_id' => $this->integration->id,
        ]);
    }

    /**
     * Balikin null (BUKAN throw) buat network/timeout error - itu
     * dianggap "sementara tidak tersedia" untuk 1 metric ini saja, caller
     * (masing-masing getter) sudah menangani null sebagai kegagalan biasa
     * dan lanjut ke metric berikutnya. Auth (401/190) TETAP dilempar
     * sebagai InstagramApiException supaya safeCall() bisa membedakannya
     * dan menghentikan sync sepenuhnya (token rusak, semua metric pasti
     * gagal sama, percuma dicoba satu-satu).
     */
    private function get(string $url, array $params = []): ?Response
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->withToken($this->token())->get($url, $params);
        } catch (ConnectionException $e) {
            Log::warning('Instagram audience API network/timeout error', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }

        if ($usage = $response->header('x-app-usage')) {
            Log::info('Instagram audience API usage', ['x-app-usage' => $usage, 'url' => $url]);
        }

        if ($response->status() === 401 || $response->json('error.code') === 190) {
            throw new InstagramApiException(
                'Token Instagram tidak valid/kadaluarsa saat mengambil audience insight.',
                InstagramApiException::AUTHENTICATION
            );
        }

        return $response;
    }

    /**
     * Token SELALU dari $integration->access_token (encrypted cast,
     * decrypt otomatis) - TIDAK PERNAH diterima sebagai parameter raw
     * (Langkah 13), dan tidak pernah ikut ke log di manapun di file ini.
     */
    private function token(): string
    {
        $token = $this->integration->access_token;

        if (! filled($token)) {
            throw new InstagramApiException('Instagram integration has no access token.', InstagramApiException::AUTHENTICATION);
        }

        return $token;
    }

    private function url(string $path): string
    {
        $version = config('services.instagram.api_version');

        return $version ? self::BASE_URL."/{$version}/{$path}" : self::BASE_URL."/{$path}";
    }
}
