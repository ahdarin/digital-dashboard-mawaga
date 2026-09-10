# Inventaris Seeder — 523 Studio Platform

**Tanggal:** 10 September 2026.
**Tujuan:** checkpoint 2 dari 4 (lihat [PRE_SEMINAR_SYSTEM_AUDIT.md](PRE_SEMINAR_SYSTEM_AUDIT.md) §9). Membaca seluruh `database/seeders/*.php` (10 file) dan `database/seeders/data/*.php` (2 fixture), menjelaskan fungsi tiap seeder, dan menentukan apakah masih dibutuhkan — sebagai dasar merancang seeder demo baru di checkpoint 3.

Metodologi: seluruh file dibaca penuh (bukan ringkasan dari dokumentasi lama), termasuk komentar panjang di tiap seeder yang mencatat riwayat keputusan desainnya sendiri.

---

## 1. Ringkasan — Apa yang Masih Dipakai Hari Ini

`DatabaseSeeder::run()` (yang jalan tiap `migrate:fresh --seed` default) HANYA memanggil 4 dari 10 seeder:

```php
$this->call([
    RoleSeeder::class,
    PermissionSeeder::class,
    MasterDataSeeder::class,
    TeamClientSeeder::class,
]);
```

6 seeder lainnya (`ContentPlannerPrerequisiteSeeder`, `ContentPlannerSeeder`, `KpiDemoDataSeeder`, `DemoSeeder`, `DocumentationSeeder`) HARUS dipanggil eksplisit lewat `--class=`. Ini desain yang disengaja (bukan seeder yang terlupa didaftarkan) — dicatat jelas di komentar `DatabaseSeeder.php`: dev DB (`digidaw`) sekarang berisi data operasional nyata (import planner + Instagram API), jadi instalasi/reset ulang wajib bersih dari data fiktif atau data historis besar secara default.

---

## 2. Tabel Keputusan Keep/Retire

| # | Seeder | Fungsi Singkat | Isi Data | Aman untuk Demo Publik? | Keputusan |
|---|---|---|---|---|---|
| 1 | `DatabaseSeeder` | Orkestrator bootstrap wajib | — (cuma memanggil 4 seeder lain) | N/A | **KEEP** — entrypoint instalasi |
| 2 | `RoleSeeder` | Buat 7 role + bootstrap 1 akun CEO resmi (`hello523studio@gmail.com`) | Role fiktif (nama generik) + **1 email nyata** (akun resmi 523 Studio, bukan personal) | Tidak — akun CEO nyata | **KEEP** untuk instalasi real; **jangan ikutkan** di environment demo publik (lihat §4) |
| 3 | `PermissionSeeder` | Definisikan 11 modul × 5 aksi = 55 permission, mapping ke 7 role | Murni struktural, tidak ada data personal | Ya | **KEEP** — RBAC tidak bisa jalan tanpa ini |
| 4 | `MasterDataSeeder` | Pillar/Tipe/Platform/Format/Kategori Client/Paket — isi dropdown | Data generik (nama pillar dsb.), **wajib** cocok persis dengan label training model Delay Risk | Ya | **KEEP** — semua form (Tambah Konten, Tambah Klien, dst.) butuh ini |
| 5 | `TeamClientSeeder` | Roster staf + daftar Client **nyata** dari fixture `data/team_and_clients.php` | **14 nama client nyata**, **11 email Gmail personal nyata** staf 523 Studio (HAGI, LOVI, RESTY, dst.) | **Tidak — data personal & bisnis nyata** | **KEEP** untuk instalasi real 523 Studio; **wajib dikeluarkan** dari environment seminar |
| 6 | `ContentPlannerPrerequisiteSeeder` | Buat Client + ContentPlan bulanan sebagai prasyarat `ContentPlannerSeeder` (khusus dev lokal kosong — Railway sudah punya Client-nya manual) | Nama client dari fixture yang sama (nyata) | Tidak | **KEEP** (one-off/rare-use, bukan bagian instalasi default) — jangan jalankan di db demo |
| 7 | `ContentPlannerSeeder` | Import 247 `ContentItem` historis dari Excel Content Planner lama (hasil `content-planner:import` yang divalidasi, ditulis ulang sebagai fixture statis) | **247 baris konten nyata**: judul, brief, link file, email PIC nyata | **Tidak — data historis bisnis nyata** | **KEEP** (arsip riwayat data real, one-off) — jangan jalankan di db demo |
| 8 | `KpiDemoDataSeeder` | Data sintetis KPI (6 bulan) — **tapi** menunjuk ke User & Client **nyata** yang sudah ada, khusus uji visual chart/tabel Performa Tim | ContentItem/Plan fiktif, namun `user_id`/`client_id` FK ke baris nyata | **Tidak — mengekspos nama staf & client nyata di angka KPI** | **KEEP** untuk QA internal (dev DB), **tidak boleh** dipakai demo publik |
| 9 | ~~`DemoSeeder` (v6)~~ | Data eksplorasi/testing lama — 5 client bernuansa "mendekati portofolio riil 523 Studio" (nama seeder sendiri yang bilang begitu, lihat baris 58) + 6 staf fiktif `@523studio.test` | Client fiktif tapi **sengaja dibuat mirip client nyata** (Yasmin IBS, LuxSuits, dst. — nama disamarkan tipis, bukan diacak) | **Tidak** — sudah diflag `DOCUMENTATION_FREEZE_CHECKLIST.md` sebagai tidak aman untuk publik | **DIHAPUS** (10 Sep 2026) — lihat §3 |
| 10 | `DocumentationSeeder` | Dataset kuratif deterministik untuk screenshot Buku Panduan Pengguna | **100% fiktif** (`@example.test`, nama client generik), dibangun khusus untuk aman dipublikasikan | **Ya — satu-satunya seeder yang sudah didesain aman dipublikasikan** | **KEEP** — jadi basis utama seeder demo seminar (checkpoint 3) |

