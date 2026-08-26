# 523 Studio Platform — Source of Truth untuk Buku Panduan Pengguna

> **Dokumen ini BUKAN buku panduan.** Ini hasil audit implementasi aktual yang
> dipakai sebagai bahan mentah untuk menyusun Buku Panduan Pengguna final.

## ⚠️ RE-AUDIT — 26 Agustus 2026 (setelah stabilization sprint)

Dokumen ini sudah di-**re-audit** setelah sprint stabilisasi penuh
(`FIX → TEST → VERIFY → CLEANUP → RE-AUDIT`) di branch
`stabilization/pre-user-manual`. **Laporan lengkap ada di
`docs/PRE_DOCUMENTATION_STABILIZATION_REPORT.md` — baca itu dulu sebelum
memakai dokumen ini.** Ringkasan yang relevan untuk pembaca dokumen ini:

- Seluruh 8 `KNOWN_ISSUE` (KI-01, KI-02, KI-03, KI-04, KI-05, KI-06, KI-07,
  KI-09) dan 2 dari 3 `NOT_READY` (KI-11, KI-14) di Bagian 22 **sudah
  diperbaiki**, dengan regression test otomatis untuk masing-masing.
- **KI-10** (Dashboard scope) diperbaiki juga meski awalnya ditandai `READY`
  (audit pertama menilai fungsional tapi cakupan datanya salah).
- 12 dari 13 `NEEDS_VERIFICATION` sudah diverifikasi (runtime read-only
  terhadap data development nyata + automated test) — lihat status terbaru
  di kolom "Status" tabel Bagian 22 di bawah, ditandai `[RE-AUDIT: ...]`.
- **3 authorization leak baru ditemukan** (di luar KI-01...KI-20 semula)
  lewat white-box re-audit, semuanya diperbaiki: AI Strategy History,
  Import Audience CSV, dan endpoint drag-and-drop kanban Produksi — detail
  di laporan stabilisasi §3.
- Metode verifikasi berubah dari **static analysis + runtime terbatas**
  (database dev nyaris kosong saat audit pertama) menjadi **82 automated
  test (213 assertion) + runtime read-only terhadap database development
  yang sekarang berisi data realistis** (10 user, 5 client, 85 content item
  — lihat catatan provenance di laporan stabilisasi §8).
- **Verdict re-audit: `DOCUMENTATION_READY`** — boleh mulai menulis Buku
  Panduan Pengguna, dengan catatan tetap perlakukan KI-08 (Instagram/TikTok)
  sebagai konseptual + peringatan App Review (bukan "selesai"), sesuai
  rekomendasi asli di bawah.

Bagian di bawah ini (Bagian 1-26) **TIDAK diedit ulang secara menyeluruh** -
tetap merepresentasikan pemahaman arsitektur/workflow yang valid, KECUALI
kolom Status di tabel Known Issues (Bagian 22) dan kalimat "Status" di tiap
Feature Record, yang sudah diperbarui reflect kondisi pasca-perbaikan.
Contoh kode/tangkapan layar yang direferensikan sebagai "tunda sampai
diperbaiki" sekarang sudah boleh dibuat.

## Metadata Audit (Audit Pertama — sebelum stabilization sprint)

| Item | Nilai |
|---|---|
| Tanggal audit | 26 Agustus 2026 |
| Commit SHA | `d6373696b9f4a025551958826240c7bba918fa58` (`d637369`) |
| Branch | `main` |
| Working tree | Bersih (tidak ada perubahan tak ter-commit saat audit) |
| Metode verifikasi | **Static analysis + runtime (parsial)** |
| Perubahan kode | **Nol.** Audit ini read-only; satu-satunya file yang dibuat adalah dokumen ini. |
| Status setelah re-audit | Lihat kotak "RE-AUDIT" di atas dan `docs/PRE_DOCUMENTATION_STABILIZATION_REPORT.md` |

### Cara runtime verification dilakukan

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

### Keterbatasan audit (WAJIB dibaca sebelum menulis buku)

1. **Database dev hampir kosong**: 3 user (semuanya CEO), 1 client, **0 content
   item**, **0 content plan**, **0 api_integration**. Akibatnya semua halaman
   daftar di atas render dalam kondisi *empty state* — halaman detail (Detail
   Konten, Detail Rencana Konten, Detail Klien dengan data) **belum pernah
   ter-render dengan data nyata** dalam audit ini.
2. **Hanya role CEO yang diverifikasi runtime.** Perilaku Manager, SMO,
   Copywriter, Content Creator, Desain Grafis disimpulkan dari
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
  di Kelola Tim, dan tidak pernah bisa masuk ke dashboard internal.
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
tombol. Status: **NOT_READY di lingkungan saat ini** (belum dipasang).

---

# Bagian 2. Role dan Permission

Sistem punya **6 role**, didefinisikan di `App\Enums\UserRole` dan dibuat oleh
`RoleSeeder`. **Satu orang bisa memegang lebih dari satu role sekaligus**
(relasi many-to-many lewat tabel `user_roles`) — misalnya seseorang bisa
sekaligus Manager dan SMO, dan akan mendapat gabungan hak akses keduanya.

Aturan khusus yang berlaku lintas role:

- **CEO dan Manager selalu melihat SEMUA klien.** Empat role lainnya hanya
  melihat klien yang secara eksplisit ditugaskan kepada mereka lewat
  **Kelola Tim → Assign Klien**.
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
**Hanya klien yang ditugaskan.** Ini penting: SMO punya akses ke Pengaturan dan
Performa, tapi daftar klien di kedua halaman itu tetap dibatasi ke roster-nya.
⚠️ Ada dua tempat di mana pembatasan ini tidak konsisten — lihat Bagian 22
(temuan KI-09 dan KI-10).

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

## Desain Grafis

**Tujuan utama role**
Mengeksekusi produksi konten desain/carousel sesuai brief.

**Menu, aktivitas, batasan, dan client scope: identik dengan Content Creator.**
Perbedaannya bukan di hak akses, melainkan di **penugasan otomatis**: saat AI
Strategy diterapkan ke Rencana Konten, konten bertipe "Desain" diarahkan ke role
Desain Grafis dan bertipe "Video" ke Content Creator.
*Implementation: `PicAssignmentService::$roleByContentType`.*

---

# Bagian 3. Matriks Role dan Fitur

Legenda: ✓ = bisa · (kosong) = tidak bisa · ⚠ = bisa, tapi ada catatan ·
Klien = pengguna Portal Klien (bukan role Laravel)

