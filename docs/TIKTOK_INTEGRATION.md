# TikTok Official API Integration

Dokumentasi teknis untuk integrasi TikTok resmi (TikTok for Developers — Login Kit
OAuth 2.0 + Display API v2). **Tidak ada secret/token asli di file ini** — hanya
nama environment variable dan contoh nilai placeholder.

Pola arsitektur ini secara sengaja mengikuti (mirror) integrasi Instagram yang
sudah ada (`InstagramIntegrationController`, `InstagramAnalyticsService`,
`InstagramAnalyticsSyncService`), dengan penyesuaian di titik-titik yang
kontraknya benar-benar berbeda dari TikTok — semua perbedaan itu didokumentasikan
eksplisit di bawah, bukan dipaksakan mengikuti pola Instagram.

## 1. Sumber API yang dipakai

**Hanya** API resmi TikTok for Developers:

- **Login Kit** (OAuth 2.0 + PKCE) — untuk otorisasi per client.
- **Display API v2** (`https://open.tiktokapis.com`) — endpoint yang dipakai:
  - `POST /v2/oauth/token/` — tukar authorization code → access/refresh token, dan refresh token.
  - `GET /v2/user/info/` — profil & statistik akun.
  - `POST /v2/video/list/` — daftar video (cursor pagination).
  - `POST /v2/video/query/` — query video spesifik by ID (dipakai selektif, bukan default flow).

**Tidak** dipakai dan **tidak boleh** dipakai untuk fitur ini: scraping,
unofficial/third-party API clone (RapidAPI dsb.), TikTok Research API (lisensinya
untuk riset akademik/non-profit, bukan dashboard komersial), atau browser
automation untuk mengambil data TikTok.

## 2. Setup TikTok for Developers Portal (dilakukan manual oleh Anda, di luar kode)

