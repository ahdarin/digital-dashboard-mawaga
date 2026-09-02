<?php

namespace App\Services;

use App\Exceptions\TikTokApiException;
use App\Models\ApiIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Komunikasi ke TikTok API v2 (Display API, open.tiktokapis.com) untuk 1
 * ApiIntegration (= 1 client yang sudah connect TikTok lewat OAuth, lihat
 * TikTokIntegrationController). Mirror InstagramAnalyticsService: cuma
 * tanggung jawab HTTP + normalisasi data mentah API; simpan ke DB dilakukan
 * TikTokAnalyticsSyncService, bukan di sini.
 *
 * Token SELALU dari $integration->access_token (kolom terenkripsi di
 * api_integrations) - TIDAK ADA fallback .env, sama seperti Instagram.
 *
 * PENTING - kontrak API di file ini berdasarkan dokumentasi resmi TikTok
 * for Developers (Display API v2) per pengetahuan terakhir, BELUM PERNAH
 * diverifikasi lewat panggilan API sungguhan (butuh TikTok Developer App
 * + client terhubung nyata - lihat docs/TIKTOK_INTEGRATION.md bagian
 * "Known Limitations & Verification Status"). WAJIB diverifikasi ulang
 * terhadap TikTok Developer Portal SEBELUM dipakai produksi.
 */
class TikTokAnalyticsService
{
    private const BASE_URL = 'https://open.tiktokapis.com/v2';
    private const TIMEOUT = 20;

    // Batas aman jumlah halaman video yang diambil per sync - sama alasan
    // dengan InstagramAnalyticsService::MAX_PAGES (defense-in-depth kalau
    // paging API kebetulan tidak berhenti wajar).
    private const MAX_PAGES = 10;
    private const VIDEOS_PER_PAGE = 20; // batas TikTok video.list max_count = 20

    // Field yang benar-benar dipakai sistem ini - JANGAN minta field yang
    // tidak disimpan/ditampilkan (Langkah 8 & 10, "Store only fields the
    // system actually needs" / "Request fields actually needed").
    //
    // PASS 1B fix (Langkah "COMPLETE TIKTOK DISPLAY API COVERAGE") -
    // 'username' DIPINDAH dari BASIC ke PROFILE (dokumentasi resmi TikTok:
    // username butuh scope user.info.profile, BUKAN user.info.basic - kode
    // lama SALAH menganggapnya unconditional. TikTok mengizinkan user
    // menolak scope opsional satu-satu, jadi field ini BISA gagal diminta
    // kalau user.info.profile ditolak - sama disiplin persis dengan
    // USER_INFO_FIELDS_STATS di bawah).
    private const USER_INFO_FIELDS_BASIC = ['open_id', 'union_id', 'avatar_url', 'display_name'];
    private const USER_INFO_FIELDS_PROFILE = ['username', 'bio_description', 'profile_deep_link', 'is_verified', 'avatar_large_url'];
    private const USER_INFO_FIELDS_STATS = ['follower_count', 'following_count', 'likes_count', 'video_count'];
    // is_aigc (PASS 1 micro-fix) - field resmi Video Object (video.list/
    // video.query, scope video.list yang sama), diminta DEFENSIF -
    // ditambahkan ke request TANPA syarat khusus, TAPI absen di response
    // (akun/provider belum menyediakan) TIDAK PERNAH menggagalkan sync
    // (lihat videoMetadataFields() di TikTokAnalyticsSyncService - null
    // dipertahankan, tidak ditebak false).
    private const VIDEO_FIELDS = [
        'id', 'create_time', 'title', 'video_description', 'duration', 'height', 'width',
        'cover_image_url', 'share_url', 'view_count', 'like_count', 'comment_count', 'share_count',
        'is_aigc',
    ];

    public function __construct(
        private readonly ApiIntegration $integration,
    ) {
    }

    /**
     * GET /v2/user/info/ - identitas dasar SELALU diminta; field stats
     * (follower_count dkk) cuma diminta kalau scope user.info.stats ada di
     * $integration->scopes (Langkah 9: "capture stats HANYA kalau granted").
     * TikTok menolak SELURUH request kalau ada field yang scope-nya tidak
     * dimiliki - makanya field list dibangun dinamis, bukan minta semua field
     * lalu berharap sebagian gagal senyap.
     */
    public function getUserInfo(): array
    {
        $fields = self::USER_INFO_FIELDS_BASIC;
        if ($this->hasScope('user.info.profile')) {
            $fields = [...$fields, ...self::USER_INFO_FIELDS_PROFILE];
        }
        if ($this->hasScope('user.info.stats')) {
            $fields = [...$fields, ...self::USER_INFO_FIELDS_STATS];
        }

        $response = $this->get($this->url('user/info/'), ['fields' => implode(',', $fields)]);

        if ($this->apiFailed($response)) {
            $this->throwApiError($response, 'Gagal mengambil profil TikTok');
        }

        return $response->json('data.user') ?? [];
    }