| Fitur | CEO | Manager | Copywriter | Content Creator | Desain Grafis | SMO | Klien |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Melihat Beranda | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| Melihat Dashboard | ✓ | ✓ | | | | ✓ | |
| Melihat Rencana Konten | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | |
| Membuat Rencana Konten | ✓ | ✓ | ✓ | | | | |
| Mengajukan Rencana Konten | ✓ | ✓ | ✓ | | | | |
| Menyetujui/Menolak Rencana | ✓ | ✓ | | | | ✓ | |
| Menambah Konten ke Rencana | ⚠ | ⚠ | ⚠ | | | | |
| Jobdesk Tambahan (mendadak) | ⚠ | ⚠ | ⚠ | | | | |
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
| Import CSV performa | ✓ | ✓ | | | | ⚠ | |
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

- **⚠ Menambah Konten ke Rencana & Jobdesk Tambahan** — tombolnya muncul untuk
  role yang benar, tapi **fungsinya rusak** (lihat KI-01 & KI-02, Bagian 22).
  Jangan dijadikan tutorial sebelum diperbaiki.
- **⚠ Import CSV performa (SMO)** — secara teknis SMO bisa meng-import untuk
  klien mana pun, termasuk yang bukan roster-nya (KI-09).
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

## Content Creator & Desain Grafis

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
| Tambah Konten (ke rencana) | Tombol di Detail Rencana Konten | Rusak — KI-01 |
| Detail Konten | Klik kartu di Produksi / hasil pencarian | Rusak — KI-03 |
| Detail Klien | Klik baris di Kelola Klien / hasil pencarian | |
| Tambah Klien / Edit Klien | Tombol di Kelola Klien | |
| Profil anggota tim | Klik nama di Performa Tim / hasil pencarian | |
| Detail Performa 1 konten | Klik baris di tab Tabel Performa | |
| Riwayat AI Strategy | Link di panel AI Strategy (halaman Performa) | |
| Import Data Performa | Link dari Pengaturan → Integrasi | |
| **Publishing Tracker** (`/publishing-tracker`) | **Hanya via URL langsung** | Duplikat dari Produksi → tab "Sudah Tayang" |
| **Revision Log** (`/revision-log`) | **Hanya via URL langsung** | Duplikat dari Produksi → tab "Revisi" |
| Unmatched Instagram Media | Link dari kartu Integrasi / Tabel Performa | |
| Unmatched TikTok Video | Link dari kartu Integrasi | |

> **Rekomendasi untuk buku:** dokumentasikan **Produksi → tab Revisi** dan
> **Produksi → tab Sudah Tayang** sebagai jalur resmi. Jangan dokumentasikan
> `/revision-log` dan `/publishing-tracker` sebagai halaman terpisah — keduanya
> menampilkan data yang sama dan tidak punya pintu masuk di UI (lihat KI-12).

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
                                                   └──► Ditolak
