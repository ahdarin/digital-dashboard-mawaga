# Deploy ke Railway

Panduan deploy 523 Studio Platform ke Railway. Arsitektur: **1 image Docker**
dipakai untuk semua peran (web/worker/scheduler), peran ditentukan oleh
environment variable `PROCESS_ROLE` yang dibaca `docker/entrypoint.sh` saat
container start — lihat penjelasan di bagian [Arsitektur](#arsitektur).

## Prasyarat

- Repo sudah di-push ke GitHub.
- Akun Railway, Google Cloud Console (OAuth), dan (opsional) Meta App /
  TikTok Developer Portal untuk integrasi sosial.

## Langkah 1 — Buat project & database

1. Railway → **New Project → Deploy from GitHub repo** → pilih repo ini.
   Railway akan otomatis mendeteksi `Dockerfile` dan `railway.json`.
2. Di project yang sama → **New → Database → Add MySQL**.
3. **Jangan deploy dulu** sebelum environment variable di Langkah 2 terisi —
   deploy pertama tanpa `APP_KEY`/`GOOGLE_*` akan gagal atau tidak bisa
   dipakai.

## Langkah 2 — Environment variables

Buka service app (bukan MySQL) → tab **Variables**, isi:

```
APP_NAME="523 Studio Platform"
APP_ENV=production
APP_KEY=                          # isi dari `php artisan key:generate --show` (jalankan lokal)
APP_DEBUG=false
APP_URL=https://REPLACE-SETELAH-DAPAT-DOMAIN
APP_LOCALE=id
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local
LOG_CHANNEL=stack
LOG_LEVEL=warning

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://REPLACE-SETELAH-DAPAT-DOMAIN/auth/google/callback

GEMINI_API_KEY=

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="hello@example.com"

INSTAGRAM_CLIENT_ID=
INSTAGRAM_CLIENT_SECRET=
INSTAGRAM_API_VERSION=
INSTAGRAM_REDIRECT_URI=https://REPLACE-SETELAH-DAPAT-DOMAIN/client-management/instagram/callback

TIKTOK_CLIENT_KEY=
TIKTOK_CLIENT_SECRET=
TIKTOK_REDIRECT_URI=https://REPLACE-SETELAH-DAPAT-DOMAIN/client-management/tiktok/callback
```

Nilai `${{MySQL.MYSQLHOST}}` dkk adalah **referensi Railway** ke variabel milik
plugin MySQL — ketik persis begitu, jangan disalin manual, supaya otomatis
sinkron kalau kredensial database berubah.

`PROCESS_ROLE` **tidak perlu diisi** untuk deploy pertama — defaultnya `all`
(mode 2-service, lihat di bawah).

## Langkah 3 — Deploy & ambil domain

1. Trigger deploy (push ke branch yang terhubung, atau klik Deploy manual).
2. Setelah build selesai, buka tab **Settings → Networking → Generate Domain**
   untuk dapat URL `*.up.railway.app`.
3. **Update `APP_URL` dan ketiga `*_REDIRECT_URI`** di atas dengan domain yang
   baru didapat, lalu redeploy (variable baru baru berlaku setelah restart).

## Langkah 4 — Daftarkan OAuth callback

Baru bisa dilakukan setelah domain didapat di Langkah 3.

| Platform | Daftarkan di | Redirect URI |
|---|---|---|
| Google | [Google Cloud Console](https://console.cloud.google.com/apis/credentials) → OAuth Client → Authorized redirect URIs | `https://<domain>/auth/google/callback` |
| Instagram | Meta App Dashboard | `https://<domain>/client-management/instagram/callback` |
| TikTok | TikTok for Developers | `https://<domain>/client-management/tiktok/callback` |

Instagram & TikTok akan tetap berstatus `EXTERNAL_BLOCKED` (siap secara kode,
tapi hanya akun tester yang bisa connect) sampai App Review masing-masing
platform lolos — deploy tidak mengubah batasan ini.

## Langkah 5 — Seed data awal

Lewat Railway CLI atau tab **Deployments → View Logs → Shell** (kalau
tersedia):

```bash
php artisan db:seed
```

Ini menjalankan `RoleSeeder` (termasuk 3 akun CEO bootstrap dengan email
asli), `PermissionSeeder`, `MasterDataSeeder`. **Jangan** jalankan
`DemoSeeder` di production — seeder itu tidak punya guard environment dan
akan menyuntikkan client/konten fiktif ke database asli.

`DocumentationSeeder` boleh dijalankan kalau butuh dataset screenshot buku
panduan (lihat `docs/DOCUMENTATION_DATASET.md`), tapi seeder itu **menolak
sendiri** kalau `APP_ENV=production` atau nama database mengandung
`prod`/`production`/`live` — jangan dipaksa lewat.

## Langkah 6 — Pasang Volume (logo klien & laporan PDF/Excel)

Filesystem Railway **ephemeral** — hilang tiap redeploy. Dua fitur yang
menulis file ke disk:

- `ClientManagementController` → logo klien (`clients.logo_path`)
- `ReportController` → laporan PDF/Excel (`Storage::disk('public')`)

Tanpa volume, logo & laporan lama hilang setiap kali deploy ulang.

1. Service app → **Settings → Volumes → New Volume**.
2. Mount path: `/var/www/html/storage/app/public`.
3. Redeploy — `entrypoint.sh` otomatis menjalankan `php artisan storage:link`
   setiap start, jadi symlink `public/storage` selalu terbentuk ulang.

## Arsitektur

### Kenapa Docker, bukan Nixpacks

`DelayRiskPredictionService` memanggil `python predict_batch.py` dengan
`scikit-learn==1.6.1` persis (model `.pkl` gagal di-load di versi lain).
Menggabung PHP 8.3 + Node (build Vite) + Python 3 + scikit-learn jauh lebih
terkendali lewat Dockerfile eksplisit daripada auto-detect Nixpacks.

### Satu image, banyak peran (`PROCESS_ROLE`)

`docker/entrypoint.sh` membaca `PROCESS_ROLE` saat container start:

| `PROCESS_ROLE` | Menjalankan |
|---|---|
| `all` (default) | nginx + php-fpm + `queue:work` + `schedule:work` dalam satu container, dikelola `supervisord` |
| `web` | Hanya nginx + php-fpm |
| `worker` | Hanya `php artisan queue:work` |
| `scheduler` | Hanya `php artisan schedule:work` |

**Migrasi & `php artisan optimize` hanya dijalankan oleh peran `web`/`all`** —
mencegah dua container start bersamaan berlomba menjalankan migration yang
sama.

### Setup 2-service (default, direkomendasikan untuk mulai)

Cukup 2 service dalam satu project Railway:

1. **app** — `PROCESS_ROLE=all` (tidak perlu diset eksplisit, itu default)
2. **MySQL** — plugin database Railway

Paling hemat kredit/biaya karena cuma satu container app yang menyala.
Trade-off: kalau container crash, worker & scheduler ikut mati bareng web.
Untuk skala tim ini (~8 staf internal, beban queue ringan — cuma job sync
Instagram/TikTok), risiko itu wajar diambil.

### Pindah ke setup 4-service (kapan saja, tanpa ubah kode)

Kalau nanti perlu worker/scheduler independen dari web:

1. Di project yang sama, **New Service → Deploy from GitHub repo** (repo yang
   sama) — lakukan 2 kali.
2. Set `PROCESS_ROLE=worker` di service baru pertama, `PROCESS_ROLE=scheduler`
   di service baru kedua.
3. Ubah `PROCESS_ROLE=web` di service **app** yang lama.
4. **Penting:** matikan healthcheck HTTP untuk service **worker** dan
   **scheduler** — keduanya tidak menyajikan HTTP sama sekali, jadi
   healthcheck `/up` dari `railway.json` akan gagal terus dan bikin Railway
   mengira service itu tidak sehat. Di masing-masing service → **Settings →
   Deploy → Healthcheck Path** → kosongkan.
5. Redeploy ketiganya.

Tidak ada migrasi database, tidak ada perubahan kode — environment variable
lain (DB, Google OAuth, dst.) otomatis ke-share lewat **shared variables**
Railway di level project.

## Catatan operasional

- **`APP_DEBUG` wajib `false`.** Kalau `true`, halaman error membocorkan isi
  `.env` termasuk kredensial database dan API key.
- **Skor Delay Risk** dihitung lewat `predict_batch.py` yang dipanggil
  `DelayRiskPredictionService` — kalau ingin deploy pertama tanpa Python
  (image lebih kecil, build lebih cepat), fungsi ini aman di-skip: kodenya
  sudah menangani model/script tidak ada dengan log error + array kosong,
  **tidak** melempar exception. Skor Risiko Keterlambatan akan kosong
  sementara sampai Python ditambahkan.
- Token OAuth per-client (`api_integrations.access_token`/`refresh_token`)
  tersimpan **terenkripsi** di database (`APP_KEY` adalah kunci enkripsinya)
  — jangan pernah mengganti `APP_KEY` setelah ada client yang connect
  Instagram/TikTok, semua token lama jadi tidak bisa didekripsi.
