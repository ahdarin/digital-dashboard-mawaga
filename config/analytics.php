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

    // Default sync (tombol "Sync Last 2 Months" / analytics:sync-instagram
    // tanpa --month) cuma ambil N bulan terakhir - biar operasional harian
    // nggak perlu narik histori lama tiap kali. Data lama TETAP bisa ditarik
    // manual lewat historical sync per bulan (--month=YYYY-MM).
    'instagram_default_sync_months' => 2,

];
