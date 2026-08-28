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

Prasyarat: role & permission dasar sudah terpasang.

```bash
php artisan db:seed                                   # RoleSeeder, PermissionSeeder, MasterDataSeeder
php artisan db:seed --class=DocumentationSeeder
```

Seeder ini **tidak pernah** dipanggil otomatis oleh `DatabaseSeeder`, dan menolak
berjalan kalau:

- `app()->isProduction()` bernilai true, atau
- nama database mengandung `prod` / `production` / `live`.

Kalau role belum ada, seeder berhenti dengan pesan yang menyuruh menjalankan
`php artisan db:seed` lebih dulu — ia sengaja **tidak** memanggil `RoleSeeder`
sendiri, karena `RoleSeeder` juga membuat akun CEO bootstrap dengan email asli.

### Idempotency

Aman dijalankan berulang. Jangkarnya:

- **User** → `email` (unik)
- **Client** → `name`
- **Content Plan** → `client_id` + `month` + `year`
- **Content Item** → unique `(import_source, external_reference)` yang memang
  sudah ada di schema, dengan `import_source = 'documentation_seeder'`

Baris turunan (workflow, assignment, log status, revisi, publikasi, metrik,
brief, audience, notifikasi, pin) **dihapus lalu dibuat ulang** setiap run —
tapi hanya baris milik seeder ini, dikenali dari marker/relasi di atas. Tidak
ada `truncate`, tidak ada `delete` tanpa filter.

`content_items` sendiri tidak pernah dihapus, jadi **id-nya stabil**: URL
screenshot seperti `/content-items/8` tetap menunjuk konten yang sama setelah
seeder dijalankan ulang.

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
| Sarah Amelia | Desain Grafis | ✓ | Kopi Senja, Ruang Belajar | Alur harian PIC desain, Detail Konten |
| Lina Kartika | Desain Grafis + Copywriter | ✓ | Nusa Apparel, Ruang Belajar | **Multi-role** — Kelola Pengguna & modal Ubah Role |
| Bayu Saputra | Desain Grafis | ✗ | Nusa Apparel | Badge **"belum memiliki akses dashboard"** & tombol Aktifkan Akses Login |

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

11 rencana konten, mencakup **keempat kondisi** yang perlu difoto:

| Kondisi | Contoh | Yang bisa difoto |
|---|---|---|
| **Draf** | Kopi Senja & Ruang Belajar & Sora Residence (bulan depan) | Tombol **Ajukan Rencana** |
| **Menunggu Persetujuan** | Nusa Apparel (bulan depan) | Tombol **Setujui** / **Tolak** |
| **Disetujui** | 7 rencana bulan berjalan & bulan-bulan sebelumnya | Rencana aktif + target kuota |
| **Pernah ditolak → dikembalikan ke draf → diajukan ulang → disetujui** | **Nusa Apparel bulan berjalan** | Panel **Riwayat Keputusan** lengkap dengan alasan penolakan |

Jumlah rencana mengikuti bulan yang benar-benar dipakai konten (3–4 bulan per
klien untuk menopang 60–90 hari data performa), bukan angka tetap.

Semua tanggal relatif terhadap `Carbon::now()` — tidak ada tahun yang di-hardcode.

## Content Workflow States

30 content item, **seluruh 8 status** terwakili:

| Status | Label | Jumlah |
|---|---|:--:|
| `brief_ready` | Siap Dikerjakan | 4 |
| `in_progress` | Sedang Dikerjakan | 4 |
| `waiting_review` | Menunggu Persetujuan | 4 |
| `revision` | Perlu Revisi | 3 |
| `approved` | Disetujui | 3 |
| `scheduled` | Terjadwal Tayang | 3 |
| `uploaded` | Sudah Tayang | 8 |
| `cancelled` | Dibatalkan | 1 |

Tambahan kondisi yang sengaja disiapkan:

- **4 konten overdue** (`is_overdue`) → panel "Perlu Perhatian" di Dashboard
- **1 konten mendesak** (`is_urgent`) → penanda Jobdesk Tambahan
- **2 konten** punya riwayat status bolak-balik (pernah revisi lalu lanjut sampai
  tayang) → contoh alur tidak lurus di tab Riwayat Status
- Riwayat status hanya memuat transisi yang **sah menurut `WorkflowTransitions`** —
  tidak ada lompatan status yang aplikasinya sendiri akan tolak

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

8 konten tayang, semuanya punya `post_url` berdomain `https://example.com/posts/…`.

## Analytics Dataset

| | Kopi Senja | Nusa Apparel |
|---|---|---|
| Baris metrik | 268 | 114 |
| Rentang | 76 hari | 69 hari |
| Total views | ±352.600 | ±160.600 |
| Platform | Instagram + TikTok | Instagram |

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
- **AI Strategy**: 2 insight `completed` (Kopi Senja — sudah diterapkan, lengkap
  dengan diskusi 4 pesan; Nusa Apparel — belum diterapkan). Ringkasan & action
  item-nya ditulis dari angka yang benar-benar ada di metrik seeder ini.
- **Delay Risk**: 12 skor sintetis (`high` / `medium` / `low`), termasuk 2 item
  risiko tinggi yang **belum** overdue — syarat munculnya panel prediktif di
  Dashboard.
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
| Produksi (Kanban) | `/production-workflow` | Content Creator | 8 kolom terisi |
| Produksi (Revisi) | `/production-workflow?tab=revisions` | Manager | Revisi terbuka, termasuk yang dari klien |
| Produksi (Sudah Tayang) | `/production-workflow?tab=published` | SMO | Daftar publikasi + link post |
| Detail Konten | `/content-items/<id>` | Content Creator | AI Brief, Status Management, PIC, link hasil, riwayat revisi & publikasi |
| Performa | `/analytics?client_id=<Kopi Senja>` | SMO | Overview, tabel, audience, AI Strategy |
| Performa (empty) | `/analytics?client_id=<Sora Residence>` | SMO | Empty state |
| Performa Tim | `/team-performance` | Manager | Beban kerja + badge beban berlebih (Dimas Ardi) |
| Kehadiran | `/team-performance?tab=kehadiran` | Manager | Absensi harian & rekap bulanan |
| Kelola Pengguna | `/user-management` | Manager | 8 orang, multi-role, badge tanpa akses login |
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
