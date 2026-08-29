# 523 Studio Platform — Source of Truth untuk Buku Panduan Pengguna

> **Dokumen ini BUKAN buku panduan.** Ini hasil audit implementasi aktual yang
> dipakai sebagai bahan mentah untuk menyusun Buku Panduan Pengguna final.

## ✅ KONDISI TERKINI — 26 Agustus 2026 (setelah Final Pre-Merge Verification)

**Ini adalah kondisi aplikasi SEKARANG.** Dokumen sudah direkonsiliasi penuh
setelah sprint stabilisasi (`FIX → TEST → VERIFY → CLEANUP → RE-AUDIT`) **dan**
Final Pre-Merge Verification, keduanya di branch `stabilization/pre-user-manual`.
Sumber: `docs/PRE_DOCUMENTATION_STABILIZATION_REPORT.md` (termasuk appendix
"Final Pre-Merge Verification") dan `docs/DOCUMENTATION_FREEZE_CHECKLIST.md`.

| Item | Nilai |
|---|---|
| Status branch | **`READY_TO_MERGE`** |
| Kesiapan dokumentasi | **`DOCUMENTATION_READY`** — Buku Panduan Pengguna boleh mulai ditulis |
| Test suite | **148 test · 363 assertion · 0 failed · 0 skipped** |
| Database pengujian | `digidaw_testing` (terisolasi permanen dari database development, dengan hard safeguard) |
| Metode verifikasi | Automated integration test (routing + middleware + database sungguhan) + runtime read-only terhadap database development berisi data realistis (10 user, 5 client, 15 rencana konten, 85 content item) |
| `KNOWN_ISSUE` tersisa | **0** |
| Blocker tersisa | Murni **eksternal** (Meta App Review, TikTok Developer Portal) — bukan kode aplikasi |

**Ringkasan perubahan sejak audit pertama:**

- Seluruh 8 `KNOWN_ISSUE` (KI-01…KI-07, KI-09) dan 2 dari 3 `NOT_READY`
  (KI-11, KI-14) **sudah diperbaiki**, masing-masing dengan regression test.
- **KI-10** (Dashboard scope) diperbaiki juga meski awalnya ditandai `READY`.
- **KI-13** (Rencana Konten Ditolak buntu) diperbaiki: sekarang ada jalur
  Ditolak → Draf → ajukan ulang, plus riwayat keputusan lengkap.
- **KI-17** (ketidaksesuaian istilah) sekarang **FIXED** — sweep terminologi
  menyeluruh atas ~45 string di 15 file, termasuk PDF laporan client-facing.
  Tabel rujukan tunggal ada di section **"Terminologi Resmi untuk Dokumentasi"**.
- **3 authorization leak baru** ditemukan lewat white-box re-audit (AI Strategy
  History, Import Audience CSV, kanban drag-drop Produksi) — semuanya
  diperbaiki dan punya regression test.
- Golden path, rejection path, dan revision path lulus sebagai **satu alur
  berkesinambungan** (`GoldenPathTest`); akses 6 role × 10 halaman diverifikasi
  lewat direct URL (`RoleAccessMatrixTest`, 63 kasus).

**Satu-satunya hal yang TIDAK boleh diklaim "selesai" di buku:** live OAuth
Instagram & TikTok (KI-08). Kode lengkap dan teruji, tapi consent screen
sungguhan bergantung App Review eksternal — status `EXTERNAL_BLOCKED`.

### Cara membaca dokumen ini

Dokumen ini memuat **dua lapisan**:

1. **Kondisi sekarang** — semua Feature Record, tabel status, daftar prosedur,
   rencana screenshot, dan struktur buku. Ini yang dipakai penulis buku.
2. **Riwayat audit** — bagian yang diberi label eksplisit
   **HISTORIS — KONDISI SEBELUM STABILISASI**. Disimpan sebagai engineering
   history. **Status di dalamnya BUKAN kondisi aplikasi sekarang** dan tidak
   boleh dijadikan dasar penulisan buku.

Kalau menemukan kalimat yang terdengar seperti "fitur X rusak" tanpa label
historis di atasnya, itu bug dokumentasi — silakan cek ulang ke Bagian 22.

---

## HISTORIS — KONDISI SEBELUM STABILISASI: Metadata Audit Pertama

> ⚠️ **Seluruh isi sub-bagian ini (sampai pembatas sebelum Bagian 1)
> menggambarkan kondisi pada audit pertama, commit `d637369`, SEBELUM sprint
> stabilisasi.** Tidak satu pun keterbatasan di bawah ini masih berlaku dalam
> bentuk aslinya. Disimpan hanya sebagai catatan metodologi audit awal.

| Item | Nilai |
|---|---|
| Tanggal audit | 26 Agustus 2026 |
| Commit SHA | `d6373696b9f4a025551958826240c7bba918fa58` (`d637369`) |
| Branch | `main` |
| Working tree | Bersih (tidak ada perubahan tak ter-commit saat audit) |
| Metode verifikasi | **Static analysis + runtime (parsial)** |
| Perubahan kode | **Nol.** Audit ini read-only; satu-satunya file yang dibuat adalah dokumen ini. |
| Status sekarang | **Sudah tidak berlaku** — lihat kotak "KONDISI TERKINI" di atas |

### Cara runtime verification dilakukan *(historis)*

Aplikasi di-boot lewat HTTP kernel Laravel dengan user `id=1` (Ahda, role CEO)
dan **hanya request GET** yang dikirim — tidak ada POST/PATCH/DELETE, tidak ada
migration, tidak ada seeding, tidak ada perubahan data.

Hasil (semua sebagai CEO):

| Halaman | HTTP |
|---|---|
| `/beranda`, `/dashboard`, `/analytics`, `/content-plan`, `/content-plan?view=calendar` | 200 |
| `/production-workflow`, `/team-performance`, `/team-performance?tab=kehadiran` | 200 |
| `/user-management`, `/client-management`, `/client-management/create` | 200 |
| `/report`, `/settings`, `/settings?tab=data-pilihan`, `/settings/import` | 200 |
| `/publishing-tracker`, `/revision-log`, `/search?q=a` | 200 |
| `/analytics/ai-strategy/history` | 302 (butuh `client_id`) |

### Keterbatasan audit pertama *(historis — semuanya sudah tidak berlaku)*

> Daftar ini **tidak lagi menggambarkan kondisi sekarang**. Ringkasan
> penyelesaiannya ada tepat setelah daftar.

1. **Database dev hampir kosong**: 3 user (semuanya CEO), 1 client, **0 content
   item**, **0 content plan**, **0 api_integration**. Akibatnya semua halaman
   daftar di atas render dalam kondisi *empty state* — halaman detail (Detail
   Konten, Detail Rencana Konten, Detail Klien dengan data) **belum pernah
   ter-render dengan data nyata** dalam audit ini.
2. **Hanya role CEO yang diverifikasi runtime.** Perilaku Manager, SMO,
   Copywriter, Content Creator, Graphic Designer disimpulkan dari
   `PermissionSeeder` + `components/sidebar.blade.php`, bukan dari login nyata.
3. **Tidak ada aksi tulis yang diuji.** Semua temuan tentang tombol Simpan /
   Submit / Approve berbasis pembacaan kode, bukan eksekusi.
4. **Integrasi Instagram & TikTok belum pernah dipakai di lingkungan ini**
   (`api_integrations` = 0 baris). Kredensial `.env` sudah terisi lengkap, tapi
   OAuth, sync, dan matching belum pernah berjalan sekali pun.
5. **Scheduler & queue worker tidak berjalan.** Tabel `jobs` kosong, dan
   `routes/console.php` sendiri mencatat bahwa tidak ada cron/Task Scheduler
   terpasang. Semua sinkronisasi terjadwal praktis tidak aktif.
6. **Test suite tidak dijalankan.** `phpunit.xml` menunjuk `DB_DATABASE=digidaw`
   — database dev yang sama — dan `ClientPortalTest` memakai `RefreshDatabase`.
   Menjalankan `php artisan test` akan **menghapus data dev**. Ini sendiri
   dicatat sebagai temuan (lihat Bagian 22).
7. **Portal Klien tidak diverifikasi runtime** (butuh token client nyata).

**Bagaimana ketujuhnya diselesaikan (kondisi sekarang):**

| # | Keterbatasan audit pertama | Kondisi sekarang |
|---|---|---|
| 1 | Database dev hampir kosong | Berisi data realistis (10 user, 5 client, 15 rencana konten, 85 content item) dari `DemoSeeder`; halaman detail sudah ter-render dengan data nyata |
| 2 | Hanya role CEO diverifikasi | 6 role × 10 halaman diverifikasi lewat direct URL (`RoleAccessMatrixTest`, 63 kasus, memakai `PermissionSeeder` produksi) |
| 3 | Tidak ada aksi tulis yang diuji | Golden path, rejection path, dan revision path dijalankan penuh lewat routing + middleware + database sungguhan |
| 4 | Integrasi IG/TikTok belum pernah dipakai | Seluruh jalur yang bisa diuji tanpa consent manusia sudah diuji (`SocialIntegrationOAuthTest`); live consent tetap `EXTERNAL_BLOCKED` |
| 5 | Scheduler & queue tidak berjalan | `composer run dev` sekarang menjalankan scheduler + queue; deployment production tetap wajib mengonfigurasinya (lihat Bagian 22, KI-14) |
| 6 | Test suite menghapus data dev | Database `digidaw_testing` terpisah + hard safeguard di `tests/TestCase.php`; 148 test aman dijalankan |
| 7 | Portal Klien tidak diverifikasi runtime | Diverifikasi dengan token asli (read-only) + `ClientPortalTest` + langkah Portal Klien di `GoldenPathTest` |

---

# Bagian 1. Gambaran Umum Sistem

## 1.1 Tujuan aplikasi

523 Studio Platform adalah dashboard operasional untuk agensi konten media
sosial. Aplikasi ini menjawab empat pertanyaan yang setiap hari muncul di
operasional agensi:

1. **Bulan ini kita janji bikin konten apa saja untuk klien siapa?**
   → Rencana Konten (target per klien, per bulan, mengikuti paket langganannya)
2. **Konten mana yang sedang dikerjakan siapa, dan sudah sampai tahap apa?**
   → Produksi (papan alur produksi dari "Siap Dikerjakan" sampai "Sudah Tayang")
3. **Klien sudah setuju belum?**
   → Portal Klien (klien membuka link sendiri, menyetujui atau minta revisi)
4. **Setelah tayang, hasilnya bagaimana?**
   → Performa (views, engagement, audiens, ditarik otomatis dari Instagram &
   TikTok, atau di-import manual dari CSV)

Di sekelilingnya ada fungsi pendukung: pengelolaan klien & paket, pengelolaan
tim & pembagian klien, absensi harian, laporan yang bisa dikirim ke klien, dan
beberapa fitur bantuan AI (penyusunan brief produksi & rekomendasi strategi
konten).

## 1.2 Aktor sistem

Ada **dua jenis pengguna yang secara teknis sangat berbeda**, dan ini harus
dijelaskan eksplisit di buku panduan karena mudah disalahpahami:

### A. Pengguna Internal (tim 523 Studio)

- Punya akun di sistem (tabel `users`).
- Masuk lewat **tombol "Masuk dengan Google"** — tidak ada login email+password
  sama sekali.
- Punya satu atau lebih **Role** yang menentukan menu & tombol apa yang muncul.
- Bisa dibatasi hanya melihat klien tertentu (lihat "Client scope" di Bagian 2).

### B. Klien (pihak eksternal)

- **BUKAN user Laravel.** Tidak punya akun, tidak punya password, tidak muncul
  di Kelola Pengguna, dan tidak pernah bisa masuk ke dashboard internal.
- Akses klien sepenuhnya lewat **satu link permanen** (Portal Klien). Link itu
  sendiri **adalah** kredensialnya — siapa pun yang memegang link tersebut punya
  akses penuh ke portal klien itu.
- Implementasi: kolom `clients.portal_token` + middleware `ResolveClientPortal`.
  Tidak ada session, tidak ada logout, tidak ada magic-link berbatas waktu.

> **Catatan penting untuk penulis buku:** jangan menyebut klien sebagai "user"
> atau "akun klien". Istilah yang benar: **"Portal Klien"** dan **"link Portal
> Klien"**. Konsekuensi keamanannya dibahas di Bagian 20.

Ada juga **satu "aktor" non-manusia** yang perlu disebut: **penjadwal otomatis
(scheduler)** yang menjalankan sinkronisasi analytics harian, penandaan konten
terlambat tiap jam, dan perhitungan skor risiko keterlambatan. Ini bukan menu,
tapi menjelaskan kenapa angka tertentu berubah sendiri tanpa ada yang menekan
tombol. Status: **`READY` di sisi aplikasi** — seluruh perintah terjadwal sudah
terdaftar dan `composer run dev` menjalankan scheduler + queue worker sekaligus.
Yang tetap perlu diperhatikan: ini **dependensi runtime**, artinya di server
production scheduler & queue worker wajib dikonfigurasi (cron/Supervisor).
Kalau keduanya mati, proses otomatis ini diam tanpa pesan error — itu materi
Panduan Administrator, bukan Buku Panduan Pengguna (lihat Bagian 19 & 22).

---

# Bagian 2. Role dan Permission

Sistem punya **6 role**, didefinisikan di `App\Enums\UserRole` dan dibuat oleh
`RoleSeeder`. **Satu orang bisa memegang lebih dari satu role sekaligus**
(relasi many-to-many lewat tabel `user_roles`) — misalnya seseorang bisa
sekaligus Manager dan SMO, dan akan mendapat gabungan hak akses keduanya.

Aturan khusus yang berlaku lintas role:

- **CEO dan Manager selalu melihat SEMUA klien.** Empat role lainnya hanya
  melihat klien yang secara eksplisit ditugaskan kepada mereka lewat
  **Kelola Pengguna → Assign Klien** (atau, dari arah sebaliknya,
  **Detail Klien → PIC Ditugaskan**).
  *Implementation: `User::canSeeAllClients()`, middleware `client.scope`.*
- Kalau seorang staf belum di-assign ke klien mana pun, **halaman Rencana
  Konten dan Produksi akan tampak kosong baginya** — ini bukan bug, ini efek
  scoping.

---

## CEO

**Tujuan utama role**
Pemilik keputusan tertinggi. Melihat gambaran menyeluruh seluruh klien, tim, dan
performa; punya akses ke semua fungsi tanpa kecuali.

**Menu yang dapat dilihat**
Beranda · Dashboard · Performa · Rencana Konten · Produksi · Performa Tim ·
Kelola Pengguna · Kelola Klien · Laporan · Pengaturan · (tombol) Jobdesk Tambahan

**Aktivitas utama**
Semua aktivitas di sistem: menyetujui rencana konten, menyetujui konten,
mengoreksi status yang terlanjur salah, onboarding klien baru, mengatur paket
klien, mengundang & menonaktifkan anggota tim, menghubungkan akun Instagram &
TikTok klien, membuat laporan.

**Aktivitas yang tidak dapat dilakukan**
Tidak ada pembatasan. (Satu-satunya larangan teknis: tidak bisa menonaktifkan
akunnya sendiri.)

**Client scope**
Seluruh klien.

*Implementation: `PermissionSeeder` memberi CEO seluruh 55 permission (`'*'`).*

---

## Manager

**Tujuan utama role**
Menjalankan operasional harian: memastikan rencana bulanan tersusun, produksi
berjalan sesuai jadwal, dan tim punya beban kerja yang wajar.

**Menu yang dapat dilihat**
Beranda · Dashboard · Performa · Rencana Konten · Produksi · Performa Tim ·
Kelola Pengguna · Kelola Klien · Laporan · Pengaturan · (tombol) Jobdesk Tambahan

**Aktivitas utama**
Membuat & menyetujui rencana konten, menambahkan konten ke rencana, memindahkan
status produksi, menyetujui konten, mengoreksi status, mengelola klien & paket,
mengundang anggota tim & mengatur pembagian klien, membuat laporan.

**Aktivitas yang tidak dapat dilakukan**
Tidak bisa mencatat publikasi/menandai konten "Sudah Tayang" — itu wewenang SMO
(dan CEO). *Implementation: `publishing,manage` hanya CEO & SMO.*

**Client scope**
Seluruh klien.

---

## SMO (Social Media Officer)

**Tujuan utama role**
Menjaga sisi tayang & performa: memastikan konten yang sudah disetujui benar-
benar terjadwal dan terbit, lalu memantau hasilnya.

**Menu yang dapat dilihat**
Beranda · Dashboard · Performa · Rencana Konten · Produksi · Laporan · Pengaturan

**Aktivitas utama**
Memindahkan status produksi, menyetujui konten, **mencatat publikasi (satu-
satunya role selain CEO yang bisa menandai konten Sudah Tayang)**, menghubungkan
post Instagram/TikTok yang belum tertaut ke konten internal, memantau Performa,
menyetujui rencana konten, mengatur Data Pilihan & Integrasi di Pengaturan.

**Aktivitas yang tidak dapat dilakukan**
Tidak bisa **membuat** rencana konten baru atau menambah konten ke rencana
(hanya bisa menyetujui). Tidak bisa mengelola klien maupun anggota tim. Tidak
bisa membuka Performa Tim.

**Client scope**
**Hanya klien yang ditugaskan.** SMO memiliki akses ke Pengaturan dan Performa, 
tetapi data klien pada halaman tersebut tetap dibatasi sesuai klien yang ditugaskan. 
Pembatasan ini juga diterapkan pada Dashboard dan proses impor performa.

---

## Copywriter

**Tujuan utama role**
Menerjemahkan ide mentah menjadi brief produksi yang siap dikerjakan tim.

**Menu yang dapat dilihat**
Beranda · Rencana Konten · Produksi · (tombol) Jobdesk Tambahan

**Aktivitas utama**
Membuat rencana konten & menambahkan konten ke dalamnya, menyusun brief produksi
(dengan bantuan AI atau manual), menerapkan brief ke tim produksi, mengisi draft
caption.

**Aktivitas yang tidak dapat dilakukan**
**Tidak bisa memindahkan status produksi sama sekali** (`workflow,update` tidak
diberikan). Jadi Copywriter melihat papan Produksi tapi tidak bisa menggeser
kartu di dalamnya. Tidak bisa menyetujui apa pun, tidak bisa melihat
Dashboard/Performa/Laporan.

**Client scope**
Hanya klien yang ditugaskan.

**Catatan tampilan**
Beranda Copywriter memakai layout berbeda (`home/index-copywriter.blade.php`) —
isinya antrean brief yang belum diterapkan, bukan daftar task produksi.
*Implementation: `UserWorkSummaryService::isCopywriter()`.*

---

## Content Creator

**Tujuan utama role**
Mengeksekusi produksi konten video sesuai brief.

**Menu yang dapat dilihat**
Beranda · Rencana Konten · Produksi

**Aktivitas utama**
Mengerjakan konten yang ditugaskan, memperbarui status pekerjaan (Kerjakan
Konten → Konten Selesai), menandai footage sudah di-take, mengisi link file
hasil produksi, mengerjakan revisi, mem-pin konten yang jadi fokusnya.

**Aktivitas yang tidak dapat dilakukan**
Tidak bisa menyetujui konten, tidak bisa membuat rencana/konten baru, tidak bisa
mencatat publikasi, tidak bisa melihat Dashboard/Performa/Laporan/Performa Tim.

**Client scope**
Hanya klien yang ditugaskan.

---

## Graphic Designer

**Tujuan utama role**
Mengeksekusi produksi konten desain/carousel sesuai brief.

**Menu, aktivitas, batasan, dan client scope: identik dengan Content Creator.**
Perbedaannya bukan di hak akses, melainkan di **penugasan otomatis**: saat AI
Strategy diterapkan ke Rencana Konten, konten bertipe "Desain" diarahkan ke role
Graphic Designer dan bertipe "Video" ke Content Creator.
*Implementation: `PicAssignmentService::$roleByContentType`.*

---

# Bagian 3. Matriks Role dan Fitur

Legenda: ✓ = bisa · (kosong) = tidak bisa · ⚠ = bisa, tapi ada catatan ·
Klien = pengguna Portal Klien (bukan role Laravel)

| Fitur | CEO | Manager | Copywriter | Content Creator | Graphic Designer | SMO | Klien |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Melihat Beranda | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| Melihat Dashboard | ✓ | ✓ | | | | ✓ | |
| Melihat Rencana Konten | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| Membuat Rencana Konten | ✓ | ✓ | ✓ | | | | |
| Mengajukan Rencana Konten | ✓ | ✓ | ✓ | | | | |
| Menyetujui/Menolak Rencana | ✓ | ✓ | | | | ✓ | |
| Menambah Konten ke Rencana | ✓ | ✓ | ✓ | | | | |
| Jobdesk Tambahan (mendadak) | ✓ | ✓ | ✓ | | | | |
| Melihat papan Produksi | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| Mengubah status produksi | ✓ | ✓ | | ✓ | ✓ | ✓ | |
| Menyetujui konten (internal) | ✓ | ✓ | | | | ✓ | |
| Koreksi Status | ✓ | ✓ | | | | | |
| Membuat Brief dengan AI | ✓ | ✓ | ✓ | | | | |
| Mengedit brief manual | ✓ | ✓ | ✓ | | | | |
| Mengisi draft caption | ✓ | ✓ | ✓ | | | | |
| Menambah catatan revisi | ✓ | ✓ | | ✓ | ✓ | ✓ | ✓ |
| Mencatat publikasi (Sudah Tayang) | ✓ | | | | | ✓ | |
| Menghubungkan post IG/TikTok manual | ✓ | | | | | ✓ | |
| Melihat Performa/Analytics | ✓ | ✓ | | | | ✓ | ⚠ |
| Menggunakan AI Strategy | ✓ | ✓ | | | | ✓ | |
| Ekspor CSV performa | ✓ | ✓ | | | | ✓ | |
| Import CSV performa | ✓ | ✓ | | | | ✓ | |
| Melihat Laporan & generate | ✓ | ✓ | | | | ✓ | |
| Melihat detail 1 klien | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| Mengelola Klien (tambah/edit/hapus) | ✓ | ✓ | | | | | |
| Mengubah Paket Klien | ✓ | ✓ | | | | | |
| Mengatur link Portal Klien | ✓ | ✓ | | | | | |
| Menghubungkan Instagram/TikTok klien | ✓ | ✓ | | | | | |
| Mengelola Pengguna (undang/nonaktif/role) | ✓ | ✓ | | | | | |
| Melihat Performa Tim | ✓ | ✓ | | | | | |
| Melihat rekap Kehadiran seluruh tim | ✓ | ✓ | | | | | |
| Absen check-in/check-out sendiri | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| Mengatur Data Pilihan & Paket | ✓ | ✓ | | | | ✓ | |
| Menyetujui konten dari sisi klien | | | | | | | ✓ |
| Meminta revisi dari sisi klien | | | | | | | ✓ |

### Catatan matriks

- **Menambah Konten ke Rencana & Jobdesk Tambahan** — berfungsi normal
  (KI-01 & KI-02 sudah diperbaiki, punya regression test). Boleh dijadikan
  tutorial.
- **Import CSV performa (SMO)** — sekarang dibatasi ke klien roster SMO
  (KI-09 diperbaiki, `ImportPerformanceScopeTest`).
- **⚠ Klien melihat Analytics** — klien hanya melihat data klien-nya sendiri,
  dalam bentuk sederhana tanpa metrik operasional internal.
- **Melihat detail 1 klien** dibuka ke semua role internal secara sengaja (biar
  hasil pencarian tidak buntu 403), tapi tetap dibatasi ke klien yang di-assign,
  dan tombol-tombol ubah data di halaman itu tidak aktif untuk role yang tidak
  punya hak.
- Tanda **kosong pada CEO** hanya muncul di baris "Menyetujui konten dari sisi
  klien" / "Meminta revisi dari sisi klien" — itu memang aksi milik Portal
  Klien, bukan aksi internal.