1. Buat/masuk ke app di [developers.tiktok.com](https://developers.tiktok.com).
2. Tambahkan product **Login Kit** dan **Display API**.
3. Di pengaturan app, daftarkan **Redirect URI** — harus **exact match** dengan
   nilai `TIKTOK_REDIRECT_URI` di `.env` (TikTok menolak redirect_uri yang tidak
   persis sama, termasuk trailing slash).
4. Request scope minimum: `user.info.basic`, `video.list`. Scope tambahan yang
   dipakai kalau di-grant: `user.info.profile`, `user.info.stats`. Jangan
   request scope yang tidak dipakai kode ini (mis. `video.upload`,
   `video.publish` — di luar scope fitur ini, lihat Section 9).
5. Selama app masih di **Development/Sandbox mode**, TikTok hanya mengizinkan
   OAuth untuk akun yang didaftarkan manual sebagai target-user/tester di App
   Dashboard — persis seperti pola App Review Meta untuk Instagram. Client baru
   yang akunnya belum terdaftar akan gagal connect sampai app lolos **App
   Review** TikTok (proses eksternal, di luar kendali kode ini).
6. Salin `Client Key` dan `Client Secret` dari dashboard ke `.env` (lihat
   Section 3) — **jangan pernah commit nilai asli ke git**.

## 3. Environment variables

Ditambahkan ke `.env.example` sebagai placeholder kosong:

```
TIKTOK_CLIENT_KEY=
TIKTOK_CLIENT_SECRET=
TIKTOK_REDIRECT_URI=
```

Isi di `.env` lokal/production dengan nilai asli dari Developer Portal. Contoh
bentuk `TIKTOK_REDIRECT_URI` (nilai asli menyesuaikan domain masing-masing
environment): `https://app-domain-anda/client-management/tiktok/callback`.

Dibaca lewat `config/services.php` → key `tiktok` (`client_key`, `client_secret`,
`redirect`) — pola identik dengan blok `instagram` yang sudah ada di file yang
sama.

## 4. Arsitektur — apa yang di-reuse dari Instagram, apa yang baru

| Konsep | Instagram | TikTok | Keputusan |
|---|---|---|---|
| Kredensial per client | `ApiIntegration` (token di kolom, dienkripsi) | Sama | **Reuse langsung**, tanpa tabel baru |
| OAuth | Authorization Code sederhana | Authorization Code **+ PKCE (S256)** wajib | Controller baru (`TikTokIntegrationController`), bukan mirror 1:1 |
| Token lifetime | 1 long-lived token (~60 hari), refresh pakai token itu sendiri | `access_token` (~24 jam) **+** `refresh_token` terpisah (~365 hari), **rotated** tiap dipakai | Kolom baru `refresh_token_expires_at`, command refresh baru dengan logika berbeda |
| Snapshot konten | `InstagramMediaSnapshot` (media_type/media_product_type ala taksonomi Meta) | Video only, tidak ada taksonomi setara | Tabel **baru** `tiktok_video_snapshots` (lihat Section 5 — alasan tidak reuse) |
| Matching ke `ContentItem` | `ContentPublicationMatcher` | Sama | **Reuse langsung**, field TikTok di-remap ke key generik matcher sebelum dipanggil (lihat Section 7) |
| `ContentPublication` | Generik, platform-agnostic | Sama | **Reuse langsung**, tanpa tabel TikTok-only |
| Audience/follower snapshot | `AudienceInsight` (fields demografis lengkap) | Cuma `follower_count` (kalau scope granted) | **Reuse** `AudienceInsight` dengan `source = SOURCE_TIKTOK_API` baru, field demografis lain dibiarkan NULL |
| Analytics/AI Strategy aggregation | `AnalyticsController`, `AiStrategyService` | — | **Sudah platform-generic sejak awal**, nol perubahan kode — data TikTok otomatis muncul begitu `ContentMetric` punya `platform_id` TikTok yang benar |
| Historical Excel matching | `HistoricalContentMatcher` | Tidak ada dataset historis TikTok setara | **Tidak dibuat versi TikTok** — lihat Section 8 |

## 5. Kenapa `TikTokVideoSnapshot` adalah tabel terpisah, bukan reuse `InstagramMediaSnapshot`

`InstagramMediaSnapshot` punya kolom `media_type`/`media_product_type` yang
merepresentasikan taksonomi khusus Meta (`IMAGE`/`CAROUSEL_ALBUM`/`VIDEO` ×
`FEED`/`REELS`/`STORY`). TikTok API ini cuma mengembalikan satu bentuk konten
(video) tanpa taksonomi setara — memaksakan reuse tabel yang sama berarti kolom
bermakna ganda per platform atau logika kondisional-platform di dalam satu model,
keduanya lebih berisiko dijalankan mendekati feature freeze dibanding tabel baru
yang scope-nya jelas. Karena itu dibuat `tiktok_video_snapshots` (model
`TikTokVideoSnapshot`) sebagai tabel paralel — bukan hasil dari "males refactor",
tapi keputusan sadar untuk isolasi risiko.

## 6. OAuth flow (per client, dengan PKCE)

1. Staff internal (role dengan permission `client,manage`) klik "Connect TikTok"
   di halaman Client Detail → `GET client-management/{client}/tiktok/connect`.
2. `TikTokIntegrationController::connect()` generate `code_verifier` acak +
   `code_challenge` (SHA-256, base64url dari `code_verifier`), simpan
   `code_verifier`, `state` (CSRF nonce), dan `client_id` di session, lalu
   redirect ke `https://www.tiktok.com/v2/auth/authorize/` dengan scope dari
   Section 2.
3. User approve di layar consent TikTok (akun harus terdaftar sebagai
   tester selama app masih Development mode — lihat Section 2 poin 5).
4. TikTok redirect balik ke `TIKTOK_REDIRECT_URI` (route
   `client-management/tiktok/callback` — **tanpa** `{client}` di path, karena
   TikTok mewajibkan redirect_uri statis/exact-match; `client_id` dibawa lewat
   session, bukan path, persis pola yang sudah dipakai Instagram).
5. `callback()` memvalidasi `state`, menukar `code` + `code_verifier` (wajib
   untuk PKCE) ke `POST /v2/oauth/token/`, memanggil `GET /v2/user/info/` untuk
   ambil `open_id`/`display_name`/`username`, lalu
   `ApiIntegration::updateOrCreate()` menyimpan token (terenkripsi),
   `access_token_expires_at`, `refresh_token_expires_at`, `scopes` (CSV dari
   scope yang benar-benar di-grant TikTok — bisa lebih sedikit dari yang
   di-request), `external_account_id` (open_id), `external_username`.
6. Error handling: user menolak consent, `state` tidak cocok, atau token
   exchange gagal → flash pesan aman ke user, redirect balik ke Client Detail,
   **tidak** menampilkan raw error TikTok atau stack trace.

## 7. Sync konten (video) & mapping metrik

`SyncTikTokAnalyticsJob` (queued, `WithoutOverlapping` per `api_integration_id`,
lock key `tiktok-sync-{id}`) → `TikTokAnalyticsSyncService::sync()`:

1. `TikTokAnalyticsService::getUserInfo()` — field yang di-request menyesuaikan
   scope yang benar-benar dimiliki integrasi (`hasScope('user.info.stats')`
   dicek dulu sebelum minta field statistik).
2. Kalau `follower_count` tersedia di response → disimpan sebagai snapshot
   harian lewat `AudienceInsight` (`source = tiktok_api`), field demografis lain
   dibiarkan NULL (**tidak** di-set 0 — lihat Section 10).
3. `getVideoList()` — `POST /v2/video/list/`, cursor pagination
   (`VIDEOS_PER_PAGE = 20`, `MAX_PAGES = 10` per sync run sebagai safety cap).
   TikTok API ini **tidak** punya filter tanggal server-side, jadi service
   berhenti mengambil halaman lebih lanjut begitu sebuah video punya
   `create_time` di luar window sync (`$until`) — status ini dilaporkan sebagai
   `stopped_early` di summary, **terpisah** dari flag `has_more` yang dikirim
   TikTok sendiri (supaya "kita berhenti duluan" tidak pernah tercampur dengan
   "API bilang sudah habis").
4. Tiap video di-remap ke key generik (`id`, `permalink` ← `share_url`,
   `timestamp` ← `create_time`, `caption` ← `video_description`/`title`) lalu
   diserahkan ke `ContentPublicationMatcher::match()` **tanpa modifikasi** —
   class itu sudah platform-agnostic (lihat Section 4).
5. Metrik disimpan ke `ContentMetric` dengan mapping:

   | TikTok field | `ContentMetric` column |
   |---|---|
   | `view_count` | `views` |
   | `like_count` | `likes` |
   | `comment_count` | `comments` |
   | `share_count` | `shares` |
   | *(tidak tersedia)* | `reach`, `impressions`, `saves`, `profile_visit` — dibiarkan NULL, **tidak** di-set 0 |

6. Engagement rate: `(likes + comments + shares) / views * 100` — **selalu**
   views-denominated, karena TikTok Display API tidak pernah menyediakan
   `reach`. Ini beda dari formula Instagram (yang memprioritaskan `reach` kalau
   ada). Diimplementasikan sebagai method terpisah
   (`TikTokAnalyticsSyncService::computeEngagementRate()`), **tidak**
   menimpa/mengubah formula Instagram yang sudah ada.
7. Idempotency: `tiktok_video_snapshots` unique per
   `(api_integration_id, external_post_id)`; `content_metrics` unique per
   `(tiktok_video_snapshot_id, metric_date)` — sync ulang meng-update baris yang
   sama, tidak pernah duplikat.

Sync profile/stats **tidak** punya tombol/Job terpisah — disatukan ke alur sync
konten yang sama (poin 1-2 di atas), karena `/v2/user/info/` toh dipanggil satu
kali per sync run. Ini keputusan desain sengaja, bukan fitur yang terlewat.

## 8. Publishing Tracker — video unmatched

Video TikTok yang tidak match otomatis ke `ContentItem` manapun (lihat prioritas
matching di `ContentPublicationMatcher`: exact ID → normalized URL → schedule
window → strong title/caption evidence) muncul di
`publishing-tracker/tiktok/{apiIntegration}/unmatched`
(`tiktok-unmatched.blade.php`) dengan status "Belum terhubung" + link manual.

View ini **tidak** punya fitur "saran match" seperti versi Instagram
(`instagram-unmatched.blade.php` pakai `HistoricalContentMatcher`) — fitur itu
memang dibuat khusus untuk merekonsiliasi data Excel historis Instagram yang
timestamp-nya kadang tidak presisi. Tidak ada dataset historis TikTok yang setara,
jadi tidak dibuat versi TikTok-nya. Ini bukan gap yang terlewat, melainkan
scope yang memang tidak relevan untuk TikTok.

## 9. Yang eksplisit di luar scope fitur ini

- **TikTok Direct Posting** (publish konten dari dashboard ke TikTok) — belum
  dibangun sama sekali, hanya kemungkinan pengembangan masa depan.
- Comment management, inbox/DM, TikTok Ads/campaign analytics, TikTok Research
  API, social listening, competitor scraping — semuanya tidak termasuk.

## 10. Keterbatasan data resmi (Section penting — jangan diasumsikan tersedia)

TikTok Display API v2 standar (bukan Research API, bukan Ads API) **tidak**
menyediakan:

- `reach` / `impressions` (hanya `view_count`)
- `saves` / `profile_visit`
- Demografi audiens (gender, age range, top cities/countries)
- "Online followers" / jam aktif audiens

Field-field ini **tidak difabrikasi** — disimpan sebagai NULL di database, dan
UI menampilkan pesan "Data tidak tersedia melalui TikTok API" (atau
menyembunyikan card terkait) alih-alih menampilkan `0`, yang secara semantik
salah (0 berarti "diukur dan hasilnya nol", bukan "tidak diukur").

## 11. Keamanan token

- `access_token` dan `refresh_token` disimpan dengan Eloquent cast `encrypted`
  di kolom `api_integrations` (`APP_KEY` sebagai kunci enkripsi — pastikan
  `APP_KEY` production tidak pernah berubah tanpa rencana migrasi ulang data
  terenkripsi).
- Kedua kolom itu ada di `$hidden` pada model `ApiIntegration` — tidak pernah
  ikut ter-serialize ke JSON/array secara tidak sengaja.
- Tidak ada titik di codebase (controller, service, Job, command, view, log)
  yang menulis nilai token asli ke `Log::`, `dd()`/`dump()`, atau output blade
  — hanya status turunan (`Connected`/`Needs Reconnect`/dst) yang pernah
  ditampilkan ke UI.

## 12. Status koneksi & error UX

`ApiIntegration::status` merepresentasikan state yang dipahami user (bukan raw
error TikTok):

| Situasi | Status yang ditampilkan |
|---|---|
| Token valid, sync terakhir sukses | Connected |
| `access_token` expired tapi `refresh_token` masih valid | Otomatis di-refresh oleh scheduler, user tidak melihat error |
| `refresh_token` expired/revoked, atau TikTok kembalikan `access_token_invalid`/`scope_not_authorized` | Needs Reconnect — user diarahkan klik "Reconnect" (ulang OAuth) |
| Sync gagal (network/rate limit/5xx TikTok) | Sync Failed + `last_error` ringkas (bukan stack trace mentah) |

## 13. Troubleshooting

- **"Connect TikTok" disabled / pesan "OAuth belum dikonfigurasi"** — cek
  `TIKTOK_CLIENT_KEY`/`TIKTOK_CLIENT_SECRET`/`TIKTOK_REDIRECT_URI` di `.env`
  sudah terisi (bukan string kosong).
- **Redirect ke TikTok gagal / error "invalid redirect_uri"** — nilai
  `TIKTOK_REDIRECT_URI` di `.env` harus **persis sama karakter-per-karakter**
  (termasuk `http` vs `https`, trailing slash) dengan yang didaftarkan di
  Developer Portal.
- **Consent screen TikTok muncul tapi akun ditolak** — app masih Development
  mode dan akun belum didaftarkan sebagai tester di Developer Portal (lihat
  Section 2 poin 5). Ini bukan bug kode.
- **Sync jalan tapi tidak ada video baru** — cek `AnalyticsSyncLog` terakhir:
  `synced_count`/`skipped_count`/`error_message`. `skipped_count` tinggi bisa
  berarti video sudah pernah tersimpan (idempotent, bukan error).
- **Follower count tidak muncul di card** — scope `user.info.stats` tidak
  di-grant user saat consent (TikTok mengizinkan user menolak scope opsional
  satu-satu). Cek `ApiIntegration::scopes` untuk memastikan.
