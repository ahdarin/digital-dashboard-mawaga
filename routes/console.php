<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\RecomputeDelayRiskScores;
use App\Console\Commands\SendDelayRiskNotifications;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('analytics:detect-anomalies')->hourly();

Schedule::command(RecomputeDelayRiskScores::class)->dailyAt('10:00');
Schedule::command(SendDelayRiskNotifications::class)->dailyAt('08:00');
Schedule::command('workflow:update-overdue')->hourly();

// Long-lived token Instagram (OAuth per client) cuma berlaku ~60 hari -
// di-refresh otomatis tiap hari sebelum kadaluarsa (lihat RefreshInstagramTokens).
Schedule::command('analytics:refresh-instagram-tokens')->daily();

// TikTok - access_token JAUH lebih pendek umurnya dari Instagram (~24 jam,
// bukan ~60 hari), jadi refresh dijadwalkan harian juga (lihat
// RefreshTikTokTokens docblock - kontrak refresh token TikTok beda total
// dari Instagram, refresh_token terpisah + dirotasi tiap dipakai).
Schedule::command('analytics:refresh-tiktok-tokens')->daily();

// Analytics V2 Phase B - "AUTO SYNC, ONCE PER 24 HOURS" - SATU command
// terkonsolidasi (lihat AutoSyncAnalytics docblock) menggantikan 3 baris
// jadwal lama (analytics:sync-all-instagram, analytics:sync-all-instagram-
// audience, analytics:sync-all-tiktok - command-nya MASIH ADA & tetap bisa
// dijalankan manual buat debugging, cuma JADWAL OTOMATISNYA yang dipindah
// ke sini biar tidak dispatch dobel). Command baru ini manggil
// AnalyticsSyncOrchestrator::dispatch() PERSIS pipeline yang sama dengan
// tombol manual "Perbarui Data" (trigger='scheduled' saja yang beda) -
// duplicate-protection & AnalyticsSyncRun/Task tracking otomatis ikut.
// Jam dikonfigurasi lewat config('analytics.auto_sync_time')
// (ANALYTICS_AUTO_SYNC_TIME di .env), BUKAN hardcoded di sini.
//
// PASS 1B (Langkah "SCHEDULER TIMEZONE") - ->timezone() DIEKSPLISITKAN
// (bukan diam-diam mengandalkan default implisit) walau SECARA FAKTA
// Laravel bootstrap SUDAH memanggil date_default_timezone_set(config(
// 'app.timezone')) di setiap request/command termasuk schedule:run
// sendiri, jadi tanpa baris ini pun evaluasi jadwal SUDAH konsisten pakai
// config('app.timezone') (Asia/Jakarta, default config/app.php - TIDAK
// bergantung ke timezone OS server manapun app ini di-hosting). Baris ini
// murni membuat kebergantungan itu EKSPLISIT/self-documenting - kalau
// config('app.timezone') pernah berubah di masa depan, satu sumber
// kebenaran yang sama otomatis ikut, TIDAK ADA jam kedua yang bisa drift.
Schedule::command('analytics:auto-sync')
    ->dailyAt(config('analytics.auto_sync_time'))
    ->timezone(config('app.timezone'));

// Retention rolling content_metric_snapshots (audit sync horizon +
// snapshot retention) - command SUDAH ADA & struktural benar (lihat
// PruneContentMetricSnapshots buat semantik inclusive/exclusive cutoff
// lengkap, bisa dijalankan manual kapan saja buat testing), TAPI JADWAL
// OTOMATISNYA SENGAJA DINONAKTIFKAN (dikomentari, BUKAN dihapus) sampai
// ada keputusan retention policy eksplisit - deletion snapshot TIDAK BISA
// direkonstruksi dari API manapun (lihat config/analytics.php), jadi
// belum boleh jalan otomatis tanpa review pertumbuhan tabel/storage/
// kebutuhan historical-reporting jangka panjang terlebih dulu.
// Schedule::command('analytics:prune-content-metric-snapshots')->dailyAt('03:00');

// PENTING - dependency operasional yang harus disetup terpisah, BUKAN
// otomatis aktif cuma karena baris ini ada:
// 1. Baris Schedule:: di file ini cuma "terdaftar", baru benar-benar jalan
//    kalau ada cron/Windows Task Scheduler yang memanggil `php artisan
//    schedule:run` tiap menit - belum ada di lingkungan dev ini (dicek
//    langsung, tidak ada Task Scheduler entry apapun untuk project ini).
// 2. Job SyncInstagramAnalyticsJob di atas cuma akan diproses kalau ada
//    `php artisan queue:work` (atau setara) yang jalan terus-menerus.
//    QUEUE_CONNECTION=database sudah dikonfigurasi tapi belum pernah ada
//    worker aktif di project ini sebelum fitur ini dibuat.
// Rekomendasi produksi: jalankan queue:work via process manager (Supervisor/
// NSSM), ATAU tambahkan `Schedule::command('queue:work --stop-when-empty')
// ->everyMinute()` di sini kalau tidak mau proses worker terpisah - tapi
// tetap butuh poin 1 di atas supaya schedule:run sendiri terpicu.