---

# Bagian 4. Navigasi dan Menu

Sidebar dibangun dari daftar statis di `components/sidebar.blade.php` lalu
di-filter per permission. **Grup yang seluruh isinya tidak boleh diakses akan
hilang sepenuhnya**, jadi jumlah grup yang terlihat berbeda-beda per role.

## CEO & Manager (identik)

**Ringkasan**
- Beranda
- Dashboard
- Performa

**Konten**
- Rencana Konten
- Produksi
- *(tombol merah)* Jobdesk Tambahan

**Tim**
- Performa Tim
- Kelola Pengguna

**Klien**
- Kelola Klien

**Laporan**
- Laporan

**Sistem**
- Pengaturan

## SMO

**Ringkasan** — Beranda · Dashboard · Performa
**Konten** — Rencana Konten · Produksi
**Laporan** — Laporan
**Sistem** — Pengaturan

*(Tidak ada grup Tim maupun Klien. Tidak ada tombol Jobdesk Tambahan.)*

## Copywriter

**Ringkasan** — Beranda
**Konten** — Rencana Konten · Produksi · *(tombol)* Jobdesk Tambahan

## Content Creator & Graphic Designer

**Ringkasan** — Beranda
**Konten** — Rencana Konten · Produksi

## Bagian bawah sidebar (semua role)

- Pemilih tema: **Terang / Gelap / Ikut Sistem**
- **Logout**

## Topbar (semua role)

- Kolom **pencarian global** (klien, anggota tim, judul konten)
- Ikon **notifikasi** dengan penanda jumlah belum dibaca

## Halaman yang bisa diakses tapi TIDAK ada di sidebar

Ini penting untuk buku panduan: beberapa halaman hanya bisa dicapai lewat klik
dari halaman lain, bukan dari menu.

| Halaman | Cara mencapainya | Catatan |
|---|---|---|
| Detail Rencana Konten | Klik baris di Rencana Konten | |
| Tambah Konten (ke rencana) | Tombol di Detail Rencana Konten | |
| Detail Konten | Klik kartu di Produksi / hasil pencarian | Halaman kerja utama tiap konten |
| Detail Klien | Klik baris di Kelola Klien / hasil pencarian | |
| Tambah Klien / Edit Klien | Tombol di Kelola Klien | |
| Profil anggota tim | Klik nama di Performa Tim / hasil pencarian | |
| Detail Performa 1 konten | Klik baris di tab Tabel Performa | |
| Riwayat AI Strategy | Link di panel AI Strategy (halaman Performa) | |
| Import Data Performa | Link dari Pengaturan → Integrasi | |
| ~~**Publishing Tracker** (`/publishing-tracker`)~~ | URL lama, sekarang **redirect** ke Produksi → tab "Sudah Tayang" | Bukan halaman terpisah lagi |
| ~~**Revision Log** (`/revision-log`)~~ | URL lama, sekarang **redirect** ke Produksi → tab "Revisi" | Bukan halaman terpisah lagi |
| Unmatched Instagram Media | Link dari kartu Integrasi / Tabel Performa | |
| Unmatched TikTok Video | Link dari kartu Integrasi | |

> **Rekomendasi untuk buku:** dokumentasikan **Produksi → tab Revisi** dan
> **Produksi → tab Sudah Tayang** sebagai jalur resmi. Jangan dokumentasikan
> `/revision-log` dan `/publishing-tracker` sebagai halaman terpisah — kedua URL
> lama itu sekarang **mengarahkan otomatis** ke tab resmi di Produksi (KI-12
> diperbaiki, `LegacyRouteRedirectTest`), jadi bookmark lama tetap aman.

---

# Bagian 5. Workflow Utama Konten

Alur produksi punya **8 status**. Label internal → label yang tampil ke pengguna
didefinisikan sekali di `App\Support\WorkflowTransitions` dan dipakai konsisten
di semua halaman.

```text
                    Siap Dikerjakan
                          │
                          ▼
                   Sedang Dikerjakan  ◄─────────────┐
                          │                         │
                          ▼                         │
                  Menunggu Persetujuan              │
                     │          │                   │
            ┌────────┘          └────────┐          │
            ▼                            ▼          │
        Disetujui                   Perlu Revisi ────┘
            │
            ▼
     Terjadwal Tayang
            │
            ▼
       Sudah Tayang          (status akhir)


   Semua status di atas, kecuali dua status akhir,
   bisa dipindahkan ke:  Dibatalkan   (status akhir)
```

**Transisi yang diizinkan (tidak ada jalur lain):**

| Dari | Ke |
|---|---|
| Siap Dikerjakan | Sedang Dikerjakan · Dibatalkan |
| Sedang Dikerjakan | Menunggu Persetujuan · Dibatalkan |
| Menunggu Persetujuan | Disetujui · Perlu Revisi · Dibatalkan |
| Perlu Revisi | Sedang Dikerjakan · Dibatalkan |
| Disetujui | Terjadwal Tayang · Dibatalkan |
| Terjadwal Tayang | Sudah Tayang · Dibatalkan |
| Sudah Tayang | — (akhir) |
| Dibatalkan | — (akhir) |

## Detail per status

### Siap Dikerjakan (`brief_ready`)

**Makna bagi user** — Konten sudah tercatat di sistem dan sudah punya penanggung
jawab, tapi belum ada yang mulai menggarapnya.
**Siapa yang biasanya bertindak** — Penanggung Jawab (Content Creator / Desain
Grafis), atau Manager/SMO.
**Langkah berikutnya** — Tekan **Kerjakan Konten** untuk pindah ke Sedang
Dikerjakan. Kalau brief belum ada, Copywriter menyusunnya lebih dulu.

### Sedang Dikerjakan (`in_progress`)

**Makna bagi user** — Sedang digarap. Di tahap ini PIC bisa menandai **footage
sudah di-take** (khusus video) dan mengisi **link file hasil produksi**.
**Siapa yang biasanya bertindak** — Penanggung Jawab.
**Langkah berikutnya** — **Konten Telah Selesai** → Menunggu Persetujuan.

### Menunggu Persetujuan (`waiting_review`)

**Makna bagi user** — Hasil kerja siap dinilai. Di titik inilah konten **muncul
di Portal Klien** untuk disetujui klien.
**Siapa yang biasanya bertindak** — Klien (lewat Portal Klien) memberi sinyal
setuju atau minta revisi; lalu Manager/SMO/CEO yang memutuskan secara internal.
**Langkah berikutnya** — **Approve Konten** → Disetujui, atau tambahkan catatan
revisi → Perlu Revisi.

> **Penting & sering disalahpahami:** persetujuan klien di Portal **tidak
> mengubah status**. Klien menekan "Setuju", sistem hanya mencatat
> `client_reviewed_at` + hasilnya, lalu mengirim notifikasi ke Manager & SMO.
> Perpindahan ke **Disetujui** tetap dilakukan manual oleh tim internal.
> Sebaliknya, klien yang **meminta revisi** MEMANG langsung memindahkan status
> ke **Perlu Revisi**. Dua aksi klien ini asimetris — jelaskan di buku.

### Perlu Revisi (`revision`)

**Makna bagi user** — Ada catatan perbaikan yang harus dikerjakan. Bisa ada
beberapa catatan sekaligus.
**Siapa yang biasanya bertindak** — Penanggung Jawab.
**Langkah berikutnya** — Tekan **Kerjakan Revisi** → status kembali ke Sedang
Dikerjakan, dan semua catatan revisi yang berstatus terbuka otomatis ditandai
sedang dikerjakan.

### Disetujui (`approved`)

**Makna bagi user** — Konten lolos review, tinggal dijadwalkan tayang.
**Siapa yang biasanya bertindak** — SMO.
**Langkah berikutnya** — Isi tanggal & jam rencana upload → **Jadwalkan Upload**.

**Syarat yang dicek sistem sebelum Approve:**
- Tidak boleh ada catatan revisi yang masih terbuka/sedang dikerjakan.
- Penekan tombol harus punya hak menyetujui (CEO, Manager, atau SMO).
- Kalau klien belum merespons, tombol **tetap bisa ditekan**, tapi muncul
  peringatan "Klien belum merespons konten ini."

### Terjadwal Tayang (`scheduled`)

**Makna bagi user** — Sudah ada tanggal & jam tayang yang direncanakan.
**Siapa yang biasanya bertindak** — SMO.
**Langkah berikutnya** — Setelah benar-benar diposting, catat publikasinya
(platform, tanggal terbit, link postingan, caption final) → Sudah Tayang.

> Konten di status ini **masih bisa ditandai terlambat** kalau deadline lewat
> sebelum benar-benar tayang. Ini disengaja.

### Sudah Tayang (`uploaded`)

**Makna bagi user** — Selesai. Konten dianggap tidak lagi jadi pekerjaan aktif,
otomatis dilepas dari daftar pin semua orang, dan mulai dihitung di Performa.
**Langkah berikutnya** — Tidak ada. Status akhir.

### Dibatalkan (`cancelled`)

**Makna bagi user** — Konten tidak jadi diproduksi. Tidak bisa dikembalikan ke
alur produksi lewat jalur normal.
**Langkah berikutnya** — Tidak ada. Kalau ternyata salah batal, satu-satunya
jalan adalah **Koreksi Status** oleh Manager/CEO.

## Koreksi Status (jalur khusus)

**Hanya Manager & CEO.** Memindahkan konten ke status **mana pun**, mengabaikan
seluruh aturan transisi di atas, dengan alasan yang wajib diisi. Dicatat
terpisah sebagai `correction` sehingga tidak ikut terhitung sebagai revisi tim
di Performa Tim.

Gunakan untuk: status yang terlanjur salah digeser, atau konten yang salah
dibatalkan.

---

# Bagian 6. Content Plan (UI: **Rencana Konten**)

## Konsep

