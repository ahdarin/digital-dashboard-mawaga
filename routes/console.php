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

// Sync default (2 bulan terakhir) semua client yang sudah connect Instagram
// - HARIAN (cadence didiskusikan & disetujui, lihat SyncAllInstagramIntegrations
// docblock). Historical sync per bulan SELALU manual, tidak pernah di sini.
Schedule::command('analytics:sync-all-instagram')->daily();

// Audience Insights (account-level: followers/reach/active_hours/demografi)
// - TERPISAH dari sync content di atas (lock key beda, job beda), harian,
// selalu "hari ini" saja (tidak ada historical month-picker - lihat audit
// "Instagram Audience Insights").
Schedule::command('analytics:sync-all-instagram-audience')->daily();

// TikTok - access_token JAUH lebih pendek umurnya dari Instagram (~24 jam,
// bukan ~60 hari), jadi refresh dijadwalkan harian juga (lihat
// RefreshTikTokTokens docblock - kontrak refresh token TikTok beda total
// dari Instagram, refresh_token terpisah + dirotasi tiap dipakai).
Schedule::command('analytics:refresh-tiktok-tokens')->daily();

// Sync default (2 bulan terakhir) semua client yang sudah connect TikTok -
// cadence disamakan dengan Instagram (Langkah "TikTok Official API
// Integration" Section 24, "align reasonably with Instagram"). TIDAK ada
// jadwal audience/stats terpisah - follower_count (kalau scope granted)
// disatukan ke sync content ini (lihat TikTokAnalyticsSyncService::
// saveProfileSnapshot()), TikTok Display API standar tidak punya endpoint
// audience demografis seperti Instagram Insights.
Schedule::command('analytics:sync-all-tiktok')->daily();

// KPI (Fase 5) - dijadwalkan SETELAH sync analytics harian di atas supaya
// content_metric_snapshots/audience_insights hari ini sudah masuk sebelum
// dihitung. Lock (Cache::lock di dalam command) mencegah tabrakan dengan
// eksekusi manual `php artisan kpi:calculate` yang kebetulan berbarengan.
// Selalu menghitung bulan BERJALAN (idempotent - lihat CalculateKpi docblock).
Schedule::command('kpi:calculate')->dailyAt('03:00');

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
