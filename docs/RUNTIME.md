# Runtime Setup — Queue Worker & Scheduler

Catatan operasional untuk fitur background sync Instagram (`SyncInstagramAnalyticsJob`)
dan TikTok (`SyncTikTokAnalyticsJob`), serta command terjadwal lain di
`routes/console.php`. **Tidak ada secret/token di file ini.**

## Kenapa ini penting

`QUEUE_CONNECTION=database` sudah dikonfigurasi, tapi Laravel **tidak** memproses
job secara otomatis hanya karena kode di-deploy. Job baru benar-benar jalan kalau
ada proses `queue:work` yang aktif. Begitu juga command terjadwal (`Schedule::command(...)`
di `routes/console.php`) — baris itu cuma "pendaftaran", baru benar-benar terpicu
kalau `php artisan schedule:run` dipanggil setiap menit oleh cron/Task Scheduler.

Tanpa dua hal ini aktif: tombol "Sync Now" tetap akan mendispatch job (redirect
tetap instan), tapi job itu akan **diam di tabel `jobs` sampai ada worker yang
memprosesnya** — bukan error, cuma belum diproses.

## Development (lokal, termasuk Windows)

**Cara termudah** - satu perintah yang menjalankan web server, queue listener,
scheduler simulator, log viewer, dan asset watcher sekaligus (lewat
`npx concurrently`, tidak OS-specific, jalan sama di Windows/Mac/Linux):

```bash
composer run dev
```

Kalau butuh menjalankan komponennya satu-satu (mis. buat debug salah satu
proses tanpa yang lain ikut jalan), jalankan manual di terminal terpisah
(selain `php artisan serve` / Laragon):

```bash
# Proses job yang di-dispatch (Sync Now, dst)
php artisan queue:work

# Simulasikan cron tiap menit, buat tes command terjadwal
# (analytics:sync-all-instagram, analytics:refresh-instagram-tokens,
# analytics:sync-all-tiktok, analytics:refresh-tiktok-tokens, dst)
# tanpa perlu setup cron/Task Scheduler asli
php artisan schedule:work
```

## Command terjadwal — ringkasan

| Command | Cadence | Fungsi |
|---|---|---|
| `analytics:refresh-instagram-tokens` | daily | Refresh long-lived Instagram token yang mendekati expired (~60 hari) |
| `analytics:sync-all-instagram` | daily | Dispatch `SyncInstagramAnalyticsJob` untuk semua client yang connect Instagram |
| `analytics:sync-all-instagram-audience` | daily | Sync Audience Insights (follower/reach/demografi) Instagram, terpisah dari sync konten |
| `analytics:refresh-tiktok-tokens` | daily | Refresh TikTok `access_token` (~24 jam) via `refresh_token` — lihat catatan kontrak berbeda di bawah |
| `analytics:sync-all-tiktok` | daily | Dispatch `SyncTikTokAnalyticsJob` untuk semua client yang connect TikTok |

Kontrak refresh token TikTok **berbeda total** dari Instagram: Instagram punya satu
long-lived token yang di-refresh pakai token itu sendiri (`ig_refresh_token`).
TikTok punya `access_token` (~24 jam) dan `refresh_token` (~365 hari) terpisah,
dan `refresh_token` **dirotasi** (nilai baru diterbitkan) tiap kali dipakai untuk
refresh — `RefreshTikTokTokens` menyimpan `refresh_token` baru itu tiap jalan,
bukan cuma `access_token`-nya. Tidak ada sync Audience Insights terpisah untuk
TikTok — TikTok Display API standar tidak punya endpoint demografis seperti
Instagram Insights; `follower_count` (kalau scope `user.info.stats` di-grant)
disatukan ke job sync konten yang sama.

Kalau sedang aktif ubah-ubah kode Job, `queue:work` **tidak** otomatis reload kode
baru (proses PHP-nya sudah "membeku" isi class saat start) - restart manual
(`Ctrl+C` lalu jalankan ulang) tiap habis edit Job, atau pakai `php artisan queue:listen`
(lebih lambat per-job tapi auto-reload tiap job baru, cocok buat development aktif).

Untuk sekali proses tanpa proses yang nunggu terus (mis. buat testing manual):

```bash
php artisan queue:work --stop-when-empty
```

## Production (Linux)

**Queue worker** — jalankan permanen lewat process manager (Supervisor paling umum
untuk Laravel), bukan dibiarkan jalan di terminal biasa (mati kalau SSH terputus).
Contoh config Supervisor (`/etc/supervisor/conf.d/523studio-worker.conf`):

```ini
[program:523studio-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/523studio/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=1
user=www-data
stopwaitsecs=3600
```

**Scheduler** — satu baris crontab, standar Laravel (tidak berubah lintas versi):

```
* * * * * cd /path/to/523studio && php artisan schedule:run >> /dev/null 2>&1
```

