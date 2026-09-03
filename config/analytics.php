<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instagram Sync
    |--------------------------------------------------------------------------
    |
    | Konfigurasi buat ContentPublicationMatcher & SyncInstagramAnalytics.
    | File config biasa (bukan .env) - nggak ada credential di sini, cuma
    | angka/perilaku yang wajar berubah tanpa perlu redeploy .env.
    |
    */

    // Toleransi jendela waktu (menit) buat kandidat matching Priority 3
    // (schedule-based) di ContentPublicationMatcher - media Instagram
    // dianggap kandidat kalau timestamp-nya dalam ±N menit dari
    // content_items.scheduled_upload_at.
    //
    // CATATAN: angka 120 ini BELUM dikalibrasi dari data operasional nyata
    // (per audit Agustus 2026, belum ada content_item real yang benar-benar
    // melalui alur "Jadwalkan Upload" buat 3 client yang sudah connect
    // Instagram - datanya masih terlalu baru). Tinjau ulang begitu ada pola
    // nyata seberapa jauh jadwal vs waktu post asli biasanya meleset.
    'instagram_schedule_match_tolerance_minutes' => 120,

    // Default sync (tombol "Sync Now" / analytics:sync-instagram tanpa
    // --month) cuma ambil N hari terakhir - biar operasional harian nggak
    // perlu narik histori lama tiap kali. Data lama TETAP bisa ditarik
    // manual lewat historical sync per bulan (--month=YYYY-MM).
    //
    // EXACT DAYS (bukan "bulan") - disamakan dengan filter Performa 7/30/90
    // hari, biar ingestion horizon selalu >= filter period terpanjang yang
    // UI tawarkan. CATATAN PENTING: ini cuma lookback PENGAMBILAN konten
    // dari API (video/post APA SAJA yang di-fetch) - BUKAN klaim bahwa
    // sistem otomatis punya 90 hari GENUINE PERFORMANCE HISTORY harian
    // (itu baru ada setelah snapshot collection harian berjalan, lihat
    // Phase 2/3 arsitektur period calculation - JANGAN disamakan).
    'instagram_default_sync_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | TikTok Sync
    |--------------------------------------------------------------------------
    |
    | Mirror konfigurasi Instagram di atas, dipakai ContentPublicationMatcher
    | (matcher SAMA, cuma toleransi window beda per platform) &
    | TikTokAnalyticsSyncService. Nilai SAMA dengan Instagram sebagai starting
    | point (belum ada data operasional TikTok real buat kalibrasi ulang -
    | sama seperti catatan instagram_schedule_match_tolerance_minutes di atas).
    |
    */

    'tiktok_schedule_match_tolerance_minutes' => 120,
    // EXACT DAYS - sama alasan persis dengan instagram_default_sync_days
    // di atas.
    'tiktok_default_sync_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | Snapshot Retention (audit sync horizon - REVIEW-ONLY, belum final)
    |--------------------------------------------------------------------------
    |
    | content_metric_snapshots (histori observasi harian, Phase 2) - kandidat
    | retention rolling. Fakta yang SUDAH diverifikasi terhadap
    | PeriodPerformanceService: minimum MUTLAK buat filter 90-hari saat ini
    | (filter terpanjang yang UI tawarkan) benar-benar full coverage adalah
    | 91 hari (period_start s/d hari ini, PLUS 1 hari sebelum period_start
    | sebagai baseline ideal). 120 = 91 + buffer ~29 hari buat toleransi
    | scheduler yang kelewat/API down sementara/sync telat.
    |
    | PENTING - buffer 29 hari ini BELUM DIKALIBRASI dari data operasional
    | nyata (sama seperti instagram_schedule_match_tolerance_minutes di atas)
    | - JANGAN anggap 120 sebagai angka optimal, cuma starting point yang
    | matematis cukup (91) plus margin aman yang masuk akal.
    |
    | DELETION IRREVERSIBLE - content_metric_snapshots yang terhapus TIDAK
    | BISA direkonstruksi dari Instagram/TikTok API (kedua platform cuma
    | expose nilai cumulative SAAT INI, bukan "nilai per tanggal X di masa
    | lalu"). Karena itu:
    |
    | analytics:prune-content-metric-snapshots (app/Console/Commands) SUDAH
    | ADA dan struktural benar (aman dijalankan manual, HANYA menyentuh
    | content_metric_snapshots), TAPI SCHEDULE OTOMATISNYA SENGAJA
    | DINONAKTIFKAN (lihat routes/console.php - baris Schedule:: buat
    | command ini dikomentari, BUKAN dihapus) sampai ada keputusan retention
    | policy eksplisit yang mempertimbangkan: pertumbuhan tabel nyata,
    | dampak storage, kebutuhan historical-reporting jangka panjang di masa
    | depan. Command tetap bisa dijalankan manual kapan saja buat testing.
    |
    */
    'content_metric_snapshot_retention_days' => 120,

    /*
    |--------------------------------------------------------------------------
    | Known-Content Refresh Budget (audit sync horizon - observation rotation)
    |--------------------------------------------------------------------------
    |
    | InstagramAnalyticsSyncService::refreshKnownMedia()/
    | TikTokAnalyticsSyncService::refreshKnownVideos() - refresh metrik buat
    | content yang SUDAH DIKENAL sistem (InstagramMediaSnapshot/
    | TikTokVideoSnapshot manapun milik integration ini).
    |
    | ROLLING 90-DAY SYNC COVERAGE - FINAL CORRECTION PASS (keputusan produk
    | direvisi, MEMBALIK catatan lama di bawah): eligible HANYA kalau
    | published_at/create_time masih di dalam rolling coverage window yang
    | SAMA dengan discovery (*_default_sync_days) - content age SEKARANG
    | MENENTUKAN eligibility. Content di luar window TETAP TERSIMPAN (tidak
    | dihapus/didetach, snapshot/report/AI history utuh), cuma tidak lagi
    | ikut rotasi refresh normal. Selection dari kandidat yang eligible:
    | urut last_fetched_at ASC (paling lama tidak di-refresh duluan)
    | dibatasi budget di bawah - rotating di dalam window, bukan lagi
    | rotating tanpa batas usia.
    |
    | Budget DIPISAH per platform karena biaya API-nya TIDAK SIMETRIS:
    | Instagram getMediaInsights() murni per-media (Graph API tidak punya
    | endpoint batch-by-IDs buat insights), jadi budget-nya harus dijaga
    | konservatif. TikTok queryVideos() genuinely batched (POST
    | video/query/, maks 20 ID/panggilan, ini batas RESMI TikTok bukan
    | pilihan kita) - budget-nya bisa jauh lebih besar per unit biaya API
    | yang sama.
    |
    | PENTING - kedua angka ini ADALAH default operasional konservatif
    | AWAL, BUKAN batas aman API yang sudah dikalibrasi dari data
    | operasional nyata (x-app-usage Instagram di-log tiap request tapi
    | belum dipakai buat throttle otomatis - lihat InstagramAnalyticsService::get()).
    | Tinjau ulang begitu ada data pemakaian API nyata dari client yang
    | benar-benar terhubung.
    |
    */
    'instagram_known_refresh_budget' => 50,
    'tiktok_known_refresh_budget' => 500, // ~25 panggilan queryVideos() (batch 20)

    /*
    |--------------------------------------------------------------------------
    | Auto Sync - Analytics V2 Phase B
    |--------------------------------------------------------------------------
    |
    | Jam refresh otomatis harian (format "HH:MM", 24 jam, timezone app -
    | lihat config('app.timezone')) - config value SENGAJA (Langkah "AUTO
    | SYNC", "make execution time configurable rather than scattering a
    | hard-coded hour across code"), BUKAN angka hardcoded di
    | routes/console.php. Dini hari default (traffic API/dashboard rendah).
    |
    */
    'auto_sync_time' => env('ANALYTICS_AUTO_SYNC_TIME', '03:15'),

    /*
    |--------------------------------------------------------------------------
    | Progressive Sync Engine - Resilience Pass
    |--------------------------------------------------------------------------
    |
    | Satu "Perbarui Data" run TIDAK LAGI diproses sebagai satu job monolitik
    | yang loop lewat SELURUH workload 90 hari sebelum job itu selesai
    | (lihat SyncStageBoundary::forInstagram()/forTikTok() dan
    | ProcessInstagramSyncChunkJob/ProcessTikTokSyncChunkJob) - workload
    | dipartisi jadi chunk sebesar sync_chunk_size, tiap chunk 1 job
    | eksekusi terpisah yang bounded durasinya, chunk berikutnya di-dispatch
    | begitu chunk ini selesai. discovered_count/processed_count/dst tetap
    | SATU AnalyticsSyncTask yang sama - chunking murni implementation
    | detail, TIDAK PERNAH terekspos ke user sebagai "sync" terpisah
    | (Langkah 2/6).
    |
    | sync_chunk_size dipakai KEDUA provider (Instagram per-media, TikTok
    | per-video) - SATU angka, direferensikan dari config di setiap
    | job/service yang butuh (Langkah 5, "do not hardcode the same literal
    | in several jobs/services").
    |
    | Age bucket (STAGE 1/2/3) dihitung dari published_at vs now(), BUKAN
    | dari boundary kalender bulan - lihat SyncStageBoundary::stageFor().
    |
    */
    'sync_chunk_size' => (int) env('ANALYTICS_SYNC_CHUNK_SIZE', 20),
    'sync_stage_1_max_age_days' => 30, // 0-29 hari = STAGE 1 (recent)
    'sync_stage_2_max_age_days' => 60, // 30-59 hari = STAGE 2 (mid)
    // STAGE 3 = sisanya sampai instagram_default_sync_days/tiktok_default_sync_days (60-89 hari).

    /*
    |--------------------------------------------------------------------------
    | Progressive Sync Engine - Chunk Soft Deadline (FINAL CLOSURE GATE)
    |--------------------------------------------------------------------------
    |
    | 20 media/chunk (default sync_chunk_size) x maks 2 request/media (1
    | core + 1 optional batched, Instagram) = SAMPAI 40 request per chunk.
    | Tiap request punya timeout 20 detik (InstagramAnalyticsService::TIMEOUT/
    | TikTokAnalyticsService::TIMEOUT) - kalau BANYAK request di 1 chunk
    | genuinely hit full timeout (provider degraded, bukan skenario normal -
    | data real: 24 request/29 detik = ~1.2 detik/request rata-rata), 40 x
    | 20 detik = 800 detik BISA melebihi ProcessInstagramSyncChunkJob::
    | $timeout (300 detik).
    |
    | Perbaikan: processChunk() (InstagramAnalyticsSyncService/
    | TikTokAnalyticsSyncService) berhenti mengambil item BARU begitu waktu
    | eksekusi chunk ini sudah melewati angka di bawah, MENYISAKAN sisa
    | item chunk itu tetap 'pending' (BUKAN hilang) - ProcessInstagramSyncChunkJob/
    | ProcessTikTokSyncChunkJob lalu dispatch ulang chunk_index YANG SAMA
    | (bukan lanjut ke chunk berikutnya) buat menghabiskan sisanya di
    | eksekusi job baru. Item yang SEDANG diproses saat deadline terlewati
    | tetap dibiarkan selesai dulu (deadline dicek SEBELUM item berikutnya,
    | bukan menginterupsi request yang sedang berjalan) - worst case 1 item
    | terakhir (maks 2 request x 20 detik = 40 detik) bisa mendorong durasi
    | aktual sampai ~200+40=240 detik, MASIH di bawah $timeout (300 detik,
    | margin 60 detik) dan DB_QUEUE_RETRY_AFTER (360 detik, margin 120
    | detik) - lihat final report Section 3 buat perhitungan lengkap.
    |
    | Ini SATU-SATUNYA angka (dipakai kedua provider), preferensi eksplisit
    | dari arahan "avoid unnecessary complexity" - BUKAN budget waktu
    | per-item terpisah, BUKAN penurunan sync_chunk_size default (yang
    | akan memperlambat kasus normal demi skenario degradasi yang jarang).
    |
    */
    'sync_chunk_soft_deadline_seconds' => (int) env('ANALYTICS_SYNC_CHUNK_SOFT_DEADLINE_SECONDS', 200),

];
