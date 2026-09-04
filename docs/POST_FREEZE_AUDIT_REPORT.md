# Audit Sistem Pasca-Documentation-Freeze

**Rentang yang diaudit:** commit `772bcef` (26 Agustus 2026, documentation
freeze) → `HEAD` (4 September 2026) — 82 commit, 286 berkas berubah.
**Tujuan:** menemukan bug kritis sebelum dokumen buku panduan disusun, lalu
menyelaraskan dokumentasi dan dataset screenshot dengan kondisi aplikasi yang
sebenarnya.

## Cara audit dilakukan

| Metode | Cakupan |
|---|---|
| Test suite penuh | Baseline **733 test · 2.274 assertion · 0 gagal**; sesudah perbaikan + 12 regression test baru: **745 test · 2.304 assertion · 0 gagal** |
| Migrasi dari nol | `migrate:fresh` pada database bersih — 0 error, tidak ada tabrakan urutan migrasi |
| Smoke test read-only | 42 halaman × role yang berhak (termasuk keempat halaman Portal Klien untuk 3 klien) terhadap database hasil `DocumentationSeeder` |
| Matriks role × menu | Untuk **7 role**: sidebar yang benar-benar dirender dibandingkan dengan halaman yang benar-benar bisa dibuka |
| Probe aksi berat | Ekspor CSV Performa, 4 kombinasi Laporan (progres/performa × PDF/Excel), perhitungan KPI, dan 5 command terjadwal |
| Pemeriksaan foreign key | Seluruh FK ke `clients`, `users`, dan `platforms` diperiksa aturan `ON DELETE`-nya, lalu dicocokkan dengan guard di controller |

## Ringkasan temuan

**6 bug ditemukan, 6 diperbaiki. 2 keterbatasan struktural dicatat (tidak
diperbaiki — bukan bug).**

| # | Tingkat | Temuan | Status |
|---|---|---|---|
| 1 | **Tinggi** | Analisis AI Strategy bulan berjalan hilang dari panel keesokan harinya | ✅ Diperbaiki |
| 2 | **Tinggi** | Hapus klien ber-riwayat performa → halaman error 500 | ✅ Diperbaiki |
| 3 | **Sedang** | Menu "Kelola Klien" tampil untuk 4 role yang selalu kena 403 | ✅ Diperbaiki |
| 4 | **Sedang** | Hapus Platform di Data Pilihan bisa lolos guard lalu gagal di database | ✅ Diperbaiki |
| 5 | **Sedang** | Satu klien bisa punya dua Rencana Konten untuk bulan yang sama | ✅ Diperbaiki |
| 6 | **Sedang** | "Ketepatan Prediksi Risiko Tinggi" menandai hampir semua konten terlambat | ✅ Diperbaiki |
| A | — | Bonus Performa mustahil terisi untuk klien yang datanya CSV-only | 📝 Dicatat sebagai keterbatasan |
| B | — | `php artisan db:seed` memasukkan klien & staf asli ke database dokumentasi | 📝 Prosedur dokumentasi diperbaiki |

---

## 1. Analisis AI Strategy bulan berjalan hilang keesokan harinya · TINGGI

**Gejala.** SMO men-generate AI Strategy untuk bulan berjalan hari ini. Besok ia
membuka halaman Performa yang sama, filternya tidak diubah sama sekali, tapi
panelnya berbunyi *"Belum ada analisis buat client ini"*. Ringkasan, rekomendasi
split, daftar ide, dan seluruh thread diskusinya lenyap dari tampilan.

**Sebab.** Panel mencari insight dengan `period_start` **dan** `period_end` yang
sama persis dengan jendela bulan aktif. Untuk bulan berjalan,
`AiStrategyService::resolveMonthWindow()` memberi `period_end` = **hari ini**,
sedangkan baris yang tersimpan membawa `period_end` = **hari saat digenerate**.
Begitu tanggal berganti, keduanya tidak pernah cocok lagi.