**Restart worker setelah deploy** — `queue:work` membekukan kode job di memori
saat start (lihat catatan di bawah), jadi WAJIB direstart tiap deploy yang
menyentuh `app/Jobs/`, `app/Services/`, atau `app/Console/Commands/` supaya
worker memakai kode baru, bukan versi lama yang masih nyangkut di memori:

```bash
php artisan queue:restart
```

Ini mengirim sinyal graceful (worker menyelesaikan job yang sedang jalan dulu,
baru berhenti) - Supervisor (`autorestart=true` di config atas) otomatis
menyalakannya lagi dengan kode baru.

**Log & error** — `storage/logs/laravel.log` (Laravel default, termasuk semua
`Log::error()`/`Log::warning()` dari service TikTok/Instagram/AI Brief).
Job yang gagal permanen (habis retry) tercatat di tabel `failed_jobs` (cek
lewat `php artisan queue:failed`), TERPISAH dari `analytics_sync_logs` (yang
menyimpan histori sync per-integration untuk ditampilkan di UI).

**Health-check sederhana** — dua sinyal cepat untuk memastikan queue/scheduler
benar-benar berjalan, bukan cuma "terdaftar":

```bash
# Job gagal permanen (menandakan ada masalah berulang, bukan sekali gagal)
php artisan queue:failed

# AnalyticsSyncLog yang nyangkut 'pending' lebih dari 30 menit = tanda queue
# worker TIDAK berjalan (job dispatch tapi tidak pernah diproses) - lihat
# command cleanup di bagian Maintenance di bawah.
php artisan tinker --execute="echo \App\Models\AnalyticsSyncLog::where('status','pending')->where('created_at','<',now()->subMinutes(30))->count().' log nyangkut pending.';"
```

## Testing database (KI-15 — isolasi dari database development)

**Test TIDAK PERNAH boleh menyentuh database development (`digidaw`).** Sebelumnya
`phpunit.xml` menunjuk `DB_DATABASE=digidaw` (sama persis dengan `.env` development)
padahal ada test (`ClientPortalTest`) memakai `RefreshDatabase` — kombinasi ini
berisiko menghapus/migrate ulang data development tiap `php artisan test` dijalankan.

Perbaikan:

- `phpunit.xml` sekarang menunjuk `DB_DATABASE=digidaw_testing` — database MySQL
  terpisah (host/credentials sama, cuma nama database beda), dibuat khusus untuk
  testing dan aman di-`RefreshDatabase` kapan saja.
- SQLite/in-memory **tidak dipakai** karena migration awal (`clients`, `users`,
  `content_plans`, `content_workflows`, `content_revisions`, `api_integrations`,
  `analytics_sync_logs`, `delay_risk_scores`, `content_brief_drafts`, dan beberapa
  migration `ALTER TABLE ... MODIFY` lain) memakai `DB::statement()` dengan sintaks
  MySQL murni (`ALTER TABLE ... ADD CONSTRAINT ... CHECK`, `ALTER TABLE ... MODIFY`)
  yang tidak didukung SQLite — migrate akan gagal di tengah jalan kalau dipaksa SQLite.
- `tests/TestCase.php` punya **safeguard runtime**: `setUp()` mengecek
  `app()->environment('testing')` dan nama database aktif (harus mengandung `"test"`
  dan tidak boleh persis `"digidaw"`) — kalau salah satu gagal, test langsung
  di-abort dengan `RuntimeException` SEBELUM `RefreshDatabase` sempat jalan. Ini
  melindungi dari config yang salah lagi di masa depan (mis. `phpunit.xml` ke-revert,
  atau `.env.testing` baru yang salah isi).
- Database `digidaw_testing` dibuat manual sekali (`CREATE DATABASE digidaw_testing`),
  lalu otomatis di-migrate oleh `RefreshDatabase` tiap test run — tidak perlu
  `php artisan migrate` manual untuk database ini.

Sebelum test pertama dijalankan setelah audit ini, proof berikut dicetak dan
diverifikasi (`APP_ENV=testing`, koneksi `mysql`, database `digidaw_testing`,
BUKAN `digidaw`) — baseline: 26 test lulus (2 `ExampleTest` + 24 `ClientPortalTest`),
database development (`digidaw`, 3 user) terbukti tidak berubah.

## Maintenance

Kalau ada `analytics_sync_logs` yang nyangkut `pending` (worker crash di tengah
job, dst), bersihkan manual atau jadwalkan:

```bash
php artisan analytics:cleanup-stale-sync-logs
```

Default threshold 30 menit (`--minutes=N` untuk ubah). Log basi ditandai `failed`
dengan pesan `"Sync interrupted or stale."` — tidak menghapus data historis
`content_metrics` yang sudah tersimpan dari sync sebelumnya.