---

## 3. Detail Per Seeder

### 3.1 `DatabaseSeeder.php` (40 baris)
Orkestrator murni. Komentarnya sendiri secara eksplisit mencatat riwayat: dulu memanggil `ContentPlannerSeeder`/`ContentPlannerPrerequisiteSeeder` langsung, diubah karena instalasi baru jadi "penuh data lampau" alih-alih mulai kosong. Juga eksplisit menyatakan **tidak pernah** memanggil `DemoSeeder` di environment manapun lagi sejak dev DB berisi data real. **Tidak ada tindakan** — sudah dalam bentuk paling aman & minimal.

### 3.2 `RoleSeeder.php` (61 baris)
Buat 7 role sistem (CEO, Manager, Content Creator, Graphic Designer, SMO, Copywriter, Admin) — nama role generik, aman. Tapi juga bootstrap **satu akun CEO resmi** (`hello523studio@gmail.com`, nama "523 Studio") — ini akun institusional (bukan Gmail pribadi seseorang), namun tetap identitas perusahaan nyata yang tidak pantas muncul di demo depan dosen penguji dari kampus lain. Komentar di file ini mencatat riwayat: sebelumnya sempat 3 akun Gmail personal (Ahda/Surdik/Ghazi), diganti 1 akun resmi institusional per keputusan pemilik produk.

### 3.3 `PermissionSeeder.php` (129 baris)
Definisikan katalog 55 pasangan modul×aksi lalu mapping ke 7 role sesuai matriks RBAC (CEO=semua, Admin=view-only semua modul, dst.). Murni struktural — tidak ada data personal/bisnis sama sekali. Ini fondasi RBAC; tanpa seeder ini **tidak ada** satu pun role yang punya izin apa-apa.

### 3.4 `MasterDataSeeder.php` (80 baris)
Isi dropdown dasar: 6 Content Pillar, 2 Content Type, 2 Platform, 3 Content Format, 5 Client Category, 4 Package Template. Catatan penting di komentarnya: **nama Pillar harus identik** dengan label yang dipakai melatih model Delay Risk — kalau nama diubah di sini, encoder model tidak mengenalinya dan skor jadi tidak akurat. Ini constraint yang harus dihormati seeder baru di checkpoint 3 juga.

### 3.5 `TeamClientSeeder.php` (83 baris) + fixture `data/team_and_clients.php` (122 baris)
Menggantikan `ContentPlannerSeeder` sebagai default untuk instalasi baru — hanya membawa roster staf + 14 Client nyata, TANPA histori ContentPlan/ContentItem (supaya instalasi baru mulai "kosongan"). Fixture berisi **11 alamat Gmail pribadi nyata** staf produksi 523 Studio beserta client yang pernah mereka tangani (`content_planner_guide` sebagai `source`, artinya data riwayat asli, bukan dummy). Ini persis jenis data yang menurut Anda tidak etis ditampilkan di seminar kampus.

### 3.6 `ContentPlannerPrerequisiteSeeder.php` (78 baris)
Pembantu satu-arah: buat Client + ContentPlan bulanan yang jadi prasyarat `ContentPlannerSeeder`, HANYA relevan untuk database dev lokal yang benar-benar kosong (di Railway/production, 13 Client ini sudah dibuat manual lewat UI sebelum `ContentPlannerSeeder` pernah dijalankan — jadi seeder ini "tidak pernah perlu jalan di sana"). Nama Client diambil langsung dari fixture yang sama dengan `ContentPlannerSeeder` (bukan didefinisikan ulang), jadi otomatis konsisten.

