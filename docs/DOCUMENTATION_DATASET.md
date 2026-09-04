# Documentation Dataset

Dataset khusus untuk **screenshot Buku Panduan Pengguna 523 Studio Platform**.

> ⚠️ **Seluruh isi dataset ini fiktif dan BUKAN untuk production.** Tidak ada nama
> client nyata, email/nomor telepon orang nyata, API token, maupun URL posting
> media sosial nyata di dalamnya. Lihat bagian [Safety](#safety) sebelum memakai
> dataset ini untuk materi yang akan dipublikasikan.

## Purpose

`DocumentationSeeder` menyiapkan satu dataset kuratif yang cukup kaya untuk
memotret **seluruh halaman penting** aplikasi, tapi tetap aman dipublikasikan.

Bedanya dengan `DemoSeeder`:

| | `DemoSeeder` | `DocumentationSeeder` |
|---|---|---|
| Tujuan | Eksplorasi & uji coba fitur | Screenshot buku panduan |
| Isi | Acak, sengaja bervariasi tiap run | Kuratif, deterministik |
| Idempoten | Tidak | Ya |
| Nama klien | Mendekati portofolio riil 523 Studio | Sepenuhnya fiktif |
| Aman dipublikasikan | Tidak | Ya |

`DocumentationSeeder` **tidak menggantikan** `DemoSeeder` dan tidak menghapusnya.

## How to Seed

> ⛔ **JANGAN jalankan `php artisan db:seed` polos untuk database dokumentasi.**
> Sejak `TeamClientSeeder` masuk ke `DatabaseSeeder`, perintah itu ikut
> memasukkan **daftar klien dan staf 523 Studio yang sungguhan** — nama-nama itu
> akan ikut terfoto di Kelola Klien dan Kelola Pengguna.

Pakai database **terpisah** dari database kerja sehari-hari, lalu panggil
prasyaratnya satu per satu:

```bash
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=MasterDataSeeder
php artisan db:seed --class=DocumentationSeeder
```

Seeder ini **tidak pernah** dipanggil otomatis oleh `DatabaseSeeder`, dan menolak
berjalan kalau:

- `app()->isProduction()` bernilai true, atau
- nama database mengandung `prod` / `production` / `live`.

Kalau role belum ada, seeder berhenti dengan pesan yang menyuruh memasang role &
permission dasar lebih dulu — ia sengaja **tidak** memanggil `RoleSeeder`
sendiri, karena `RoleSeeder` juga membuat akun CEO bootstrap dengan email asli.

### Akun CEO bootstrap ikut disamarkan

`RoleSeeder` membuat akun `523 Studio <hello523studio@gmail.com>` — alamat email
**sungguhan**, dan akun itu tampil di Kelola Pengguna, Performa Tim, serta ikut
terhitung di kartu "Tim Aktif" pada Dashboard. `DocumentationSeeder` mengganti
identitasnya menjadi `Akun Sistem 523 <akun.sistem@example.test>`.

Mengembalikannya: `php artisan db:seed --class=RoleSeeder`.

### Idempotency

Aman dijalankan berulang. Jangkarnya:

- **User** → `email` (unik)
- **Client** → `name`
- **Content Plan** → `client_id` + `month` + `year`
- **Content Item** → unique `(import_source, external_reference)` yang memang
  sudah ada di schema, dengan `import_source = 'documentation_seeder'`

Baris turunan (workflow, assignment, log status, revisi, publikasi, metrik,
brief, audience, notifikasi, pin, platform konten, hasil KPI) **dihapus lalu
dibuat ulang** setiap run — tapi hanya baris milik seeder ini, dikenali dari
marker/relasi di atas. Tidak ada `truncate`, tidak ada `delete` tanpa filter.

`content_items` bernomor (`DOC:KS-01` dst) tidak pernah dihapus, jadi **id-nya
stabil**: URL screenshot seperti `/content-items/8` tetap menunjuk konten yang
sama setelah seeder dijalankan ulang.

**Perkecualian:** slot Draf bulan depan (`DOC:slot:*`, lihat "Slot Draf" di
bawah) **dihapus dan digenerate ulang** tiap run, jadi id-nya berubah. Slot
memang tidak pernah jadi target URL screenshot — yang difoto halaman rencananya
(`/content-plan/<id>` dan `/content-plan/<id>/deadlines`), dan id rencana tetap
stabil.

Angka pun deterministik (PRNG internal, bukan `rand()`), jadi grafik pada
screenshot lama tidak jadi basi hanya karena seeder dijalankan ulang.

## Users

Domain email `@example.test` (RFC 6761 — dijamin tidak pernah bisa diregistrasi).
Semua berstatus `active`.

| Nama | Role | Akses login | Assigned Client | Tujuan Screenshot |
|---|---|:--:|---|---|
| Ardi Pratama | CEO | ✓ | *(semua, via visibility)* | Dashboard penuh, Kelola Klien, Kelola Pengguna, Laporan |
| Nadia Putri | Manager | ✓ | *(semua, via visibility)* | Beranda Manager, Setujui/Tolak rencana, Koreksi Status, Ganti PIC |
| Raka Mahendra | SMO | ✓ | Kopi Senja, Nusa Apparel | Performa, Jadwalkan & catat publikasi, Import CSV |
| Siti Rahma | Copywriter | ✓ | Kopi Senja, Ruang Belajar | Beranda Copywriter (antrean brief), AI Brief, Ajukan Rencana |
| Dimas Ardi | Content Creator | ✓ | Kopi Senja, Nusa Apparel | Beranda Content Creator, Kanban, penanda footage — **satu-satunya yang beban kerjanya berlebih** |
| Sarah Amelia | Graphic Designer | ✓ | Kopi Senja, Ruang Belajar | Alur harian PIC desain, Detail Konten |
| Lina Kartika | Graphic Designer + Copywriter | ✓ | Nusa Apparel, Ruang Belajar | **Multi-role** — Kelola Pengguna & modal Ubah Role |
| Bayu Saputra | Graphic Designer | ✗ | Nusa Apparel | Badge **"belum memiliki akses dashboard"** & tombol Aktifkan Akses Login |
| Galih Prasetya | **Admin** | ✓ | *(semua, via visibility)* | Role **read-only** baru — sidebar selengkap CEO tapi tanpa satu pun tombol pengubah data |
| Akun Sistem 523 | CEO | ✓ | *(semua, via visibility)* | Akun bootstrap `RoleSeeder` yang identitasnya disamarkan — biarkan apa adanya, tidak perlu difoto |

CEO & Manager sengaja tidak punya baris `user_client_assignments`:
`User::canSeeAllClients()` sudah memberi mereka visibility ke semua klien, jadi
baris assignment untuk mereka tidak mengubah apa pun.

### Beban kerja (halaman Performa Tim)

| Nama | Total konten | Konten aktif | Catatan |
|---|:--:|:--:|---|
| Dimas Ardi | 10 | **6** | Di atas ambang 5 → badge beban berlebih |
| Sarah Amelia | 9 | 5 | Pas di ambang |
| Lina Kartika | 6 | 5 | Pas di ambang |
| Raka Mahendra | 8 | 3 | Penugasan SMO (publikasi) |
| Siti Rahma | 6 | 3 | Penugasan copywriter (brief/caption) |
| Bayu Saputra | 3 | 3 | Beban ringan |

## Clients

| Nama | Kategori | Paket | Karakter Dataset | Tujuan |
|---|---|---|---|---|
| **Kopi Senja** | UMKM | Paket Growth (10 konten / 8 desain) | Data paling lengkap: 11 konten, 6 sudah tayang, Instagram + TikTok, 268 baris metrik & 76 hari data performa | Dashboard, Performa, Audience, AI Strategy, Portal Klien |
| **Nusa Apparel** | Retail | Paket Growth (10 / 8) | 11 konten, banyak di produksi & revisi, 114 baris metrik | Produksi (Kanban), tab Revisi, antrean persetujuan Portal Klien |
| **Ruang Belajar** | Startup | Paket Starter (6 / 4) | 6 konten, semuanya desain, rencana konten aktif | Rencana Konten, AI Brief, riwayat persetujuan klien |
| **Sora Residence** | Korporat | Paket Starter (6 / 4) | 2 konten `Siap Dikerjakan`, **tanpa PIC**, tanpa metrik, tanpa audience | **Empty state**: "Belum ada data", onboarding klien baru |

Kategori diambil dari master data yang memang sudah ada (UMKM/Startup/Korporat/
Retail) — seeder ini **tidak** menambah kategori atau paket baru ke Data Pilihan,
supaya master data resmi tidak kotor oleh entri khusus buku.

## Content Plan States

15 rencana konten, mencakup **keempat kondisi** yang perlu difoto:

| Kondisi | Contoh | Yang bisa difoto |
|---|---|---|
| **Draf** | Sora Residence (bulan berjalan) | Tombol **Ajukan Rencana** |
| **Menunggu Persetujuan** | **Nusa Apparel (bulan depan)** | Tombol **Setujui** / **Tolak** |
| **Disetujui** | Seluruh rencana bulan lampau & bulan berjalan, plus Kopi Senja bulan depan | Rencana aktif + target kuota |
| **Pernah ditolak → dikembalikan ke draf → diajukan ulang → disetujui** | **Nusa Apparel bulan berjalan** | Panel **Riwayat Keputusan** lengkap dengan alasan penolakan |
| **Ditolak permanen** (belum dikembalikan ke draf) | **Ruang Belajar (bulan depan)** | Badge **Ditolak** + alasan penolakan + Riwayat Keputusan + tombol **Kembalikan ke Draf & Perbaiki** |

Jumlah rencana mengikuti bulan yang benar-benar dipakai konten (3–6 bulan per
klien untuk menopang data performa dan tren KPI 6 bulan), bukan angka tetap.

Semua tanggal relatif terhadap `Carbon::now()` — tidak ada tahun yang di-hardcode.

### Slot Draf & alur Rencana Konten baru

Sejak slot konten digenerate otomatis dari kuota paket, tiga layar baru harus
bisa difoto. Seeder menyiapkan **tiga rencana bulan depan** khusus untuk itu:

| Klien (bulan depan) | Status rencana | Slot | Deadline upload | Yang bisa difoto |
|---|---|:--:|---|---|
| **Kopi Senja** | Disetujui | 18 (`C1…C10`, `D1…D8`) | terisi semua | Halaman **Atur Deadline** dengan tanggal sudah terisi, dan tombol **Kirim ke Produksi** yang siap ditekan |
| **Nusa Apparel** | Menunggu Persetujuan | 18 | kosong | Tombol **Setujui** / **Tolak**; Atur Deadline masih terkunci |
| **Ruang Belajar** | Ditolak (permanen) | 10 (`C1…C6`, `D1…D4`) | kosong | Badge **Ditolak**, alasan penolakan, Riwayat Keputusan, dan tombol **Kembalikan ke Draf & Perbaiki** — beda dari siklus Nusa Apparel bulan berjalan yang berakhir Disetujui |

Slot dibuat lewat `ContentPlanItemGeneratorService` **yang asli** (bukan salinan
logika), jadi jumlah dan penamaannya persis sama dengan yang dilihat pengguna.

Total 46 konten berstatus **Draf**. Ini normal dan memang perilaku aplikasi:
konten Draf tidak muncul di papan Kanban, tidak dihitung sebagai beban kerja,
dan tidak masuk perhitungan KPI.

## Content Workflow States

82 content item, **seluruh 9 status** terwakili (46 di antaranya slot Draf
bulan depan):

| Status | Label | Jumlah |
|---|---|:--:|
| `draft` | Draf | 46 |
| `brief_ready` | Brief Ready | 4 |
| `in_progress` | Sedang Dikerjakan | 4 |
| `waiting_review` | Menunggu Persetujuan | 4 |
| `revision` | Perlu Revisi | 3 |
| `approved` | Disetujui | 3 |
| `scheduled` | Terjadwal Tayang | 3 |
| `uploaded` | Sudah Tayang | 14 |
| `cancelled` | Dibatalkan | 1 |

Tambahan kondisi yang sengaja disiapkan:

- **4 konten overdue** (`is_overdue`) → panel "Perlu Perhatian" di Dashboard
- **1 konten mendesak** (`is_urgent`) → penanda Jobdesk Tambahan
- **2 konten** punya riwayat status bolak-balik (pernah revisi lalu lanjut sampai
  tayang) → contoh alur tidak lurus di tab Riwayat Status
- **3 konten tayang terlambat** dari jadwal yang sudah dikunci → menghidupkan
  variasi nilai Ketepatan Kerja di KPI dan angka Ketepatan Prediksi Risiko
- Riwayat status hanya memuat transisi yang **sah menurut `WorkflowTransitions`** —
  tidak ada lompatan status yang aplikasinya sendiri akan tolak
- Riwayat status konten yang **sudah tayang** berhenti di sekitar tanggal
  tayangnya sendiri, bukan di minggu ini — supaya tab Riwayat Status masuk akal
  dibaca dan evaluasi model Delay Risk tidak menganggap semua konten telat

### Kolom yang lahir setelah documentation freeze

| Kolom / tabel | Isi di dataset |
|---|---|
| `content_format_id` (master **Single Post / Carousel / Video**) | Diisi untuk **semua** konten, sinkron dengan kolom teks lama supaya tidak ada dua nama untuk format yang sama |
| `content_item_platforms` (multi-platform) | Diisi untuk semua konten; **KS-05** sengaja dua platform (Instagram + TikTok) |
| `reference_link` (**Link Referensi**) | Terisi di 5 konten, kosong di sisanya — buku perlu menampilkan kedua kondisi |
| `upload_deadline_at` | Terisi untuk semua konten produksi (= deadline pengerjaan + 2 hari), dan untuk slot Draf Kopi Senja |
| `provisional_code` (`C1`/`D3`) | Terisi untuk slot Draf |

### AI Brief

6 brief, tanpa memanggil Gemini sama sekali:

| Status | Jumlah | Kelayakan |
|---|:--:|---|
| `finalized` | 3 | 2× `ok`, 1× `warning` |
| `draft` | 2 | `ok` |
| `discussing` | 1 | `critical`, **plus riwayat diskusi 4 pesan** |

Tanggal mulai & tanggal posting selalu dihitung mundur dari deadline konten, jadi
tidak pernah jatuh di masa lampau yang janggal.

### Revisi

| Kondisi | Konten |
|---|---|
| 1 putaran, selesai | "3 Cara Menikmati Kopi di Pagi Hari" |
| 2 putaran, yang terakhir masih terbuka | "Pilihan Warna Favorit Minggu Ini" |
| **Revisi dari Portal Klien** (actor = client, bukan user) | "Testimoni Pelanggan Setia" |

Revisi terbuka hanya ada pada konten berstatus `revision` — tidak pernah pada
konten yang sudah disetujui atau tayang.

### Publikasi

14 konten tayang, semuanya punya `post_url` berdomain
`https://example.com/posts/…`. Sebarannya sengaja mencakup **6 bulan terakhir
termasuk bulan berjalan** — bulan berjalan wajib ada isinya, karena halaman
Performa Tim membuka bulan berjalan secara default dan akan tampil kosong total
tanpa satu pun konten tayang di bulan itu.

## Performa Tim & KPI

`user_monthly_kpi_results` **dihitung** oleh `TeamPerformanceKpiCalculator` yang
asli dari data seeder ini (bukan angka yang ditulis lepas), untuk **6 bulan
terakhir**. Jadi angka di Performa Tim, kartu KPI di Profil, dan konten yang
jadi dasarnya benar-benar konsisten satu sama lain.

Dijalankan langsung tanpa queue, dan `calculated_at`-nya bertanggal hari
seeding — jadi sesi pemotretan **tidak perlu menyalakan queue worker**.

| Yang bisa difoto | Kondisinya di dataset |
|---|---|
| **Tren 6 Bulan Terakhir** (3 grafik garis) | Keenam bulan terisi, nilainya naik-turun (bukan garis lurus 100%) |
| **Perbandingan Nilai KPI Anggota** | 6 anggota, nilainya bervariasi dari sempurna sampai jauh di bawah |
| **Ketepatan Prediksi Risiko Tinggi** | Terisi, dengan pecahan per tingkat risiko — bukan "belum cukup data" |
| Baris `-` di Daftar Anggota | CEO/Manager/Admin tetap `-` karena tidak punya konten yang bisa diatribusikan; ini kondisi **normal** yang juga perlu dijelaskan di buku |
| **Kartu KPI di halaman Profil** | Terisi untuk anggota yang punya nilai bulan berjalan |

### ⚠️ Bonus Performa selalu `-` di dataset ini

Kolom **Bonus Performa** tampil `-` untuk **semua** anggota dan **semua** bulan,
dan itu bukan kesalahan seeder. Bonus hanya bisa dihitung dari
`content_metric_snapshots` — rekaman per-observasi yang **cuma ditulis oleh
sinkronisasi API Instagram/TikTok**, bukan oleh Import CSV Performa. Dataset ini
sengaja tidak mengarang baris hasil API (lihat "Batasan yang disengaja" poin 4),
jadi bonus memang tidak akan pernah muncul di sini.

**Untuk buku:** jelaskan Bonus Performa dari rumusnya
(`docs/KPI_TEAM_PERFORMANCE.md`), dan pakai screenshot dari **akun tester dengan
integrasi API sungguhan** kalau kolomnya perlu ditampilkan terisi. Kondisi `-`
sendiri juga layak difoto — itu yang akan dilihat tim untuk klien yang datanya
masuk lewat CSV.

## Analytics Dataset

| | Kopi Senja | Nusa Apparel |
|---|---|---|
| Baris metrik | 274 | 117 |
| Rentang | ±76 hari | ±69 hari |
| Platform | Instagram + TikTok | Instagram |

> ⚠️ **Angka di halaman Performa TIDAK sama dengan total di atas.** Sejak
> Performa memakai **semantik cohort tanggal tayang**, kartu "Total Views Bulan
> Ini" hanya menjumlahkan konten yang **dipublikasikan pada bulan terpilih** —
> bukan seluruh baris metrik. Jangan menuliskan total kumulatif ini sebagai
> "views bulan ini" di buku.

Bentuk kurvanya **peluruhan**, bukan angka acak: views tertinggi di hari pertama
lalu turun ke plateau. Tiap konten punya profil sendiri — high / average / low
performer — supaya grafik Dashboard & Performa punya bentuk yang masuk akal.

`likes`, `comments`, `shares`, `saves`, `reach`, `impressions` dihitung
proporsional terhadap views, dan `engagement_rate` dihitung dari interaksi
terhadap reach lalu dijaga di rentang wajar **1,5 %–8 %** — bukan angka acak yang
lepas dari kolom lain di baris yang sama.

- **Audience**: 90 snapshot harian × 3 pasangan klien-platform (270 baris), dengan
  demografi, lokasi teratas, dan jam aktif. Tren follower naik wajar, tidak
  bergerigi.
- **AI Strategy**: 3 insight `completed`. Ringkasan & action item-nya ditulis
  dari angka yang benar-benar ada di metrik seeder ini.
  - **Kopi Senja — bulan berjalan**: inilah yang muncul begitu halaman Performa
    dibuka **tanpa mengubah filter apa pun**. Lengkap dengan diskusi 4 pesan dan
    daftar ide yang siap ditekan **Terapkan ke Slot Ini**. Panel AI Strategy
    hanya menampilkan analisis yang **bulannya cocok** dengan filter Bulan
    Analisis, jadi tanpa baris ini screenshot default akan berbunyi "Belum ada
    analisis buat client ini".
  - **Kopi Senja — bulan lalu**: sudah **diterapkan** (tombol Revert aktif) →
    untuk halaman **Riwayat AI Strategy**.
  - **Nusa Apparel — bulan lalu**: belum diterapkan.
- **Delay Risk**: 24 skor sintetis (`high` / `medium` / `low`).
  - 12 skor **terkini** pada konten yang masih berjalan, termasuk 2 item risiko
    tinggi yang **belum** overdue — syarat munculnya panel prediktif di
    Dashboard.
  - 12 skor **historis** pada konten yang sudah tayang, dibuat 3 hari sebelum
    tanggal tayang masing-masing. Tanpa ini kartu **Ketepatan Prediksi Risiko
    Tinggi** selalu berbunyi "belum ada cukup data", karena kartu itu hanya
    menghitung konten tayang yang punya skor bertanggal **sebelum** tayang.
- **Anomali performa**: 3 baris, dihitung dari metrik yang baru dibuat memakai
  pola yang sama dengan command `DetectPerformanceAnomalies`.
- **Riwayat sinkronisasi**: 6 baris, termasuk **1 yang gagal** (Ruang Belajar)
  untuk memotret tampilan error.

## Empty States

Sengaja **tidak** semua halaman diisi penuh:

| Kondisi kosong | Di mana |
|---|---|
| Klien tanpa data performa sama sekali | Performa → pilih **Sora Residence** |
| Klien tanpa audience | Performa → tab Audience → Sora Residence |
| Klien tanpa akun media sosial tersambung | Pengaturan → Integrasi → **semua klien** (lihat catatan di bawah) |
| Klien tanpa PIC | Detail Klien → Sora Residence |
| Konten tanpa penanggung jawab | 2 konten Sora Residence → "Belum ditugaskan" |
| Portal Klien tanpa grafik | `/portal/<token>/analytics` milik Sora Residence |
| Staf tanpa akses dashboard | Kelola Pengguna → Bayu Saputra |

## Screenshot Recommendations

Sudah diverifikasi lewat smoke test read-only — semua halaman di bawah membalas
`200` **dan** memuat kondisi yang dimaksud.

| Halaman | URL | Peran | Yang terlihat |
|---|---|---|---|
| Beranda | `/beranda` | Manager, Copywriter, Content Creator | Ringkasan pekerjaan, Langkah Berikutnya, kartu absensi, konten yang di-pin |
| Dashboard | `/dashboard` | CEO | 6 KPI, tren views, Perlu Perhatian, risiko keterlambatan, top konten, peringkat klien |
| Rencana Konten | `/content-plan`, `?view=calendar` | CEO/Manager | Tabel & kalender, status draf/pending/approved berdampingan |
| Riwayat Keputusan | `/content-plan/<id Nusa Apparel bulan ini>` | Manager | Alasan penolakan + jejak pengajuan ulang |
| Rencana berisi slot Draf | `/content-plan/<id Kopi Senja bulan depan>` | SMO/Manager | Daftar slot `C1…D8` berstatus **Draf** + tombol **Atur Deadline & Kirim ke Produksi** |
| **Atur Deadline** | `/content-plan/<id Kopi Senja bulan depan>/deadlines` | SMO | Form tanggal upload per slot (sudah terisi) |
| Rencana menunggu persetujuan | `/content-plan/<id Nusa Apparel bulan depan>` | Manager | Tombol **Setujui** / **Tolak** |
| Produksi (Kanban) | `/production-workflow` | Content Creator | Kolom produksi terisi (**kolom Draf memang tidak ada di Kanban**) |
| Produksi (Revisi) | `/production-workflow?tab=revisions` | Manager | Revisi terbuka, termasuk yang dari klien |
| Produksi (Sudah Tayang) | `/production-workflow?tab=published` | SMO | Daftar publikasi + link post |
| Detail Konten | `/content-items/<id>` | Content Creator | AI Brief, Status Management, PIC, link hasil, riwayat revisi & publikasi |
| Performa | `/analytics?client_id=<Kopi Senja>` | SMO | Overview, tabel, audience, AI Strategy (analisis bulan berjalan langsung tampil) |
| Performa (empty) | `/analytics?client_id=<Sora Residence>` | **Manager / CEO / Admin** | Empty state. ⚠️ **Bukan SMO** — Raka tidak di-assign ke Sora Residence, jadi URL ini 403 baginya |
| Performa Tim (KPI) | `/team-performance` | Manager | Tren KPI 6 bulan, perbandingan antar anggota, Ketepatan Prediksi Risiko, Daftar Anggota |
| Kehadiran | `/team-performance?tab=kehadiran` | Manager | Absensi harian & rekap bulanan |
| Kartu KPI di Profil | `/profile/<id Sarah Amelia>` | Graphic Designer | Nilai KPI pribadi bulan berjalan, di atas 4 kartu ringkasan kerja |
| Sidebar role read-only | halaman mana pun | **Admin** (Galih Prasetya) | Sidebar selengkap CEO tanpa tombol pengubah data |
| Kelola Pengguna | `/user-management` | Manager | 10 orang, multi-role, badge tanpa akses login, **tab Aktif/Nonaktif** |
| Kelola Klien | `/client-management` + detail | Manager | 4 klien, kartu Paket Aktif, PIC ditugaskan |
| Laporan | `/report` | SMO | Form + data cukup untuk preview/PDF |
| Pengaturan | `/settings?tab=data-pilihan&type=package-template` | Manager | Tab Paket |
| Pengaturan | `/settings?tab=integrasi&client_id=<Ruang Belajar>` | Manager | Riwayat sinkronisasi termasuk baris gagal |
| Portal Klien | `/portal/<token>` + `/calendar` `/history` `/analytics` | — | Dashboard klien, kalender, riwayat, antrean persetujuan |

**Pencarian global** (`/search?q=…`) sudah bisa menemukan klien, pengguna, dan
konten — coba `Kopi`, `Sarah`, atau `Arabika`.

## Batasan yang disengaja

Tiga hal yang **sengaja tidak** dibuat seeder ini, beserta alasannya:

1. **Tidak ada `ApiIntegration` sama sekali.** Baris `ApiIntegration` tanpa OAuth
   sungguhan membuat kartu Instagram/TikTok di Pengaturan tampil **"Terhubung"**
   padahal tidak — persis bug yang pernah diaudit dan datanya dibersihkan (lihat
   catatan di `DemoSeeder`). Untuk buku, kondisi "Terhubung" **harus** difoto dari
   akun tester sungguhan, sesuai status `EXTERNAL_BLOCKED` di
   `docs/USER_MANUAL_SOURCE_OF_TRUTH.md`.
2. **`AudienceInsight` & `AnalyticsSyncLog` ditandai sumber CSV/import, bukan
   `instagram_api`/`tiktok_api`.** Datanya memang sintetis dan tidak berasal dari
   API mana pun; menandainya sebagai hasil API akan membuat halaman Audience
   mengklaim sumber yang tidak pernah ada.
3. **Tidak ada `GeneratedReport`.** Baris riwayat laporan menunjuk berkas PDF/XLSX
   di `storage`; tanpa berkas aslinya, tombol unduh di halaman Laporan akan mati.
   Riwayat laporan lebih baik diisi dengan benar-benar menekan tombol **Buat
   Laporan** saat sesi pemotretan.

4. **Tidak ada `content_metric_snapshots`, `instagram_media_snapshots`, maupun
   `tiktok_video_snapshots`.** Ketiganya adalah rekaman mentah per-observasi
   dari API provider; mengisinya berarti mengarang bahwa dataset ini pernah
   disinkronkan dari Instagram/TikTok, padahal tidak (alasan yang sama dengan
   poin 1 & 2). Konsekuensinya **tidak merusak halaman mana pun**: grafik tren
   di Dashboard dan Performa punya jalur CSV/manual yang dipakai apa adanya, dan
   itu sudah diverifikasi lewat smoke test. Yang tidak akan muncul hanyalah
   angka "pertumbuhan periode" per konten API — di tempatnya aplikasi
   menampilkan banner jujur "riwayat observasi belum cukup", yang justru salah
   satu kondisi yang layak difoto untuk buku.
5. **Tidak ada `analytics_sync_runs` / `analytics_sync_tasks`.** Panel progres
   sinkronisasi hidup hanya masuk akal kalau ada `ApiIntegration` sungguhan
   (poin 1). Untuk buku, panel progres & tombol coba-lagi **harus** difoto dari
   akun tester sungguhan.

Selain itu, **Delay Risk di-seed langsung tanpa memanggil script Python ML**, dan
`ContentWorkflow` dibuat lewat `withoutEvents()` supaya `ContentWorkflowObserver`
tidak ikut men-spawn proses prediksi saat seeding.

## Safety

- Seluruh data **fiktif**: nama orang, nama klien, judul konten, caption, brief,
  dan angka performa semuanya dikarang untuk keperluan buku.
- Email memakai domain `@example.test`; tautan memakai `https://example.com/…`.
  **Tidak ada** email pribadi, nomor telepon, API key, access token, atau URL
  posting Instagram/TikTok nyata.
- **Bukan untuk production.** Seeder menolak berjalan di environment production
  maupun di database yang namanya terbaca sebagai production.
- **Token Portal Klien tidak pernah dicetak ke konsol** dan tidak boleh ditulis di
  dokumentasi mana pun. Ambil lewat **Kelola Klien → Detail Klien** kalau perlu
  membuka portalnya, dan **sensor sebagian token** pada screenshot yang akan
  dipublikasikan.
- **Jangan mengganti dataset ini dengan data klien nyata saat membuat screenshot
  publik.** Kalau terpaksa memotret dari data nyata, ikuti daftar sensor lengkap
  di bagian "Checklist Keamanan Data Dokumentasi" pada
  `docs/USER_MANUAL_SOURCE_OF_TRUTH.md`.
- Dataset ini tidak pernah dipakai oleh automated test — test suite tetap
  terisolasi di database `digidaw_testing` sesuai `phpunit.xml`.
