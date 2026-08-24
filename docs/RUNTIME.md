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

Jalankan di 2 terminal terpisah (selain `php artisan serve` / Laragon):

```bash
# Terminal 1 - proses job yang di-dispatch (Sync Now, dst)
php artisan queue:work

# Terminal 2 - simulasikan cron tiap menit, buat tes command terjadwal
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

## Maintenance

Kalau ada `analytics_sync_logs` yang nyangkut `pending` (worker crash di tengah
job, dst), bersihkan manual atau jadwalkan:

```bash
php artisan analytics:cleanup-stale-sync-logs
```

Default threshold 30 menit (`--minutes=N` untuk ubah). Log basi ditandai `failed`
dengan pesan `"Sync interrupted or stale."` — tidak menghapus data historis
`content_metrics` yang sudah tersimpan dari sync sebelumnya.