### 3.7 `ContentPlannerSeeder.php` (289 baris) + fixture `data/content_planner.php` (5.956 baris)
Yang paling besar dari sisi volume data: 247 `ContentItem` historis hasil `content-planner:import` (dari spreadsheet Excel operasional lama), ditulis ulang sebagai fixture statis (bukan baca Excel lagi) karena impor langsung ke Railway lewat TCP proxy publik pernah gagal di tengah jalan. Desainnya sangat hati-hati soal performa (batch insert lewat query builder, bukan `Model::create()` satu-satu, supaya tidak memicu `ContentWorkflowObserver` — jadi prediksi Delay Risk TIDAK terpicu otomatis untuk data historis ini) dan idempotency (identity key `import_source+external_reference`, sama seperti importer asli). **Bukan sumber ide teknik untuk checkpoint 3** dari sisi datanya (semua nyata), tapi pola batch-insert + resolve-natural-key-nya adalah referensi teknik yang baik kalau seeder baru butuh insert volume besar dengan cepat.

### 3.8 `KpiDemoDataSeeder.php` (229 baris)
Didesain eksplisit "aman dijalankan di `digidaw` (dev DB operasional)" — TIDAK PERNAH membuat Client/User fiktif, hanya menambah ContentPlan/ContentItem/assignment/publication/revision/brief/`ContentMetricSnapshot` sintetis yang menunjuk ke Client & User **nyata** yang sudah ada, ditandai `import_source = 'kpi_demo_seed'` supaya gampang dihapus lagi (query hapusnya sudah didokumentasikan di komentar file). **Ini seeder paling relevan secara teknik untuk checkpoint 3**: satu-satunya seeder yang sudah membuat `ContentMetricSnapshot` (bukan cuma `ContentMetric`) — struktur data yang dibutuhkan Bonus Performa KPI (lihat temuan audit §7, structural limitation A) — dan sudah memanggil `TeamPerformanceKpiCalculator::calculateForPeriod()` langsung per bulan dalam loop 6 bulan mundur, persis pola "bertahap secara historis, dihitung ulang oleh sistem" yang Anda minta. Kelemahannya untuk tujuan demo: FK ke User/Client nyata, bukan fiktif.

### 3.9 `DemoSeeder.php` (796 baris)
Versi ke-6 dari seeder demo lama, riwayat penggabungan dari 3 seeder terpisah yang sebelumnya saling tumpang tindih. Baris komentar 58 mengakui sendiri: 5 client yang dipakai ("Yasmin International Boarding School", "PT Guna Griya Abadi", "LuxSuits", "Top Scorer Arena", "FTI UNAND") dipilih supaya **"mendekati portofolio riil 523 Studio"** — bukan nama acak, tapi versi yang disamarkan tipis dari client asli (bandingkan dengan nama asli di `team_and_clients.php`: nyaris identik). `DOCUMENTATION_FREEZE_CHECKLIST.md` sudah mencatat ini sebagai alasan seeder ini tidak aman untuk publik. Sudah digantikan sepenuhnya secara fungsional oleh `DocumentationSeeder` (dataset lebih lengkap, lebih deterministik, dan benar-benar fiktif). **Status: dihapus 10 September 2026** (`database/seeders/DemoSeeder.php` — dikonfirmasi tidak ada referensi fungsional lain sebelum dihapus, hanya disebut di komentar historis file lain yang dibiarkan apa adanya sebagai catatan riwayat).

### 3.10 `DocumentationSeeder.php` (2.253 baris) — **paling relevan untuk checkpoint 3**
Dibuat khusus untuk sesi pemotretan layar Buku Panduan Pengguna, dengan disiplin desain yang jauh lebih tinggi dari seeder lain:
- **100% fiktif** — domain email `@example.test`, tidak ada nama client/orang/API token/URL posting nyata.
- **Guard produksi 2 lapis** (`guardEnvironment()`): menolak jalan kalau `app()->isProduction()` ATAU nama database mengandung pola `prod`/`production`/`live` — perlindungan yang tidak dimiliki seeder lain manapun di proyek ini.
- **Deterministik**: PRNG internal (xorshift32) dengan seed tetap, bukan `rand()`/`mt_rand()` — dua kali `run()` menghasilkan angka identik, jadi screenshot lama tidak basi.
- **Idempotent & stabil id**: `purgePreviousRun()` hanya menghapus baris turunan (workflow, metrik, dst.), Client/User/ContentItem intinya di-`updateOrCreate` supaya URL seperti `/content-items/12` tetap menunjuk konten yang sama antar run.
- **KPI dihitung sungguhan**: `seedKpiResults()` memanggil `TeamPerformanceKpiCalculator::calculateForPeriod()` asli untuk 6 bulan terakhir, dijalankan **paling akhir** (setelah semua data pendukung ada) — persis prinsip yang Anda minta untuk seeder baru.
- **Satu gap terhadap kebutuhan Anda**: `seedDelayRisk()` menulis skor Delay Risk **langsung** ke tabel (bukan memanggil script Python), dan `ContentWorkflow` dibuat lewat `withoutEvents()` supaya `ContentWorkflowObserver` (yang men-trigger prediksi ML sungguhan saat status masuk `brief_ready`) tidak ikut terpicu. Ini keputusan desain yang masuk akal untuk kebutuhan ASLI seeder ini (screenshot buku panduan tidak boleh bergantung runtime Python yang mungkin belum ter-setup di laptop siapa pun yang meng-generate ulang buku panduan) — **tapi bertentangan dengan permintaan Anda** bahwa Delay Risk di seeder demo seminar harus benar-benar dihitung sistem, bukan ditulis manual.
- Tidak membuat `ApiIntegration` sama sekali (supaya kartu "Terhubung" di Pengaturan tidak palsu) dan `AudienceInsight`/`ContentMetric` ditandai provenance CSV/import (bukan `instagram_api`/`tiktok_api`) — konsisten dengan structural limitation A di audit: Bonus Performa akan tampil kosong untuk seeder ini juga, karena tidak menulis `ContentMetricSnapshot`.