```

- **Draf** — baru dibuat, masih bisa diisi konten.
- **Menunggu Persetujuan** — sudah diajukan; penyetuju mendapat notifikasi.
- **Disetujui** / **Ditolak** — keputusan final, disertai catatan siapa yang
  memutuskan.

⚠️ Tidak ada jalur kembali dari Ditolak ke Draf di dalam kode. Rencana yang
ditolak tidak bisa diajukan ulang (`submit()` hanya menerima status Draf).
Catat ini di buku sebagai keterbatasan — lihat KI-13.

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
**Status** — **`KNOWN_ISSUE` — fitur ini gagal total saat tombol Simpan ditekan.**
Lihat KI-01. **Jangan buat tutorialnya sekarang.**

### Jobdesk Tambahan (permintaan mendadak)

**Role** — CEO, Manager, Copywriter
**Entry point** — Tombol merah di sidebar (tersedia dari halaman mana pun)
**Tujuan** — Permintaan mendadak dari klien (dokumentasi acara, liputan, dsb.)
yang harus langsung masuk produksi tanpa lewat perencanaan bulanan
**Langkah user** — Pilih klien · judul · tipe · platform · deadline · Penanggung
Jawab (opsional) · catatan · Simpan
**Expected result** *(secara desain)* — Sistem otomatis mencari/membuat rencana
bulan berjalan untuk klien itu, konten ditandai **mendesak**, PIC langsung
mendapat notifikasi
**Status** — **`KNOWN_ISSUE` — gagal total saat disimpan.** Lihat KI-02.

### Mengajukan Rencana (Ajukan Rencana)

**Role** — CEO, Manager, Copywriter · **Permission** `content_plan,create`
**Precondition** — Status harus **Draf**
**Expected result** — Status jadi **Menunggu Persetujuan**; semua pemegang hak
menyetujui (kecuali pembuatnya sendiri) mendapat notifikasi
**Status** — `READY`

### Menyetujui / Menolak Rencana

**Role** — CEO, Manager, SMO · **Permission** `content_plan,approve`
**Precondition** — Status harus **Menunggu Persetujuan**
**Expected result** — Status jadi **Disetujui** atau **Ditolak**, tercatat siapa
yang memutuskan
**Status** — `READY`

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
  jam (`workflow:update-overdue`) — yang saat ini **tidak berjalan** di
  lingkungan ini.

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
judul konten, brief mentah, nama tipe konten, dan nama platform. **Tidak ada
data klien, data rencana konten, atau tanggal sistem yang disuntikkan.**

Penilaian kelayakan (`assessFeasibility()`) memakai data nyata: `deadline_at`
konten, tanggal posting hasil AI, dan jumlah konten aktif lain milik tiap PIC
pada minggu deadline yang sama.

## ⚠️ Investigasi: tanggal brief menggunakan tahun 2024

**Observed / suspected issue**
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

**Recommended documentation status** — `KNOWN_ISSUE`.
Fitur AI Brief boleh didokumentasikan (bagian lainnya berfungsi), **dengan
peringatan eksplisit** agar pengguna selalu memeriksa dan mengoreksi Tanggal
Mulai/Tanggal Posting secara manual, dan tidak memercayai penilaian kelayakan
sampai bug ini diperbaiki. Jangan pakai screenshot yang menampilkan tanggal
salah.

## Feature Record

**Status:** `KNOWN_ISSUE`
**Digunakan oleh:** CEO, Manager, Copywriter
**Tujuan:** Mengubah ide mentah jadi brief produksi siap eksekusi
**Entry point:** Detail Konten → kartu "AI Brief Execution Assistant"
**Precondition:** Konten sudah ada; `GEMINI_API_KEY` terisi (**terverifikasi
terisi**)
**Expected result:** Brief tersusun, bisa didiskusikan/diedit, lalu dikunci dan
PIC produksi dapat notifikasi
**Permission:** `content_plan,create` (+ `client.scope`)
**Dependencies:** Google Gemini API (jaringan keluar)
**Known issues:** Tanggal tahun 2024 (di atas). Juga: **halaman tempat fitur ini
berada sedang rusak** (KI-03), jadi saat ini praktis tidak terjangkau.
**Relevant implementation:** `ContentBriefController`, `BriefGenerationService`,
`content-items/partials/ai-brief.blade.php`, `ai-brief-discussion.blade.php`
**Documentation recommendation:** Tunda sampai KI-03 dan bug tanggal diperbaiki.

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

**Status:** `READY` (terverifikasi runtime, kondisi kosong)

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

**Status:** **`KNOWN_ISSUE`** — halaman ini gagal dimuat begitu klien punya
minimal satu staf aktif yang di-assign. Lihat KI-03. Ini **memblokir hampir
seluruh alur produksi**, karena Status Management, AI Brief, caption, link
konten, dan Ganti PIC semuanya hanya ada di halaman ini.

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

**Status:** `READY` secara logika (guard-nya lengkap & konsisten), **tapi tidak
terjangkau** selama KI-03 belum diperbaiki.

## Ganti Penanggung Jawab

**Tujuan** — Memindahkan konten ke PIC lain, misalnya karena beban kerja atau
ketidakhadiran.
**Kandidat** — Hanya staf aktif yang **sudah di-assign ke klien konten itu**,
diurutkan dari yang task aktifnya paling sedikit, lengkap dengan jumlah task
aktif masing-masing.
**Efek** — PIC berubah, penugasan diperbarui, dan skor Delay Risk konten langsung
dihitung ulang.
**Status:** **`KNOWN_ISSUE`** — gagal saat disimpan. Lihat KI-04.

## Alur dari perspektif tiap role

### Content Creator

1. Buka **Beranda** → lihat daftar task dan panel **Langkah Berikutnya**
2. Buka konten yang jadi tanggung jawabnya (dari Beranda atau papan Produksi)
3. **Kerjakan Konten** → status jadi Sedang Dikerjakan
4. Setelah syuting: tandai **footage sudah di-take** dengan tanggal sebenarnya
5. Setelah selesai edit: isi **link file hasil produksi**
6. **Konten Telah Selesai** → Menunggu Persetujuan
7. Kalau ada revisi: **Kerjakan Revisi** → ulangi dari langkah 5

### Desain Grafis

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
| **Terlambat (overdue)** | Deadline sudah lewat tapi konten belum tayang/dibatalkan. Ditandai otomatis tiap jam. | ⚠️ Proses otomatisnya tidak berjalan (Bagian 22, KI-14) |
| **Mendesak (urgent)** | Konten dari Jobdesk Tambahan, ditandai khusus agar menonjol | Terikat KI-02 |
| **Pin** | Penanda pribadi "ini fokus saya". Tidak terlihat orang lain, ada batas maksimal, otomatis lepas saat konten Sudah Tayang | `READY` |
| **Skor Risiko Keterlambatan** | Prediksi AI 0–100 seberapa berisiko konten ini telat, plus faktor utamanya | `NEEDS_VERIFICATION` |
| **Akurasi Prediksi** | Seberapa sering prediksi risiko terbukti benar; tampil di Dashboard & Performa Tim | `NEEDS_VERIFICATION` |

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
Desain Grafis (semua pemegang `workflow,update`). **Copywriter tidak bisa.**

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
**Status** — `READY` (logika), terblokir KI-03 lewat halaman detail

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

**Status** — `NEEDS_VERIFICATION`. Kode lengkap dan konsisten, tapi belum pernah
dijalankan dengan data nyata (`api_integrations` = 0).

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
| Kemungkinan error | ⚠️ Jalur **Kelola Pengguna → Assign Klien saat ini rusak** (KI-05). **Gunakan jalur Detail Klien → PIC Ditugaskan** |
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
| Kemungkinan error | ⚠️ **Tanpa queue worker aktif, tombol Sync tetap terlihat berhasil tetapi tidak ada yang diproses** (Bagian 22, KI-14) |
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
| Lalu | Tambahkan konten satu per satu ⚠️ **(rusak — KI-01)**, atau pakai **AI Strategy → Terapkan** untuk membuat kerangka otomatis |
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

**Status keseluruhan alur:** `NEEDS_VERIFICATION` — setiap langkah masuk akal
secara kode, tapi rangkaian penuhnya belum pernah dijalankan sampai tuntas di
lingkungan ini (0 integrasi, 0 konten). Langkah 3 dan 8 mengandung fitur rusak.

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
| Terhubung tapi data tidak muncul | Queue worker tidak berjalan | Lihat KI-14 |

### Data apa yang didapat

**Konten:** views, reach, impressions, likes, comments, shares, saves, profile
visit, engagement rate — per post, per tanggal.
**Audiens:** jumlah follower, reach, jam aktif audiens, serta demografi
(gender, rentang usia, kota, negara) dalam tiga varian: pengikut, yang
terjangkau, dan yang berinteraksi.

Audiens disinkronkan lewat **proses terpisah** dari konten (tombol dan jadwal
sendiri).

**Status:** `NEEDS_VERIFICATION` — implementasi lengkap (OAuth, penyegaran token
otomatis, sinkronisasi konten & audiens terpisah, snapshot media, pencocokan,
penanganan error, penyembunyian token), tapi **belum pernah dijalankan** di
lingkungan ini.

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

**Status:** `NEEDS_VERIFICATION`.
Secara struktural **selesai dan setara Instagram**: OAuth dengan PKCE, penukaran
token, penyegaran token otomatis (kontrak TikTok berbeda — refresh token
dirotasi tiap dipakai), sinkronisasi video, snapshot, pencocokan, halaman
unmatched, kartu di Pengaturan, penanganan error, enkripsi token. Yang belum ada
**bukan kode**, melainkan: (a) App Review TikTok, dan (b) satu pun percobaan
nyata.

> **Penilaian eksplisit terhadap "TikTok belum selesai":** setelah audit
> menyeluruh, tidak ditemukan TODO, placeholder, atau jalur yang belum
> diimplementasikan pada integrasi TikTok. Yang membuatnya belum bisa disebut
> `READY` adalah **nol verifikasi runtime** dan **ketergantungan pada App Review
> eksternal**, bukan kode yang setengah jadi. Ini berbeda dari, misalnya, fitur
> Tambah Konten yang memang benar-benar rusak.

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

**Status:** `KNOWN_ISSUE` — fungsinya berjalan, tetapi tidak ada pemeriksaan hak
akses klien (KI-09).

## Import Audience CSV

Tersedia lewat rute `/audience/import` (`analytics,view`).
**Status:** `NEEDS_VERIFICATION` — tidak diverifikasi mendalam dalam audit ini.

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
  Creator, Desain → Desain Grafis). Kalau tidak ada yang cocok, sistem tetap
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

**Status:** `NEEDS_VERIFICATION` — implementasi lengkap dan kredensial Gemini
terisi, tapi belum pernah dijalankan dengan data performa nyata (0 metrik di
lingkungan ini). Perhatikan juga: konten hasil Terapkan akan bermuara di halaman
Detail Konten yang saat ini rusak (KI-03).

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

> ⚠️ **Dashboard tidak dibatasi per klien sama sekali.** SMO — yang di semua
> halaman lain hanya melihat klien roster-nya — di sini melihat angka seluruh
> klien, termasuk Peringkat Klien. Lihat KI-10.

**Status:** `READY` (terverifikasi runtime), dengan catatan cakupan di atas.

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

## Kelola Pengguna (judul halaman: **Kelola Tim**)

> ⚠️ **Ketidaksesuaian istilah:** menu sidebar menulis **"Kelola Pengguna"**,
> judul halaman menulis **"Kelola Tim"**. Buku panduan harus memilih salah satu
> dan menyebut yang lain sebagai alias. **Rekomendasi: pakai "Kelola Pengguna"**
> (sesuai menu, karena itu yang dicari pengguna).

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
**Status** — **`KNOWN_ISSUE`** — akses login **tidak benar-benar tersimpan**,
sehingga orang yang baru diundang **tidak akan bisa masuk**. Lihat KI-06.

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

**Status** — **`KNOWN_ISSUE`** — gagal saat disimpan. Lihat KI-05.
**Solusi sementara:** gunakan **Detail Klien → PIC Ditugaskan** (arah sebaliknya,
berfungsi normal).

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
aktif. ⚠️ Lihat KI-06 — saat ini tidak ada satu pun cara di UI untuk
mengaktifkan akses login.

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

**Status:** `NEEDS_VERIFICATION` — halaman terverifikasi terbuka (200), tetapi
pembuatan berkas PDF/Excel belum pernah diuji dan tidak ada data untuk diuji.

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

**Status:** `NEEDS_VERIFICATION` (halaman terbuka, tapi 0 integrasi)

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
  Task Scheduler) — sudah terdokumentasi di `docs/RUNTIME.md`
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
ID tersebut valid. Perilaku ini **dilindungi 26 pengujian otomatis** — satu-
satunya bagian sistem yang punya cakupan pengujian nyata.

**Status:** `READY` — bagian dengan bukti kebenaran terkuat di seluruh aplikasi,
meskipun tidak dijalankan runtime dalam audit ini (butuh token klien nyata).

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

# Bagian 22. Known Issues dan Fitur Belum Siap

Diurutkan dari yang paling menghambat. Semua temuan **diverifikasi terhadap kode
pada commit yang diaudit** — bukan dugaan.

| ID | Area | Status Audit Pertama | Status Re-Audit | Masalah (asli) | Dokumentasikan Sekarang? |
|---|---|---|---|---|---|
| **KI-01** | Rencana Konten → Tambah Konten | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentPlanTest` | `Rule::exists()` tanpa import; form/kode field name mismatch | ✅ Ya, sudah boleh — lihat laporan stabilisasi §2 |
| **KI-02** | Jobdesk Tambahan | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentPlanTest` | Sama root cause KI-01 | ✅ Ya, sudah boleh |
| **KI-03** | Detail Konten | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentItemDetailTest` + verifikasi runtime data nyata | `$activeCountsByMember` tidak pernah dibuat | ✅ Ya, sudah boleh — **blocker terbesar sudah tidak ada** |
| **KI-04** | Ganti Penanggung Jawab | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentItemDetailTest` | Field mismatch + variabel `$user` tidak terdefinisi | ✅ Ya, sudah boleh |
| **KI-05** | Kelola Pengguna → Assign Klien | `KNOWN_ISSUE` | ✅ **FIXED** — `UserManagementTest` | `$user->isClientUser()` tidak ada | ✅ Ya, sudah boleh |
| **KI-06** | Undang User / akses login | `KNOWN_ISSUE` | ✅ **FIXED** — `UserManagementTest` | `login_enabled` tidak fillable + tidak ada tombol UI, **sekarang ada** | ✅ Ya, sudah boleh (peringatan lama sudah tidak relevan) |
| **KI-07** | AI Brief — tanggal | `KNOWN_ISSUE` | ✅ **FIXED** — `BriefGenerationDateTest` | Prompt tidak tahu tanggal hari ini; sekarang divalidasi+fallback deterministik | ✅ Ya, tanpa peringatan tanggal lagi (tetap sarankan user cek manual sebagai praktik baik) |
| **KI-08** | Integrasi Instagram & TikTok | `NEEDS_VERIFICATION` | ⚠️ Diverifikasi sejauh mungkin — `SocialIntegrationOAuthTest` (10 test); live OAuth tetap `EXTERNAL_BLOCKED` (App Review) | Kode lengkap tapi belum pernah dipakai | ⚠️ Ya, konseptual, **jangan klaim "selesai"** — lihat laporan stabilisasi §5 |
| **KI-09** | Import CSV Performa | `KNOWN_ISSUE` | ✅ **FIXED** — `ImportPerformanceScopeTest` | Tidak ada guard client scope | ✅ Ya, sudah boleh |
| **KI-10** | Dashboard | `KNOWN_ISSUE` (awalnya ditandai `READY` di rekap, itu keliru) | ✅ **FIXED** — `DashboardScopeTest` | Nol scoping per client | ✅ Ya, dengan cakupan yang sekarang benar |
| **KI-11** | Enum status workflow | `NOT_READY` | ✅ **FIXED (dihapus)** | Dead code, 2 method badan kosong | ❌ Tidak relevan bagi pengguna |
| **KI-12** | Revision Log & Publishing Tracker | `KNOWN_ISSUE` | ✅ **FIXED** — `LegacyRouteRedirectTest` (redirect ke tab resmi) | Duplikat tanpa pintu masuk UI | ❌ Dokumentasikan **hanya** tab di Produksi (URL lama tetap jalan via redirect) |
| **KI-13** | Rencana Konten ditolak | `KNOWN_ISSUE` | ✅ **FIXED** — `ContentPlanTest` (jalur Ditolak→Draf→ajukan ulang + riwayat) | Tidak ada jalur balik dari Ditolak | ✅ Ya, sudah boleh — keterbatasan lama sudah tidak berlaku |
| **KI-14** | Proses otomatis (scheduler & queue) | `NOT_READY` | ✅ **FIXED (tooling+dokumentasi)** — `composer run dev` sekarang termasuk scheduler | Tidak ada cara mudah jalankan semua proses bareng | ✅ Ya — troubleshooting section tetap relevan untuk deployment yang belum setup queue/cron |
| **KI-15** | Konfigurasi pengujian | `KNOWN_ISSUE` | ✅ **FIXED** — DB testing terisolasi permanen + safeguard hard-abort | `phpunit.xml` menunjuk DB dev | ❌ Bukan untuk pengguna → Panduan Administrator |
| **KI-16** | Cakupan pengujian | `NEEDS_VERIFICATION` | ✅ **FIXED** — 82 test (dari 26), lintas semua area utama | Hanya Portal Klien punya test | ❌ Bukan untuk pengguna |
| **KI-17** | Ketidaksesuaian istilah | `KNOWN_ISSUE` | ⚠️ **Sebagian diperbaiki** — 5 contoh paling jelas diselaraskan (Kelola Pengguna, Performa, Rencana Konten, Laporan, Pengaturan, Data Pilihan); sweep menyeluruh belum dilakukan | Menu vs judul halaman tidak sama | ✅ Ya — buku **tetap wajib** memilih istilah baku (Bagian 25), sweep lanjutan disarankan saat menulis |
| **KI-18** | Publikasi langsung ke media sosial | `NOT_READY` | ✅ Confirmed tetap `OUT_OF_SCOPE` (by design, bukan bug) — tidak ada wording menyesatkan ditemukan | Tidak ada, dan eksplisit di luar cakupan | ✅ Ya — tulis eksplisit "tidak tersedia" |
| **KI-19** | Dokumentasi kode usang | `KNOWN_ISSUE` | ✅ **FIXED** — komentar diperbarui | Komentar bilang OAuth "UI saja" padahal sudah fungsional | ❌ Bukan untuk pengguna |
| **KI-20** | Kode mati kecil | `KNOWN_ISSUE` | ✅ **FIXED (dihapus)** | `$picOptions` tidak terpakai | ❌ Tidak |

**Temuan tambahan Phase L (re-audit, di luar KI-01...KI-20):** 3 authorization
leak baru (AI Strategy History, Import Audience CSV, kanban drag-drop
Produksi) — semuanya **FIXED**, detail di laporan stabilisasi §3. Tidak
berdampak ke konten buku panduan (bukan bug user-facing, murni celah akses).

## Rekapitulasi status — AUDIT PERTAMA (sebelum stabilization sprint)

| Status | Jumlah fitur |
|---|---|
| `READY` | **26** |
| `NEEDS_VERIFICATION` | **13** |
| `KNOWN_ISSUE` | **8** |
| `NOT_READY` | **3** |
| **Total fitur utama** | **50** |

Rincian:

**`READY` (26)** — Beranda · Dashboard · Rencana Konten (lihat) · Buat Rencana ·
Ajukan Rencana · Setujui/Tolak Rencana · Papan Produksi · Tab Revisi · Tab Sudah
Tayang · Status Management *(logika)* · Koreksi Status · Catatan Revisi ·
Kerjakan Revisi · Catat Publikasi *(logika)* · Kelola Klien · Ubah Paket · Atur
PIC dari Detail Klien · Kelola link Portal Klien · Edit Role · Nonaktifkan/
Aktifkan User · Performa Tim · Absensi · Data Pilihan & Paket · Portal Klien
(Dashboard/Kalender/Riwayat/Persetujuan) · Pencarian · Notifikasi · Pin · Tema

**`NEEDS_VERIFICATION` (13)** — Performa: Analytics · Performa: Tabel Performa ·
Performa: Audiens · Detail Performa Konten · Ekspor CSV · Import Audience CSV ·
AI Strategy · Instagram Integration · TikTok Integration · Unmatched Instagram ·
Unmatched TikTok · Laporan (2 jenis) · Portal Klien: Analytics · Skor Risiko
Keterlambatan & Deteksi Anomali

**`KNOWN_ISSUE` (8)** — Tambah Konten (KI-01) · Jobdesk Tambahan (KI-02) ·
Detail Konten (KI-03) · Ganti PIC (KI-04) · Assign Klien (KI-05) · Undang User /
akses login (KI-06) · AI Brief (KI-07) · Import CSV Performa (KI-09)

**`NOT_READY` (3)** — Proses otomatis terjadwal (KI-14) · Publikasi langsung ke
media sosial (KI-18) · Enum status workflow, kode mati (KI-11)

> Catatan: Dashboard (KI-10) tetap dihitung `READY` karena berfungsi penuh —
> masalahnya cakupan data, bukan kerusakan. Import CSV Performa dihitung
> `KNOWN_ISSUE` karena celah aksesnya berdampak nyata.

## Rekapitulasi status — RE-AUDIT (setelah stabilization sprint)

| Status | Jumlah fitur | Perubahan |
|---|---|---|
| `READY` | **35** | +9 (KI-01…KI-07, KI-09, KI-10, KI-13 diperbaiki; Laporan, AI Strategy, Performa/Analytics/Tabel/Audiens/Detail/Ekspor CSV, Import Audience CSV diverifikasi runtime) |
| `NEEDS_VERIFICATION` | **4** | -9 — sisa: Instagram/TikTok Integration (KI-08, live OAuth), Unmatched Instagram, Unmatched TikTok, Portal Klien: Analytics, Skor Risiko Keterlambatan & Deteksi Anomali (akurasi model ML, bukan graceful-degradation-nya) *(catatan: 5 item, bukan 4 — lihat rincian)* |
| `KNOWN_ISSUE` | **0** | -8 — seluruhnya diperbaiki dengan regression test |
| `NOT_READY` | **1** | -2 — KI-11 & KI-14 diperbaiki; KI-18 tetap (`OUT_OF_SCOPE` by design, bukan bug) |
| **Total fitur utama** | **50** | tidak berubah |

Rincian re-audit:

**`READY` tambahan (9)** — Tambah Konten (KI-01) · Jobdesk Tambahan (KI-02) ·
Detail Konten (KI-03) · Ganti PIC (KI-04) · Assign Klien (KI-05) · Undang User /
akses login (KI-06) · AI Brief (KI-07) · Import CSV Performa (KI-09) · Rencana
Ditolak buntu (KI-13) — semua dengan regression test otomatis (lihat
`docs/PRE_DOCUMENTATION_STABILIZATION_REPORT.md` §2). **Plus** yang sudah
`READY` sebelumnya tapi sekarang diverifikasi runtime (bukan cuma dianggap
benar dari static code): Dashboard (dengan scope yang sudah benar, KI-10),
Performa/Analytics (overview+table+audience tab), Detail Performa Konten,
Ekspor CSV, Import Audience CSV, Laporan (progres+performa), AI Strategy
(generate+apply+revert).

**`NEEDS_VERIFICATION` tersisa (5)**:
- **Instagram/TikTok Integration** (KI-08) — kode & jalur non-consent
  terverifikasi lewat test; live OAuth (consent screen sungguhan) tetap
  butuh akun tester nyata, `EXTERNAL_BLOCKED`.
- **Unmatched Instagram**, **Unmatched TikTok** — TIDAK diuji ulang di
  sprint ini (di luar cakupan KI-01...KI-20 dan tidak ditemukan indikasi
  bug saat re-audit code path terkait, tapi belum ada regression test
  baru maupun runtime check khusus fitur ini).
- **Portal Klien: Analytics** — TIDAK diuji ulang (`ClientPortalTest`
  yang ada tidak menyentuh sub-halaman Analytics-nya secara spesifik).
- **Skor Risiko Keterlambatan & Deteksi Anomali** — *graceful degradation*-nya
  (model/script Python gagal → log+skip, bukan crash) terverifikasi lewat
  pembacaan kode `DelayRiskPredictionService`; akurasi prediksi model ML
  itu sendiri tidak diuji (di luar kemampuan verifikasi tanpa data historis
  yang cukup + model yang sudah dilatih).

**`KNOWN_ISSUE` (0)** — seluruh 8 temuan awal diperbaiki.

**`NOT_READY` (1)** — Publikasi langsung ke media sosial (KI-18), tetap
`OUT_OF_SCOPE` sesuai desain, dikonfirmasi ulang tidak ada wording
menyesatkan.

---

# Bagian 23. Daftar Prosedur untuk Buku Panduan

Prioritas **Tinggi** = wajib ada di rilis pertama buku. **Sedang** = sebaiknya
ada. **Rendah** = boleh menyusul.

| ID | Tutorial | Role | Prioritas | Status |
|---|---|---|---|---|
| UG-01 | Masuk ke sistem dengan akun Google | Semua internal | Tinggi | `KNOWN_ISSUE` (KI-06) |
| UG-02 | Mengenal Beranda & panel Langkah Berikutnya | Semua internal | Tinggi | `READY` |
| UG-03 | Check-in & check-out harian | Semua internal | Tinggi | `READY` |
| UG-04 | Mengganti tema & melipat sidebar | Semua internal | Rendah | `READY` |
| UG-05 | Mencari klien, orang, atau konten | Semua internal | Sedang | `READY` |
| UG-06 | Membaca & menindaklanjuti notifikasi | Semua internal | Sedang | `READY` |
| UG-07 | Mem-pin konten sebagai fokus pribadi | Semua internal | Sedang | `READY` |
| UG-08 | Menambahkan klien baru | CEO, Manager | Tinggi | `READY` |
| UG-09 | Menentukan & mengubah paket klien | CEO, Manager | Tinggi | `READY` |
| UG-10 | Menugaskan tim (PIC) ke klien | CEO, Manager | **Tinggi** | ⚠️ `KNOWN_ISSUE` (KI-05) — dokumentasikan jalur Detail Klien |
| UG-11 | Mengaktifkan & membagikan link Portal Klien dengan aman | CEO, Manager | **Tinggi** | `READY` |
| UG-12 | Menghubungkan akun Instagram klien | CEO, Manager | Tinggi | `NEEDS_VERIFICATION` |
| UG-13 | Menghubungkan akun TikTok klien | CEO, Manager | Sedang | `NEEDS_VERIFICATION` |
| UG-14 | **Onboarding klien baru dari nol sampai siap** (menggabungkan UG-08…UG-13) | CEO, Manager | **Tinggi** | `NEEDS_VERIFICATION` |
| UG-15 | Membuat Rencana Konten bulanan | CEO, Manager, Copywriter | Tinggi | `READY` |
| UG-16 | Menambahkan konten ke rencana | CEO, Manager, Copywriter | Tinggi | ❌ `KNOWN_ISSUE` (KI-01) — **tunda** |
| UG-17 | Mengajukan & menyetujui Rencana Konten | CEO, Manager, SMO, Copywriter | Tinggi | `READY` |
| UG-18 | Mencatat permintaan mendadak (Jobdesk Tambahan) | CEO, Manager, Copywriter | Sedang | ❌ `KNOWN_ISSUE` (KI-02) — **tunda** |
| UG-19 | Membuat brief produksi dengan AI | Copywriter, Manager | Tinggi | ⚠️ `KNOWN_ISSUE` (KI-07 + KI-03) |
| UG-20 | Berdiskusi dengan AI & mengedit brief manual | Copywriter, Manager | Sedang | ⚠️ `KNOWN_ISSUE` (KI-03) |
| UG-21 | Menerapkan brief ke tim produksi | Copywriter, Manager | Tinggi | ⚠️ `KNOWN_ISSUE` (KI-03) |
| UG-22 | Membaca papan Produksi (Kanban & Daftar) | Semua internal | **Tinggi** | `READY` |
| UG-23 | Mengerjakan konten yang ditugaskan (alur harian PIC) | Content Creator, Desain Grafis | **Tinggi** | ⚠️ `KNOWN_ISSUE` (KI-03) |
| UG-24 | Menandai footage sudah di-take & mengisi link hasil | Content Creator | Sedang | ⚠️ `KNOWN_ISSUE` (KI-03) |
| UG-25 | Mengisi draft caption untuk dibaca klien | Copywriter, Manager | Sedang | ⚠️ `KNOWN_ISSUE` (KI-03) |
| UG-26 | Meminta revisi & mengerjakannya | Manager, SMO, PIC | Tinggi | `READY` |
| UG-27 | Menyetujui konten (review internal) | CEO, Manager, SMO | **Tinggi** | ⚠️ `KNOWN_ISSUE` (KI-03) |
| UG-28 | Menjadwalkan & mencatat publikasi | SMO, CEO | **Tinggi** | ⚠️ `KNOWN_ISSUE` (KI-03) |
| UG-29 | Mengoreksi status yang salah | CEO, Manager | Sedang | ⚠️ `KNOWN_ISSUE` (KI-03) |
| UG-30 | Memindahkan konten ke PIC lain | CEO, Manager | Sedang | ❌ `KNOWN_ISSUE` (KI-04) — **tunda** |
| UG-31 | Membaca halaman Performa | CEO, Manager, SMO | Tinggi | `NEEDS_VERIFICATION` |
| UG-32 | Menautkan post media sosial yang belum terhubung | SMO, CEO | Sedang | `NEEDS_VERIFICATION` |
| UG-33 | Mengimpor data performa dari CSV | CEO, Manager, SMO | Sedang | `KNOWN_ISSUE` (KI-09) |
| UG-34 | Mengekspor data performa | CEO, Manager, SMO | Rendah | `NEEDS_VERIFICATION` |
| UG-35 | Menjalankan analisis AI Strategy | CEO, Manager, SMO | Sedang | `NEEDS_VERIFICATION` |
| UG-36 | Berdiskusi & memperbarui analisis AI | CEO, Manager, SMO | Rendah | `NEEDS_VERIFICATION` |
| UG-37 | Menerapkan & menarik kembali AI Strategy | CEO, Manager, SMO | Sedang | `NEEDS_VERIFICATION` |
| UG-38 | Membuat laporan untuk klien | CEO, Manager, SMO | Tinggi | `NEEDS_VERIFICATION` |
| UG-39 | Mengundang anggota tim baru | CEO, Manager | Tinggi | ⚠️ `KNOWN_ISSUE` (KI-06) |
| UG-40 | Mengubah role anggota tim | CEO, Manager | Sedang | `READY` |
| UG-41 | Menonaktifkan anggota & memindahkan tugasnya | CEO, Manager | Tinggi | `READY` |
| UG-42 | Memantau beban kerja tim | CEO, Manager | Sedang | `READY` |
| UG-43 | Melihat rekap kehadiran bulanan | CEO, Manager | Sedang | `READY` |
| UG-44 | Mengelola Data Pilihan & Paket | CEO, Manager, SMO | Sedang | `READY` |
| UG-45 | Menjalankan sinkronisasi & membaca riwayatnya | CEO, Manager, SMO | Sedang | `NEEDS_VERIFICATION` |
| UG-46 | **(Untuk klien)** Menggunakan Portal Klien | Klien | **Tinggi** | `READY` |
| UG-47 | **(Untuk klien)** Menyetujui konten / meminta revisi | Klien | **Tinggi** | `READY` |
| UG-48 | Troubleshooting: "kenapa halaman saya kosong?" | Semua internal | **Tinggi** | `READY` |
| UG-49 | Troubleshooting: "kenapa data performa tidak muncul?" | CEO, Manager, SMO | **Tinggi** | Terkait KI-14 |
| UG-50 | Troubleshooting: "kenapa saya tidak bisa masuk?" | Semua internal | **Tinggi** | Terkait KI-06 |

**Ringkasan kelayakan:** dari 50 prosedur, **22 bisa langsung ditulis lengkap**,
**14 bisa ditulis dengan peringatan**, **4 harus ditunda sepenuhnya** (UG-16,
UG-18, UG-30, dan bagian dari UG-19), sisanya menunggu verifikasi runtime.

> **Prosedur yang sengaja TIDAK dibuat** karena tidak relevan bagi pengguna:
> menjalankan perintah baris perintah, mengisi `.env`, memasang queue worker,
> import Content Planner dari Excel (perkakas migrasi sekali pakai), dan halaman
> `/revision-log` serta `/publishing-tracker` (duplikat, lihat KI-12).

---

# Bagian 24. Rencana Screenshot

Prinsip: **1 screenshot = 1 konsep penting.** Jangan memotret setiap klik.

⚠️ **Prasyarat pengambilan screenshot:** database dev saat ini hampir kosong
(0 konten, 1 klien). Sebelum memotret, siapkan data demo yang layak — minimal
3 klien, 2–3 rencana konten, dan 15–20 konten yang tersebar di semua status.
Tanpa itu, semua screenshot akan berupa halaman kosong.

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
| SS-CP-06 | Modal Jobdesk Tambahan | Input cepat permintaan mendadak | Manager | **Modal terbuka** — ⚠️ tunda sampai KI-02 diperbaiki |

## Produksi

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-PR-01 | Produksi → Papan (Kanban) | Konsep inti papan produksi | Manager | **Semua 8 kolom terisi minimal 1 kartu**, ada 1 kartu ter-pin di atas, ada 1 kartu bertanda terlambat |
| SS-PR-02 | Produksi → Daftar | Tampilan alternatif + pengurutan | Manager | Kolom terurut, filter status aktif |
| SS-PR-03 | Produksi → tab Revisi | Tempat melihat semua revisi | Manager | Beberapa revisi, ada yang dari klien dan dari internal |
| SS-PR-04 | Produksi → tab Sudah Tayang | Riwayat publikasi | SMO | Beberapa publikasi lintas platform |
| SS-PR-05 | Modal saat menggeser kartu ke Revisi | Catatan revisi wajib diisi | Manager | **Modal terbuka**, kolom catatan kosong |
| SS-PR-06 | Detail Konten (atas) | Peta halaman kerja utama | Content Creator | Judul, info konten, kartu AI Brief — ⚠️ tunggu KI-03 |
| SS-PR-07 | Detail Konten → Status Management | Tombol yang tersedia per status | Content Creator | Status **Sedang Dikerjakan**, tombol aktif & nonaktif berdampingan — ⚠️ tunggu KI-03 |
| SS-PR-08 | Detail Konten → tombol nonaktif + tooltip | Membuktikan pembatasan hak akses terlihat | Copywriter | Tooltip "Kamu tidak punya izin memindahkan status" — ⚠️ tunggu KI-03 |
| SS-PR-09 | Detail Konten → penanda footage | Fitur khusus video | Content Creator | Status Sedang Dikerjakan + kolom tanggal take — ⚠️ tunggu KI-03 |
| SS-PR-10 | Detail Konten → modal Koreksi Status | Jalur khusus Manager/CEO | Manager | **Modal terbuka**, kolom alasan wajib — ⚠️ tunggu KI-03 |
| SS-PR-11 | Detail Konten → modal Ganti PIC | Kandidat + beban kerja | Manager | **Modal terbuka**, kandidat terurut dari task paling sedikit — ⚠️ tunggu KI-03 & KI-04 |
| SS-PR-12 | Modal Catat Publikasi | Data yang harus diisi | SMO | **Modal terbuka**, semua field terlihat |

## AI Brief & AI Strategy

| ID | Halaman | Tujuan | Role | Kondisi/Data yang harus tampak |
|---|---|---|---|---|
| SS-AI-01 | Kartu AI Brief (kosong) | Ajakan membuat brief | Copywriter | Tombol Buat Brief menonjol |
| SS-AI-02 | Kartu AI Brief (terisi) | Isi brief lengkap | Copywriter | Hook, adegan/slide, talent, properti — ⚠️ **JANGAN memotret bagian tanggal sampai KI-07 diperbaiki** |
| SS-AI-03 | Panel Diskusi AI Brief | Cara berdiskusi + usulan perubahan | Copywriter | Percakapan berisi minimal 1 tanya-jawab |
| SS-AI-04 | Kartu Estimasi Kompleksitas & Kelayakan | Membaca penilaian AI | Copywriter | ⚠️ Tunda — nilai kelayakan saat ini tidak dapat dipercaya (KI-07) |
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

**Total: 74 screenshot**, dengan **13 di antaranya diblokir** oleh KI-03/KI-04/
KI-02/KI-07 sampai perbaikan selesai.

> **Catatan penyuntingan:** sensor sebagian token Portal Klien, alamat email
> pribadi, dan nama klien nyata pada semua screenshot yang akan dipublikasikan.

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
| **Kelola Pengguna** | Pengelolaan anggota tim (judul halamannya tertulis "Kelola Tim") |
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
| **Kelayakan (Feasibility)** | Penilaian AI apakah brief realistis dikerjakan. ⚠️ Saat ini tidak dapat dipercaya (KI-07) |

## ⚠️ Istilah yang harus DIBAKUKAN di buku

Aplikasi masih memakai istilah berbeda untuk hal yang sama. **Buku harus memilih
satu**, dan konsisten sepanjang dokumen:

| Konsep | Muncul sebagai | **Pakai istilah ini** |
|---|---|---|
| Menu pengelolaan tim | "Kelola Pengguna" (menu) / "Kelola Tim" (judul) | **Kelola Pengguna** |
| Menu analitik | "Performa" (menu) / "Performa Konten" (judul) / `analytics` (URL) | **Performa** |
| Menu perencanaan | "Rencana Konten" (menu) / "Rencana Konten Bulanan" (judul) / "Content Plan Bulanan" (tab peramban) | **Rencana Konten** |
| Menu laporan | "Laporan" (menu & judul) / "Report Generator" (tab peramban) | **Laporan** |
| Menu pengaturan | "Pengaturan" (menu & judul) / "Settings" (tab peramban) | **Pengaturan** |
| Halaman revisi | "Revisi" (tab) / "Revision Log" (halaman lama) | **Produksi → tab Revisi** |
| Halaman publikasi | "Sudah Tayang" (tab) / "Publishing Tracker" (halaman lama) | **Produksi → tab Sudah Tayang** |
| Post belum terhubung | "Unmatched Instagram Media" / "Unmatched TikTok Video" | **Post Belum Tertaut** |
| Data referensi | "Data Pilihan" (tab) / "Master Data" (istilah lama di kode) | **Data Pilihan** |
| Penanggung jawab | "PIC" / "Penanggung Jawab" | **Penanggung Jawab (PIC)** — sebut keduanya sekali, lalu pakai PIC |

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
     3.2 Kalau tidak bisa masuk                        [terkait KI-06]
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
  13. Untuk Desain Grafis
  14. Untuk SMO
      → tiap bab: "hari-hari Anda", 3-5 tugas utama, apa yang tidak bisa
        Anda lakukan dan harus minta ke siapa

BAGIAN V — TUTORIAL BERDASARKAN PEKERJAAN   (isi utama buku)
  15. Mengelola klien
      15.1 Menambahkan klien baru
      15.2 Menentukan paket
      15.3 Menugaskan tim ke klien                     [KI-05: jalur alternatif]
      15.4 ★ Onboarding klien baru dari nol sampai siap
  16. Menghubungkan media sosial klien
      16.1 Instagram   16.2 TikTok   16.3 Sinkronisasi & verifikasi
  17. Merencanakan konten
      17.1 Membuat Rencana Konten
      17.2 Menambahkan konten                          [KI-01: TUNDA]
      17.3 Mengajukan & menyetujui
      17.4 Permintaan mendadak                         [KI-02: TUNDA]
  18. Menyusun brief
      18.1 Membuat brief dengan AI
      18.2 Berdiskusi & mengedit manual
      18.3 Menerapkan ke tim produksi
      ⚠ Peringatan: selalu periksa Tanggal Mulai & Posting  [KI-07]
  19. Mengerjakan & menyelesaikan konten
  20. Revisi & persetujuan
  21. Menjadwalkan & mencatat publikasi
  22. Membaca Performa
  23. Menggunakan AI Strategy
      23.1 Apa yang hanya rekomendasi, apa yang benar-benar mengubah sistem
  24. Membuat laporan untuk klien
  25. Mengelola tim
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
      "Tidak bisa masuk"                               [KI-06]
      "Halaman saya kosong"        → belum di-assign ke klien
      "Tidak punya akses ke halaman ini"
  30. Masalah data
      "Data performa tidak muncul"                     [KI-14]
      "Sync ditekan tapi tidak terjadi apa-apa"        [KI-14]
      "Post tidak muncul di Performa"  → Post Belum Tertaut
      "Link portal klien tidak bisa dibuka"
  31. Keterbatasan yang diketahui
      31.1 Tidak ada posting langsung ke media sosial  [KI-18]
      31.2 Data TikTok lebih terbatas dari Instagram
      31.3 Tidak ada koreksi absensi manual
      31.4 Rencana yang ditolak tidak bisa diajukan ulang  [KI-13]
      31.5 Tanggal pada brief AI                       [KI-07]
      31.6 Fitur yang sedang diperbaiki                [KI-01…KI-05]

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
   Tulis "Content Creator dan Desain Grafis dapat memperbarui status pekerjaan
   pada klien yang ditugaskan kepada mereka", bukan "dibutuhkan
   `workflow.update`". Tabel permission teknis cukup di Lampiran B.
5. **Beri tanda jelas pada fitur bermasalah** dengan kotak peringatan, jangan
   disembunyikan. Pengguna lebih percaya buku yang jujur soal keterbatasan.
6. **Bagian VI bisa dicetak/dikirim terpisah** ke klien — jangan campur dengan
   isi internal.
7. **Konsisten pada istilah baku** di tabel Bagian 25.

## Urutan pengerjaan yang disarankan

| Tahap | Isi | Kenapa |
|---|---|---|
| **1** | Bagian I, II, III, VI, VII | Semuanya `READY` atau terdokumentasi baik; bisa langsung dikerjakan sekarang |
| **2** | Bagian IV (panduan per role) | Butuh Bagian III selesai lebih dulu |
| **3** | Bagian V bab 15, 17.1, 17.3, 19–21, 24–26 | Bergantung sebagian pada perbaikan KI-03 |
| **4** | Bagian V bab 16, 22, 23 | Tunggu verifikasi runtime integrasi & analytics |
| **5** | Bagian V bab 17.2, 17.4, 18 | **Tunggu perbaikan KI-01, KI-02, KI-03, KI-07** |

---

*Akhir dokumen. Disusun dari audit read-only commit `d637369` pada 26 Agustus
2026. Tidak ada satu baris kode pun yang diubah dalam proses audit ini.*
