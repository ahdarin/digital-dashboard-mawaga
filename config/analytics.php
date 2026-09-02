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

];