**Dampak.** Datanya tidak hilang (masih ada di Riwayat AI Strategy), tapi
pengguna melihatnya hilang dan akan men-generate ulang — setiap hari, setiap
klien. Tiap generate ulang memanggil Gemini API sungguhan, dan status
"sudah diterapkan" serta diskusi sebelumnya tertinggal di baris lama.

**Perbaikan.** `period_end` dicocokkan sebagai **rentang** di dalam bulan yang
sama, bukan sama persis. Untuk bulan yang sudah lewat keduanya identik
(`period_end` = akhir bulan), jadi perilaku lama tidak berubah sedikit pun;
`period_start` tetap dikunci sehingga tidak mungkin bocor lintas bulan.

`app/Http/Controllers/AnalyticsController.php`

---

## 2. Hapus klien ber-riwayat performa → error 500 · TINGGI

**Gejala.** Klien baru di-onboard, akun sosialnya disambungkan atau performanya
di-import lewat CSV, lalu klien itu dibatalkan dan dihapus → halaman error 500.

**Sebab.** Guard `ClientManagementController@destroy` hanya memeriksa
`contentItems` dan `contentPlans`. Klien tanpa konten lolos ke `$client->delete()`,
padahal `analytics_sync_logs.client_id` adalah foreign key **RESTRICT** (berbeda
dari `api_integrations`/`audience_insights`/`content_metric_snapshots` yang
CASCADE). Database menolak, aplikasi melempar `SQLSTATE[23000]`.

**Reproduksi (terverifikasi).**

```
client id=6, sync logs=1, content items=0, plans=0
EXCEPTION Illuminate\Database\QueryException: SQLSTATE[23000]:
Integrity constraint violation: 1451 Cannot delete or update a parent row
```

**Perbaikan.** Riwayat performa (sync log, metrik, audiens, integrasi API) kini
dihitung sama seperti riwayat konten: klien **dijeda**, tidak dihapus permanen,
dengan pesan yang menjelaskan alasannya. Sesudah perbaikan, skenario yang sama
menghasilkan `status = paused`, bukan 500.

`app/Http/Controllers/ClientManagementController.php`

---

## 3. Menu "Kelola Klien" berujung 403 untuk 4 role · SEDANG

**Gejala.** SMO, Copywriter, Content Creator, dan Graphic Designer melihat menu
**Kelola Klien** di sidebar. Diklik → halaman 403.

**Sebab.** Sidebar menggerbang menu itu dengan permission `client,view`, yang
memang sengaja dibuka ke semua role internal — tapi hanya untuk halaman
**detail satu klien** yang ditugaskan kepada mereka. Daftar lengkapnya
(`ClientManagementController@index`) sengaja lebih ketat: butuh `client,manage`
atau `canSeeAllClients()`.

**Catatan.** Ini juga membuat aplikasi tidak sesuai dengan dokumentasinya
sendiri — Bagian 4 "Navigasi dan Menu" sudah menuliskan bahwa SMO/Copywriter/
Content Creator/Graphic Designer **tidak** punya grup "Klien" di sidebar.

**Perbaikan.** Item sidebar sekarang punya syarat tambahan yang dijaga identik
dengan controller-nya. Diverifikasi ulang lewat matriks role × menu: menu itu
hilang untuk keempat role dan tetap ada untuk CEO/Manager/Admin.

`resources/views/components/sidebar.blade.php`

---

## 4. Guard hapus Platform tidak lengkap · SEDANG

**Sebab.** Guard `MasterDataController@destroy` memeriksa 6 tabel yang mereferensi
`platforms`, tapi dua tabel baru pasca-freeze belum masuk daftar:
`content_metric_snapshots` dan `ai_strategy_insights`. Keduanya bisa punya baris
untuk platform yang jumlah content item-nya nol — snapshot metrik juga ditulis
untuk post yang belum ke-link ke konten internal, dan AI Strategy per-platform
disimpan per klien, bukan per konten.

**Dampak.** Kegagalan yang persis sama dengan yang komentar di kode itu sendiri
sudah berusaha dicegah: halaman error 500 mentah, bukan pesan "masih dipakai
data lain".

