# KPI Team Performance

## Tujuan

Memberi satu nilai KPI global per user per bulan pada halaman Team
Performance (tab Performa), dihitung otomatis dari data yang sudah ada
(assignment, brief, status log, revision, publication, analytics) - tanpa
menambah role, permission, assignment, form, atau langkah kerja baru. Role
existing hanya ditampilkan sebagai label informasi; satu user dengan
beberapa role tetap mendapat satu nilai KPI.

## Formula

```
Skor Dasar = (Ketepatan Kerja x 60%) + (Kualitas Kerja x 40%)
Nilai KPI  = min(100, Skor Dasar + Bonus Analytics)
```

### Ketepatan Kerja

Persentase content tepat waktu, dengan urutan sumber data per content:

1. Kalau ada `scheduled_upload_at` dan sudah ada publication: bandingkan
   `scheduled_upload_at` dengan publication paling awal (`published_at`).
   Tepat waktu kalau `published_at <= scheduled_upload_at + 24 jam`.
2. Kalau tidak: pakai handoff pertama `in_progress -> waiting_review` (dari
   `content_status_logs`) dibanding `deadline_at`.
3. Kalau data waktu tidak lengkap: content dikecualikan dari perhitungan
   ketepatan (tidak dianggap terlambat, tidak masuk penyebut).

Ketepatan Kerja = (content tepat waktu) / (content yang datanya bisa
dinilai) x 100. Kalau tidak ada satupun content yang datanya bisa dinilai,
Ketepatan Kerja bernilai kosong dan Skor Dasar jatuh sepenuhnya ke Kualitas
Kerja (lihat Keterbatasan).

### Kualitas Kerja

```
Kualitas = (content tanpa revisi internal) / (content yang dihitung) x 100
```

Revisi dari `content_revisions.requested_by_user_id` dianggap revisi
internal; revisi dari `requested_by_client_id` (klien) tidak jadi penalti.
Beberapa revisi pada content yang sama tetap dihitung sebagai satu content
bermasalah.

### Bonus Analytics (0-10, tambahan saja)

Untuk tiap content, dibandingkan dengan baseline minimal 3 publication
sebelumnya dari klien + platform + format yang sama (format = 
`content_format_id`, fallback `content_type_id` untuk item lama - Video
tidak pernah dibandingkan dengan Desain). Indikator: reach/views dan
engagement rate (dari likes+comments+shares+saves kalau engagement rate
API tidak tersedia), diukur dari `content_metric_snapshots` pada window
D+7 s.d. D+10 setelah publish.

Aturan bonus per indikator (dirata-rata kalau dua indikator tersedia):

- performa <= baseline: 0
- 25% di atas baseline: 5
- 50% atau lebih di atas baseline: 10
- di antaranya: interpolasi linear

Bonus per user = rata-rata bonus dari seluruh content miliknya yang
analytics-nya tersedia. Kalau tidak ada satupun content dengan baseline
cukup, `analytics_available = false` dan bonus tidak menambah maupun
mengurangi Skor Dasar.

## Sumber Data & Atribusi

Content dihitung kalau sudah punya minimal satu publication, masuk ke
bulan dari publication pertamanya (tanggal publish paling awal lintas
platform).

User dianggap terlibat pada satu content dari salah satu sumber berikut
(digabung, dedup per content):

- `content_item_assignments.user_id` (PIC)
- `content_brief_drafts.created_by`
- `content_status_logs.changed_by_user_id` pada transisi ke `scheduled`
  atau `uploaded`

Approval (`waiting_review -> approved`) SENGAJA tidak termasuk - sistem
belum punya SLA/standar kepemimpinan yang jelas untuk approval Manager/CEO,
jadi approval murni tidak menghasilkan skor pribadi. Manager/CEO hanya
mendapat baris KPI kalau mereka juga tercatat lewat salah satu sumber di
atas (mis. ikut jadi PIC produksi).

Satu content dengan beberapa PIC memberi hasil yang sama ke setiap PIC.
Satu content tidak pernah dihitung dua kali untuk user yang sama walau
muncul dari beberapa sumber atribusi sekaligus.

## Minimum Data & Status

- 0 content -> tidak ada baris KPI sama sekali ("Belum ada data" di UI).
- 1-2 content, atau >=3 content tapi kurang dari 3 yang punya data waktu
  yang bisa dinilai -> tersimpan dengan status internal `sementara`
  (`UserMonthlyKpiResult::isSufficient()` bernilai false).
- Minimal 3 content DAN minimal 3 di antaranya punya data waktu yang bisa
  dinilai -> status internal mengikuti Nilai KPI: 80-100 "Sangat baik",
  70-79 "Baik", 60-69 "Perlu perhatian", di bawah 60 "Perlu evaluasi".