    /**
     * POST /v2/video/list/ dengan cursor pagination - video PUBLIK milik
     * akun yang diotorisasi, terbaru dulu. TikTok TIDAK menyediakan filter
     * since/until server-side seperti Instagram (dibuktikan dari dokumentasi
     * resmi - endpoint ini murni cursor-based tanpa parameter rentang
     * tanggal), jadi early-stop di sisi client dilakukan lewat $cutoff kalau
     * diisi.
     *
     * PENTING: $cutoff HARUS lower bound rentang sync (rangeFrom), BUKAN
     * upper bound (rangeTo) - begitu 1 video di halaman ini create_time-nya
     * < $cutoff, sisa halaman itu & seterusnya pasti lebih lama juga karena
     * urutan terbaru dulu, aman berhenti tanpa scan seluruh histori tiap
     * sync. Memakai upper bound di sini (bug lama, sudah di-fix di caller -
     * lihat SyncTikTokAnalyticsJob) bikin pagination berhenti di video
     * PERTAMA setiap kali rentang sync mencapai "hari ini", karena hampir
     * semua video create_time-nya < hari ini.
     *
     * @return array{videos: array<int, array>, has_more: bool, cursor: ?int, oldest_fetched: ?int, newest_fetched: ?int}
     */
    public function getVideoList(?\Illuminate\Support\Carbon $cutoff = null): array
    {
        $videos = [];
        $cursor = null;
        $hasMore = true;
        $page = 0;
        $stoppedEarly = false;

        while ($hasMore && $page < self::MAX_PAGES) {
            $page++;

            $body = ['max_count' => self::VIDEOS_PER_PAGE];
            if ($cursor !== null) {
                $body['cursor'] = $cursor;
            }

            $response = $this->post($this->url('video/list/').'?fields='.implode(',', self::VIDEO_FIELDS), $body);

            if ($this->apiFailed($response)) {
                $this->throwApiError($response, 'Gagal mengambil daftar video TikTok');
            }

            $data = $response->json('data') ?? [];
            $pageVideos = $data['videos'] ?? [];

            foreach ($pageVideos as $video) {
                if ($cutoff && isset($video['create_time']) && $video['create_time'] < $cutoff->timestamp) {
                    $stoppedEarly = true;
                    break 2; // video ini & seterusnya (urutan terbaru dulu) sudah lebih lama dari window - berhenti
                }
                $videos[] = $video;
            }

            $hasMore = (bool) ($data['has_more'] ?? false);
            $cursor = $data['cursor'] ?? null;
        }

        $timestamps = array_column($videos, 'create_time');

        return [
            'videos' => $videos,
            // has_more dari API SELALU dilaporkan APA ADANYA - TIDAK PERNAH
            // diubah/dikira "false" cuma karena kita berhenti duluan.
            // stopped_early TERPISAH menandai "kita sengaja tidak ambil
            // semua" (early-stop via $until atau MAX_PAGES) - dua makna
            // beda, jangan dicampur jadi satu boolean (Langkah 26, "Do NOT
            // claim full account history unless API result proves it").
            'has_more' => $hasMore,
            'stopped_early' => $stoppedEarly,
            'cursor' => $cursor,
            'oldest_fetched' => $timestamps ? min($timestamps) : null,
            'newest_fetched' => $timestamps ? max($timestamps) : null,
        ];
    }

    /**
     * POST /v2/video/query/ - refresh metrik video yang SUDAH diketahui
     * ID-nya (dipakai buat re-fetch tanpa perlu paging ulang seluruh list).
     * TikTok membatasi maksimal 20 video_id per request (Langkah 11, "Batch
     * IDs within TikTok's API limit") - caller (TikTokAnalyticsSyncService)
     * yang bertanggung jawab memecah batch besar, method ini cuma menolak
     * kalau caller lupa.
     *
     * @param  array<int, string>  $videoIds
     * @return array<int, array>
     */
    public function queryVideos(array $videoIds): array
    {
        if (count($videoIds) > 20) {
            throw new \InvalidArgumentException('queryVideos() maksimal 20 video_id per panggilan (batas resmi TikTok video/query).');
        }

        if (empty($videoIds)) {
            return [];
        }

        $response = $this->post(
            $this->url('video/query/').'?fields='.implode(',', self::VIDEO_FIELDS),
            ['filters' => ['video_ids' => $videoIds]]
        );

        if ($this->apiFailed($response)) {
            $this->throwApiError($response, 'Gagal mengambil detail video TikTok');
        }

        return $response->json('data.videos') ?? [];
    }