**Perbaikan.** Kedua tabel dimasukkan ke pemeriksaan.
`content_item_platforms` sengaja **tidak** ikut karena FK-nya CASCADE — memang
dirancang ikut terhapus.

`app/Http/Controllers/MasterDataController.php`, `app/Models/Platform.php`

---

## 5. Rencana Konten ganda untuk bulan yang sama · SEDANG

**Sebab.** `content_plans` tidak punya unique index `(client_id, month, year)`,
dan `ContentPlanController@store` tidak memeriksanya.

**Kenapa baru sekarang jadi masalah.** Sebelum perombakan alur, rencana ganda
relatif tidak berbahaya — isinya diisi manual satu per satu, jadi rencana kedua
lahir kosong dan kelihatan janggal. Sejak slot digenerate otomatis dari kuota
paket, rencana kedua **langsung menambah satu set penuh slot Draf**: untuk klien
Paket Growth itu 18 konten hantu. Panel "Target vs Realisasi" di halaman Rencana
Konten ikut berlipat, dan tim melihat dua kartu bulan yang sama tanpa tahu mana
yang dipakai.

**Perbaikan.** Percobaan membuat rencana kedua ditolak sebagai error validasi di
modal Buat Rencana, dengan pesan yang menyuruh membuka rencana yang sudah ada.

`app/Http/Controllers/ContentPlanController.php`

---

## 6. Evaluasi model AI Delay Risk membaca semua konten sebagai terlambat · SEDANG

**Sebab.** `DelayRiskAccuracyService` menyimpulkan "terlambat" dari
`log uploaded.changed_at > content_items.deadline_at`. Sejak perombakan alur
Content Plan, `deadline_at` adalah deadline **pengerjaan** yang memang dihitung
**2 hari sebelum** `upload_deadline_at`. Artinya konten yang tayang **tepat pada
jadwalnya** otomatis melewati `deadline_at`.

**Dampak.** Kartu "Ketepatan Prediksi Risiko Tinggi" di Performa Tim dan
Dashboard menampilkan precision yang mendekati 100% terus-menerus, dan bucket
risiko rendah pun ikut terbaca "telat" — angka itu berhenti berarti sebagai
evaluasi model.

**Perbaikan.** Perbandingan memakai `upload_deadline_at` (tanggal target tayang)
kalau ada, dengan fallback ke `deadline_at` untuk konten lama hasil import
planner — jadi perilaku data lama tidak berubah. Setelah perbaikan, dataset
dokumentasi menghasilkan angka yang wajar: precision **67%** (2 dari 3 risiko
tinggi benar-benar telat), risiko sedang 1 dari 3, risiko rendah 0 dari 6.

`app/Services/DelayRiskAccuracyService.php`

---

## A. Keterbatasan: Bonus Performa mustahil terisi untuk klien CSV-only

**Bukan bug — tapi harus diketahui sebelum KPI dipakai menilai orang.**

Bonus Performa hanya dihitung dari `content_metric_snapshots`, dan tabel itu
**hanya ditulis oleh sinkronisasi API Instagram/TikTok** — tidak pernah oleh
Import CSV Performa maupun input manual. Untuk klien yang data performanya masuk
lewat CSV, kolom Bonus Performa **tidak akan pernah** terisi.

Efeknya bukan penalti (bonus tidak pernah mengurangi nilai), tapi PIC klien
CSV-only tidak punya jalan menembus batas atas lewat bonus, sementara PIC klien
ber-API punya. Kalau KPI dipakai membandingkan orang lintas klien, perbedaan ini
harus disebutkan lebih dulu.

Dicatat di `docs/KPI_TEAM_PERFORMANCE.md` → **Keterbatasan**.

---

## B. `php artisan db:seed` mencemari database dokumentasi

`DatabaseSeeder` sekarang ikut memanggil `TeamClientSeeder`, yang memasukkan
**14 klien dan 13 staf 523 Studio yang sungguhan**. Sementara itu
`docs/DOCUMENTATION_DATASET.md` masih menuliskan `php artisan db:seed` sebagai
prasyarat sebelum `DocumentationSeeder` — artinya prosedur yang tertulis akan
menaruh nama klien dan nama staf asli tepat di halaman yang mau difoto.