Satu **Rencana Konten** = satu klien × satu bulan × satu tahun. Di dalamnya ada
banyak **konten** (content item). Setiap rencana menyimpan salinan kuota paket
klien saat rencana dibuat, yang tampil sebagai **Target** (misalnya "Target: 8
Konten / 4 Desain").

Kalau klien belum punya paket tercatat, rencana **tetap boleh dibuat** — target-
nya saja yang kosong. (Ini perubahan disengaja; dulu diblokir.)

## Siklus hidup Rencana Konten

```text
Draf ──(Ajukan Rencana)──► Menunggu Persetujuan ──┬──► Disetujui
  ▲                                                │
  └──(Kembalikan ke Draf & Perbaiki)───────────────┴──► Ditolak
```

- **Draf** — baru dibuat, masih bisa diisi konten.
- **Menunggu Persetujuan** — sudah diajukan; penyetuju mendapat notifikasi.
- **Disetujui** — keputusan final.
- **Ditolak** — disertai **alasan penolakan yang wajib diisi**, dan **bisa
  dibuka kembali** ke Draf untuk diperbaiki lalu diajukan ulang.

**Jalur perbaikan rencana yang ditolak (KI-13, sudah tersedia):** Ditolak →
**Kembalikan ke Draf & Perbaiki** → Draf → perbaiki/tambah konten → **Ajukan Rencana** →
Menunggu Persetujuan → Disetujui. Seluruh transisi tercatat di panel **Riwayat
Keputusan** (tabel `content_plan_status_logs`), termasuk alasan penolakan — yang
**tidak hilang** setelah rencana dibuka kembali. Rencana tidak diduplikasi;
baris rencananya tetap sama. Terverifikasi lewat `ContentPlanTest`.

## Aktivitas

### Melihat Rencana Konten

**Role** — Semua role internal
**Entry point** — Sidebar → Rencana Konten
**Precondition** — Untuk role non-CEO/Manager: sudah di-assign ke minimal 1 klien
**Langkah user** — Pilih bulan & tahun · pilih klien (opsional) · pilih tampilan
**Tabel** atau **Kalender**
**Expected result** — Daftar rencana beserta jumlah konten dan capaian vs target;
tampilan Kalender menampilkan konten per tanggal deadline, dikelompokkan per
klien, dibatasi tipe **Video** dan **Desain** saja
**Permission** — `content_plan,view`
**Client scope** — Dibatasi roster, kecuali CEO/Manager
**Status** — `READY`

### Membuat Rencana Konten

**Role** — CEO, Manager, Copywriter
**Entry point** — Tombol di halaman Rencana Konten
**Precondition** — Klien sudah ada dan berstatus aktif
**Langkah user** — Pilih klien · pilih bulan · pilih tahun · Simpan
**Expected result** — Rencana baru berstatus **Draf**, langsung diarahkan ke
halaman detailnya
**Permission** — `content_plan,create`
**Status** — `READY`

### Menambah Konten ke Rencana

**Role** — CEO, Manager, Copywriter
**Entry point** — Detail Rencana Konten → tombol Tambah Konten
**Precondition** — PIC yang dipilih **harus sudah di-assign ke klien rencana ini**
**Langkah user** — Isi judul · brief singkat · pilar konten · tipe konten ·
platform · deadline · Penanggung Jawab · Simpan
**Expected result** *(secara desain)* — Konten baru muncul di papan Produksi
dengan status **Siap Dikerjakan**, PIC tercatat sebagai penanggung jawab utama
**Permission** — `content_plan,create`
**Status** — `READY` (KI-01 diperbaiki; `ContentPlanTest`)

### Jobdesk Tambahan (permintaan mendadak)

**Role** — CEO, Manager, Copywriter
**Entry point** — Tombol merah di sidebar (tersedia dari halaman mana pun)
**Tujuan** — Permintaan mendadak dari klien (dokumentasi acara, liputan, dsb.)
yang harus langsung masuk produksi tanpa lewat perencanaan bulanan
**Langkah user** — Pilih klien · judul · tipe · platform · deadline · Penanggung
Jawab (opsional) · catatan · Simpan
**Expected result** — Sistem otomatis mencari/membuat rencana bulan berjalan
untuk klien itu, konten ditandai **mendesak**, PIC langsung mendapat notifikasi
**Status** — `READY` (KI-02 diperbaiki; `ContentPlanTest`)

### Mengajukan Rencana (Ajukan Rencana)

**Role** — CEO, Manager, Copywriter · **Permission** `content_plan,create`
**Precondition** — Status harus **Draf**
**Expected result** — Status jadi **Menunggu Persetujuan**; semua pemegang hak
menyetujui (kecuali pembuatnya sendiri) mendapat notifikasi
**Status** — `READY`

### Menyetujui / Menolak Rencana

**Role** — CEO, Manager, SMO · **Permission** `content_plan,approve`
**Precondition** — Status harus **Menunggu Persetujuan**
**Langkah user** — Tekan **Setujui**, atau tekan **Tolak** lalu isi **alasan
penolakan** (wajib) di modal yang muncul
**Expected result** — Status jadi **Disetujui** atau **Ditolak**, tercatat siapa
yang memutuskan beserta alasannya di panel **Riwayat Keputusan**
**Status** — `READY`

### Kembalikan ke Draf & Perbaiki (rencana yang ditolak)

**Role** — CEO, Manager, Copywriter · **Permission** `content_plan,create`
**Entry point** — Detail Rencana Konten berstatus **Ditolak** → tombol
**Kembalikan ke Draf & Perbaiki**
**Expected result** — Status kembali ke **Draf**, konten bisa ditambah/diperbaiki,
lalu diajukan ulang. Alasan penolakan tetap tersimpan di Riwayat Keputusan
**Status** — `READY` (KI-13 diperbaiki; `ContentPlanTest`)

## Hubungan dengan entitas lain

- **Rencana Konten → Konten** — satu ke banyak. Konten wajib punya rencana induk;
  itulah sebabnya Jobdesk Tambahan membuatkan rencana bulan berjalan otomatis.
- **Rencana Konten → Paket Klien** — kuota disalin sebagai *snapshot* saat
  rencana dibuat, jadi mengganti paket klien tidak mengubah target rencana lama.
- **Rencana Konten → AI Strategy** — tombol **Terapkan** di AI Strategy membuat
  draft konten massal ke dalam rencana bulan berjalan (lihat Bagian 14).
- **Konten → Brief** — satu konten punya paling banyak satu brief (Bagian 7).

## Aturan tanggal/periode

- Bulan & tahun rencana dipilih manual; tahun minimal 2020, tidak ada batas atas.
- Deadline konten bebas, tidak divalidasi harus berada di dalam bulan rencana.
- Tampilan Kalender memakai **tanggal deadline**, bukan tanggal tayang.
- Konten yang lewat deadline ditandai **terlambat** oleh proses terjadwal tiap
  jam (`workflow:update-overdue`). Perintahnya terdaftar dan `composer run dev`
  menjalankan scheduler; di server production scheduler wajib dikonfigurasi —
  kalau tidak, penandaan terlambat tidak pernah jalan (Bagian 22, KI-14).

---

# Bagian 7. AI Brief (UI: **AI Brief Execution Assistant**)

## Apa fungsinya bagi pengguna

Mengubah ide mentah (judul + catatan singkat di konten) menjadi **brief produksi
lengkap**: hook/judul, tanggal mulai & tanggal posting, platform, rincian per
adegan/slide (visual + naskah), talent, properti, estimasi durasi/jumlah slide,
tingkat kerumitan, plus **penilaian kelayakan jadwal** yang membandingkan
rencana posting dengan deadline dan beban kerja PIC minggu itu.

Mesinnya: Google Gemini (`gemini-flash-lite-latest`).

## Siklus hidup brief

```text
(belum ada) ──Buat Brief──► Draf ──┬──Diskusi──► Sedang Didiskusikan
                                    │                  │
                                    │◄──Terapkan Perubahan / Edit Manual
                                    │
                                    └──Terapkan ke Tim──► Final (terkunci)
                                                              │
                                                        Tarik Kembali
                                                              │
                                                              ▼
                                                   Sedang Didiskusikan
```

## Aksi yang tersedia

| Aksi | Arti bagi pengguna | Catatan |
|---|---|---|
| **Buat Brief** | Susun brief pertama kali dari ide mentah | Kalau brief sudah ada, tidak digenerate ulang (hemat biaya API) |
| **Susun Ulang** (regenerate) | Buang isi brief, susun ulang dari nol | Kondisi sebelumnya disimpan supaya bisa dikembalikan |
| **Diskusi** | Tanya/minta penyesuaian ke AI | AI membalas + **mengusulkan** perubahan, belum menerapkannya |
| **Terapkan Perubahan** | Menerapkan usulan hasil diskusi ke brief | |
| **Edit Manual** | Ubah hook, platform, talent, properti, dan isi tiap adegan tanpa AI | Untuk perubahan kecil, tanpa biaya API |
| **Kembalikan** (revert) | Undo **satu langkah** ke kondisi sebelumnya | Hanya satu langkah; setelah dipakai, riwayatnya hilang |
| **Terapkan ke Tim** (finalize) | Brief dikunci & PIC produksi dapat notifikasi | Setelah ini brief read-only |
| **Tarik Kembali** (withdraw) | Buka kembali brief yang sudah dikunci | |

Semua aksi kecuali Terapkan ke Tim & Tarik Kembali akan ditolak kalau brief
sudah terkunci.

## Bagaimana konteks/prompt disusun

Prompt dibangun di `BriefGenerationService::buildGeneratePrompt()` dari:
judul konten, brief mentah, nama tipe konten, nama platform, **tanggal hari
ini**, dan **deadline konten**. Model diminta menentukan tanggal relatif
terhadap tanggal hari ini yang diberikan eksplisit, dan dilarang mengembalikan
tanggal sebelum hari ini atau tahun selain tahun berjalan.

Prompt bukan satu-satunya pengaman. Sebelum disimpan, seluruh tanggal hasil AI
melewati `sanitizeDates()` di backend: tanggal yang tidak valid, di masa lalu,
atau lebih dari 90 hari ke depan **ditolak dan diganti nilai deterministik**
(mulai besok, posting beberapa hari sesudahnya). Sanitasi ini berjalan di
`generate()`, `regenerate()`, **dan** saat menerapkan perubahan hasil diskusi —
jadi tidak ada jalur yang bisa menyelipkan tanggal ngawur ke database.

Penilaian kelayakan (`assessFeasibility()`) memakai data nyata: `deadline_at`
konten, tanggal posting hasil AI **yang sudah disanitasi**, dan jumlah konten
aktif lain milik tiap PIC pada minggu deadline yang sama. Karena tanggalnya
sudah valid, hasil penilaian kelayakan sekarang bermakna.

**Status:** `READY` (KI-07 diperbaiki; `BriefGenerationDateTest` menguji jalur
tanggal invalid dan tanggal valid, dengan Gemini di-fake).

## HISTORIS — KONDISI SEBELUM STABILISASI: investigasi tanggal brief 2024

> ⚠️ **Sub-bagian ini adalah riwayat temuan, BUKAN kondisi aplikasi sekarang.**
> Bug ini (KI-07) **sudah diperbaiki** dan punya regression test. Lihat
> "Bagaimana konteks/prompt disusun" di atas untuk kondisi terkini. Disimpan
> karena akar masalahnya (LLM diminta tanggal relatif tanpa titik acuan)
> berguna sebagai pelajaran rekayasa.

**Observed / suspected issue** *(saat audit pertama)*
Brief yang dihasilkan AI dapat menampilkan **Tanggal Mulai** dan **Tanggal
Posting** dengan tahun 2024 (atau tahun lampau lain), padahal konteks aplikasi
berada di tahun berjalan.

**Likely cause — TERKONFIRMASI dari kode, bukan dugaan**
Prompt meminta model menentukan tanggal secara relatif:

> `start_date: perkiraan tanggal mulai produksi (format YYYY-MM-DD), asumsikan mulai besok`
> `post_date: perkiraan tanggal posting (format YYYY-MM-DD), 3-5 hari setelah start_date`

…tetapi **prompt tidak pernah memberi tahu model tanggal hari ini.** Model
bahasa tidak punya jam; ketika diminta "besok" tanpa titik acuan, ia mengarang
tanggal dari kebiasaan data latihnya — yang umumnya jatuh di 2024.

Diperparah oleh: nilai `start_date`/`post_date` dari AI **disimpan apa adanya
tanpa validasi atau pembatasan rentang** di `generate()` dan `regenerate()`.

Pemeriksaan tambahan yang sudah dilakukan:
- Tidak ada tahun yang di-hardcode di mana pun dalam `app/` (dicek menyeluruh).
- Bukan masalah timezone — tidak ada konversi zona waktu pada field ini.
- Bukan dari seed/demo data — brief hanya dibuat lewat pemanggilan AI.
- **AI Strategy tidak terdampak** — semua tanggal di sana dihitung di PHP dengan
  `Carbon::now()`, bukan diminta ke AI.

**User impact**
1. Kolom **Tanggal Mulai** dan **Tanggal Posting** di kartu brief menampilkan
   tanggal yang salah dan membingungkan.
2. **Lebih serius:** tanggal itu jadi masukan penilaian kelayakan. Tanggal
   posting tahun 2024 dibandingkan dengan deadline 2026 menghasilkan selisih
   ratusan hari "MELEWATI deadline", sehingga penilaian kelayakan hampir selalu
   keluar sebagai **critical** dengan alasan yang tidak masuk akal.

**Relevant files**
- `app/Services/BriefGenerationService.php:145-218` — `buildGeneratePrompt()`
  (sumber masalah)
- `app/Services/BriefGenerationService.php:34-93` — `generate()` / `regenerate()`
  (menyimpan tanpa validasi)
- `app/Services/BriefGenerationService.php:227-317` — `assessFeasibility()` /
  `buildFeasibilityPrompt()` (dampak lanjutan)
- `resources/views/content-items/partials/ai-brief.blade.php:232,238` — tempat
  tanggal ini terlihat pengguna

**Confidence** — **Tinggi.** Penyebabnya terbaca langsung dari prompt; tidak ada
mekanisme lain di jalur ini yang bisa menghasilkan tanggal.

**Bagaimana temuan ini diselesaikan** — dua lapis, bukan satu:
1. **Prompt** sekarang menyuntikkan tanggal hari ini + deadline konten secara
   eksplisit, dan melarang tanggal di masa lalu / tahun selain tahun berjalan.
2. **Backend** (`sanitizeDates()`) memvalidasi dan mengganti tanggal di luar
   rentang wajar dengan nilai deterministik — sehingga perbaikan tidak
   bergantung pada kepatuhan model bahasa.
Dampak lanjutannya (penilaian kelayakan yang selalu *critical*) ikut hilang,
karena sanitasi berjalan **sebelum** `assessFeasibility()`.

## Feature Record

**Status:** `READY`
**Digunakan oleh:** CEO, Manager, Copywriter
**Tujuan:** Mengubah ide mentah jadi brief produksi siap eksekusi
**Entry point:** Detail Konten → kartu "AI Brief Execution Assistant"
**Precondition:** Konten sudah ada; `GEMINI_API_KEY` terisi (**terverifikasi
terisi**)
**Expected result:** Brief tersusun dengan tanggal yang masuk akal, bisa
didiskusikan/diedit, lalu dikunci dan PIC produksi dapat notifikasi
**Permission:** `content_plan,create` (+ `client.scope`)
**Dependencies:** Google Gemini API (jaringan keluar)
**Known issues:** Tidak ada. KI-07 (tanggal) dan KI-03 (halaman induk) sudah
diperbaiki dan punya regression test masing-masing.
**Catatan untuk buku:** tetap anjurkan pengguna membaca ulang hasil AI sebelum
menerapkan brief ke tim — bukan karena ada bug, tapi karena itu praktik yang
wajar untuk keluaran AI mana pun (hook, adegan, talent, properti tetap perlu
penilaian manusia).
**Relevant implementation:** `ContentBriefController`, `BriefGenerationService`
(`sanitizeDates()`), `content-items/partials/ai-brief.blade.php`,
`ai-brief-discussion.blade.php`
**Documentation recommendation:** Boleh ditulis lengkap sekarang, termasuk
screenshot bagian tanggal dan kartu kelayakan.

---

# Bagian 8. Produksi Konten (UI: **Produksi**)

Halaman Produksi punya **tiga tab**:

| Tab | Isi |
|---|---|
| **Papan Alur Produksi** | Semua konten aktif, sebagai Kanban atau Daftar |
| **Revisi** | Semua catatan revisi lintas konten |
| **Sudah Tayang** | Riwayat konten yang sudah dipublikasikan |

## Tab: Papan Alur Produksi

Dua tampilan yang bisa ditukar:

- **Papan (Kanban)** — 8 kolom sesuai status. Kartu bisa **digeser (drag & drop)**
  untuk memindahkan status. Kalau perpindahan butuh data tambahan (catatan
  revisi, jadwal upload, data publikasi), muncul modal isian dulu.
- **Daftar (List)** — tabel yang bisa diurutkan per kolom: Judul, Klien, Tipe,
  Status, PIC, Deadline, Risiko. Bisa difilter per status.

> Drag & drop memakai HTML5 Drag & Drop API yang **tidak berfungsi di layar
> sentuh**. Karena itu di perangkat mobile sistem otomatis mengarahkan ke
> tampilan Daftar. Sebutkan ini di buku panduan.

**Filter yang tersedia:** klien, bulan (berdasarkan deadline), status (khusus
tampilan Daftar).

**Konten yang di-pin selalu diapungkan ke atas**, baik di kolom Kanban maupun di
Daftar, terlepas dari urutan sort yang aktif.

**Status:** `READY` (terverifikasi runtime dengan data nyata)

## Detail Konten

Halaman kerja utama tiap konten. Isinya, dari atas ke bawah:

1. **Judul + penanda mendesak** kalau konten berasal dari Jobdesk Tambahan
2. **Kartu AI Brief** (Bagian 7)
3. **Informasi konten** — klien, tipe, platform, pilar, deadline, PIC
4. **Link file hasil produksi** — draft di Google Drive/Canva/dsb., diisi PIC
   setelah selesai mengedit, sebelum masuk review. **Berbeda dari link postingan
   live** yang baru diisi saat mencatat publikasi.
5. **Draft caption** — diisi Copywriter/Manager; caption inilah yang dibaca &
   disetujui klien di Portal Klien saat konten Menunggu Persetujuan
6. **Penanda footage sudah di-take** (khusus video, hanya saat Sedang
   Dikerjakan) — tanggalnya diisi manual karena syuting sering baru dicatat
   beberapa hari kemudian; bisa dibatalkan kalau salah klik
7. **Panel diskusi AI Brief**
8. **Aset klien** — link aset yang tercatat di data klien
9. **Status Management** — tombol-tombol perpindahan status
10. **Ganti Penanggung Jawab** — daftar kandidat, diurutkan dari yang task
    aktifnya paling sedikit
11. **Riwayat status**, **catatan revisi**, **riwayat publikasi**, dan
    **10 skor Delay Risk terakhir**
12. **Tombol Pin**

**Status:** `READY` — KI-03 diperbaiki (`ContentItemDetailTest` + verifikasi
runtime terhadap konten yang klien-nya punya staf ter-assign, yaitu persis
kondisi yang dulu bikin halaman ini gagal). Halaman ini adalah pusat alur
produksi: Status Management, AI Brief, caption, link file hasil, penanda
footage, dan Ganti PIC semuanya ada di sini, jadi jadikan ia bab tersendiri
di buku.

## Status Management (tombol perpindahan status)

Tombol yang muncul tergantung status saat ini:

| Status sekarang | Tombol | Yang boleh menekan |
|---|---|---|
| Siap Dikerjakan | **Kerjakan Konten** | pemegang `workflow,update` |
| Sedang Dikerjakan | **Konten Telah Selesai** | pemegang `workflow,update` |
| Menunggu Persetujuan | **Approve Konten** | CEO, Manager, SMO |
| Menunggu Persetujuan / Perlu Revisi | **Tambah Catatan Revisi** | pemegang `workflow,update` |
| Perlu Revisi | **Kerjakan Revisi** | pemegang `workflow,update` |
| Disetujui | **Jadwalkan Upload** (+ tanggal & jam) | pemegang `workflow,update` |
| Terjadwal Tayang | **Catat Publikasi** | CEO, SMO (`publishing,manage`) |
| Semua kecuali status akhir | **Batalkan Konten** | pemegang `workflow,update` |
| Semua | **Koreksi Status** (+ alasan wajib) | CEO, Manager |

Tombol yang tidak boleh ditekan **tetap terlihat tapi nonaktif**, dengan tooltip
penjelasan ("Kamu tidak punya izin memindahkan status"). Ini bagus untuk
dokumentasi — user tahu fitur itu ada dan siapa yang harus dimintai tolong.

**Status:** `READY` — guard-nya lengkap & konsisten, dan halaman induknya
(Detail Konten) sudah berfungsi normal.

## Ganti Penanggung Jawab

**Tujuan** — Memindahkan konten ke PIC lain, misalnya karena beban kerja atau
ketidakhadiran.
**Kandidat** — Hanya staf aktif yang **sudah di-assign ke klien konten itu**,
diurutkan dari yang task aktifnya paling sedikit, lengkap dengan jumlah task
aktif masing-masing.
**Efek** — PIC berubah, penugasan diperbarui, skor Delay Risk konten langsung
dihitung ulang, dan PIC baru mendapat notifikasi.
**Pengaman** — PIC baru **wajib** sudah di-assign ke klien konten itu; percobaan
memindahkan ke orang di luar tim klien ditolak.
**Status:** `READY` (KI-04 diperbaiki; `ContentItemDetailTest`)

## Alur dari perspektif tiap role

### Content Creator

1. Buka **Beranda** → lihat daftar task dan panel **Langkah Berikutnya**
2. Buka konten yang jadi tanggung jawabnya (dari Beranda atau papan Produksi)
3. **Kerjakan Konten** → status jadi Sedang Dikerjakan
4. Setelah syuting: tandai **footage sudah di-take** dengan tanggal sebenarnya
5. Setelah selesai edit: isi **link file hasil produksi**
6. **Konten Telah Selesai** → Menunggu Persetujuan
7. Kalau ada revisi: **Kerjakan Revisi** → ulangi dari langkah 5

### Graphic Designer

Identik dengan Content Creator, kecuali langkah 4 (penandaan footage) yang hanya
relevan untuk video.

### Copywriter

1. Buka **Beranda** (tampilan khusus: **antrean brief**)
2. Buka konten yang briefnya belum diterapkan
3. **Buat Brief** → review → **Diskusi** / **Edit Manual** seperlunya
4. **Terapkan ke Tim** → PIC produksi dapat notifikasi
5. Isi **draft caption** untuk dibaca klien nanti

> Copywriter **tidak bisa** menggeser status di papan Produksi — hanya melihat.

### Manager

1. Buka **Dashboard** → cek **Item Overdue** dan panel **Perlu Perhatian**
2. Cek **Konten Berisiko Tinggi** (prediktif — belum terlambat tapi berisiko)
3. Buka **Rencana Konten** → setujui rencana yang diajukan
4. Buka **Produksi** → review konten yang **Menunggu Persetujuan**
5. **Approve Konten**, atau tambahkan **catatan revisi**
6. Buka **Performa Tim** → cek beban kerja & tanda kelebihan beban (>5 task aktif)
7. Bila perlu: **Ganti Penanggung Jawab** atau **Koreksi Status**

### SMO

1. Buka **Produksi** → cari konten berstatus **Disetujui**
2. **Jadwalkan Upload** dengan tanggal & jam
3. Setelah benar-benar diposting: **Catat Publikasi** (platform, tanggal terbit,
   link postingan, caption final) → status jadi **Sudah Tayang**
4. Buka **Pengaturan → Integrasi**, jalankan sinkronisasi bila perlu
5. Cek **post yang belum tertaut** (Unmatched) dan hubungkan manual
6. Buka **Performa** untuk memantau hasil

## Konsep pendukung yang perlu dijelaskan di buku

| Konsep | Penjelasan untuk pengguna | Status |
|---|---|---|
| **Terlambat (overdue)** | Deadline sudah lewat tapi konten belum tayang/dibatalkan. Ditandai otomatis tiap jam. | `READY` — butuh scheduler berjalan (dependensi runtime, Bagian 22, KI-14) |
| **Mendesak (urgent)** | Konten dari Jobdesk Tambahan, ditandai khusus agar menonjol | `READY` |
| **Pin** | Penanda pribadi "ini fokus saya". Tidak terlihat orang lain, ada batas maksimal, otomatis lepas saat konten Sudah Tayang | `READY` |
| **Skor Risiko Keterlambatan** | Prediksi AI 0–100 seberapa berisiko konten ini telat, plus faktor utamanya | `OPERATIONALLY_SAFE_WITH_LIMITATION` — aman dipakai (kalau model/script gagal, sistem mencatat log lalu melewatinya, bukan crash); **akurasi model ML belum divalidasi** |
| **Akurasi Prediksi** | Seberapa sering prediksi risiko terbukti benar; tampil di Dashboard & Performa Tim | `OPERATIONALLY_SAFE_WITH_LIMITATION` — angkanya tampil benar, tapi bermakna hanya sebanding dengan akurasi model di atas |

---

# Bagian 9. Revision dan Approval

Ada **dua jenis persetujuan yang berbeda** dan harus dipisahkan tegas di buku
panduan:

## Persetujuan Klien (Portal Klien)

- **Siapa** — Klien, lewat link Portal Klien
- **Kapan** — Saat konten berstatus **Menunggu Persetujuan**
- **Efek "Setuju"** — Sistem mencatat waktu & hasil persetujuan, lalu mengirim
  notifikasi ke seluruh **Manager & SMO** aktif: *"Klien Sudah Setuju - Perlu
  Dicek"*. **Status konten TIDAK berubah.**
- **Efek "Minta Revisi"** — Catatan revisi baru dibuat atas nama klien, **dan
  status langsung pindah ke Perlu Revisi**, lalu semua PIC yang ditugaskan
  mendapat notifikasi.
- **Sekali saja** — setelah klien merespons, tombolnya tidak muncul lagi.

## Review Internal

- **Siapa** — CEO, Manager, SMO (`workflow,approve`)
- **Kapan** — Saat konten berstatus **Menunggu Persetujuan**
- **Efek "Approve Konten"** — Status pindah ke **Disetujui**
- **Syarat** — Tidak boleh ada catatan revisi yang masih terbuka atau sedang
  dikerjakan
- **Kalau klien belum merespons** — tetap boleh approve, tapi muncul peringatan

## Alur revisi lengkap

```text
Menunggu Persetujuan
        │
        ├── Klien menekan "Minta Revisi"  ──────┐
        │   (catatan atas nama klien)            │
        │                                        ▼
        └── Tim internal menambah catatan ──► Perlu Revisi
                                                 │
                                    (boleh tambah beberapa catatan lagi)
                                                 │
                                       "Kerjakan Revisi"
                                                 │
                                                 ▼
                                        Sedang Dikerjakan
                                    (semua catatan → sedang dikerjakan)
                                                 │
                                     "Konten Telah Selesai"
                                                 │
                                                 ▼
                                       Menunggu Persetujuan
                                  (semua catatan → selesai otomatis)
```

**Status catatan revisi:** Terbuka → Sedang Dikerjakan → Selesai.
Perpindahannya **otomatis**, mengikuti perpindahan status konten — tidak ada
tombol untuk menandai satu catatan revisi selesai secara terpisah. Jelaskan ini,
karena pengguna sering mencari tombol tersebut.

**Siapa yang bisa meminta revisi:** Klien, CEO, Manager, SMO, Content Creator,
Graphic Designer (semua pemegang `workflow,update`). **Copywriter tidak bisa.**

**Di mana melihat semua revisi:** Produksi → tab **Revisi**, bisa difilter per
klien dan per status (default menampilkan yang masih Terbuka).

**Status:** `READY`

---

# Bagian 10. Publishing

## Mencatat publikasi (Catat Publikasi)

**Role** — CEO & SMO saja (`publishing,manage`)
**Kapan** — Konten berstatus **Terjadwal Tayang**
**Data yang diisi** — Platform (wajib), tanggal & jam terbit (wajib), link
postingan, caption final
**Efek** — Konten jadi **Sudah Tayang**, catatan publikasi tersimpan, konten
otomatis dilepas dari pin semua orang
**Status** — `READY` (terverifikasi lewat `GoldenPathTest`: jadwalkan → catat
publikasi → Sudah Tayang)

## Melihat riwayat tayang

Produksi → tab **Sudah Tayang**. Bisa difilter per klien dan per platform.
(Halaman `/publishing-tracker` menampilkan hal yang sama tapi tidak punya menu —
lihat KI-12.)
**Status** — `READY`

## Penautan otomatis post media sosial

Saat sinkronisasi Instagram/TikTok berjalan, setiap post yang ditemukan dicoba
dicocokkan otomatis ke konten internal, dengan urutan prioritas:

1. ID postingan yang persis sama
2. URL yang sama (setelah dinormalisasi)
3. Jatuh di dalam jendela waktu jadwal tayang
4. Kemiripan kuat judul/caption

Hasilnya salah satu dari: **tertaut**, **belum tertaut**, atau **ambigu** (ada
beberapa kandidat, perlu dipilih manual).

## Post yang belum tertaut (Unmatched)

**Halaman** — "Unmatched Instagram Media" / "Unmatched TikTok Video"
**Role** — CEO & SMO
**Isi** — Daftar post/video dari akun klien yang belum terhubung ke konten
internal, lengkap dengan thumbnail, caption, tanggal, dan link ke postingan asli
**Aksi** — Pilih konten internal yang sesuai → **Simpan** → post tertaut, dan
seluruh data metriknya ikut dipindahkan ke konten tersebut

**Khusus Instagram** tersedia tambahan **saran pencocokan otomatis** untuk konten
hasil import Content Planner lama (skor kemiripan judul + selisih tanggal +
kecocokan format). Saran ini **tidak pernah menautkan sendiri** — staf tetap
harus menekan Simpan. TikTok tidak punya fitur saran ini, dan itu memang
disengaja (tidak ada dataset historis TikTok yang setara).

**Status** — `NEEDS_VERIFICATION`. Kode lengkap dan konsisten, dan **tidak ada
indikasi defect** saat re-audit jalur kodenya. Yang belum ada: regression test
khusus fitur ini dan sekali pun percobaan dengan data sinkronisasi sungguhan
(bergantung pada akun yang benar-benar terhubung — lihat `EXTERNAL_BLOCKED` di
Bagian 12). **Boleh ditulis di buku secara konseptual**, jangan pakai screenshot
hasil rekaan.

## Yang TIDAK bisa dilakukan sistem ini

**Tidak ada publikasi langsung ke Instagram atau TikTok dari dashboard.**
Semua posting dilakukan manual di aplikasi masing-masing; dashboard hanya
**mencatat** bahwa itu sudah dilakukan. Tulis ini eksplisit di buku — ini
ekspektasi yang sangat sering keliru.

---

# Bagian 11. Client Management (UI: **Kelola Klien**)

## Aktivitas

| Aktivitas | Role | Catatan |
|---|---|---|
| Melihat daftar klien | CEO, Manager | Bisa dicari & difilter status |
| Melihat detail 1 klien | Semua role internal (discope) | Tombol ubah data nonaktif untuk yang tak berhak |
| Tambah Klien | CEO, Manager | Link Portal Klien otomatis dibuat |
| Edit Klien | CEO, Manager | Termasuk mengubah status klien |
| Hapus Klien | CEO, Manager | Lihat catatan di bawah |
| Ubah Paket | CEO, Manager | |
| Atur PIC | CEO, Manager | Dari sisi klien |
| Kelola link Portal Klien | CEO, Manager | Buat ulang / nonaktifkan / aktifkan |
| Hubungkan Instagram | CEO, Manager | |
| Hubungkan TikTok | CEO, Manager | |

**Status klien:** Aktif · Menunggak (`past_due`) · Dijeda (`paused`)

**Data klien:** Nama, Nama Brand, Kategori Klien, Logo (maks 2 MB), Link Aset,
Status.

### Catatan penting soal Hapus Klien

Kalau klien **sudah punya riwayat konten atau rencana konten**, klien
**tidak dihapus** — statusnya diubah jadi **Dijeda**, dengan pesan yang
menjelaskan. Penghapusan permanen (beserta logo dan riwayat paketnya) hanya
terjadi kalau klien benar-benar belum punya riwayat apa pun. Ini perilaku
melindungi data dan **harus dijelaskan**, karena tombolnya bertuliskan "Hapus"
tapi hasilnya bisa bukan penghapusan.

### Ubah Paket

Paket klien memakai **sistem snapshot**: saat paket diganti, paket lama ditandai
berakhir (tidak dihapus, supaya riwayat kuota per periode tetap terlihat), lalu
dibuat catatan paket baru yang **menyalin** nama & kuota dari template saat itu.
Artinya: mengubah template paket di Data Pilihan **tidak** mengubah kuota klien
yang sudah berjalan.

Kalau belum ada paket sama sekali, modal Ubah Paket akan mengarahkan ke
**Pengaturan → Data Pilihan → Paket** untuk membuatnya lebih dulu.

### Atur PIC dari sisi klien

Di Detail Klien ada kartu **PIC Ditugaskan** berisi staf yang menangani klien
itu, lengkap dengan jumlah konten aktif masing-masing.

**Pengaman yang perlu dijelaskan:** kalau seorang PIC dicoba dikeluarkan padahal
masih memegang konten aktif klien tersebut, **seluruh perubahan ditolak** dengan
pesan yang menyebutkan siapa dan berapa kontennya. Untuk mengeluarkannya, harus
lewat tombol **Keluarkan** di kartu PIC yang bersangkutan, yang **mewajibkan
memilih pengganti** — semua konten aktifnya dipindahkan ke pengganti itu dulu.

**Status:** `READY` (seluruh aktivitas di bagian ini)

---

# Client Onboarding Workflow

Alur ini **direkonstruksi dari kode**, bukan diasumsikan. Urutannya sengaja
berbeda dari dugaan awal di beberapa titik — perhatikan catatan di tiap langkah.

### Langkah 0 (prasyarat, sekali saja) — Siapkan Paket & Data Pilihan

| | |
|---|---|
| Penanggung jawab | CEO, Manager, atau SMO |
| Menu | Pengaturan → Data Pilihan |
| Data | Tab **Paket**: nama paket + kuota konten + kuota desain. Tab **Kategori Klien**, **Platform**, **Tipe Konten**, **Pilar Konten** |
| Indikator berhasil | Paket muncul di daftar dan bisa dipilih saat menambah klien |
| Kemungkinan error | Tidak bisa menghapus data yang masih dipakai (pesan jelas) |
| Langkah berikutnya | Langkah 1 |

> Di lingkungan yang diaudit: 4 paket, 2 platform (Instagram, TikTok), 2 tipe
> konten (Video, Desain), 6 pilar, 4 kategori klien — **sudah terisi**.

### Langkah 1 — Buat data klien

| | |
|---|---|
| Penanggung jawab | CEO / Manager |
| Menu | Kelola Klien → **Tambah Klien** |
| Data | Nama, Nama Brand, Kategori, Logo (opsional), Link Aset (opsional), **Paket (opsional, bisa langsung di sini)** |
| Tindakan | Isi form → Simpan |
| Indikator berhasil | Diarahkan ke halaman Detail Klien, muncul pesan *"Klien berhasil dibuat. Link Portal Klien telah tersedia."* |
| Kemungkinan error | Logo >2 MB, format link aset bukan URL valid |
| Langkah berikutnya | Langkah 2 |

> **Koreksi terhadap asumsi:** paket **bisa** ditentukan langsung di form Tambah
> Klien — tidak harus jadi langkah terpisah setelahnya.

### Langkah 2 — Pastikan paket sudah benar

| | |
|---|---|
| Penanggung jawab | CEO / Manager |
| Menu | Detail Klien → kartu **Paket Aktif** → **Ubah Paket** |
| Indikator berhasil | Kartu Paket Aktif menampilkan nama paket, kuota konten & desain, dan tanggal mulai |
| Catatan | Boleh dilewati. Klien tanpa paket **tetap bisa** dibuatkan Rencana Konten — hanya kolom Target-nya yang kosong |
| Langkah berikutnya | Langkah 3 |

### Langkah 3 — Tentukan PIC (tim yang menangani klien ini)

| | |
|---|---|
| Penanggung jawab | CEO / Manager |
| Menu | Detail Klien → **PIC Ditugaskan**, atau Kelola Pengguna → **Assign Klien** |
| Tindakan | Centang staf yang menangani klien ini |
| Indikator berhasil | Nama staf muncul di kartu PIC Ditugaskan |
| **Kenapa langkah ini kritis** | Tanpa ini: (a) staf non-CEO/Manager **tidak melihat klien ini sama sekali**; (b) **tidak ada kandidat PIC** yang bisa dipilih saat menambah konten; (c) pembagian PIC otomatis oleh AI Strategy gagal |
| Kemungkinan error | Staf yang dipilih harus berstatus aktif. **Kedua jalur berfungsi** (KI-05 diperbaiki) — pakai yang paling nyaman: Detail Klien kalau sedang menyiapkan satu klien, Kelola Pengguna kalau sedang mengatur satu orang untuk beberapa klien |
| Langkah berikutnya | Langkah 4 |

### Langkah 4 — Aktifkan Portal Klien (opsional)

| | |
|---|---|
| Penanggung jawab | CEO / Manager |
| Menu | Detail Klien → kartu **Portal Klien** |
| Tindakan | Salin link → kirim ke klien lewat jalur yang aman |
| Indikator berhasil | Link bisa dibuka di jendela penyamaran tanpa diminta login |
| Kemungkinan error | Portal dinonaktifkan → link menampilkan **404** (bukan pesan "dinonaktifkan"; ini disengaja demi keamanan) |
| **Peringatan keamanan** | Link itu **adalah** kata sandinya. Lihat Bagian 20 |
| Langkah berikutnya | Langkah 5 |

### Langkah 5 — Hubungkan akun media sosial klien

| | |
|---|---|
| Penanggung jawab | CEO / Manager |
| Menu | Detail Klien → kartu **Integrasi Analytics** → **Connect Instagram** / **Connect TikTok** |
| Prasyarat | Klien harus login ke akun Instagram/TikTok-nya sendiri saat layar persetujuan muncul |
| Indikator berhasil | Kartu berubah jadi **Terhubung** dan menampilkan nama akun |
| Kemungkinan error | Kredensial `.env` kosong · akun belum terdaftar sebagai penguji (aplikasi masih mode pengembangan) · `redirect_uri` tidak persis sama |
| Langkah berikutnya | Langkah 6 |

Detail lengkap di **Bagian 12**.

### Langkah 6 — Jalankan sinkronisasi pertama

| | |
|---|---|
| Penanggung jawab | CEO / Manager / SMO |
| Menu | Pengaturan → Integrasi → pilih klien → **Sync** |
| Indikator berhasil | Riwayat sinkronisasi menampilkan status **Berhasil** dengan jumlah data yang tersinkron |
| Kemungkinan error | Sinkronisasi berjalan sebagai proses latar. Kalau **queue worker di server tidak berjalan**, tombol Sync tetap terlihat berhasil tetapi tidak ada yang diproses — ini gejala konfigurasi server, bukan kesalahan pengguna (Bagian 22, KI-14; masukkan ke bab Troubleshooting) |
| Langkah berikutnya | Langkah 7 |

### Langkah 7 — Verifikasi data muncul di Performa

| | |
|---|---|
| Penanggung jawab | SMO / Manager |
| Menu | Performa → pilih klien |
| Indikator berhasil | Tab **Analytics** menampilkan angka views & engagement; tab **Tabel Performa** menampilkan daftar post; tab **Audiens** menampilkan follower & demografi (Instagram) |
| Kemungkinan error | Kosong → cek apakah sinkronisasi benar-benar berjalan, dan cek daftar post **belum tertaut** |
| Langkah berikutnya | Langkah 8 |

### Langkah 8 — Buat Rencana Konten pertama

| | |
|---|---|
| Penanggung jawab | CEO / Manager / Copywriter |
| Menu | Rencana Konten → **Buat Rencana** → pilih klien, bulan, tahun |
| Lalu | Tambahkan konten satu per satu, atau pakai **AI Strategy → Terapkan** untuk membuat kerangka otomatis |
| Indikator berhasil | Konten muncul di papan Produksi dengan status **Siap Dikerjakan** |
| **Klien siap digunakan** | ✅ |

## Ringkasan urutan onboarding

```text
Paket & Data Pilihan  (sekali saja, untuk semua klien)
        │
        ▼
Tambah Klien  ──►  (paket bisa langsung di sini)
        │
        ▼
Tentukan PIC  ◄── LANGKAH PALING KRITIS
        │
        ├──► Aktifkan Portal Klien  (opsional, bisa kapan saja)
        │
        ▼
Hubungkan Instagram / TikTok
        │
        ▼
Sinkronisasi pertama
        │
        ▼
Verifikasi di Performa
        │
        ▼
Rencana Konten pertama  ──►  Produksi berjalan
```

**Status keseluruhan alur:** `READY`, dengan satu langkah `EXTERNAL_BLOCKED`.

Rangkaian Langkah 1 → 3 → 8 dan seterusnya sampai konten tayang, performa
ter-import, dan laporan PDF terbit sudah dijalankan **sebagai satu alur
berkesinambungan** lewat `GoldenPathTest` (routing, middleware, permission,
client scope, transisi status, notifikasi, dan Portal Klien semuanya lewat kode
aplikasi asli). Tidak ada lagi langkah yang mengandung fitur rusak.

Yang tetap tidak bisa dijamin: **Langkah 5 (Hubungkan akun media sosial)** —
`EXTERNAL_BLOCKED` karena consent screen sungguhan bergantung App Review
Meta/TikTok. Langkah 6 dan 7 ikut tertahan selama Langkah 5 belum bisa
diselesaikan untuk klien yang bersangkutan. Alternatifnya tersedia dan
berfungsi: **Import CSV Performa** (Bagian 13), sehingga onboarding tetap bisa
tuntas tanpa integrasi API.

---

# Bagian 12. Social Media Analytics Onboarding

## Instagram

### Akun apa yang login?

**Akun Instagram milik klien**, bukan akun 523 Studio. Staf internal membuka
halaman Detail Klien dan menekan **Connect Instagram**; yang muncul berikutnya
adalah layar persetujuan Instagram, dan di situ **klien** yang harus masuk ke
akunnya. Praktiknya: dilakukan bersama klien, atau klien diberi panduan untuk
melakukannya sendiri dari perangkatnya.

Akun harus berupa **akun bisnis/kreator Instagram** (izin yang diminta adalah
izin bisnis).

### Izin yang diminta

- `instagram_business_basic` — data dasar akun & daftar media
- `instagram_business_manage_insights` — metrik performa & data audiens

### Precondition

1. `INSTAGRAM_CLIENT_ID`, `INSTAGRAM_CLIENT_SECRET`, `INSTAGRAM_REDIRECT_URI`
   terisi di `.env` — **terverifikasi terisi**
2. `redirect_uri` di `.env` **persis sama karakter per karakter** dengan yang
   didaftarkan di Meta App Dashboard
3. Selama aplikasi Meta masih mode pengembangan: akun klien **harus didaftarkan
   manual sebagai Instagram Tester** di App Dashboard. Tanpa App Review Meta,
   klien baru yang belum terdaftar **akan gagal connect** — ini bukan bug kode
4. Platform bernama "Instagram" ada di Data Pilihan — **terverifikasi ada**
5. Staf yang menekan tombol punya hak `client,manage` (CEO/Manager)

### Steps

1. Kelola Klien → pilih klien → Detail Klien
2. Kartu **Integrasi Analytics** → **Connect Instagram**
3. Klien masuk ke akun Instagram-nya di layar persetujuan Meta
4. Klien menyetujui izin yang diminta
5. Sistem kembali ke Detail Klien, kartu berubah jadi **Terhubung**

### Verification

- Kartu Integrasi Instagram menampilkan status **Terhubung** + nama akun
- Pengaturan → Integrasi → pilih klien → kartu Instagram menunjukkan hal serupa
- Setelah sinkronisasi pertama: **Riwayat Sinkronisasi** menampilkan baris
  berstatus **Berhasil** dengan jumlah data

### Common failure

| Gejala | Penyebab | Solusi |
|---|---|---|
| Tombol Connect memberi pesan "belum diisi di .env" | Kredensial kosong | Isi `.env`, minta bantuan teknis |
| "Koneksi Instagram dibatalkan." | Klien menekan Batal di layar persetujuan | Ulangi |
| "Sesi otorisasi kadaluarsa atau tidak valid" | Terlalu lama di layar persetujuan, atau proses dibuka di tab lain | Ulangi dari awal |
| Layar persetujuan muncul tapi akun ditolak | Aplikasi masih mode pengembangan & akun belum terdaftar sebagai tester | Daftarkan akun di App Dashboard, atau tunggu App Review |
| "Platform 'Instagram' tidak ditemukan di master data" | Data Pilihan → Platform belum berisi Instagram | Tambahkan |
| Terhubung tapi data tidak muncul | Queue worker di server tidak berjalan | Masalah konfigurasi server, bukan aplikasi — lihat KI-14 & Panduan Administrator |

### Data apa yang didapat

**Konten:** views, reach, impressions, likes, comments, shares, saves, profile
visit, engagement rate — per post, per tanggal.
**Audiens:** jumlah follower, reach, jam aktif audiens, serta demografi
(gender, rentang usia, kota, negara) dalam tiga varian: pengikut, yang
terjangkau, dan yang berinteraksi.

Audiens disinkronkan lewat **proses terpisah** dari konten (tombol dan jadwal
sendiri).

**Status:** `EXTERNAL_BLOCKED` — **siap secara kode, live OAuth bergantung App
Review.**

Implementasi lengkap (OAuth, penyegaran token otomatis, sinkronisasi konten &
audiens terpisah, snapshot media, pencocokan, penanganan error, penyembunyian
token) dan **seluruh jalur yang bisa diuji tanpa consent manusia nyata sudah
diuji dan lulus** (`SocialIntegrationOAuthTest`): pembentukan URL redirect,
`state`, validasi callback, `state` yang tidak cocok, penolakan oleh pengguna,
kegagalan penukaran token, dan upsert `ApiIntegration`.

Yang menahan status `READY` **bukan kode**, melainkan **Meta App Review**:
selama app Instagram masih mode Development, hanya akun yang didaftarkan manual
sebagai *Instagram Tester* yang bisa connect. Ini keterbatasan provider.

**Untuk buku:** tulis prosedurnya lengkap, tapi sertakan kotak peringatan bahwa
koneksi hanya berhasil untuk akun yang sudah terdaftar sebagai penguji selama
App Review belum selesai. **Jangan menulisnya sebagai "sudah berfungsi
end-to-end".**

---

## TikTok

### Akun apa yang login?

**Akun TikTok milik klien.** Polanya identik dengan Instagram.

### Izin yang diminta

`user.info.basic` (wajib), `video.list` (wajib), serta `user.info.profile` dan
`user.info.stats` (opsional — TikTok mengizinkan pengguna menolaknya satu per
satu). Kalau `user.info.stats` ditolak, **jumlah follower tidak akan muncul**.

### Precondition

1. `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`, `TIKTOK_REDIRECT_URI` terisi —
   **terverifikasi terisi**
2. `redirect_uri` persis sama dengan yang didaftarkan di TikTok Developer Portal
   (termasuk `http`/`https` dan garis miring di akhir)
3. Selama aplikasi masih mode pengembangan/sandbox: akun klien **harus terdaftar
   sebagai target-user/tester** di Developer Portal — sama seperti Meta
4. Platform bernama "TikTok" ada di Data Pilihan — **terverifikasi ada**

### Steps

Identik dengan Instagram, lewat tombol **Connect TikTok** di kartu Integrasi
Analytics.

### Verification

Kartu Integrasi TikTok menampilkan **Terhubung** + nama akun; setelah
sinkronisasi, Riwayat Sinkronisasi menampilkan baris berhasil.

### Common failure

Sama seperti tabel Instagram, ditambah:

| Gejala | Penyebab |
|---|---|
| Jumlah follower tidak muncul di kartu | Izin `user.info.stats` tidak diberikan saat persetujuan |
| Sinkronisasi berjalan tapi video baru tidak masuk | TikTok tidak punya filter tanggal; sistem berhenti mengambil halaman begitu menemukan video di luar rentang. Dilaporkan sebagai *berhenti lebih awal*, bukan error |

### ⚠️ Keterbatasan data TikTok — WAJIB dijelaskan di buku

TikTok Display API resmi **tidak menyediakan**:

- reach / impressions (hanya jumlah tayangan/views)
- saves / kunjungan profil
- **demografi audiens sama sekali** (gender, usia, kota, negara)
- jam aktif audiens

Field-field ini **sengaja dibiarkan kosong**, bukan diisi 0 — karena 0 berarti
"diukur dan hasilnya nol", sedangkan kenyataannya "tidak pernah diukur". UI
menampilkan "Data tidak tersedia melalui TikTok API" atau menyembunyikan
kartunya.

Konsekuensi untuk pengguna: **tab Audiens praktis hanya berguna untuk
Instagram.** Untuk TikTok, satu-satunya data audiens adalah jumlah follower, dan
itupun hanya kalau izin statistik diberikan.

Rumus engagement rate TikTok juga berbeda (dibagi views, karena reach tidak
tersedia) dari Instagram (mengutamakan reach). Angka kedua platform **tidak
sepenuhnya setara** — sebutkan di glosarium.

### Yang eksplisit di luar cakupan

Posting langsung ke TikTok dari dashboard, manajemen komentar, DM, TikTok Ads,
dan analitik kompetitor — **semuanya tidak ada dan tidak direncanakan** di
fitur ini.

**Status:** `EXTERNAL_BLOCKED` — **siap secara kode, live OAuth bergantung App
Review.**

Secara struktural **selesai dan setara Instagram**: OAuth dengan PKCE, penukaran
token, penyegaran token otomatis (kontrak TikTok berbeda — refresh token
dirotasi tiap dipakai), sinkronisasi video, snapshot, pencocokan, halaman
unmatched, kartu di Pengaturan, penanganan error, enkripsi token. Jalur
non-consent-nya **sudah diuji dan lulus** bersama Instagram di
`SocialIntegrationOAuthTest`.

Yang menahan status `READY` **bukan kode**, melainkan **TikTok Developer
Portal**: layar consent sandbox masih berpotensi mengembalikan
`unauthorized_client`/`client_key`, masalah registrasi app di sisi provider yang
belum terselesaikan lintas beberapa sesi debugging. Sengaja **tidak** diubah
jadi "READY end-to-end".

> **Penilaian eksplisit terhadap anggapan "TikTok belum selesai":** setelah dua
> putaran audit, tidak ditemukan TODO, placeholder, atau jalur yang belum
> diimplementasikan pada integrasi TikTok. Yang membedakannya dari `READY`
> murni verifikasi live yang bergantung pihak ketiga — **bukan** kode setengah
> jadi. Bedakan tegas di buku: ini "menunggu izin platform", bukan "fitur
> rusak".

---

## Ringkasan: kapan data muncul di Performa

```text
Klien connect (OAuth)
        │
        ▼
Sinkronisasi berjalan  ◄── butuh QUEUE WORKER aktif
        │
        ├─► Post/video tersimpan sebagai snapshot
        │
        ├─► Dicocokkan otomatis ke konten internal
        │        │
        │        ├─ cocok        ──► metrik menempel ke konten
        │        └─ belum cocok  ──► masuk daftar "Unmatched", tautkan manual
        │
        └─► Metrik tersimpan per post per tanggal
                 │
                 ▼
        Muncul di Performa (Analytics / Tabel Performa / Audiens)
```

**Post yang belum tertaut TETAP muncul** di tab Tabel Performa dan tetap ikut
dihitung di ringkasan — ini disengaja, supaya angka performa akun tidak timpang
hanya karena penautan belum dikerjakan.

---

# Bagian 13. Analytics / Performa (UI: **Performa**)

Menu sidebar **Performa** (rute `/analytics`), judul halaman **Performa Konten**.
Tiga tab dalam satu halaman; pilihan klien dan periode **ikut terbawa** saat
berpindah tab.

**Aturan penting:** kalau belum memilih klien, halaman **sengaja menampilkan
keadaan kosong** dan meminta memilih klien dulu. Tidak ada agregasi lintas semua
klien di halaman ini (agar tidak ramai dan lambat). Jelaskan ini — pengguna
sering mengira halamannya rusak.

**Periode:** 7 / 30 / 90 hari.
**Client scope:** dibatasi roster, kecuali CEO/Manager.

## Tab **Analytics** (ringkasan)

- Kartu statistik ringkas
- Grafik tren views sepanjang periode
- Rincian per platform
- Konten dengan performa terbaik
- **Panel AI Strategy** (Bagian 14)

## Tab **Tabel Performa**

Daftar semua post yang punya data performa untuk klien itu — **termasuk post
yang belum tertaut** ke konten internal.

- Kolom: Judul, Platform, Tipe, Total Views, Rata-rata Engagement, Deadline,
  status tayang/terlambat
- Bisa diurutkan (views, engagement, deadline, judul), dicari, difilter per
  platform dan per tipe konten
- Baris yang belum tertaut punya aksi **Hubungkan Konten**
- Klik baris yang sudah tertaut → **Detail Performa 1 konten**

Untuk baris yang belum tertaut, kolom Tipe menampilkan format Instagram
(Reels/Carousel/Image/Video) **hanya sebagai tampilan** — ini bukan Tipe Konten
internal dan tidak pernah disimpan sebagai tipe.

## Tab **Audiens**

Menampilkan follower, reach, jam aktif, dan demografi.

**Aturan sumber data yang perlu dijelaskan:** kalau klien+platform sudah pernah
punya data dari **API**, tab ini **hanya membaca data API** — data hasil import
CSV untuk kombinasi itu **diabaikan sepenuhnya**, tidak digabung. Alasannya:
satuan dan cara pengukurannya berbeda; mencampur keduanya menghasilkan angka
yang tidak bermakna. Kalau belum pernah ada data API sama sekali, barulah CSV
dipakai.

Nilai yang belum tersedia ditampilkan sebagai kosong, **bukan 0**.

## Detail Performa 1 Konten

Riwayat metrik harian satu konten: total views, rata-rata engagement, jumlah hari
terpantau, hari terbaik, grafik tren, metrik video (watch time, completion rate,
shares, saves) bila ada, riwayat sinkronisasi/import, serta **perbandingan
terhadap rata-rata konten lain milik klien yang sama dalam 30 hari terakhir**.

## Ekspor CSV

Tombol ekspor menghasilkan berkas dengan kolom yang **persis sama** dengan
format import — jadi hasil ekspor bisa langsung di-import kembali (untuk
cadangan, atau memindahkan data antar klien).

Kolom: `content_title, platform, metric_date, views, engagement_rate`

## Import CSV Performa

**Menu:** Pengaturan → Integrasi → **Import Data Performa** (halaman tersendiri)
**Kolom wajib:** `content_title, platform, metric_date, views, engagement_rate`
**Kolom opsional:** `reach, impressions, likes, comments, profile_visit,
watch_time_avg, completion_rate, shares, saves`

- Judul konten dicocokkan ke konten **milik klien yang dipilih** (jadi judul
  cukup unik per klien, tidak harus unik se-sistem)
- Nama platform dicocokkan tanpa membedakan huruf besar/kecil
- Baris yang judulnya tidak ditemukan **dilewati dan dilaporkan**, tidak
  menggagalkan seluruh proses
- Kolom opsional yang kosong disimpan sebagai kosong, **bukan 0**
- Ukuran berkas maksimal 5 MB

**Status:** `READY` — KI-09 diperbaiki: baik halaman import maupun proses
import-nya sekarang membatasi pilihan klien ke roster pengguna
(`ImportPerformanceScopeTest`). Ini juga **jalur cadangan resmi** saat integrasi
API masih `EXTERNAL_BLOCKED`.

## Import Audience CSV

Tersedia lewat rute `/audience/import` (`analytics,view`).
**Status:** `READY` — diverifikasi ulang saat re-audit. Catatan: white-box
re-audit menemukan bahwa jalur ini dulu bisa **menulis** data audiens klien mana
pun lewat `client_id` di body request; sudah ditutup dan punya regression test
(`PhaseLAuthorizationLeaksTest`).

---

# Bagian 14. AI Strategy

## Siapa yang menggunakannya

CEO, Manager, SMO (`analytics,view`) — dari panel di halaman **Performa**,
setelah memilih klien.

## Tujuan

Menganalisis performa konten **satu bulan kalender penuh sebelumnya** untuk satu
klien, lalu memberi rekomendasi: apa yang berhasil, apa yang perlu diubah, dan
ide konten konkret untuk bulan berikutnya.

> Periodenya **selalu bulan kalender penuh sebelumnya**, bukan "30 hari
> terakhir". Artinya: analisis yang dijalankan kapan pun di bulan Agustus selalu
> membahas performa penuh bulan Juli. Ini disengaja agar hasilnya konsisten dan
> selaras dengan Rencana Konten yang juga per bulan.

## Input

Diambil otomatis dari sistem, tanpa diketik pengguna: total views & tren
dibanding bulan sebelumnya, performa per pilar konten, performa per platform,
5 konten terbaik, serta data audiens bila tersedia.

Kalau klien **belum punya data performa sama sekali** di bulan tersebut, sistem
menolak dengan pesan jelas — **AI tidak akan menebak-nebak**.

## Output

| Bagian | Isi |
|---|---|
| **Ringkasan** | Narasi analisis performa bulan itu |
| **Action Items** | Daftar tindakan yang disarankan |
| **Komposisi Disarankan** | Usulan pembagian porsi antar pilar konten (persen) |
| **Pilar Teratas** | Pilar yang paling berhasil + alasannya |
| **Ide Konten** | Ide konkret (judul, brief, pilar, tipe, platform), masing-masing diberi skor |
| **Kelengkapan Data** | Persentase seberapa lengkap data periode itu — indikator seberapa bisa dipercaya |

## Arti tiap tombol

### Generate / Generate Ulang
Menjalankan analisis baru. Hasil lama **tidak dihapus** — bisa dilihat lagi di
**Riwayat AI Strategy**.

### Diskusi (chat)
Bertanya atau memberi konteks tambahan ke AI (misalnya "bulan itu kami ganti
strategi di tanggal 15"). **Chat tidak mengubah apa pun yang tersimpan.** AI
sendiri diinstruksikan untuk selalu mengarahkan pengguna menekan tombol
Perbarui bila ingin perubahannya benar-benar terjadi. Ini menutup
kesalahpahaman umum "sudah saya minta di chat, kenapa tidak berubah".

### Perbarui Analisis dari Diskusi Ini (refine)
Menyusun ulang ringkasan, action items, komposisi, pilar, dan ide konten
**berdasarkan seluruh diskusi**, pada analisis yang sama (riwayat chat tetap
menyatu). Ditolak kalau analisis sudah diterapkan.

### Regenerate satu ide
Mengganti **satu** ide konten saja, opsional sekaligus memindahkannya ke pilar
lain. Ditolak kalau analisis sudah diterapkan.

### **Terapkan (Apply)** — ini yang benar-benar mengubah sistem

Membuat **draft konten secara massal** ke dalam Rencana Konten bulan berjalan
untuk klien tersebut:

- Jumlahnya mengikuti **kuota paket klien** (kuota konten + kuota desain). Kalau
  klien belum punya paket, jumlahnya mengikuti banyaknya ide yang tersimpan —
  **kuota tidak pernah dikarang**
- Pembagian antar pilar mengikuti **Komposisi Disarankan**
- Judul, brief, tipe, dan platform diisi dari ide AI bila ada; slot yang tidak
  kebagian ide diberi judul placeholder dan **dilaporkan jumlahnya** agar
  dilengkapi manual
- Deadline **disebar acak ke seluruh hari bulan berjalan** — termasuk tanggal
  yang sudah lewat. Draft yang langsung "terlambat" itu **disengaja**, sebagai
  sinyal prioritas
- **PIC dibagi otomatis** dengan giliran berbasis beban kerja, dibatasi hanya ke
  tim yang di-assign ke klien itu, dan diarahkan sesuai tipe (Video → Content
  Creator, Desain → Graphic Designer). Kalau tidak ada yang cocok, sistem tetap
  membatasi ke tim klien itu dan **memberi tahu** agar penugasan diperiksa
- Hasilnya: pesan ringkas berisi jumlah draft, jumlah placeholder, dan pembagian
  PIC per orang
- Hanya bisa diterapkan **sekali** per analisis

### **Revert (Tarik Kembali)**

Menghapus **semua draft** yang dibuat dari analisis itu, **asal belum ada
progres nyata**. Kalau ada satu saja yang sudah diposting, punya revisi, punya
metrik, atau statusnya sudah melewati Siap Dikerjakan, **seluruh revert
ditolak** dengan pesan jelas — sistem tidak akan menghapus pekerjaan yang sudah
kadung berjalan. Setelah revert, analisis bisa diterapkan ulang.

### Riwayat AI Strategy

Halaman terpisah berisi **semua** analisis klien itu, bukan hanya yang terbaru —
supaya analisis lama yang masih diterapkan tetap bisa dilihat dan di-revert.

## Apakah hasil AI hanya rekomendasi?

**Sebagian besar ya, satu tidak.**

- Ringkasan, action items, komposisi, pilar teratas, ide konten, dan seluruh
  chat: **murni rekomendasi**, tidak mengubah apa pun.
- **Terapkan (Apply): benar-benar mengubah sistem** — membuat konten nyata,
  menugaskan PIC nyata, dengan deadline nyata yang akan muncul di papan Produksi
  dan di beban kerja tim.

Bedakan tegas di buku panduan.

**Status:** `READY` — siklus hidup generate → apply → revert diverifikasi lewat
`AiStrategyLifecycleTest`, dan halaman Performa tempat panel ini berada
diverifikasi runtime dengan data nyata. Konten hasil **Terapkan** bermuara di
Detail Konten yang sekarang berfungsi normal.

Catatan keamanan yang ikut diperbaiki: halaman **Riwayat AI Strategy** dulu bisa
dibuka untuk klien mana pun lewat `client_id` di query string; sekarang dibatasi
ke roster pengguna (`PhaseLAuthorizationLeaksTest`).

---

# Bagian 15. Dashboard dan Beranda

Tiga halaman ini sering tertukar. **Bedanya adalah sudut pandang**, bukan
tampilan.

| Halaman | Sudut pandang | Untuk siapa | Pertanyaan yang dijawab |
|---|---|---|---|
| **Beranda** | Saya | Semua role | "Apa yang harus **saya** kerjakan hari ini?" |
| **Dashboard** | Organisasi | CEO, Manager, SMO | "Bagaimana kondisi agensi secara keseluruhan?" |
| **Performa** | Klien | CEO, Manager, SMO | "Bagaimana hasil konten **klien ini** setelah tayang?" |

## Beranda

Halaman pendaratan setiap kali masuk. Isinya:

- **Spanduk sambutan** — "Selamat datang kembali di 523 Studio"
- **Kartu Absensi** — tombol Check-In / Check-Out, status hari ini, dan
  keterangan telat bila ada
- **Langkah Berikutnya** — maksimal **3** tindakan paling relevan, diurutkan
  prioritas (lihat tabel di bawah)
- **Fokus Saya** — konten yang di-pin
- **Ringkasan pekerjaan saya** — daftar task dan hitungannya

Panel **Langkah Berikutnya** menyesuaikan role:

| Kondisi | Pesan yang muncul | Prioritas |
|---|---|---|
| Ada task saya yang lewat deadline | "N task kamu sudah lewat deadline" | Tertinggi |
| Ada konten saya perlu direvisi | "N konten kamu perlu direvisi" | Tinggi |
| Ada rencana menunggu persetujuan saya | "N rencana konten menunggu persetujuanmu" | Sedang |
| Ada konten yang sudah disetujui klien | "N konten sudah disetujui klien, menunggu pengecekanmu" | Sedang |
| (Copywriter) ada brief belum diterapkan | "N brief belum diterapkan ke tim produksi" | Rendah |
| Tidak ada apa-apa | "Tidak ada langkah berikutnya saat ini" | — |

**Copywriter mendapat tampilan Beranda yang berbeda**: berisi antrean brief,
bukan daftar task produksi.

**Status:** `READY` (terverifikasi runtime)

## Dashboard

Ringkasan eksekutif **seluruh organisasi**. Isinya:

**Enam kartu statistik:** Konten Bulan Ini (+ perbandingan bulan lalu) · Klien
Aktif (+ klien baru bulan ini) · Tim Aktif · Item Overdue (+ persentase) · Total
Views Bulan Ini (+ perbandingan) · Konten Tayang bulan berjalan.

**Panel lain:** grafik output konten 7 bulan terakhir · grafik tren views
(7/30/90 hari) · **Perlu Perhatian** (4 konten paling lama terlambat — reaktif) ·
**Konten Berisiko Tinggi** (4 konten belum terlambat tapi skor risikonya tinggi
— prediktif) · Konten Terbaik bulan ini · **Peringkat Klien** · akurasi prediksi
risiko · konten terbaru · dan beberapa kalimat wawasan otomatis.

> **Cakupan data sudah benar (KI-10 diperbaiki).** Seluruh angka di Dashboard —
> kartu statistik, grafik, Perlu Perhatian, Konten Berisiko Tinggi, Konten
> Terbaik, **Peringkat Klien**, akurasi prediksi, dan konten terbaru — mengikuti
> client scope pengguna. CEO & Manager melihat seluruh klien; SMO hanya melihat
> klien roster-nya, konsisten dengan halaman lain. Terverifikasi lewat
> `DashboardScopeTest`.
>
> Konsekuensi untuk buku: jelaskan bahwa **angka Dashboard dua orang bisa
> berbeda** dan itu benar, bukan kesalahan data.

**Status:** `READY` (terverifikasi runtime + test scope)

## Performa

Lihat Bagian 13.

---

# Bagian 16. Attendance (UI: **Absensi** / tab **Kehadiran**)

## Aturan jam kerja

| | |
|---|---|
| Jam masuk | **11:00** |
| Jam pulang | **17:00** |
| Toleransi | **15 menit** |
| Hari kerja | Senin–Jumat (Sabtu & Minggu otomatis Libur) |

## Prosedur untuk pengguna

**Check-in**
1. Buka **Beranda**
2. Tekan **Check-In** pada kartu Absensi
3. Sistem mencatat jam saat itu dan menilai **Tepat Waktu** (≤ 11:15) atau
   **Telat** (> 11:15), lengkap dengan berapa menit telatnya

**Check-out**
1. Buka **Beranda**
2. Tekan **Check-Out**
3. Sistem menilai: **Pulang Awal** (< 16:45), **Normal**, atau **Lembur**
   (> 17:15)

## Yang perlu diketahui pengguna

- **Tidak bisa check-in di akhir pekan** — muncul pesan "Hari ini bukan hari
  kerja (akhir pekan)."
- **Tidak bisa check-in dua kali** dalam satu hari.
- **Tidak bisa check-out sebelum check-in.**
- **Tidak ada koreksi manual.** Kalau lupa check-out, datanya dibiarkan kosong
  dan ditandai **Lupa Check-Out** di rekap — sistem sengaja tidak menebak jam
  pulang, agar tidak ada durasi kerja yang sebenarnya hanya karangan. Ini
  keputusan desain, bukan fitur yang belum jadi. **Jelaskan di buku**, karena
  pengguna pasti mencari tombol koreksinya.

## Daftar status kehadiran

| Status | Arti |
|---|---|
| **Libur** | Akhir pekan |
| **Belum Datang** | Tanggal di masa depan |
| **Belum Check-In** | Hari ini, jam pulang belum lewat, belum check-in |
| **Tidak Hadir** | Hari kerja yang sudah lewat tanpa check-in |
| **Sudah Check-In** | Hari ini, sudah masuk, belum pulang |
| **Telat (Belum Pulang)** | Hari ini, masuk terlambat, belum pulang |
| **Lupa Check-Out** | Hari lampau, check-in ada tapi check-out tidak |
| **Tepat Waktu** / **Telat** / **Pulang Awal** / **Lembur** | Hari sudah lengkap |

## Rekap (untuk CEO & Manager)

**Menu:** Performa Tim → tab **Kehadiran**

- **Absensi Harian** — pilih tanggal, lihat seluruh staf aktif beserta statusnya
- **Rekap Bulanan** — pilih bulan, lihat per orang: Hari Kerja, Hadir, Telat,
  Tidak Hadir, Lupa Check-Out. Bisa dicari per nama, ada penomoran halaman

"Hari Kerja" hanya menghitung sampai **hari ini** untuk bulan berjalan, sehingga
sisa bulan tidak langsung terhitung sebagai tidak hadir.

**Status:** `READY` (tab Kehadiran terverifikasi runtime)

---

# Bagian 17. Team Management

## Kelola Pengguna

> ✅ **Istilah sudah seragam (KI-17 diperbaiki).** Menu sidebar, judul halaman,
> dan judul tab peramban semuanya menulis **"Kelola Pengguna"**. Istilah lama
> "Kelola Tim" sudah tidak ada lagi di aplikasi — jangan dipakai di buku.

**Role:** CEO & Manager saja.

Halaman ini menampilkan **seluruh** staf internal — baik yang punya akses
dashboard maupun yang tidak. Tiap baris memuat: nama, email, **semua role**-nya,
klien yang ditangani, jumlah task aktif, dan status.

### Undang User

**Tujuan** — Menambahkan orang baru sekaligus memberinya akses masuk
**Langkah** — Isi nama · email · centang **satu atau lebih role** · Simpan
**Efek yang dimaksudkan** — Akun dibuat berstatus **Diundang**, akses login
diaktifkan, dan email undangan dikirim
**Catatan penting tentang email undangan** — Email itu **hanya pemberitahuan**.
Tidak ada tautan ajaib atau kata sandi di dalamnya. Penerimanya tetap masuk
lewat tombol **Masuk dengan Google** menggunakan email yang sama. Kalau
pengiriman email gagal (SMTP belum siap), akun **tetap dibuat** dan admin diberi
tahu agar memberitahukan secara manual
**Status** — `READY` — KI-06 diperbaiki: akses login sekarang benar-benar
tersimpan saat undangan dibuat, sehingga orang yang diundang langsung bisa
masuk lewat **Masuk dengan Google** (`UserManagementTest`)

### Edit Role

Mengubah kumpulan role seseorang (bisa lebih dari satu). Berlaku sama untuk
orang yang punya maupun tidak punya akses login — **jabatan operasional tidak
bergantung pada apakah orang itu bisa masuk ke dashboard**.
**Status** — `READY`

### Assign Klien

Menentukan klien mana saja yang ditangani orang itu. Ini **satu-satunya sumber**
data "klien yang ditangani", dan menentukan apa yang bisa dilihat orang tersebut.

Berlaku juga untuk CEO/Manager — bagi mereka ini mencatat tanggung jawab
operasional nyata, terpisah dari kemampuan melihat semua klien.

**Status** — `READY` (KI-05 diperbaiki; `UserManagementTest`)
**Jalur setara:** **Detail Klien → PIC Ditugaskan** melakukan hal yang sama dari
arah sebaliknya. Keduanya berfungsi — pilih sesuai konteks: Kelola Pengguna
kalau sedang mengatur satu orang untuk beberapa klien, Detail Klien kalau sedang
menyiapkan satu klien.

### Aktifkan / Cabut Akses Login

Tombol ikon tersendiri di kolom Aksi (tersedia di tampilan desktop maupun
mobile), dengan tooltip **"Aktifkan akses login"** / **"Cabut akses login"**,
untuk **mengaktifkan atau mencabut akses dashboard** seorang staf yang sudah
ada —
terpisah dari status akun dan dari role. Dipakai misalnya saat staf lapangan
mulai perlu membuka dashboard, atau saat akses seseorang perlu dihentikan
sementara tanpa menonaktifkan akunnya.
**Role** — CEO & Manager
**Status** — `READY` (ditambahkan bersama perbaikan KI-06; `UserManagementTest`)

### Nonaktifkan / Aktifkan Kembali

**Nonaktifkan** — Kalau orang itu masih memegang konten aktif, sistem
**mewajibkan memilih pengganti** lebih dulu dan memindahkan seluruh tugas
aktifnya, baru akunnya dinonaktifkan. Pesan hasilnya menyebutkan berapa tugas
yang dipindahkan dan ke siapa. Tidak bisa menonaktifkan akun sendiri.
**Aktifkan Kembali** — Mengembalikan status jadi aktif.
**Status** — `READY`

### Status akun vs akses login

Dua hal berbeda yang perlu dijelaskan:

| | Arti |
|---|---|
| **Status** (Menunggu / Diundang / Aktif / Nonaktif / Ditolak) | Apakah orang ini staf yang sedang bekerja |
| **Akses login** | Apakah orang ini boleh masuk ke dashboard |

Seseorang bisa berstatus **Aktif** (staf sungguhan, muncul di Performa Tim, bisa
ditugaskan sebagai PIC) tetapi **tidak punya akses login** — misalnya staf
lapangan yang tidak memakai dashboard. Di daftar pilihan PIC, orang seperti ini
diberi keterangan **"(belum memiliki akses dashboard)"**.

Syarat bisa masuk: status **Aktif** atau **Diundang**, **DAN** akses login
aktif. Keduanya bisa diatur dari UI: status lewat **Nonaktifkan / Aktifkan
Kembali**, akses login lewat **Aktifkan / Cabut Akses Login**. Orang yang baru
diundang otomatis mendapat akses login.

## Performa Tim

**Role:** CEO & Manager. Dua tab: **Performa** dan **Kehadiran**.

Tab **Performa** menampilkan per anggota tim: total konten · task aktif · task
terlambat · task selesai · jumlah revisi · **penanda kelebihan beban** (lebih
dari 5 task aktif) · rata-rata skor risiko keterlambatan.

Di atasnya ada ringkasan: jumlah personel aktif, total task aktif, rata-rata
revisi per orang, dan **akurasi prediksi risiko**.

Klik nama → **halaman Profil** berisi seluruh penugasan orang itu.

**Catatan:** revisi yang berasal dari **Koreksi Status** sengaja tidak dihitung
sebagai revisi tim.

**Status:** `READY` (terverifikasi runtime)

---

# Bagian 18. Report (UI: **Laporan**)

**Role:** CEO, Manager, SMO (`report,view`)

## Perbedaan Laporan vs Performa — jelaskan tegas di buku

| | **Performa** | **Laporan** |
|---|---|---|
| Sifat | Halaman interaktif | Berkas yang diunduh |
| Isi | Hasil setelah tayang | Bisa progres produksi, bisa performa |
| Periode | 7/30/90 hari | Rentang tanggal bebas |
| Untuk | Dipakai tim sendiri | **Dikirim ke klien** |
| Format | Layar | PDF atau Excel |

## Dua jenis laporan

### 1. Laporan Progres Operasional

**Isi:** Total konten · Selesai · Terlambat · Sedang Direvisi · Total Revisi ·
daftar konten pada rentang tanggal itu
**Menjawab:** "Apa saja yang kami kerjakan periode ini, dan bagaimana
ketepatan waktunya?"
**Pilihan klien:** CEO/Manager boleh **mengosongkan** klien (lintas semua klien);
role lain **wajib** memilih satu klien dari roster-nya
**Dasar tanggal:** deadline konten

### 2. Laporan Performa Konten

**Isi:** Total Views · Rata-rata Engagement · Jumlah Konten · Jumlah Platform ·
10 konten terbaik · rincian per platform
**Menjawab:** "Seberapa bagus hasil konten periode ini?"
**Pilihan klien:** **wajib** pilih satu klien
**Dasar tanggal:** tanggal metrik

## Prosedur

1. Sidebar → **Laporan**
2. Pilih jenis laporan
3. Pilih klien (atau kosongkan bila diizinkan)
4. Isi tanggal mulai & tanggal akhir (akhir tidak boleh sebelum mulai)
5. Pilih format **PDF** atau **Excel**
6. Tekan Generate → berkas terunduh otomatis

Setiap laporan yang dibuat **tersimpan** dan muncul di daftar riwayat.

> ⚠️ Daftar riwayat hanya menampilkan laporan yang **dibuat oleh diri sendiri**.
> Manager tidak melihat laporan buatan SMO. Ini bukan bug, tapi harus dijelaskan
> agar tidak dianggap laporan hilang.

**Status:** `READY` — pembuatan berkas diuji lewat `ReportGenerationTest`
(kedua jenis laporan, kedua format), dan langkah "generate Laporan PDF" ikut
dijalankan di dalam `GoldenPathTest`. Isi PDF client-facing juga sudah lolos
sweep terminologi (sebelumnya 100% berbahasa Inggris — lihat "Terminologi Resmi
untuk Dokumentasi").

---

# Bagian 19. Settings dan Master Data (UI: **Pengaturan**)

**Role:** CEO, Manager, SMO. Tiga tab: **Umum**, **Data Pilihan**, **Integrasi**.

## Tab Umum

Menampilkan data akun yang sedang masuk (**read-only**), serta status koneksi
layanan tingkat aplikasi: **Google Sign-In** dan **Gemini AI** (terhubung /
tidak). Keduanya **terverifikasi terisi**.

## Tab Data Pilihan

Isi dropdown yang dipakai di seluruh sistem. Lima sub-tab:

| Sub-tab | Isi | Nilai saat ini |
|---|---|---|
| **Pilar Konten** | Tema besar konten | Education, Entertainment, Soft Selling, Hard Selling, Product Highlight, Information |
| **Tipe Konten** | Bentuk konten | Video, Desain |
| **Platform** | Kanal publikasi | Instagram, TikTok |
| **Kategori Klien** | Pengelompokan klien | UMKM, Startup, Korporat, Retail |
| **Paket** | Nama paket + kuota konten & desain + aktif/tidak | 4 paket |

**Aksi:** Tambah · (khusus Paket) Edit · Hapus.

**Pengaman hapus:** data yang masih dipakai **tidak bisa dihapus**, muncul pesan
"Tidak bisa dihapus, masih dipakai data lain." Untuk Platform, pengecekannya
menyeluruh — konten, publikasi, metrik, integrasi, log sinkronisasi, dan data
audiens.

**Paket** punya penanda **Aktif/Nonaktif**; hanya paket aktif yang muncul saat
memilih paket klien.

**Status:** `READY` (terverifikasi runtime)

## Tab Integrasi

**Berbasis klien** — pilih klien dulu, baru muncul kartu integrasi milik klien
itu. Isi per kartu: status koneksi, nama akun, hasil sinkronisasi terakhir,
tombol **Sync**, dan link ke daftar post **belum tertaut**. Instagram punya
kartu Audiens terpisah.

Di bawahnya: **Riwayat Sinkronisasi**, bisa difilter per status dan tanggal.
CEO/Manager punya tambahan pilihan **"Semua Klien"**.

Dari tab ini juga ada link ke halaman **Import Data Performa**.

**Status:** `READY` untuk seluruh bagian yang tidak bergantung pada akun
terhubung (memilih klien, membaca kartu, membaca Riwayat Sinkronisasi, membuka
Import Data Performa). Bagian **Sync** baru bisa menghasilkan data setelah ada
akun yang benar-benar terhubung — lihat `EXTERNAL_BLOCKED` di Bagian 12.

## Pemisahan isi buku: Panduan Pengguna vs Panduan Administrator

### ✅ MASUK Buku Panduan Pengguna

- Tab **Umum** — melihat data akun, mengecek status Google & Gemini
- Tab **Data Pilihan** — seluruhnya (menambah pilar/tipe/platform/kategori/paket)
- Tab **Integrasi** — memilih klien, membaca status koneksi, menekan Sync,
  membaca Riwayat Sinkronisasi
- **Import Data Performa** — format CSV, cara mengunggah, cara membaca hasilnya
- **Connect Instagram / Connect TikTok** — dari sisi alur pengguna
- **Pemilih tema** (Terang/Gelap/Ikut Sistem)

### ❌ TIDAK masuk Buku Panduan Pengguna → Panduan Administrator/Deployment

- Isi berkas `.env` (`INSTAGRAM_CLIENT_ID`, `TIKTOK_CLIENT_SECRET`,
  `GEMINI_API_KEY`, dsb.)
- Pendaftaran aplikasi di Meta App Dashboard & TikTok Developer Portal, App
  Review, pendaftaran akun tester
- **Queue worker** (`php artisan queue:work`) dan **scheduler** (`cron` /
  Task Scheduler) — sudah terdokumentasi di `docs/RUNTIME.md`. Untuk
  pengembangan lokal, `composer run dev` menjalankan keduanya sekaligus; di
  production keduanya **wajib** dikonfigurasi terpisah
- Konfigurasi database, SMTP, Supervisor
- Perintah baris perintah: sinkronisasi manual, import Content Planner Excel,
  pembersihan log basi, perhitungan ulang skor risiko

> **Rekomendasi:** buat dokumen terpisah **"Panduan Administrator 523 Studio
> Platform"**, dan dari Buku Panduan Pengguna cukup merujuk ke sana bila
> pengguna menemui gejala seperti "tombol Sync ditekan tapi tidak terjadi apa-
> apa". Bahan mentahnya sudah tersedia di `docs/RUNTIME.md` dan
> `docs/TIKTOK_INTEGRATION.md`.

---

# Bagian 20. Portal Klien

Pengalaman terpisah, **layout berbeda**, tanpa sidebar internal, tanpa login,
tanpa logout.

## Bagaimana klien mendapat akses

CEO/Manager membuka Detail Klien, menyalin **link Portal Klien**, dan
mengirimkannya ke klien. Bentuknya kira-kira:

```
https://<domain>/portal/<token-panjang-acak>
```

Token dibuat otomatis saat klien dibuat.

## ⚠️ Sifat token — bagian keamanan paling penting di buku ini

**Link itu SENDIRI adalah kata sandinya.** Tidak ada lapisan lain.

| Sifat | Konsekuensi bagi pengguna |
|---|---|
| **Permanen** | Tidak pernah kedaluwarsa dengan sendirinya |
| **Tanpa identitas** | Sistem tidak tahu *siapa* yang membuka; siapa pun yang punya link punya akses penuh |
| **Tanpa logout** | Tidak ada cara "keluar"; link tetap berlaku di perangkat mana pun |
| **Bisa diteruskan** | Diteruskan ke grup WhatsApp = seluruh grup punya akses |

**Yang bisa dilihat pemegang link:** seluruh rencana konten, kalender, caption
sebelum tayang, riwayat konten, dan data performa klien tersebut. **Yang bisa
dilakukan:** menyetujui konten dan meminta revisi atas nama klien.

### Cara memutus akses

| Aksi | Efek |
|---|---|
| **Buat Link Baru** (regenerate) | Token diganti. **Link lama langsung mati.** Klien harus dikirimi link baru |
| **Nonaktifkan Portal** | Link tetap tersimpan tapi tidak bisa dipakai. Bisa diaktifkan lagi kapan saja |
| **Aktifkan Portal** | Mengaktifkan kembali dengan token yang sama |

**Link yang mati menampilkan halaman "tidak ditemukan" (404) — bukan pesan
"portal dinonaktifkan".** Ini disengaja: dari luar, link salah dan link
dinonaktifkan harus terlihat sama persis, agar tidak bocor bahwa suatu link
"pernah ada".

### Anjuran yang WAJIB masuk buku

1. Kirim link lewat kanal pribadi (email atau chat langsung ke PIC klien),
   **bukan grup**
2. **Buat Link Baru** bila: kontak klien berganti, kerja sama berakhir, atau
   link diduga tersebar
3. **Nonaktifkan Portal** saat kerja sama dijeda
4. Ingatkan klien: link ini setara kata sandi, jangan diteruskan

## Halaman di Portal Klien

Navigasi atas: **Dashboard · Kalender · Riwayat · Analytics**

### Dashboard

Empat kartu: Konten Bulan Ini · **Menunggu Persetujuan Anda** · Konten Tayang
Bulan Ini · Total Views (30 Hari). Ditambah grafik tren, konten terbaru, dan
bagian **Persetujuan** berisi dua daftar: yang **menunggu respons** dan yang
**sudah direspons**.

Sengaja **tidak ada** metrik operasional internal: tidak ada persentase
keterlambatan, jumlah tim, PIC, maupun skor risiko AI.

### Kalender

Kalender bulanan konten klien itu berdasarkan tanggal deadline. Klik item →
halaman persetujuan.

### Riwayat

Arsip seluruh konten lintas semua status, bisa difilter tipe & bulan, dengan
penomoran halaman. Kolom PIC dan Risiko sengaja tidak ditampilkan.

### Analytics

Versi ringkas dari Performa internal: statistik, tren, rincian per platform,
konten terbaik. Periode 7/30/90 hari. **Read-only**, dan selalu terkunci ke
klien pemilik token — tidak mungkin melihat data klien lain.

### Halaman Persetujuan

Menampilkan detail satu konten beserta caption yang diusulkan, dengan dua
tombol:

- **Setuju** → dicatat, tim internal diberi tahu; **status konten belum berubah**
- **Minta Revisi** (catatan **wajib** diisi) → konten langsung pindah ke **Perlu
  Revisi**, PIC diberi tahu

Hanya muncul saat konten berstatus **Menunggu Persetujuan**, dan **hanya bisa
sekali**.

## Isolasi antar klien

Setiap konten selalu diambil **melalui** klien pemilik token. Mencoba membuka
konten milik klien lain menghasilkan **404**, bukan 403 — agar tidak bocor bahwa
ID tersebut valid. Perilaku ini dilindungi **24 pengujian otomatis khusus
Portal Klien** (`ClientPortalTest`), ditambah langkah Portal Klien di dalam
`GoldenPathTest` yang berjalan **tanpa `actingAs()` sama sekali** — murni lewat
token, persis seperti klien asli.

**Status:** `READY` — bagian dengan bukti kebenaran terkuat di seluruh aplikasi.
Selain automated test, portal juga dibuka runtime dengan token klien sungguhan
(read-only) saat Final Pre-Merge Verification.

---

# Bagian 21. Fitur Global

| Fitur | Penjelasan | Perlu bab sendiri? | Status |
|---|---|---|---|
| **Pencarian global** | Kolom di topbar, minimal 2 huruf. Mencari **klien**, **anggota tim**, dan **judul konten** (maks. 5 hasil per kategori), sudah terbatas pada hak akses masing-masing | Sebut singkat | `READY` |
| **Notifikasi** | Lonceng di topbar dengan jumlah belum dibaca. Klik notifikasi → langsung ke halaman terkait; klik notifikasi konten yang sudah dihapus memberi pesan sopan, bukan error | Sebut singkat | `READY` |
| **Tandai semua dibaca** | Satu tombol untuk mengosongkan penanda | Sebut singkat | `READY` |
| **Profil** | Halaman ringkasan pekerjaan seseorang. Bisa dibuka dari Performa Tim atau hasil pencarian. Tampilan Copywriter berbeda | Sebut singkat | `READY` |
| **Tema** | Terang / Gelap / Ikut Sistem, di bagian bawah sidebar. Tersimpan per pengguna | **Ya, di bab "Cara Memulai"** | `READY` |
| **Sidebar bisa dilipat** | Tombol panah melipat sidebar jadi ikon saja; pilihannya diingat | Sebut singkat | `READY` |
| **Pin** | Penanda pribadi "ini fokus saya". Konten yang di-pin selalu naik ke atas di papan Produksi & Daftar, dan muncul di **Fokus Saya** di Beranda. Ada batas maksimal; otomatis lepas saat konten Sudah Tayang | **Ya, layak subbab** — konsep khas yang tidak terduga | `READY` |
| **Logout** | Di bagian bawah sidebar | Sebut singkat | `READY` |
| **Filter per halaman** | Tiap halaman punya filternya sendiri (klien, bulan, status, platform, periode); pilihan ikut terbawa saat pindah halaman & saat ganti tab | Jelaskan pola umumnya sekali | `READY` |

**Jenis notifikasi yang ada:**

| Kejadian | Penerima |
|---|---|
| Rencana konten diajukan | Pemegang hak menyetujui rencana (kecuali pengaju) |
| Brief diterapkan ke tim | PIC yang ditugaskan |
| Jobdesk Tambahan dibuat | PIC yang ditugaskan |
| Klien menyetujui konten | Semua Manager & SMO aktif |
| Klien meminta revisi | PIC yang ditugaskan |
| Anomali performa terdeteksi | Terkait — mengarah ke halaman Performa |
| Risiko keterlambatan tinggi | Terkait |

**Catatan:** Portal Klien punya pemilih tema sendiri yang hanya tersimpan di
peramban klien (tidak ada akun untuk menyimpannya).

---

# Bagian 22. Status Fitur, Riwayat Temuan, dan Batasan yang Masih Berlaku

## Ringkasan sekali baca

| | |
|---|---|
| `KNOWN_ISSUE` tersisa | **0** — seluruh 8 temuan awal diperbaiki dengan regression test |
| `NOT_READY` tersisa | **0** — KI-11 & KI-14 diperbaiki; KI-18 adalah `OUT_OF_SCOPE` by design, bukan fitur belum jadi |
| Batasan yang **masih** berlaku | **Eksternal saja**: live OAuth Instagram (Meta App Review) & TikTok (Developer Portal) |
| Batasan berikutnya | Akurasi model Delay Risk belum divalidasi (fitur tetap aman dipakai) |
| Dependensi runtime | Scheduler & queue worker wajib jalan di production (bukan cacat fitur) |

## Riwayat Audit Sebelum Stabilisasi — KI-01 s/d KI-20

> ⚠️ **Kolom "Status Audit Pertama" di tabel ini adalah HISTORIS — KONDISI
> SEBELUM STABILISASI.** Jangan dibaca sebagai keadaan aplikasi sekarang.
> Kondisi sekarang ada di kolom **"Status Sekarang"**. Tabel dipertahankan
> karena berguna sebagai engineering history dan sebagai penjelasan kenapa
> beberapa keputusan desain (mis. sanitasi tanggal di backend) ada.

| ID | Area | Status Audit Pertama *(historis)* | Status Sekarang | Masalah (asli, historis) | Dokumentasikan Sekarang? |
|---|---|---|---|---|---|
| **KI-01** | Rencana Konten → Tambah Konten | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentPlanTest` | `Rule::exists()` tanpa import; form/kode field name mismatch | ✅ Ya, sudah boleh — lihat laporan stabilisasi §2 |
| **KI-02** | Jobdesk Tambahan | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentPlanTest` | Sama root cause KI-01 | ✅ Ya, sudah boleh |
| **KI-03** | Detail Konten | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentItemDetailTest` + verifikasi runtime data nyata | `$activeCountsByMember` tidak pernah dibuat | ✅ Ya, sudah boleh — **blocker terbesar sudah tidak ada** |
| **KI-04** | Ganti Penanggung Jawab | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentItemDetailTest` | Field mismatch + variabel `$user` tidak terdefinisi | ✅ Ya, sudah boleh |
| **KI-05** | Kelola Pengguna → Assign Klien | `KNOWN_ISSUE` | ✅ **FIXED** — `UserManagementTest` | `$user->isClientUser()` tidak ada | ✅ Ya, sudah boleh |
| **KI-06** | Undang User / akses login | `KNOWN_ISSUE` | ✅ **FIXED** — `UserManagementTest` | `login_enabled` tidak fillable + tidak ada tombol UI, **sekarang ada** | ✅ Ya, sudah boleh (peringatan lama sudah tidak relevan) |
| **KI-07** | AI Brief — tanggal | `KNOWN_ISSUE` | ✅ **FIXED** — `BriefGenerationDateTest` | Prompt tidak tahu tanggal hari ini; sekarang divalidasi+fallback deterministik | ✅ Ya, tanpa peringatan tanggal lagi (tetap sarankan user cek manual sebagai praktik baik) |
| **KI-08** | Integrasi Instagram & TikTok | `NEEDS_VERIFICATION` | 🔒 **`EXTERNAL_BLOCKED`** — siap secara kode; seluruh jalur non-consent lulus (`SocialIntegrationOAuthTest`, 10 test). Live consent bergantung Meta App Review / TikTok Developer Portal | Kode lengkap tapi belum pernah dipakai | ⚠️ Ya, tulis prosedurnya **dengan kotak peringatan App Review**; jangan klaim "sudah berfungsi end-to-end" |
| **KI-09** | Import CSV Performa | `KNOWN_ISSUE` | ✅ **FIXED** — `ImportPerformanceScopeTest` | Tidak ada guard client scope | ✅ Ya, sudah boleh |
| **KI-10** | Dashboard | `KNOWN_ISSUE` (awalnya ditandai `READY` di rekap, itu keliru) | ✅ **FIXED** — `DashboardScopeTest` | Nol scoping per client | ✅ Ya, dengan cakupan yang sekarang benar |
| **KI-11** | Enum status workflow | `NOT_READY` | ✅ **FIXED (dihapus)** | Dead code, 2 method badan kosong | ❌ Tidak relevan bagi pengguna |
| **KI-12** | Revision Log & Publishing Tracker | `KNOWN_ISSUE` | ✅ **FIXED** — `LegacyRouteRedirectTest` (redirect ke tab resmi) | Duplikat tanpa pintu masuk UI | ❌ Dokumentasikan **hanya** tab di Produksi (URL lama tetap jalan via redirect) |
| **KI-13** | Rencana Konten ditolak | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentPlanTest` (jalur Ditolak→Draf→ajukan ulang + riwayat) | Tidak ada jalur balik dari Ditolak | ✅ Ya, sudah boleh — keterbatasan lama sudah tidak berlaku |
| **KI-14** | Proses otomatis (scheduler & queue) | `NOT_READY` | ✅ **FIXED** — perintah terjadwal sudah terdaftar dan `composer run dev` menjalankan scheduler + queue sekaligus. **Tetap dependensi runtime:** production wajib mengonfigurasi worker + cron/Supervisor | Tidak ada cara mudah jalankan semua proses bareng | ✅ Ya — bukan sebagai "fitur rusak", tapi sebagai bab Troubleshooting ("Sync ditekan tapi tidak terjadi apa-apa") |
| **KI-15** | Konfigurasi pengujian | `KNOWN_ISSUE` | ✅ **FIXED** — DB testing terisolasi permanen + safeguard hard-abort | `phpunit.xml` menunjuk DB dev | ❌ Bukan untuk pengguna → Panduan Administrator |
| **KI-16** | Cakupan pengujian | `NEEDS_VERIFICATION` | ✅ **FIXED** — **148 test / 363 assertion** (dari 26), lintas semua area utama, termasuk golden/rejection/revision path dan matriks akses 6 role | Hanya Portal Klien punya test | ❌ Bukan untuk pengguna |
| **KI-17** | Ketidaksesuaian istilah | `KNOWN_ISSUE` | ✅ **FIXED** — sweep **menyeluruh** ke seluruh `resources/views/`: ~45 string di 15 file diperbaiki, termasuk 2 **PDF laporan client-facing** yang sebelumnya 100% Bahasa Inggris, plus 1 celah pesan validasi (`pic_user_id`, `rejection_note`) | Menu vs judul halaman tidak sama | ✅ Ya — pakai tabel **"Terminologi Resmi untuk Dokumentasi"** sebagai rujukan tunggal |
| **KI-18** | Publikasi langsung ke media sosial | `NOT_READY` | ✅ Confirmed tetap `OUT_OF_SCOPE` (by design, bukan bug) — tidak ada wording menyesatkan ditemukan | Tidak ada, dan eksplisit di luar cakupan | ✅ Ya — tulis eksplisit "tidak tersedia" |
| **KI-19** | Dokumentasi kode usang | `KNOWN_ISSUE` | ✅ **FIXED** — komentar diperbarui | Komentar bilang OAuth "UI saja" padahal sudah fungsional | ❌ Bukan untuk pengguna |
| **KI-20** | Kode mati kecil | `KNOWN_ISSUE` | ✅ **FIXED (dihapus)** | `$picOptions` tidak terpakai | ❌ Tidak |

**Temuan tambahan Phase L (re-audit, di luar KI-01...KI-20):** 3 authorization
leak baru (AI Strategy History, Import Audience CSV, kanban drag-drop
Produksi) — semuanya **FIXED**, detail di laporan stabilisasi §3. Tidak
berdampak ke konten buku panduan (bukan bug user-facing, murni celah akses).

## HISTORIS — KONDISI SEBELUM STABILISASI: rekapitulasi audit pertama

> ⚠️ **Angka di bawah ini adalah kondisi audit pertama, BUKAN kondisi sekarang.**
> Rekapitulasi yang berlaku ada di sub-bagian berikutnya.
>
> **Catatan koreksi hitung:** tabel asli menulis total 50 fitur (26/13/8/3),
> tetapi daftar rinciannya sendiri memuat **53 item** (28/14/8/3). Angka
> ringkasnya salah hitung sejak awal. Rekapitulasi kondisi sekarang memakai
> **53** — hasil menghitung ulang item yang benar-benar terdaftar.

| Status | Jumlah fitur *(angka asli)* | Jumlah item yang benar-benar terdaftar |
|---|---|---|
| `READY` | 26 | **28** |
| `NEEDS_VERIFICATION` | 13 | **14** |
| `KNOWN_ISSUE` | 8 | **8** |
| `NOT_READY` | 3 | **3** |
| **Total** | 50 | **53** |

Rincian:

**`READY` — label asli “26”, terdaftar 28 item** — Beranda · Dashboard · Rencana Konten (lihat) · Buat Rencana ·
Ajukan Rencana · Setujui/Tolak Rencana · Papan Produksi · Tab Revisi · Tab Sudah
Tayang · Status Management *(logika)* · Koreksi Status · Catatan Revisi ·
Kerjakan Revisi · Catat Publikasi *(logika)* · Kelola Klien · Ubah Paket · Atur
PIC dari Detail Klien · Kelola link Portal Klien · Edit Role · Nonaktifkan/
Aktifkan User · Performa Tim · Absensi · Data Pilihan & Paket · Portal Klien
(Dashboard/Kalender/Riwayat/Persetujuan) · Pencarian · Notifikasi · Pin · Tema

**`NEEDS_VERIFICATION` — label asli “13”, terdaftar 14 item** — Performa: Analytics · Performa: Tabel Performa ·
Performa: Audiens · Detail Performa Konten · Ekspor CSV · Import Audience CSV ·
AI Strategy · Instagram Integration · TikTok Integration · Unmatched Instagram ·
Unmatched TikTok · Laporan (2 jenis) · Portal Klien: Analytics · Skor Risiko
Keterlambatan & Deteksi Anomali

**`KNOWN_ISSUE` (8, cocok)** — Tambah Konten (KI-01) · Jobdesk Tambahan (KI-02) ·
Detail Konten (KI-03) · Ganti PIC (KI-04) · Assign Klien (KI-05) · Undang User /
akses login (KI-06) · AI Brief (KI-07) · Import CSV Performa (KI-09)

**`NOT_READY` (3, cocok)** — Proses otomatis terjadwal (KI-14) · Publikasi langsung ke
media sosial (KI-18) · Enum status workflow, kode mati (KI-11)

> Catatan historis: Dashboard (KI-10) waktu itu tetap dihitung `READY` karena
> berfungsi penuh — masalahnya cakupan data, bukan kerusakan.

## Rekapitulasi status — KONDISI SEKARANG

**Dasar penghitungan:** 53 item fitur yang benar-benar terdaftar di rincian
audit pertama (28 `READY` + 14 `NEEDS_VERIFICATION` + 8 `KNOWN_ISSUE` +
3 `NOT_READY`). Angka "50" di rekap lama tidak pernah cocok dengan daftarnya
sendiri; di sini dihitung ulang supaya jumlah baris = jumlah item.

| Kategori | Jumlah | Arti |
|---|:--:|---|
| `READY` | **45** | Berfungsi, terverifikasi, boleh didokumentasikan penuh |
| `EXTERNAL_BLOCKED` | **2** | Siap secara kode; verifikasi live tertahan pihak ketiga |
| `OPERATIONALLY_SAFE_WITH_LIMITATION` | **1** | Aman dipakai, tapi satu aspeknya belum divalidasi |
| `NEEDS_VERIFICATION` | **3** | Tidak ada indikasi bug, tapi belum diuji ulang |
| `OUT_OF_SCOPE` | **2** | Bukan fitur (by design / dead code yang dihapus) |
| `KNOWN_ISSUE` | **0** | — |
| `NOT_READY` | **0** | — |
| **Total** | **53** | |

### `READY` (45)

Seluruh 28 item yang sudah `READY` di audit pertama, **ditambah**:

- **8 bekas `KNOWN_ISSUE`** — Tambah Konten (KI-01) · Jobdesk Tambahan (KI-02) ·
  Detail Konten (KI-03) · Ganti PIC (KI-04) · Assign Klien (KI-05) · Undang User
  / akses login (KI-06) · AI Brief (KI-07) · Import CSV Performa (KI-09).
  Masing-masing punya regression test.
- **8 bekas `NEEDS_VERIFICATION`** — Performa: Analytics · Performa: Tabel
  Performa · Performa: Audiens · Detail Performa Konten · Ekspor CSV · Import
  Audience CSV · AI Strategy · Laporan (2 jenis).
- **1 bekas `NOT_READY`** — Proses otomatis terjadwal (KI-14): didukung penuh
  oleh aplikasi; yang tersisa adalah **dependensi runtime**, bukan fitur yang
  belum jadi.

Dua catatan tambahan yang tidak mengubah hitungan: **Dashboard** naik dari
"berfungsi tapi cakupan salah" jadi benar-benar ter-scope (KI-10), dan **Rencana
Konten Ditolak** tidak lagi buntu (KI-13) — keduanya bagian dari item yang sudah
terhitung.

### `EXTERNAL_BLOCKED` (2)

| Fitur | Kenapa | Yang sudah terbukti |
|---|---|---|
| Integrasi Instagram | Meta App Review — selama app mode Development, hanya akun terdaftar sebagai *Instagram Tester* yang bisa connect | Redirect, `state`, validasi callback, penukaran token, upsert `ApiIntegration` (`SocialIntegrationOAuthTest`) |
| Integrasi TikTok | TikTok Developer Portal masih berpotensi `unauthorized_client` — registrasi app di sisi provider | OAuth + PKCE lengkap, rotasi refresh token, jalur error, semua lulus test yang sama |

**Ini bukan `KNOWN_ISSUE`.** Tidak ada defect kode yang diketahui pada keduanya.

### `OPERATIONALLY_SAFE_WITH_LIMITATION` (1)

**Skor Risiko Keterlambatan & Deteksi Anomali.** *Graceful degradation*-nya
dikonfirmasi: kalau model/script Python gagal, sistem mencatat log lalu
melewatinya — workflow utama **tidak** crash. Yang belum divalidasi adalah
**akurasi prediksi model ML** itu sendiri (butuh data historis memadai + model
terlatih). Boleh didokumentasikan sebagai fitur bantu, jangan dijual sebagai
prediksi yang akurat.

### `NEEDS_VERIFICATION` (3)

| Fitur | Kenapa masih di sini |
|---|---|
| Unmatched Instagram | Tidak diuji ulang di sprint ini; tidak ada indikasi bug saat re-audit jalur kodenya, tapi belum ada regression test maupun runtime check khusus |
| Unmatched TikTok | Sama seperti di atas |
| Portal Klien: Analytics | `ClientPortalTest` tidak menyentuh sub-halaman Analytics-nya secara spesifik |

Ketiganya **boleh ditulis di buku secara konseptual**; hindari klaim detail yang
belum diverifikasi, dan ambil screenshot hanya dari kondisi nyata.

### `OUT_OF_SCOPE` (2)

- **Publikasi langsung ke media sosial (KI-18)** — memang tidak ada dan tidak
  direncanakan. Tulis eksplisit di buku sebagai keterbatasan yang disengaja.
  Dikonfirmasi ulang: tidak ada wording di UI yang menyesatkan soal ini.
- **Enum status workflow (KI-11)** — dead code, sudah dihapus. Tidak pernah
  merupakan fitur pengguna; dicantumkan hanya agar jumlah item tetap cocok
  dengan daftar audit pertama.

### Batasan yang berlaku lintas fitur (bukan status fitur)

**Scheduler & queue worker adalah dependensi runtime.** `composer run dev`
menjalankan keduanya untuk pengembangan lokal; di production wajib dikonfigurasi
sendiri. Kalau mati, sinkronisasi terjadwal, penandaan terlambat, skor risiko,
dan deteksi anomali diam **tanpa pesan error**. Ini bukan cacat fitur — tapi
harus muncul di bab Troubleshooting buku, dan detail teknisnya masuk Panduan
Administrator, bukan Buku Panduan Pengguna.

---

# Bagian 23. Daftar Prosedur untuk Buku Panduan

Prioritas **Tinggi** = wajib ada di rilis pertama buku. **Sedang** = sebaiknya
ada. **Rendah** = boleh menyusul.

Kolom Status memakai kategori yang sama dengan Bagian 22:
`READY` (tulis lengkap) · `EXTERNAL_BLOCKED` (tulis lengkap **plus** kotak
peringatan App Review) · `NEEDS_VERIFICATION` (tulis konseptual, screenshot
hanya dari kondisi nyata). **Tidak ada lagi prosedur berstatus `KNOWN_ISSUE`
atau yang perlu ditunda.**

| ID | Tutorial | Role | Prioritas | Status |
|---|---|---|---|---|
| UG-01 | Masuk ke sistem dengan akun Google | Semua internal | Tinggi | `READY` |
| UG-02 | Mengenal Beranda & panel Langkah Berikutnya | Semua internal | Tinggi | `READY` |
| UG-03 | Check-in & check-out harian | Semua internal | Tinggi | `READY` |
| UG-04 | Mengganti tema & melipat sidebar | Semua internal | Rendah | `READY` |
| UG-05 | Mencari klien, orang, atau konten | Semua internal | Sedang | `READY` |
| UG-06 | Membaca & menindaklanjuti notifikasi | Semua internal | Sedang | `READY` |
| UG-07 | Mem-pin konten sebagai fokus pribadi | Semua internal | Sedang | `READY` |
| UG-08 | Menambahkan klien baru | CEO, Manager | Tinggi | `READY` |
| UG-09 | Menentukan & mengubah paket klien | CEO, Manager | Tinggi | `READY` |
| UG-10 | Menugaskan tim (PIC) ke klien | CEO, Manager | **Tinggi** | `READY` — dokumentasikan **kedua** jalur (Detail Klien & Kelola Pengguna) |
| UG-11 | Mengaktifkan & membagikan link Portal Klien dengan aman | CEO, Manager | **Tinggi** | `READY` |
| UG-12 | Menghubungkan akun Instagram klien | CEO, Manager | Tinggi | `EXTERNAL_BLOCKED` — siap secara kode, live OAuth bergantung App Review |
| UG-13 | Menghubungkan akun TikTok klien | CEO, Manager | Sedang | `EXTERNAL_BLOCKED` — siap secara kode, live OAuth bergantung App Review |
| UG-14 | **Onboarding klien baru dari nol sampai siap** (menggabungkan UG-08…UG-13) | CEO, Manager | **Tinggi** | `READY` — hanya langkah UG-12/UG-13 yang `EXTERNAL_BLOCKED`; sebutkan Import CSV sebagai jalur alternatif |
| UG-15 | Membuat Rencana Konten bulanan | CEO, Manager, Copywriter | Tinggi | `READY` |
| UG-16 | Menambahkan konten ke rencana | CEO, Manager, Copywriter | Tinggi | `READY` |
| UG-17 | Mengajukan & menyetujui Rencana Konten | CEO, Manager, SMO, Copywriter | Tinggi | `READY` |
| UG-18 | Mencatat permintaan mendadak (Jobdesk Tambahan) | CEO, Manager, Copywriter | Sedang | `READY` |
| UG-19 | Membuat brief produksi dengan AI | Copywriter, Manager | Tinggi | `READY` |
| UG-20 | Berdiskusi dengan AI & mengedit brief manual | Copywriter, Manager | Sedang | `READY` |
| UG-21 | Menerapkan brief ke tim produksi | Copywriter, Manager | Tinggi | `READY` |
| UG-22 | Membaca papan Produksi (Kanban & Daftar) | Semua internal | **Tinggi** | `READY` |
| UG-23 | Mengerjakan konten yang ditugaskan (alur harian PIC) | Content Creator, Graphic Designer | **Tinggi** | `READY` |
| UG-24 | Menandai footage sudah di-take & mengisi link hasil | Content Creator | Sedang | `READY` |
| UG-25 | Mengisi draft caption untuk dibaca klien | Copywriter, Manager | Sedang | `READY` |
| UG-26 | Meminta revisi & mengerjakannya | Manager, SMO, PIC | Tinggi | `READY` |
| UG-27 | Menyetujui konten (review internal) | CEO, Manager, SMO | **Tinggi** | `READY` |
| UG-28 | Menjadwalkan & mencatat publikasi | SMO, CEO | **Tinggi** | `READY` |
| UG-29 | Mengoreksi status yang salah | CEO, Manager | Sedang | `READY` |
| UG-30 | Memindahkan konten ke PIC lain | CEO, Manager | Sedang | `READY` |
| UG-31 | Membaca halaman Performa | CEO, Manager, SMO | Tinggi | `READY` |
| UG-32 | Menautkan post media sosial yang belum terhubung | SMO, CEO | Sedang | `NEEDS_VERIFICATION` — butuh data sinkronisasi nyata |
| UG-33 | Mengimpor data performa dari CSV | CEO, Manager, SMO | Sedang | `READY` |
| UG-34 | Mengekspor data performa | CEO, Manager, SMO | Rendah | `READY` |
| UG-35 | Menjalankan analisis AI Strategy | CEO, Manager, SMO | Sedang | `READY` |
| UG-36 | Berdiskusi & memperbarui analisis AI | CEO, Manager, SMO | Rendah | `READY` |
| UG-37 | Menerapkan & menarik kembali AI Strategy | CEO, Manager, SMO | Sedang | `READY` |
| UG-38 | Membuat laporan untuk klien | CEO, Manager, SMO | Tinggi | `READY` |
| UG-39 | Mengundang anggota tim baru | CEO, Manager | Tinggi | `READY` |
| UG-40 | Mengubah role anggota tim | CEO, Manager | Sedang | `READY` |
| UG-41 | Menonaktifkan anggota & memindahkan tugasnya | CEO, Manager | Tinggi | `READY` |
| UG-42 | Memantau beban kerja tim | CEO, Manager | Sedang | `READY` |
| UG-43 | Melihat rekap kehadiran bulanan | CEO, Manager | Sedang | `READY` |
| UG-44 | Mengelola Data Pilihan & Paket | CEO, Manager, SMO | Sedang | `READY` |
| UG-45 | Menjalankan sinkronisasi & membaca riwayatnya | CEO, Manager, SMO | Sedang | `EXTERNAL_BLOCKED` — halaman & riwayat `READY`; hasil sync butuh akun terhubung |
| UG-46 | **(Untuk klien)** Menggunakan Portal Klien | Klien | **Tinggi** | `READY` |
| UG-47 | **(Untuk klien)** Menyetujui konten / meminta revisi | Klien | **Tinggi** | `READY` |
| UG-48 | Troubleshooting: "kenapa halaman saya kosong?" | Semua internal | **Tinggi** | `READY` |
| UG-49 | Troubleshooting: "kenapa data performa tidak muncul?" | CEO, Manager, SMO | **Tinggi** | `READY` — bahas 3 sebab: belum sync, post belum tertaut, scheduler/queue mati di server |
| UG-50 | Troubleshooting: "kenapa saya tidak bisa masuk?" | Semua internal | **Tinggi** | `READY` — bahas: akses login belum diaktifkan, status akun nonaktif, email Google berbeda |

**Ringkasan kelayakan (kondisi sekarang):**

| Status | Jumlah | Artinya untuk penulis |
|---|:--:|---|
| `READY` | **46** | Tulis lengkap, ambil screenshot bebas |
| `EXTERNAL_BLOCKED` | **3** | UG-12, UG-13, UG-45 — tulis lengkap **plus** kotak peringatan App Review |
| `NEEDS_VERIFICATION` | **1** | UG-32 — tulis konseptual, screenshot hanya dari kondisi nyata |
| **Ditunda** | **0** | Tidak ada lagi prosedur yang harus ditunda |
| **Total** | **50** | |

> **Prosedur yang sengaja TIDAK dibuat** karena tidak relevan bagi pengguna:
> menjalankan perintah baris perintah, mengisi `.env`, memasang queue worker,
> import Content Planner dari Excel (perkakas migrasi sekali pakai), dan halaman
> `/revision-log` serta `/publishing-tracker` (duplikat, lihat KI-12).

---

# Bagian 24. Rencana Screenshot

Prinsip: **1 screenshot = 1 konsep penting.** Jangan memotret setiap klik.

**Prasyarat data — sudah terpenuhi.** Database development sekarang berisi data
demo dari `database/seeders/DemoSeeder.php`: 10 user, 5 client, 15 rencana
konten, 85 content item. Cukup untuk seluruh rencana screenshot di bawah.
Provenance-nya sudah dikonfirmasi (`KNOWN_SOURCE`) — lihat "Checklist Keamanan
Data Dokumentasi".

**Tidak ada lagi screenshot yang diblokir oleh bug.** Semua larangan pemotretan
di versi sebelumnya berasal dari KI-01…KI-04 dan KI-07, yang sudah diperbaiki.
Yang tersisa hanyalah:

1. **Aturan keamanan data** (sensor token, email, link privat, nama klien) —
   tetap wajib, lihat catatan penyuntingan di akhir bagian ini.
2. **Screenshot yang butuh akun media sosial benar-benar terhubung**
   (SS-CL-09, SS-AN-06, sebagian SS-ST-04) — tertahan `EXTERNAL_BLOCKED`, bukan
   bug. Ambil setelah ada akun tester yang berhasil connect; jangan direkayasa.

## Orientasi & akses

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-AUTH-01 | Halaman Masuk | Menunjukkan bahwa satu-satunya cara masuk adalah tombol Google | — | Tombol "Masuk dengan Google", teks "Khusus tim internal 523 Studio" |
| SS-AUTH-02 | Halaman Masuk (gagal) | Menjelaskan pesan error "belum memiliki akses login" | — | Pesan error tampil jelas |
| SS-NAV-01 | Sidebar penuh | Peta menu lengkap | CEO | Semua 6 grup terbuka, termasuk tombol merah Jobdesk Tambahan |
| SS-NAV-02 | Sidebar terbatas | Membuktikan menu berbeda per role | Content Creator | Hanya grup Ringkasan & Konten — **kontras dengan SS-NAV-01** |
| SS-NAV-03 | Topbar | Menunjukkan pencarian & lonceng notifikasi | Manager | Pencarian terisi kata kunci + hasil dropdown; lonceng ber-badge |
| SS-NAV-04 | Pemilih tema | Cara mengganti tampilan | Siapa saja | Tiga tombol Terang/Gelap/Ikut Sistem terlihat |

## Beranda & absensi

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-HOME-01 | Beranda | Titik awal semua pengguna | Content Creator | Kartu Absensi + panel Langkah Berikutnya berisi **minimal 2 item** + Fokus Saya berisi 1–2 konten ter-pin |
| SS-HOME-02 | Beranda Copywriter | Membuktikan tampilan Copywriter berbeda | Copywriter | Antrean brief, bukan daftar task produksi |
| SS-ATT-01 | Kartu Absensi (sebelum) | Lokasi tombol Check-In | Siapa saja | Tombol Check-In aktif |
| SS-ATT-02 | Kartu Absensi (sesudah, telat) | Menjelaskan status Telat | Siapa saja | Status "Telat" + jumlah menit |
| SS-ATT-03 | Performa Tim → Kehadiran | Membaca rekap bulanan | Manager | Tabel rekap dengan kolom Hari Kerja/Hadir/Telat/Tidak Hadir/**Lupa Check-Out** terisi |

## Klien & onboarding

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-CL-01 | Kelola Klien | Struktur halaman + tombol Tambah Klien | Manager | Minimal 3 klien dengan status berbeda (Aktif, Dijeda) |
| SS-CL-02 | Tambah Klien | Field yang harus diisi | Manager | Form terisi contoh, termasuk **dropdown Paket** |
| SS-CL-03 | Detail Klien (atas) | Peta halaman detail | Manager | Logo, status, kartu Paket Aktif **berisi kuota**, kartu Portal Klien |
| SS-CL-04 | Detail Klien → modal Ubah Paket | Cara mengganti paket | Manager | **Modal terbuka**, dropdown menampilkan kuota tiap paket |
| SS-CL-05 | Detail Klien → kartu PIC Ditugaskan | Cara menugaskan tim (jalur yang berfungsi) | Manager | Minimal 3 PIC dengan jumlah konten aktif berbeda |
| SS-CL-06 | Detail Klien → modal Keluarkan PIC | Kewajiban memilih pengganti | Manager | **Modal terbuka**, dropdown pengganti, peringatan jumlah konten aktif |
| SS-CL-07 | Detail Klien → kartu Portal Klien | Lokasi link + tombol pengelolaannya | Manager | Link terlihat (**sensor sebagian token**), tombol Buat Link Baru & Nonaktifkan |
| SS-CL-08 | Detail Klien → kartu Integrasi Analytics (belum) | Kondisi awal sebelum connect | Manager | Kedua kartu berstatus belum terhubung + tombol Connect |
| SS-CL-09 | Detail Klien → kartu Integrasi (sudah) | Seperti apa kondisi berhasil | Manager | Status Terhubung + nama akun + waktu sinkronisasi terakhir |

## Rencana Konten

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-CP-01 | Rencana Konten (Tabel) | Struktur halaman + tombol Buat Rencana | Manager | Beberapa rencana dengan status berbeda + **Target vs Realisasi** terisi |
| SS-CP-02 | Rencana Konten (Kalender) | Tampilan alternatif | Manager | Kalender berisi item di beberapa tanggal, dikelompokkan per klien |
| SS-CP-03 | Modal Buat Rencana | Field yang diisi | Manager | **Modal terbuka** |
| SS-CP-04 | Detail Rencana Konten | Isi rencana + tombol Ajukan | Copywriter | Status **Draf**, daftar konten, header Target |
| SS-CP-05 | Detail Rencana (menunggu) | Tombol Setujui & Tolak | Manager | Status **Menunggu Persetujuan**, kedua tombol terlihat |
| SS-CP-06 | Modal Jobdesk Tambahan | Input cepat permintaan mendadak | Manager | **Modal terbuka** |
| SS-CP-07 | Detail Rencana (ditolak) | Alasan penolakan + tombol Kembalikan ke Draf | Copywriter | Status **Ditolak**, panel **Riwayat Keputusan** berisi alasan, tombol "Kembalikan ke Draf & Perbaiki" terlihat |

## Produksi

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-PR-01 | Produksi → Papan (Kanban) | Konsep inti papan produksi | Manager | **Semua 8 kolom terisi minimal 1 kartu**, ada 1 kartu ter-pin di atas, ada 1 kartu bertanda terlambat |
| SS-PR-02 | Produksi → Daftar | Tampilan alternatif + pengurutan | Manager | Kolom terurut, filter status aktif |
| SS-PR-03 | Produksi → tab Revisi | Tempat melihat semua revisi | Manager | Beberapa revisi, ada yang dari klien dan dari internal |
| SS-PR-04 | Produksi → tab Sudah Tayang | Riwayat publikasi | SMO | Beberapa publikasi lintas platform |
| SS-PR-05 | Modal saat menggeser kartu ke Revisi | Catatan revisi wajib diisi | Manager | **Modal terbuka**, kolom catatan kosong |
| SS-PR-06 | Detail Konten (atas) | Peta halaman kerja utama | Content Creator | Judul, info konten, kartu AI Brief |
| SS-PR-07 | Detail Konten → Status Management | Tombol yang tersedia per status | Content Creator | Status **Sedang Dikerjakan**, tombol aktif & nonaktif berdampingan |
| SS-PR-08 | Detail Konten → tombol nonaktif + tooltip | Membuktikan pembatasan hak akses terlihat | Copywriter | Tooltip "Kamu tidak punya izin memindahkan status" |
| SS-PR-09 | Detail Konten → penanda footage | Fitur khusus video | Content Creator | Status Sedang Dikerjakan + kolom tanggal take |
| SS-PR-10 | Detail Konten → modal Koreksi Status | Jalur khusus Manager/CEO | Manager | **Modal terbuka**, kolom alasan wajib |
| SS-PR-11 | Detail Konten → modal Ganti PIC | Kandidat + beban kerja | Manager | **Modal terbuka**, kandidat terurut dari task paling sedikit (hanya tim klien itu) |
| SS-PR-12 | Modal Catat Publikasi | Data yang harus diisi | SMO | **Modal terbuka**, semua field terlihat |

## AI Brief & AI Strategy

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-AI-01 | Kartu AI Brief (kosong) | Ajakan membuat brief | Copywriter | Tombol Buat Brief menonjol |
| SS-AI-02 | Kartu AI Brief (terisi) | Isi brief lengkap | Copywriter | Hook, adegan/slide, talent, properti, **Tanggal Mulai & Tanggal Posting** (sekarang valid — justru bagus ditampilkan) |
| SS-AI-03 | Panel Diskusi AI Brief | Cara berdiskusi + usulan perubahan | Copywriter | Percakapan berisi minimal 1 tanya-jawab |
| SS-AI-04 | Kartu Estimasi Kompleksitas & Kelayakan | Membaca penilaian AI | Copywriter | Penilaian kelayakan dengan margin hari yang masuk akal terhadap deadline |
| SS-AI-05 | Brief berstatus Final | Kondisi terkunci + tombol Tarik Kembali | Copywriter | Brief read-only |
| SS-AI-06 | Panel AI Strategy di Performa | Lokasi & hasil analisis | Manager | Ringkasan + Action Items + Komposisi Disarankan + Kelengkapan Data |
| SS-AI-07 | Modal detail Ide Konten | Ide + skor + tombol regenerate | Manager | **Modal terbuka** |
| SS-AI-08 | Chat AI Strategy | Diskusi & arahan menekan tombol Perbarui | Manager | Balasan AI yang mengarahkan ke tombol Perbarui |
| SS-AI-09 | Hasil setelah Terapkan | Bukti bahwa Apply benar-benar membuat konten | Manager | Halaman Rencana Konten + pesan berisi jumlah draft & pembagian PIC |

## Performa & laporan

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-AN-01 | Performa (belum pilih klien) | Menjelaskan keadaan kosong yang disengaja | SMO | Pesan minta memilih klien |
| SS-AN-02 | Performa → tab Analytics | Struktur ringkasan | SMO | Kartu statistik + grafik tren + rincian platform + konten terbaik |
| SS-AN-03 | Performa → tab Tabel Performa | Daftar post + aksi Hubungkan Konten | SMO | Ada baris **belum tertaut** yang jelas terlihat |
| SS-AN-04 | Performa → tab Audiens | Data audiens Instagram | SMO | Follower, demografi, jam aktif terisi |
| SS-AN-05 | Detail Performa 1 Konten | Perbandingan vs rata-rata klien | SMO | Bagian perbandingan terisi |
| SS-AN-06 | Halaman Unmatched Instagram | Cara menautkan manual | SMO | Beberapa post + dropdown pilihan konten + **saran pencocokan** |
| SS-AN-07 | Import Data Performa | Format CSV & cara unggah | Manager | Form + keterangan kolom wajib |
| SS-AN-08 | Hasil import (sebagian dilewati) | Membaca laporan hasil | Manager | Pesan berhasil + daftar baris yang dilewati beserta alasannya |
| SS-RP-01 | Laporan | Dua jenis laporan + form | Manager | Kedua form terlihat + riwayat laporan terisi |
| SS-RP-02 | Contoh PDF Laporan Progres | Hasil akhir yang dikirim ke klien | — | Halaman pertama PDF |
| SS-RP-03 | Contoh PDF Laporan Performa | Hasil akhir yang dikirim ke klien | — | Halaman pertama PDF |

## Tim & pengaturan

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-TM-01 | Kelola Pengguna | Struktur halaman | Manager | Beberapa orang, ada yang **multi-role**, ada yang bertanda "belum memiliki akses dashboard" |
| SS-TM-07 | Kelola Pengguna → tombol akses login | Membedakan status akun vs akses dashboard | Manager | Tooltip "Aktifkan akses login" / "Cabut akses login" terlihat pada baris staf |
| SS-TM-02 | Modal Undang User | Field + pilihan role ganda | Manager | **Modal terbuka**, ≥2 role tercentang |
| SS-TM-03 | Modal Edit Role | Cara mengubah role | Manager | **Modal terbuka** |
| SS-TM-04 | Modal Nonaktifkan (ada tugas aktif) | Kewajiban memilih pengganti | Manager | **Modal terbuka** + peringatan jumlah tugas |
| SS-TM-05 | Performa Tim → tab Performa | Membaca beban kerja | Manager | Minimal 1 orang bertanda **kelebihan beban** |
| SS-TM-06 | Profil anggota tim | Isi halaman profil | Manager | Daftar penugasan terisi |
| SS-ST-01 | Pengaturan → Umum | Isi tab Umum | Manager | Data akun + status Google & Gemini |
| SS-ST-02 | Pengaturan → Data Pilihan | Lima sub-tab | Manager | Sub-tab **Paket** aktif, menampilkan kuota |
| SS-ST-03 | Modal Edit Paket | Cara mengubah kuota | Manager | **Modal terbuka** |
| SS-ST-04 | Pengaturan → Integrasi | Kartu integrasi + riwayat sinkronisasi | SMO | Klien terpilih, kedua kartu terlihat, riwayat terisi |

## Portal Klien (untuk bab terpisah)

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-PK-01 | Portal → Dashboard | Halaman pertama yang dilihat klien | Klien | Empat kartu + bagian Persetujuan berisi ≥1 item menunggu |
| SS-PK-02 | Portal → Kalender | Jadwal konten | Klien | Kalender berisi beberapa item |
| SS-PK-03 | Portal → Riwayat | Arsip konten | Klien | Beberapa konten lintas status |
| SS-PK-04 | Portal → Analytics | Data performa versi klien | Klien | Statistik + tren terisi |
| SS-PK-05 | Portal → Halaman Persetujuan | Tombol Setuju & Minta Revisi | Klien | Caption terlihat, kedua tombol aktif |
| SS-PK-06 | Portal → Minta Revisi | Catatan wajib diisi | Klien | Form catatan revisi terbuka |
| SS-PK-07 | Portal → sudah direspons | Kondisi setelah klien memutuskan | Klien | Tombol hilang, keterangan sudah direspons |

**Total: 77 screenshot** (dihitung ulang dari baris tabel di atas; versi
sebelumnya menulis 74, yang tidak cocok dengan daftarnya sendiri — sekarang
ditambah SS-CP-07 dan SS-TM-07 untuk fitur baru hasil stabilisasi).
**0 diblokir oleh bug.** 3 di antaranya
(SS-CL-09, SS-AN-06, sebagian SS-ST-04) menunggu akun media sosial terhubung —
`EXTERNAL_BLOCKED`, bukan bug.

> **Catatan penyuntingan (WAJIB, tidak berubah):** sebelum publikasi, sensor
> sebagian **token Portal Klien**, alamat **email pribadi**, **nomor telepon**,
> **link aset privat** (Google Drive/Canva klien asli), **nama akun media sosial
> asli**, dan **session/CSRF token** kalau sampai terekam dari dev tools.
> **Nama klien** hanya boleh tampil setelah dikonfirmasi boleh dipublikasikan —
> lihat "Checklist Keamanan Data Dokumentasi" untuk daftar lengkap 10 item.

---

# Terminologi Resmi untuk Dokumentasi

> Ditambahkan saat **Final Pre-Merge Verification** (lihat appendix di
> `docs/PRE_DOCUMENTATION_STABILIZATION_REPORT.md`) setelah full sweep
> terminologi user-facing menyeluruh (sidebar, page title, browser title,
> heading, tab, tombol, dropdown, modal, validation message, empty state,
> table heading, filter, form label, tooltip, status label, Client Portal,
> **dan PDF laporan yang dikirim ke klien**). Tabel ini melengkapi — bukan
> menggantikan — glosarium di Bagian 25 di bawah; dipakai sebagai rujukan
> tunggal saat menulis Buku Panduan Pengguna supaya istilah konsisten dari
> awal sampai akhir.
>
> **Prinsip:** istilah resmi mengikuti yang sudah dipakai sidebar/UI utama.
> Nama produk/fitur yang memang dipertahankan dalam Bahasa Inggris (AI
> Strategy, AI Brief, OAuth, CSV, dst) TIDAK diterjemahkan. Konsep yang
> berbeda secara makna (mis. Link File Hasil Produksi vs Link Postingan,
> Persetujuan Klien vs Review Internal) TIDAK disamakan hanya karena mirip.

| Istilah Resmi | Alias/Internal Term | Digunakan Untuk | Jangan Gunakan |
|---|---|---|---|
| Performa | `Analytics` (internal), "Performa Konten" | Menu & halaman analisis performa konten | "Analytics", "Performa Konten" sebagai judul halaman |
| Produksi | `Production Workflow` (internal) | Papan alur pengerjaan konten | "Production Workflow" di buku |
| Rencana Konten | `Content Plan` (internal), "Rencana Konten Bulanan" | Perencanaan bulanan per klien | "Content Plan", "Rencana Konten Bulanan" |
| Kelola Pengguna | `User Management` (internal), "Kelola Tim" | Menu pengelolaan anggota tim | "Kelola Tim" sebagai judul halaman, "User Management" |
| Kelola Klien | `Client Management` (internal) | Menu pengelolaan data klien & paket | "Client Management" |
| Laporan | `Report Generator` (internal/tab browser) | Menu pembuatan laporan PDF/Excel | "Report Generator" |
| Pengaturan | `Settings` (internal/tab browser) | Menu data pilihan, integrasi, akun | "Settings" |
| Data Pilihan | `Master Data` (internal) | Sub-tab Pengaturan (paket, tipe, dst) | "Master Data" sebagai label tab |
| Dashboard | — | Ringkasan eksekutif seluruh organisasi | diterjemahkan ("Papan Kontrol", dst) |
| Beranda | `Home` (internal) | Halaman pribadi tugas & langkah berikutnya | "Home" |
| Produksi → tab Revisi | `/revision-log` (route lama) | Satu-satunya jalur resmi lihat semua revisi | "Revision Log" sebagai nama halaman terpisah |
| Produksi → tab Sudah Tayang | `/publishing-tracker` (route lama) | Satu-satunya jalur resmi lihat riwayat publikasi | "Publishing Tracker" sebagai nama halaman terpisah |
| Manajemen Status | "Status Management" | Panel tombol perpindahan status di Detail Konten | "Status Management" |
| Catatan Revisi | "Revision Log" (sempat muncul di 2 tempat kode) | Panel/tabel riwayat revisi per konten | "Revision Log" sebagai label panel |
| Terbuka / Sedang Dikerjakan / Selesai | "Open" / "Sedang Dikerjakan" / "Resolved" (sempat campur di kode) | Status per-catatan revisi (bukan status konten) | Campur bahasa dalam satu daftar status |
| Risiko Tinggi / Risiko Sedang / Risiko Rendah | "High" / "Medium" / "Low" (risk_level internal) | Label tingkat Skor Risiko Keterlambatan | "High Risk" dst di UI (boleh tetap di kode/nama kolom) |
| Sudah Tayang / Berlangsung | "Published" / "On Progress" (badge Tabel Performa) | Status ringkas baris Tabel Performa | "Published", "On Progress" |
| Brief Awal | "Initial Brief" | Field `brief` mentah saat konten dibuat, beda dari AI Brief | "Initial Brief" — jangan disamakan dengan AI Brief |
| AI Brief / AI Brief Execution Assistant | — | Nama fitur produk, dipertahankan | diterjemahkan |
| AI Strategy | — | Nama fitur produk, dipertahankan | diterjemahkan |
| Item Tindakan | "Action Items" | Daftar rekomendasi aksi dari AI Strategy | "Action Items" |
| Komposisi Disarankan | "Suggested Split" | Rekomendasi pembagian pilar dari AI Strategy | "Suggested Split" |
| Integrasi Performa / Integrasi Otomatis | "Analytics Integration" / "Automatic Integrations" | Kartu koneksi API Instagram/TikTok per klien | "Automatic Integrations" |
| Analitik Konten | "Content Analytics" | Sub-bagian sync data konten dalam kartu integrasi | "Content Analytics" |
| Insight Audiens | "Audience Insights" | Sub-bagian sync data audiens (khusus Instagram) | "Audience Insights" |
| Import Data Performa / Import Data Audiens | "Import Performance/Audience CSV" | Link import manual CSV di kartu integrasi | Judul link berbahasa Inggris |
| Terhubung / Belum Terhubung | "Connected" / "Not Connected" | Status koneksi Instagram/TikTok | "Connected", "Not Connected" |
| Tersinkron / Menyinkronkan / Gagal / Belum Tersinkron | "Synced" / "Syncing" / "Failed" / "Not Synced" | Status sinkronisasi per platform | Versi Inggrisnya |
| Sambungkan Ulang / Hubungkan | "Reconnect" / "Connect" | Tombol OAuth connect per platform | "Reconnect X", "Connect X" |
| Log Sinkronisasi | "Sync Log" | Riwayat sync/import di Pengaturan → Integrasi | "Sync Log" |
| Ekspor PDF / Ekspor Excel / Ekspor | "Export PDF" / "Export Excel" / "Export" | Tombol unduh laporan/data performa | "Export" |
| Pratinjau Data | "Data Preview" | Preview CSV sebelum konfirmasi import | "Data Preview" |
| Petunjuk / Kolom Wajib | "Instructions" / "Required Columns" | Panduan format CSV di halaman Import | Versi Inggrisnya |
| Anggota Tim / Tugas Aktif | "Team Members" / "Task Aktif" | Tabel & kartu ringkasan Performa Tim | "Team Members", "Task Aktif" (campuran) |
| Tren Views / Tren Reach / Sebaran Views per Platform | "Views Trend" / "Reach Trend" / "Traffic per Platform" | Grafik tren di Performa & Portal Klien Analytics | Versi Inggrisnya |
| Konten Berkinerja Terbaik | "Top Performing Content" | Daftar konten teratas di Performa/Dashboard/Portal Klien | "Top Performing Content" |
| Detail Performa | "Performance Details" | Divider seksi grafik/metrik di Performa | "Performance Details" |
| Laporan Progres Operasional / Laporan Performa Konten | "Operational Progress Report" / "Content Performance Report" | Judul kartu & **judul dokumen PDF** yang dikirim ke klien | Versi Inggrisnya — ini bocor ke dokumen client-facing |
| Views / Engagement Rate / Reach / Impressions / Completion Rate | — | Istilah data analitik, dipertahankan (lihat Bagian 25) | diterjemahkan paksa ("Tayangan", dst) |
| Instagram / TikTok / CSV / OAuth / PDF | — | Nama platform/format resmi, dipertahankan | diterjemahkan |
| Keluar | "Logout" | Tombol keluar di sidebar | "Logout" (tidak konsisten dengan "Masuk dengan Google") |
| Link Portal Permanen | "Permanent Portal Link" | Label link Portal Klien di Detail Klien | "Permanent Portal Link" |
| Penanggung Jawab (PIC) | "PIC" / "Penanggung Jawab" | Orang yang bertanggung jawab atas konten | sebut keduanya sekali, lalu pakai PIC |

> **Catatan status field validasi:** `pic_user_id` dan `rejection_note` (dua
> field baru dari sprint stabilisasi) sekarang punya terjemahan resmi di
> `lang/id/validation.php` ("penanggung jawab", "alasan penolakan") — pesan
> error otomatis Laravel untuk kedua field ini sekarang berbahasa Indonesia,
> bukan fallback ke label auto-generate berbahasa Inggris.

---

# Checklist Keamanan Data Dokumentasi

> Ditambahkan saat **Final Pre-Merge Verification**. Dipakai SEBELUM
> screenshot final buku panduan diambil — periksa & sensor tiap item berikut
> yang muncul di frame screenshot sebelum dipublikasikan.

| # | Field/Item | Di mana biasanya muncul | Wajib disensor? |
|---|---|---|---|
| 1 | Email pribadi staf (mis. `ahdaalamin2506@gmail.com`, `surdik2811@gmail.com`) | Kelola Pengguna, Profil, Notifikasi | ✅ Ya — pakai akun demo (`@523studio.test`) untuk screenshot, bukan email pribadi asli |
| 2 | Nomor telepon/WhatsApp | Form client (`owner_phone`), Kelola Klien | ✅ Ya |
| 3 | **Portal Client token** (`clients.portal_token`, 64 karakter) | Detail Klien → kartu Portal Klien, URL Portal Klien | ✅ Ya — sensor sebagian (sudah jadi kebijakan sejak audit pertama, lihat Bagian 24) |
| 4 | API identifier (`external_account_id`, `external_username` kalau berupa akun asli) | Kartu Integrasi Instagram/TikTok | ✅ Ya kalau akun asli client; akun demo/test boleh tampil |
| 5 | OAuth access/refresh token | Tidak pernah tampil di UI manapun (encrypted, `$hidden` di model) — tetap JANGAN screenshot response mentah API/log Laravel yang memuatnya | ✅ Ya kalau sampai terekspos di dev tools/log |
| 6 | Link asset private (Google Drive/Canva folder client asli) | Detail Konten → Link File Hasil Produksi, Aset Klien | ✅ Ya kalau folder asli berisi data client; boleh pakai link folder dummy publik |
| 7 | Nama client nyata tanpa izin | Kelola Klien, Detail Klien, Rencana Konten, dsb | ⚠️ Lihat catatan provenance data demo di bawah — minta konfirmasi eksplisit sebelum publish |
| 8 | Nama akun sosial media private/asli | Kartu Integrasi (`@username`), Unmatched Instagram/TikTok | ✅ Ya kalau akun client asli |
| 9 | Password demo `"password"` (`admin@523studio.test`) | Tidak tampil di UI manapun (tidak ada form login password) - field ini vestigial/tidak dipakai jalur login manapun saat ini | ⚠️ Jangan disebut di buku sekalipun tidak berbahaya - hindari kesan ada login password |
| 10 | Session ID / CSRF token | Cookie/dev tools kalau sampai screenshot browser dev tools | ✅ Ya |

## Provenance data demo (Step 9 — Final Pre-Merge Verification)

Database development berisi data demo yang **konfirmasi asalnya**:
`database/seeders/DemoSeeder.php` (sudah ada di repo sejak commit dasar
`d637369`, bukan ditambahkan sesi ini) — 10 user (email `@***.test`, aman), 5
client (Yasmin International Boarding School, PT Guna Griya Abadi, LuxSuits,
Top Scorer Arena, FTI UNAND), 15 rencana konten, 85 content item, semua
timestamp identik `2026-08-26 15:32:58` (satu kali jalan `php artisan
db:seed --class=DemoSeeder` di luar sesi verifikasi ini).

**Klasifikasi: `KNOWN_SOURCE`.**

**Rekomendasi:** data ini AMAN dipakai untuk screenshot buku panduan
(email fiktif domain `.test`, tidak ada data pribadi asli) — **DENGAN
SATU catatan yang perlu dikonfirmasi user**: komentar di `DemoSeeder.php`
menyebut client "mendekati portofolio riil 523 Studio" - pastikan dulu ke
tim apakah nama-nama client demo ini boleh muncul di buku publik, atau
sebaiknya diganti nama fiktif yang lebih jelas-jelas bukan referensi ke
client asli manapun, sebelum screenshot final diambil.

---

# Bagian 25. Glosarium

## Istilah menu & halaman

| Istilah | Arti dalam aplikasi ini |
|---|---|
| **Beranda** | Halaman pribadi berisi tugas & langkah berikutnya untuk diri sendiri |
| **Dashboard** | Ringkasan eksekutif seluruh organisasi (bukan per klien) |
| **Performa** | Halaman analisis hasil konten setelah tayang, per klien |
| **Rencana Konten** | Daftar konten yang direncanakan satu klien untuk satu bulan |
| **Produksi** | Papan alur pengerjaan konten dari siap dikerjakan sampai tayang |
| **Performa Tim** | Beban kerja & kehadiran anggota tim |
| **Kelola Pengguna** | Pengelolaan anggota tim (menu, judul halaman, dan tab peramban semuanya memakai istilah ini) |
| **Kelola Klien** | Pengelolaan data klien & paketnya |
| **Laporan** | Pembuatan berkas PDF/Excel untuk dikirim ke klien |
| **Pengaturan** | Data pilihan, integrasi, dan info akun |
| **Portal Klien** | Halaman terpisah untuk klien, diakses lewat link permanen |

## Istilah operasional

| Istilah | Arti |
|---|---|
| **Content Item / Konten** | Satu unit pekerjaan konten (satu video atau satu desain) |
| **Brief** | Panduan produksi lengkap: hook, adegan/slide, naskah, talent, properti |
| **Pilar Konten** | Tema besar konten (Education, Entertainment, Soft Selling, dst.) |
| **Tipe Konten** | Bentuk konten: **Video** atau **Desain** |
| **Platform** | Kanal publikasi: **Instagram** atau **TikTok** |
| **PIC / Penanggung Jawab** | Orang yang bertanggung jawab mengerjakan konten itu |
| **Assign Klien** | Menentukan klien mana saja yang ditangani seorang staf |
| **Paket** | Langganan klien, berisi kuota konten & desain per bulan |
| **Kuota / Target** | Jumlah konten & desain yang dijanjikan per bulan |
| **Deadline** | Batas waktu penyelesaian konten (bukan tanggal tayang) |
| **Terlambat (Overdue)** | Deadline lewat tapi konten belum tayang/dibatalkan |
| **Mendesak (Urgent)** | Konten dari Jobdesk Tambahan |
| **Jobdesk Tambahan** | Permintaan mendadak di luar rencana bulanan |
| **Pin / Fokus Saya** | Penanda pribadi "ini yang sedang saya kerjakan" |
| **Revisi** | Catatan permintaan perbaikan pada sebuah konten |
| **Approval / Persetujuan** | Keputusan bahwa konten boleh lanjut. **Dua jenis:** persetujuan klien (dari Portal) & review internal |
| **Koreksi Status** | Memindahkan status ke mana pun karena sebelumnya salah (khusus CEO/Manager) |

## Status konten

| Istilah | Arti |
|---|---|
| **Siap Dikerjakan** | Tercatat & ada PIC, belum digarap |
| **Sedang Dikerjakan** | Sedang digarap |
| **Menunggu Persetujuan** | Menunggu review; tampil di Portal Klien |
| **Perlu Revisi** | Ada catatan perbaikan yang harus dikerjakan |
| **Disetujui** | Lolos review, tinggal dijadwalkan |
| **Terjadwal Tayang** | Sudah punya jadwal upload |
| **Sudah Tayang** | Sudah dipublikasikan (status akhir) |
| **Dibatalkan** | Tidak jadi diproduksi (status akhir) |

## Status rencana konten

**Draf** → **Menunggu Persetujuan** → **Disetujui** / **Ditolak**

## Istilah analytics

| Istilah | Arti |
|---|---|
| **Views** | Jumlah tayangan konten |
| **Reach** | Jumlah akun **unik** yang melihat konten. ⚠️ Instagram saja — TikTok tidak menyediakannya |
| **Impressions** | Total kemunculan konten (satu orang bisa terhitung berkali-kali). Instagram saja |
| **Engagement** | Interaksi: suka, komentar, bagikan, simpan |
| **Engagement Rate** | Persentase interaksi terhadap jangkauan. ⚠️ Rumus Instagram & TikTok **berbeda** — angkanya tidak sepenuhnya setara |
| **Saves** | Jumlah orang yang menyimpan konten. Instagram saja |
| **Profile Visit** | Kunjungan ke profil setelah melihat konten. Instagram saja |
| **Audiens** | Data pengikut: jumlah, demografi, jam aktif. ⚠️ Demografi hanya Instagram |
| **Watch Time** | Rata-rata durasi tontonan (konten video) |
| **Completion Rate** | Persentase penonton yang menonton sampai selesai |
| **Belum Tertaut (Unmatched)** | Post di media sosial yang belum dihubungkan ke konten di sistem |
| **Sinkronisasi (Sync)** | Proses menarik data terbaru dari Instagram/TikTok |
| **Kelengkapan Data** | Seberapa lengkap data periode itu — indikator kepercayaan hasil analisis AI |

## Istilah AI

| Istilah | Arti |
|---|---|
| **AI Brief** | Penyusunan brief produksi dengan bantuan AI |
| **AI Strategy** | Analisis performa bulan lalu + rekomendasi & ide konten |
| **Terapkan (Apply)** | **Benar-benar membuat konten** di Rencana Konten dari rekomendasi AI |
| **Tarik Kembali (Revert)** | Menghapus konten yang dibuat oleh Terapkan, selama belum ada progres |
| **Perbarui dari Diskusi (Refine)** | Menyusun ulang analisis berdasarkan percakapan |
| **Kembalikan (Revert brief)** | Undo satu langkah perubahan pada brief |
| **Skor Risiko Keterlambatan** | Prediksi 0–100 seberapa berisiko konten ini telat |
| **Kelayakan (Feasibility)** | Penilaian AI apakah brief realistis dikerjakan, membandingkan tanggal posting brief dengan deadline & beban PIC minggu itu |

## ✅ Istilah baku (KI-17 sudah diperbaiki di aplikasi)

Aplikasi **sudah** diselaraskan lewat sweep terminologi menyeluruh — istilah
lama di kolom tengah tidak lagi muncul di UI. Tabel ini sekarang berfungsi
sebagai **pengingat historis + rujukan cepat**; rujukan lengkap dan otoritatif
ada di section **"Terminologi Resmi untuk Dokumentasi"** di atas.

| Konsep | Istilah lama yang sudah TIDAK dipakai lagi | **Pakai istilah ini** |
|---|---|---|
| Menu pengelolaan tim | "Kelola Tim" (judul lama) | **Kelola Pengguna** |
| Menu analitik | "Performa Konten" sebagai judul halaman, `analytics` (URL internal) | **Performa** |
| Menu perencanaan | "Rencana Konten Bulanan", "Content Plan Bulanan" (tab peramban lama) | **Rencana Konten** |
| Menu laporan | "Report Generator" (tab peramban lama) | **Laporan** |
| Menu pengaturan | "Settings" (tab peramban lama) | **Pengaturan** |
| Halaman revisi | "Revision Log" (route lama, sekarang redirect) | **Produksi → tab Revisi** |
| Halaman publikasi | "Publishing Tracker" (route lama, sekarang redirect) | **Produksi → tab Sudah Tayang** |
| Post belum terhubung | "Unmatched Instagram Media" / "Unmatched TikTok Video" — nama teknis per platform, sengaja dipertahankan di UI | **Post Belum Tertaut** (istilah buku) |
| Data referensi | "Master Data" (istilah kode) | **Data Pilihan** |
| Penanggung jawab | "PIC" saja tanpa penjelasan | **Penanggung Jawab (PIC)** — sebut keduanya sekali, lalu pakai PIC |

> Satu-satunya istilah yang sengaja **tidak** disamakan: nama teknis per platform
> ("Unmatched Instagram Media" vs "Unmatched TikTok Video"), istilah data
> analitik (Views, Engagement Rate, Reach, dst), nama produk AI (AI Brief, AI
> Strategy), dan nama platform/format (Instagram, TikTok, CSV, OAuth, PDF).
> Itu bukan inkonsistensi.

---

# Bagian 26. Struktur Buku Panduan yang Direkomendasikan

Buku harus **berorientasi pekerjaan**, bukan daftar menu. Pembaca datang dengan
pertanyaan "bagaimana cara saya menyelesaikan X", bukan "apa isi menu Y".

```text
BUKU PANDUAN PENGGUNA — 523 STUDIO PLATFORM

BAGIAN I — ORIENTASI
  1. Apa itu 523 Studio Platform
     1.1 Empat hal yang dikelola aplikasi ini
     1.2 Siapa saja penggunanya (tim internal vs klien)
     1.3 Alur besar: Rencana → Produksi → Persetujuan → Tayang → Performa
  2. Mengenal tampilan
     2.1 Sidebar, topbar, dan pencarian
     2.2 Kenapa menu saya berbeda dengan rekan saya
     2.3 Mengganti tema

BAGIAN II — CARA MEMULAI
  3. Masuk ke sistem
     3.1 Masuk dengan akun Google
     3.2 Kalau tidak bisa masuk
  4. Hari pertama Anda
     4.1 Check-in & check-out
     4.2 Membaca Beranda & Langkah Berikutnya
     4.3 Menandai fokus dengan Pin
     4.4 Notifikasi

BAGIAN III — ALUR KERJA UTAMA
  5. Perjalanan sebuah konten (bab kunci — baca ini dulu)
     5.1 Delapan status & artinya
     5.2 Siapa bertindak di tahap mana
     5.3 Dua jenis persetujuan: klien vs internal
     5.4 Bagaimana revisi bekerja
  6. Perencanaan bulanan
  7. Produksi harian
  8. Publikasi & pemantauan performa

BAGIAN IV — PANDUAN CEPAT PER ROLE          (2-3 halaman per role)
  9.  Untuk CEO
  10. Untuk Manager
  11. Untuk Copywriter
  12. Untuk Content Creator
  13. Untuk Graphic Designer
  14. Untuk SMO
      → tiap bab: "hari-hari Anda", 3-5 tugas utama, apa yang tidak bisa
        Anda lakukan dan harus minta ke siapa

BAGIAN V — TUTORIAL BERDASARKAN PEKERJAAN   (isi utama buku)
  15. Mengelola klien
      15.1 Menambahkan klien baru
      15.2 Menentukan paket
      15.3 Menugaskan tim ke klien  (dua jalur: Detail Klien & Kelola Pengguna)
      15.4 ★ Onboarding klien baru dari nol sampai siap
  16. Menghubungkan media sosial klien
      16.1 Instagram   16.2 TikTok   16.3 Sinkronisasi & verifikasi
      16.4 Alternatif tanpa API: Import CSV Performa
      ⚠ Kotak peringatan: koneksi hanya berhasil untuk akun yang sudah
        terdaftar sebagai penguji selama App Review belum selesai
                                                       [EXTERNAL_BLOCKED]
  17. Merencanakan konten
      17.1 Membuat Rencana Konten
      17.2 Menambahkan konten
      17.3 Mengajukan & menyetujui
      17.4 Kalau rencana ditolak: memperbaiki & mengajukan ulang
      17.5 Permintaan mendadak (Jobdesk Tambahan)
  18. Menyusun brief
      18.1 Membuat brief dengan AI
      18.2 Berdiskusi & mengedit manual
      18.3 Menerapkan ke tim produksi
  19. Mengerjakan & menyelesaikan konten
  20. Revisi & persetujuan
  21. Menjadwalkan & mencatat publikasi
  22. Membaca Performa
  23. Menggunakan AI Strategy
      23.1 Apa yang hanya rekomendasi, apa yang benar-benar mengubah sistem
  24. Membuat laporan untuk klien
  25. Mengelola tim
      25.1 Mengundang anggota baru
      25.2 Role, status akun, dan akses login (tiga hal berbeda)
      25.3 Menonaktifkan & memindahkan tugas
  26. Pengaturan & Data Pilihan

BAGIAN VI — PORTAL KLIEN                    (bisa dicetak terpisah)
  27. Untuk tim internal: mengelola akses klien
      27.1 Membagikan link dengan aman
      27.2 ★ Keamanan: link ini setara kata sandi
      27.3 Membuat link baru & menonaktifkan portal
  28. Untuk klien: menggunakan Portal Klien
      28.1 Membuka portal   28.2 Dashboard   28.3 Kalender
      28.4 Riwayat          28.5 Analytics
      28.6 Menyetujui konten & meminta revisi

BAGIAN VII — TROUBLESHOOTING
  29. Masalah akses
      "Tidak bisa masuk"           → akses login belum diaktifkan,
                                     status akun nonaktif, atau email
                                     Google berbeda
      "Halaman saya kosong"        → belum di-assign ke klien
      "Tidak punya akses ke halaman ini"
      "Angka Dashboard saya beda dengan rekan saya"  → client scope
  30. Masalah data
      "Data performa tidak muncul" → belum sync, post belum tertaut, atau
                                     proses otomatis di server tidak jalan
      "Sync ditekan tapi tidak terjadi apa-apa"  → hubungi administrator
      "Post tidak muncul di Performa"  → Post Belum Tertaut
      "Link portal klien tidak bisa dibuka"
  31. Keterbatasan yang diketahui
      31.1 Tidak ada posting langsung ke media sosial  [OUT_OF_SCOPE]
      31.2 Data TikTok lebih terbatas dari Instagram
      31.3 Tidak ada koreksi absensi manual
      31.4 Koneksi Instagram/TikTok menunggu izin platform
                                                       [EXTERNAL_BLOCKED]
      31.5 Skor Risiko Keterlambatan adalah perkiraan, bukan kepastian

BAGIAN VIII — LAMPIRAN
  A. Glosarium
  B. Tabel hak akses per role
  C. Ringkasan status konten & rencana
  D. Format CSV import performa
  E. Daftar notifikasi & pemicunya
```

## Prinsip penulisan

1. **Mulai dari pekerjaan, bukan dari menu.** Judul bab: "Menyusun brief", bukan
   "Halaman Detail Konten".
2. **Bagian III adalah jantung buku.** Kalau pembaca hanya sempat membaca satu
   bab, itu harus Bab 5 (Perjalanan sebuah konten).
3. **Bagian IV membuat buku terasa personal.** Tiap orang bisa langsung membaca
   2–3 halaman miliknya sendiri lalu bekerja.
4. **Jangan pernah memakai bahasa permission teknis di badan tulisan.**
   Tulis "Content Creator dan Graphic Designer dapat memperbarui status pekerjaan
   pada klien yang ditugaskan kepada mereka", bukan "dibutuhkan
   `workflow.update`". Tabel permission teknis cukup di Lampiran B.
5. **Beri tanda jelas pada keterbatasan** dengan kotak peringatan, jangan
   disembunyikan. Pengguna lebih percaya buku yang jujur. Bedakan tegas tiga
   jenis keterbatasan: **di luar cakupan by design** (tidak ada posting
   langsung), **menunggu izin platform** (Instagram/TikTok), dan **butuh
   konfigurasi server** (proses otomatis). Tidak ada lagi kategori "fitur
   sedang rusak" — per Final Pre-Merge Verification, tidak ada yang tersisa.
6. **Bagian VI bisa dicetak/dikirim terpisah** ke klien — jangan campur dengan
   isi internal.
7. **Konsisten pada istilah baku** di tabel Bagian 25.

## Urutan pengerjaan yang disarankan

| Tahap | Isi | Kenapa |
|---|---|---|
| **1** | Bagian I, II, III, VI, VII | Semuanya `READY`; jantung buku, kerjakan lebih dulu |
| **2** | Bagian IV (panduan per role) | Butuh Bagian III selesai lebih dulu |
| **3** | Bagian V bab 15, 17, 18, 19–21, 22–26 | Seluruhnya `READY` — tidak ada lagi yang menunggu perbaikan |
| **4** | Bagian V bab 16 (Instagram/TikTok) | `EXTERNAL_BLOCKED` — teks prosedur boleh ditulis sekarang; **screenshot kondisi "Terhubung" menunggu** akun tester berhasil connect |

**Tidak ada tahap 5.** Di rencana sebelumnya tahap 5 berisi bab yang menunggu
perbaikan KI-01/KI-02/KI-03/KI-07 — keempatnya sudah selesai.

---

*Akhir dokumen.*

*Riwayat dokumen: disusun dari audit read-only commit `d637369` (26 Agustus
2026), lalu di-re-audit setelah sprint stabilisasi, lalu direkonsiliasi penuh
setelah **Final Pre-Merge Verification** — semua status, daftar prosedur,
rencana screenshot, dan struktur buku di atas merepresentasikan kondisi
aplikasi setelah verifikasi tersebut (148 test, 363 assertion, 0 failed,
0 skipped). Bagian yang masih menggambarkan kondisi lama diberi label
**HISTORIS — KONDISI SEBELUM STABILISASI** secara eksplisit.*

*Rekonsiliasi ini bersifat dokumentasi saja: tidak ada perubahan pada kode
aplikasi, test, route, atau konfigurasi.*