---

## 4. Kesimpulan untuk Checkpoint 3

**Seeder baru sebaiknya dibangun di atas pola `DocumentationSeeder`, bukan dari nol**, dengan 3 perubahan utama:

1. **Delay Risk**: ganti `seedDelayRisk()` yang menulis skor langsung → biarkan `ContentWorkflowObserver` terpicu natural (tanpa `withoutEvents()`) saat item backdated masuk status `brief_ready`, ATAU panggil eksplisit service/command prediksi yang sama yang dipakai runtime (`DelayRiskPredictionService` / `RecomputeDelayRiskScores`) setelah semua data historis selesai ditulis — supaya angka yang tampil benar-benar hasil model, bukan seeder.
2. **Bonus Performa KPI**: kalau Anda ingin kolom ini terisi (bukan `-`) saat demo, seeder baru perlu menulis `ContentMetricSnapshot` (pola dari `KpiDemoDataSeeder`, bukan `ContentMetric`/`DocumentationSeeder`) dengan provenance yang API-sourced-shaped — ini pertanyaan desain yang perlu keputusan Anda di checkpoint 3 (apakah Bonus Performa memang mau didemokan terisi, atau `-` dengan penjelasan "butuh sinkronisasi API live" itu sendiri bagian valid dari narasi demo).
3. **Isolasi dari data real**: seeder baru **tidak boleh** memanggil `TeamClientSeeder`/`ContentPlannerSeeder`/`RoleSeeder`-nya bagian bootstrap CEO — environment demo perlu database terpisah yang di-`migrate:fresh` lalu diisi HANYA lewat rantai: `RoleSeeder` (tanpa bootstrap CEO real — perlu sedikit modifikasi atau di-skip) → `PermissionSeeder` → `MasterDataSeeder` → seeder demo baru.

**Keputusan terkonfirmasi:** `DemoSeeder.php` sudah dihapus dari codebase (bukan sekadar rekomendasi) — perannya (data demo untuk eksplorasi/testing manual) diambil alih oleh seeder baru checkpoint 3, dengan jaminan tambahan (aman untuk publik + KPI/Delay Risk genuinely computed) yang tidak dimiliki `DemoSeeder`.

**File baru checkpoint 3 sengaja terpisah dari `DocumentationSeeder.php`** (bukan memodifikasi seeder yang sudah ada) — `DocumentationSeeder` tetap seperti sekarang (tanpa dependency Python) karena masih dipakai aktif untuk generate screenshot Buku Panduan Pengguna resmi; seeder demo seminar akan jadi file baru (`SeminarDemoSeeder.php`) yang boleh bergantung ke pipeline Delay Risk live karena aplikasi memang berjalan penuh saat seminar.

---

## 5. Checkpoint Selanjutnya

1. ✅ Audit sistem
2. ✅ **Analisis seluruh seeder lama** (dokumen ini)
3. ✅ Seeder demo baru — `database/seeders/SeminarDemoSeeder.php` + `config/seminar_demo.php`. Sudah diuji end-to-end (dry-run) di database `digidaw_testing`: 745-baris data (9 user, 4 client, 85 content item, 21 skor Delay Risk **dihitung sungguhan oleh model ML**, 4 baris Bonus Performa KPI non-null, 24 baris KPI bulanan lewat `TeamPerformanceKpiCalculator` asli) — idempotent (dijalankan 2x, hasil identik).
4. ⏭️ Skenario pengujian lengkap per modul

Lanjut ke checkpoint 4 setelah checkpoint 3 dikonfirmasi.