    /**
     * Orkestrasi profile -> video list (dibatasi $cutoff = lower bound
     * rentang sync, kalau diisi) - MURNI pengambilan & normalisasi, TIDAK
     * menyentuh database (persistensi ada di TikTokAnalyticsSyncService,
     * sama pola dengan Instagram).
     *
     * @return array{profile: array, videos: array<int, array>, has_more: bool, stopped_early: bool, oldest_fetched: ?int, newest_fetched: ?int}
     */
    public function sync(?\Illuminate\Support\Carbon $cutoff = null): array
    {
        $profile = $this->getUserInfo();
        $result = $this->getVideoList($cutoff);

        return [
            'profile' => $profile,
            'videos' => $result['videos'],
            'has_more' => $result['has_more'],
            'stopped_early' => $result['stopped_early'],
            'oldest_fetched' => $result['oldest_fetched'],
            'newest_fetched' => $result['newest_fetched'],
        ];
    }

    /**
     * Cek scope granted dari api_integrations.scopes (CSV, hasil OAuth
     * callback) - TANPA ini, meminta field user.info.stats padahal scope-nya
     * tidak granted bikin SELURUH request /user/info/ ditolak TikTok
     * (Langkah 3, "Do not request scopes not actually used").
     */
    private function hasScope(string $scope): bool
    {
        $granted = array_map('trim', explode(',', (string) $this->integration->scopes));

        return in_array($scope, $granted, true);
    }

    private function client()
    {
        $token = $this->integration->access_token;

        if (! filled($token)) {
            throw new TikTokApiException('TikTok integration has no access token.', TikTokApiException::AUTHENTICATION);
        }

        return Http::timeout(self::TIMEOUT)->withToken($token)->acceptJson();
    }

    private function get(string $url, array $params = []): Response
    {
        return $this->request(fn () => empty($params) ? $this->client()->get($url) : $this->client()->get($url, $params), $url);
    }

    private function post(string $url, array $body = []): Response
    {
        return $this->request(fn () => $this->client()->asJson()->post($url, $body), $url);
    }

    private function request(\Closure $call, string $url): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            Log::error('TikTok API network/timeout error', ['url' => $url, 'error' => $e->getMessage()]);

            throw new TikTokApiException(
                'Tidak bisa menghubungi TikTok API (timeout/jaringan). Coba lagi beberapa saat lagi.',
                TikTokApiException::NETWORK,
                $e
            );
        }
    }

    /**
     * TikTok API v2 melaporkan error LEWAT BODY (error.code != 'ok'), bukan
     * selalu lewat HTTP status - response bisa saja HTTP 200 tapi
     * error.code='access_token_invalid'. Jadi kegagalan dicek dua arah:
     * HTTP-level (response()->failed()) DAN body-level (error.code).
     */
    private function apiFailed(Response $response): bool
    {
        if ($response->failed()) {
            return true;
        }

        $code = $response->json('error.code');

        return $code !== null && $code !== 'ok';
    }

    private function url(string $path): string
    {
        return self::BASE_URL.'/'.$path;
    }

    /**
     * Kategori dari error.code resmi TikTok (per dokumentasi error code
     * TikTok API v2) - TIDAK ada kode dikarang. Pesan aman ditampilkan ke
     * user (tanpa token/credential).
     */
    private function throwApiError(Response $response, string $context): never
    {
        $errorBody = $response->json('error');
        $status = $response->status();
        $code = $errorBody['code'] ?? null;
        $message = $errorBody['message'] ?? "HTTP {$status}";

        Log::error($context, ['status' => $status, 'error' => $errorBody]);

        $authCodes = ['access_token_invalid', 'access_token_expired', 'scope_not_authorized', 'scope_permission_missed'];
        if ($status === 401 || in_array($code, $authCodes, true)) {
            throw new TikTokApiException(
                "Token TikTok tidak valid, kadaluarsa, atau scope tidak cukup. Hubungkan ulang akun di Client Detail. ({$message})",
                TikTokApiException::AUTHENTICATION
            );
        }

        if ($status === 429 || $code === 'rate_limit_exceeded') {
            throw new TikTokApiException(
                "Rate limit TikTok API tercapai, coba lagi beberapa saat lagi. ({$message})",
                TikTokApiException::RATE_LIMIT
            );
        }

        if ($status >= 500 || $code === 'internal_error') {
            throw new TikTokApiException(
                "TikTok API sedang bermasalah di sisi mereka (HTTP {$status}). Coba lagi beberapa saat lagi.",
                TikTokApiException::SERVER_ERROR
            );
        }

        throw new TikTokApiException(
            "{$context}: {$message}".($code ? " [{$code}]" : ''),
            TikTokApiException::UNKNOWN
        );
    }
}