Ditambah lagi, `RoleSeeder` membuat akun CEO bootstrap dengan alamat email 523
Studio yang sungguhan, dan akun itu ikut tampil di Kelola Pengguna, Performa
Tim, serta hitungan "Tim Aktif" di Dashboard.

**Tindakan:**
- Prosedur di `docs/DOCUMENTATION_DATASET.md` diganti: tiga seeder prasyarat
  dipanggil satu per satu, `db:seed` polos diberi peringatan tegas.
- `DocumentationSeeder` mengganti identitas akun bootstrap menjadi
  `Akun Sistem 523 <akun.sistem@example.test>`. Bisa dikembalikan kapan saja
  dengan `php artisan db:seed --class=RoleSeeder`.

---

## Kelengkapan dataset dokumentasi

Audit yang sama menemukan bahwa beberapa halaman tidak bisa difoto sama sekali
dengan dataset lama. Semuanya sudah dilengkapi di `DocumentationSeeder`:

| Yang sebelumnya kosong / tidak ada | Sekarang |
|---|---|
| **Performa Tim (KPI)** — seluruh halaman kosong; tidak ada konten tayang di bulan berjalan | 6 bulan nilai KPI dihitung oleh service aslinya; tren, perbandingan antar anggota, dan Daftar Anggota terisi dengan variasi nilai |
| **Ketepatan Prediksi Risiko Tinggi** — "belum ada cukup data" | Terisi (67% precision), dari 12 skor risiko historis bertanggal sebelum tayang |
| **Panel AI Strategy** — "Belum ada analisis buat client ini" pada tampilan default | Analisis bulan berjalan untuk Kopi Senja, lengkap dengan diskusi & daftar ide |
| **Slot Draf, Atur Deadline, Kirim ke Produksi** — tidak ada satu pun konten Draf | 3 rencana bulan depan (Disetujui / Menunggu Persetujuan / Draf) berisi 46 slot |
| **Rencana bulan depan** — tidak ada sama sekali, padahal dokumentasi menjanjikan status Draf & Menunggu Persetujuan | Ada, ketiga status rencana bisa difoto berdampingan |
| **Role Admin** — tidak ada akunnya | Galih Prasetya (Admin) |
| **Content Format, multi-platform, Link Referensi** — kolomnya kosong | Terisi, dengan sebagian sengaja dibiarkan kosong sebagai pembanding |
| Riwayat status konten tayang — semuanya bertanggal minggu ini walau tayang 2 bulan lalu | Berhenti di sekitar tanggal tayangnya sendiri |

Seeder tetap **idempoten** (dua kali jalan menghasilkan angka identik) dan tetap
menolak berjalan di database production.

## Yang TIDAK diubah, dan alasannya

- **`content_metric_snapshots` tidak di-seed.** Tabel itu rekaman mentah dari
  API provider; mengisinya berarti mengarang bahwa dataset ini pernah
  disinkronkan dari Instagram/TikTok. Sudah diverifikasi bahwa ketiadaannya
  tidak mengosongkan halaman mana pun — grafik tren punya jalur CSV/manual.
- **Filter bulan AI Strategy tidak digabung** dengan filter periode Performa.
  Keduanya memang berbeda peruntukan (retrospektif per bulan penuh vs rentang
  bebas), dan pemisahannya punya test sendiri.
- **Label "Brief Ready"** tidak dikembalikan ke "Siap Dikerjakan". Itu keputusan
  produk yang sudah diterapkan konsisten di seluruh aplikasi; dokumentasi yang
  disesuaikan, bukan kodenya.
- **PIC tidak dijadikan wajib.** Kelengkapan atribusi KPI memang bergantung pada
  disiplin mengisi PIC, tapi mewajibkannya mengubah alur produksi yang sudah
  berjalan — itu keputusan proses, bukan perbaikan bug.