Status "sementara" ini disimpan untuk keperluan internal/audit saja.
Tampilan (tabel Daftar Anggota, ringkasan profil) SELALU menampilkan Nilai
KPI sebagai angka begitu ada minimal satu content
(`UserMonthlyKpiResult::scoreLabel()`, dihitung dari skor saja) - tidak ada
konsep "data cukup/tidak cukup" yang disembunyikan dari pengguna, karena
istilah itu terbukti ambigu saat diuji ke pengguna. Transparansi jumlah
data cukup lewat kolom "Konten" (angka `sample_size` apa adanya).

## Proses Background

- `App\Services\TeamPerformanceKpiCalculator` - satu-satunya service
  kalkulasi. `calculateForPeriod()` selalu `updateOrCreate` per
  (user_id, period_start), aman dijalankan berulang. `ensureCalculated()`
  di service yang sama memutuskan kapan perlu redispatch job (dipanggil
  dari Team Performance maupun halaman Profil, lihat di bawah).
- `App\Jobs\RecalculateMonthlyKpi` - satu-satunya job, `ShouldBeUnique` per
  bulan + `Cache::lock` saat eksekusi supaya tidak pernah dobel.
- Dua pemicu saja: jadwal harian (`routes/console.php`, 02:00) dan saat
  halaman yang menampilkan KPI dibuka (Team Performance atau Profil)
  kalau bulan berjalan belum pernah dihitung atau hasil terakhirnya bukan
  dari hari ini.
- Kalkulasi berjalan di latar belakang tanpa indikator "sedang memuat" di
  UI (dulu ada, dihapus karena tidak pernah hilang selama belum ada queue
  worker aktif - lihat Keterbatasan). Kalau belum ada hasil sama sekali,
  halaman cukup menampilkan "Belum ada data KPI untuk periode ini."
  Pengguna tidak pernah diminta menjalankan command manual.

## Penyimpanan

Satu tabel, `user_monthly_kpi_results` (unique `user_id` + `period_start`):
`timeliness_score`, `quality_score`, `analytics_bonus`,
`analytics_available`, `final_score`, `sample_size`, `status` (internal,
lihat "Minimum Data & Status"), `breakdown` (JSON daftar content + alasan
perhitungan per content, dipakai bagian KPI di halaman Profil),
`calculated_at`.

## Tampilan

- Team Performance (tab Performa): filter bulan (flatpickr, auto-submit,
  konsisten dengan filter bulan halaman lain), tren tim 6 bulan terakhir
  (Nilai KPI/Ketepatan/Kualitas) sebagai chart, chart perbandingan Nilai
  KPI antar anggota (diurutkan skor tertinggi), lalu tabel Daftar Anggota
  (diurutkan nama). Klik satu anggota membuka halaman Profil orang itu,
  bukan halaman terpisah.
- Halaman Profil: menampilkan bagian "KPI Bulan Ini" (bulan berjalan saja,
  tidak ada filter bulan di sini) untuk pemilik profil sendiri, atau untuk
  siapa pun kalau penonton punya permission `team_performance,view` -
  tidak ada permission baru, aturan visibilitas ini murni di controller
  (`ProfileController::show()`).
- Tidak ada filter pencarian nama maupun tombol "Terapkan" terpisah -
  filter bulan submit otomatis saat tanggal dipilih.

## Keterbatasan

- Kalau Ketepatan Kerja tidak bisa dihitung sama sekali untuk seorang user
  (tidak ada satupun content dengan data waktu lengkap), Skor Dasar jatuh
  ke Kualitas Kerja saja (bobot 100%) alih-alih rata-rata tertimbang penuh.
- Job KPI berjalan lewat queue (`QUEUE_CONNECTION=database`) - kalau tidak
  ada `queue:work` yang aktif di server, hasil baru tidak akan pernah
  muncul walau job berhasil di-dispatch terus-menerus. Ini bukan masalah
  KPI secara spesifik (sync analytics lain juga bergantung queue yang
  sama), tapi penting diketahui karena efeknya di halaman ini adalah Nilai
  KPI yang terlihat "diam" walau datanya di baliknya sudah berubah.
- Bonus Analytics butuh `content_metric_snapshots` terisi pada window
  D+7-D+10 dan minimal 3 publication pembanding sejenis; kalau data sync
  analytics belum lengkap untuk klien/format tertentu, bonus akan terus
  tampil "belum tersedia" untuk PIC yang menangani klien/format itu -
  ini bukan bug, tapi konsekuensi eksplisit aturan "jangan mengarang data".
- Skor dihitung ulang penuh tiap kali job jalan (bukan incremental) -
  cukup murah untuk skala data saat ini, tapi perlu diperhatikan kalau
  jumlah content publication per bulan tumbuh sangat besar.
