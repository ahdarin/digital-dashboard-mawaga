# Pre-Documentation Stabilization Report — 523 Studio Platform

> Laporan hasil sprint **FIX → TEST → VERIFY → CLEANUP → RE-AUDIT**, dijalankan
> di branch `stabilization/pre-user-manual`, bertitik-tolak dari audit
> `docs/USER_MANUAL_SOURCE_OF_TRUTH.md` (commit `d637369`). Buku Panduan
> Pengguna **belum** ditulis — itu di luar cakupan sprint ini.

> ⚠️ **Cara membaca dokumen ini.** Bagian §1–§9 adalah laporan **sprint
> stabilisasi**, ditulis **sebelum** Final Pre-Merge Verification. Angka test,
> status KI-17, dan sebagian "Remaining Risks" di dalamnya sudah tersalip oleh
> appendix **"Final Pre-Merge Verification"** di bagian bawah dokumen ini.
> **Yang berlaku sebagai kondisi terkini adalah appendix tersebut**, dan
> ringkasannya ada di `docs/USER_MANUAL_SOURCE_OF_TRUTH.md` (kotak "KONDISI
> TERKINI") serta `docs/DOCUMENTATION_FREEZE_CHECKLIST.md`.
>
> Ringkas: **148 test / 363 assertion / 0 failed / 0 skipped**, KI-17
> **FIXED** (sweep terminologi menyeluruh), status branch `READY_TO_MERGE`.

## 1. Executive Summary

**Status: `DOCUMENTATION_READY`**

Seluruh 8 `KNOWN_ISSUE` dan 2 dari 3 `NOT_READY` dari audit awal sudah
diperbaiki dan diverifikasi dengan test otomatis. 12 dari 13
`NEEDS_VERIFICATION` sudah diverifikasi (runtime dan/atau test) dan naik
status; 1 (Delay Risk & Deteksi Anomali) diverifikasi sebagian - logika
graceful-degradation-nya terbukti aman, tapi model ML sendiri belum pernah
dilatih/dites end-to-end di lingkungan ini. Selain 20 temuan awal, white-box
re-audit (Phase L) menemukan **3 celah otorisasi baru** yang tidak tercatat
di audit pertama - ketiganya diperbaiki dan punya regression test.

Tidak ada `KNOWN_ISSUE` yang tersisa berdampak ke pengguna. Blocker yang
masih ada murni eksternal (App Review Meta/TikTok) dan sudah dipisahkan
jelas dari kesiapan kode aplikasi.

## 2. Semua Perubahan (KI-01 s/d KI-20)

| ID | Temuan | Sebelum | Perbaikan | Test | Status Akhir |
|---|---|---|---|---|---|
| KI-01 | Tambah Konten ke Rencana | `Rule::exists()` tanpa import; validasi `pic_id` vs form `pic_user_id` | Tambah `use Illuminate\Validation\Rule`; validasi key diselaraskan ke `pic_user_id` (nama field form) | `ContentPlanTest` (2 test) | Fixed |
| KI-02 | Jobdesk Tambahan | Sama root cause KI-01, arah kebalikan (form kirim `pic_id`, kode baca `pic_user_id`) | Import `Rule` ditambah; kode baca `$validated['pic_id']` (samakan ke form) | `ContentPlanTest` (2 test) | Fixed |
| KI-03 | Detail Konten | Controller kirim `activeCountsByMember` yang tidak pernah dibuat | Dihapus; view pakai `$candidate->active_task_count` yang sudah dihitung `withCount()` di query yang sama | `ContentItemDetailTest` (2 test) + verifikasi runtime data nyata (lihat §4) | Fixed |
| KI-04 | Ganti Penanggung Jawab | Validasi `pic_user_id` tapi baca `$validated['user_id']`; pakai `$user` yang tidak pernah didefinisikan | Baca `$validated['pic_user_id']`; ganti `$user`→`$newPic`; tambah guard PIC baru harus ter-assign ke client; tambah notifikasi | `ContentItemDetailTest` (3 test) | Fixed |
| KI-05 | Assign Klien (Kelola Pengguna) | `$user->isClientUser()` — method tidak ada di manapun | Dihapus (Client bukan User sama sekali, guard ini mustahil true) | `UserManagementTest` (2 test) | Fixed |
| KI-06 | Undang User / akses login | `login_enabled` tidak ada di `#[Fillable]` User — nilai `true` dibuang diam-diam | Ditambahkan ke `#[Fillable]`; ditambah tombol UI (desktop+mobile) untuk CEO/Manager mengaktifkan/mencabut akses login staf existing (`toggleLoginAccess()`) | `UserManagementTest` (3 test) | Fixed |
| KI-07 | AI Brief — tanggal 2024 | Prompt minta "asumsikan besok" tanpa tahu tanggal hari ini; hasil disimpan tanpa validasi | Prompt sekarang eksplisit kasih tanggal hari ini + deadline; backend `sanitizeDates()` validasi & fallback deterministik (bukan cuma andalkan prompt) sebelum disimpan DAN sebelum assessFeasibility() jalan | `BriefGenerationDateTest` (3 test, Gemini di-fake) | Fixed |
| KI-08 | Integrasi Instagram & TikTok | Kode lengkap tapi belum pernah dipakai (`api_integrations`=0) | Tidak ada kode yang diubah (audit tidak menemukan defect) — diverifikasi seluruh jalur yang bisa dijangkau tanpa consent manusia nyata | `SocialIntegrationOAuthTest` (10 test, HTTP di-fake) | Verified sejauh mungkin — lihat §5 External Blockers |
| KI-09 | Import CSV Performa | `importPerformance()`/`importPage()` tidak cek client scope | Tambah guard `AssignedClient`-equivalent (pola sama `syncInstagram()`) di kedua method | `ImportPerformanceScopeTest` (3 test) | Fixed |
| KI-10 | Dashboard | Nol scoping — SMO lihat data semua client | Semua query (KPI, trend, attention items, high-risk items, top content, top client, recent items) di-scope lewat helper `$scopeClient`/`$scopeViaContentItem` | `DashboardScopeTest` (3 test) | Fixed |
| KI-11 | `ContentWorkflowStatus` enum mati | 2 method badan kosong, return type `string` | Dihapus total (dikonfirmasi nol referensi di `app/`) | Konfirmasi grep, tidak perlu test (dead code) | Fixed |
| KI-12 | Revision Log & Publishing Tracker legacy | Duplikat tab Produksi, tanpa pintu masuk UI | `index()` di kedua controller dihapus; route diganti `Route::redirect()` ke tab resmi Produksi; view index lama dihapus (partial yang masih dipakai tab resmi TIDAK dihapus) | `LegacyRouteRedirectTest` (2 test) | Fixed |
| KI-13 | Rencana Konten Ditolak buntu | Tidak ada jalur Ditolak→Draf | Tambah `reopen()` (Ditolak→Draf) + tabel `content_plan_status_logs` (riwayat lengkap tiap transisi, termasuk catatan penolakan yang WAJIB diisi) + UI modal Reject dengan alasan wajib + panel Riwayat Keputusan | `ContentPlanTest` (3 test) | Fixed |
| KI-14 | Queue/scheduler runtime | Tidak ada dokumentasi jalan bareng; scheduler tidak ada di `composer dev` | `composer run dev` sekarang menjalankan scheduler (`schedule:work`) sekaligus web/queue/logs/vite; `docs/RUNTIME.md` ditambah restart-after-deploy, lokasi log, health-check sederhana | Manual verify `composer.json` + audit `routes/console.php` (tidak ada duplikat) | Fixed (dokumentasi + tooling) |
| KI-15 | Konfigurasi pengujian (database dev kena test) | `phpunit.xml` nunjuk `digidaw` (dev) | Database `digidaw_testing` terpisah dibuat; `phpunit.xml` diarahkan ke situ; `tests/TestCase.php` dapat hard safeguard (abort kalau bukan `APP_ENV=testing` + nama DB tidak mengandung "test") | Baseline 26 test di awal sprint → 82 di akhir sprint ini → **148 setelah Final Pre-Merge Verification** | Fixed |
| KI-16 | Cakupan pengujian | Cuma Portal Klien (24 test) | +56 test baru lintas Content Plan, Detail Konten, User Management, AI Brief, Dashboard scope, Import scope, OAuth, Report, Analytics, AI Strategy, Production Workflow scope, Legacy redirect, Phase L leaks | 82 test / 213 assertion di akhir sprint ini; **148 test / 363 assertion** setelah Final Pre-Merge Verification | Fixed |
| KI-17 | Ketidaksesuaian istilah | "Kelola Tim" vs "Kelola Pengguna", "Performa Konten" vs "Performa", dst | Sprint ini: judul halaman & tab browser diselaraskan (5 file). **Final Pre-Merge Verification: sweep menyeluruh ~45 string di 15 file**, termasuk 2 PDF laporan client-facing + pesan validasi | Full test suite tetap hijau + `php artisan view:cache` sukses | **Fixed** (istilah platform-spesifik "Unmatched X" sengaja dibiarkan beda, itu bukan inkonsistensi) |
| KI-18 | Posting langsung ke media sosial | Bukan bug, konfirmasi tidak ada | Konfirmasi ulang: tidak ada wording menyesatkan ditemukan di seluruh `resources/views/` | Grep audit | Confirmed OUT_OF_SCOPE (tetap, sesuai desain) |
| KI-19 | Komentar kode usang | `SettingsController` bilang OAuth "MASIH UI SAJA" padahal sudah fungsional | Komentar diperbarui reflect implementasi aktual | - | Fixed |
| KI-20 | `$picOptions` dead code | Dihitung di `ContentPlanController::index()`, tidak pernah dipakai (view composer global yang dipakai) | Dihapus | Konfirmasi grep composer di `AppServiceProvider` | Fixed |

## 3. Bug Tambahan yang Ditemukan (di luar KI-01...KI-20)

Ditemukan saat white-box re-audit (Phase L) — pola pencarian sistematis untuk
kelas bug yang sama dengan KI-09/KI-10 (client_id dari request body/query
tanpa guard scope) dan KI-01/KI-03 (missing middleware pada route sibling).

| Temuan | Lokasi | Dampak | Perbaikan | Test |
|---|---|---|---|---|
| **AI Strategy History scope leak** | `AnalyticsController::aiStrategyHistory()` | Role ter-scope bisa baca riwayat AI Strategy (rekomendasi, data performa) client manapun lewat query string `client_id` | Tambah `new AssignedClient` ke validasi | `PhaseLAuthorizationLeaksTest` |
| **Import Audience CSV scope leak (tulis)** | `AudienceController::importCsv()` | Role ter-scope bisa MENULIS data audiens (follower, demografi) client manapun — bukan cuma baca, ini sisi tulis | Tambah `new AssignedClient` ke validasi | `PhaseLAuthorizationLeaksTest` |
| **Kanban drag-drop scope leak** | route `production-workflow.update-status` | Role ter-scope bisa memindahkan status content item client MANAPUN lewat drag-and-drop kanban (sibling route `content-items.transition` sudah ter-guard, ini yang lolos) | Tambah middleware `client.scope:contentItem` | `ProductionWorkflowScopeTest` |

Ketiganya adalah **authorization/IDOR-class bug**, ditemukan lewat systematic
grep untuk pola "client_id divalidasi tanpa `AssignedClient`/`assertClientAccessible`"
dan "route dengan model client-owned tanpa `client.scope` middleware,
padahal sibling route-nya punya". Direkomendasikan pola pencarian yang sama
diulang berkala (mis. tiap kali menambah route baru dengan client-owned
model).

## 4. Runtime Verification

Dua lapis verifikasi dijalankan:

**A. Automated test suite** (82 test, 213 assertion *pada akhir sprint ini*;
kini **148 test / 363 assertion** setelah Final Pre-Merge Verification) —
Feature test dengan
factory data sintetis, database `digidaw_testing` terisolasi penuh dari
database development.

**B. Read-only runtime check terhadap database development nyata** — dengan
proteksi ketat: `SESSION_DRIVER=array` (bukan `database`) supaya tidak ada
row baru masuk tabel `sessions`, dan **hanya GET request** (tidak ada
POST/PATCH/DELETE), persis pola yang dipakai audit pertama. Row count
`users`/`clients`/`content_items`/`sessions` dicek identik sebelum & sesudah.

Selama sprint ini, database development bertambah datanya secara independen
(dari 3 user/1 client jadi 10 user/5 client/15 rencana konten/85 content
item, timestamp identik `2026-08-26 15:32:58` — indikasi seeder dijalankan
di luar sesi ini). Ini dimanfaatkan untuk verifikasi yang JAUH lebih kuat
daripada waktu audit pertama (yang cuma dapat kondisi kosong):

- **KI-03 (Detail Konten)**: `/content-items/1` diverifikasi dengan
  content item yang client-nya punya staf ter-assign (`user_client_assignments`
  ada row) — persis kondisi yang dulu bikin crash. **200 OK.**
- **14 halaman utama** (`/beranda`, `/dashboard`, `/analytics`,
  `/content-plan` (table+calendar), `/production-workflow`,
  `/team-performance`, `/user-management`, `/client-management`, `/report`,
  `/settings`, `/publishing-tracker`, `/revision-log`, `/search`) — semua
  **200** (dua yang terakhir **302** ke tab Produksi resmi, sesuai KI-12).
- **Role-by-role** (Phase N, real users): Content Creator (tanpa client
  assigned) — Beranda/Rencana Konten/Produksi **200** (kosong, bukan
  crash), Dashboard & Kelola Pengguna **403** (sesuai matrix). SMO —
  Dashboard & Performa **200**, Performa Tim **403** (sesuai matrix).
  Copywriter — Rencana Konten **200**. Semua cocok 1:1 dengan matrix role
  di Bagian 3 `USER_MANUAL_SOURCE_OF_TRUTH.md`.
- **Zero write** dikonfirmasi eksplisit (row count sebelum = sesudah) untuk
  seluruh pengecekan di atas.

**Yang TIDAK dilakukan** (batasan jujur): tidak ada klik manual lewat
browser sungguhan, tidak ada login Google/OAuth Instagram/TikTok nyata
(butuh akun tester yang tidak tersedia di lingkungan ini), tidak ada
"golden path" end-to-end yang benar-benar diklik satu-satu dari awal
sampai akhir oleh manusia. Cakupan Fase M/N Definition-of-Done dipenuhi
lewat kombinasi automated test (yang menguji tiap langkah/transisi secara
terpisah dan terisolasi) + read-only runtime check di atas, BUKAN lewat
satu sesi klik-through manual yang berkesinambungan. Kalau dibutuhkan
verifikasi klik-through manusia yang literal, itu perlu sesi terpisah
dengan browser automation dan kredensial yang belum tersedia sekarang.

## 5. External Blockers

| Blocker | Area | Detail |
|---|---|---|
| **TikTok Developer Portal — App Review/authorization** | Integrasi TikTok | Error `unauthorized_client`/`client_key` di layar consent TikTok Sandbox, terjadi di luar kode aplikasi (portal-side, belum terselesaikan lintas beberapa sesi debugging sebelumnya). Kode OAuth+PKCE terverifikasi benar lewat test (§2, KI-08). |
| **Meta App Review** | Integrasi Instagram | Selama app Instagram (523 Studio Analytics) masih mode Development, cuma akun terdaftar manual sebagai "Instagram Tester" di App Dashboard yang bisa connect. Bukan bug kode. |
| **Tidak ada akun tester nyata di lingkungan ini** | KI-08 verification | Live OAuth end-to-end (consent screen sungguhan → callback sukses) tidak bisa diuji tanpa akun Instagram/TikTok tester yang terdaftar dan sesi browser yang login. Semua yang bisa diuji TANPA consent manusia (redirect terbentuk benar, PKCE, state mismatch, penolakan user, kegagalan token exchange, upsert `ApiIntegration`) SUDAH diuji dan lulus. |

Ketiganya murni eksternal, sudah dipisahkan jelas dari status kesiapan kode
aplikasi (yang `READY`).

## 6. Automated Test

> **Angka di tabel ini adalah hasil stabilization sprint, SEBELUM Final
> Pre-Merge Verification.** Hasil final ada di tabel "Test Result (Final)" di
> appendix bawah: **148 test / 363 assertion**.

| Metrik | Nilai *(akhir sprint stabilisasi)* |
|---|---|
| Total test | 82 |
| Passed | 82 |
| Failed | 0 |
| Skipped | 0 |
| Assertion | 213 |
| Database testing | MySQL terpisah `digidaw_testing` (bukan SQLite — migration awal pakai `DB::statement()` MySQL-native yang tidak kompatibel SQLite, lihat `docs/RUNTIME.md`) |
| Baseline awal sprint | 26 test (`ClientPortalTest` 24 + `ExampleTest` 2) |
| Test baru ditambahkan | 56 (13 file baru) |

Rincian file test baru: `ContentPlanTest` (7), `ContentItemDetailTest` (5),
`UserManagementTest` (5), `BriefGenerationDateTest` (3),
`DashboardScopeTest` (3), `ImportPerformanceScopeTest` (3),
`SocialIntegrationOAuthTest` (10), `LegacyRouteRedirectTest` (2),
`PhaseLAuthorizationLeaksTest` (4), `ProductionWorkflowScopeTest` (2),
`ReportGenerationTest` (3), `AnalyticsPageSmokeTest` (7),
`AiStrategyLifecycleTest` (2).

## 7. Golden Path Result

Tidak dijalankan sebagai satu sesi klik-through manual berkesinambungan
(lihat batasan jujur di §4). Sebagai gantinya, tiap segmen golden path
diverifikasi terpisah:

| Segmen | Metode | Hasil |
|---|---|---|
| Manager membuat client → tentukan paket | Kode tidak diubah, sudah `READY` di audit awal | Tidak diuji ulang (di luar temuan KI) |
| Assign tim ke client | `UserManagementTest` | ✅ Lulus (KI-05 fix) |
| Undang user → user bisa login | `UserManagementTest` | ✅ Lulus (KI-06 fix) |
| Buat Rencana Konten → tambah konten | `ContentPlanTest` | ✅ Lulus (KI-01 fix) |
| Copywriter buat AI Brief (tanggal valid) | `BriefGenerationDateTest` | ✅ Lulus (KI-07 fix) |
| Content Creator kerjakan → isi hasil (Detail Konten) | `ContentItemDetailTest` + runtime real data | ✅ Lulus (KI-03 fix) |
| Menunggu Persetujuan → Client Portal approve/revisi | `ClientPortalTest` (pre-existing, masih lulus) | ✅ Lulus |
| SMO jadwalkan → catat publikasi | Tidak diubah kodenya (sudah `READY` sebelumnya), tidak ada test baru | Tidak diverifikasi ulang |
| Sync analytics → data muncul di Performa | `AnalyticsPageSmokeTest` | ✅ Lulus |
| Buat laporan | `ReportGenerationTest` | ✅ Lulus |
| **Rejection path**: Rencana → Ditolak → diperbaiki → diajukan ulang → Disetujui | `ContentPlanTest::test_rejected_plan_can_be_reopened_fixed_and_resubmitted_to_approved` | ✅ Lulus (KI-13 fix) |
| **Revision path**: Menunggu Persetujuan → Revisi → Dikerjakan → Approved | Tercakup tidak langsung lewat `WorkflowStatusService` (tidak diubah, existing) | Tidak diverifikasi ulang |

## 8. Remaining Risks

1. **Delay Risk model ML belum pernah diverifikasi end-to-end** (prediksi
   akurat/tidaknya) — hanya *graceful degradation*-nya (model file hilang/
   script Python gagal → log + skip, bukan crash) yang dikonfirmasi lewat
   pembacaan kode. Model file & akurasi prediksi sungguhan tidak diuji.
2. **Data demo di database development** (10 user, 5 client, 85 content
   item) muncul independen dari sesi ini pada `2026-08-26 15:32:58` — bukan
   masalah keamanan (read-only verified, tidak ada perubahan), tapi perlu
   dikonfirmasi ke user apakah ini disengaja/dari sumber lain, supaya tidak
   ada kebingungan soal provenance data saat dipakai buat screenshot buku
   panduan nanti.
3. ~~**KI-17 (terminologi) baru diperbaiki untuk 5 contoh paling jelas**~~ —
   **SUDAH SELESAI.** Sweep menyeluruh dilakukan pada Final Pre-Merge
   Verification: ~45 string di 15 file, termasuk 2 PDF laporan client-facing
   dan 1 celah pesan validasi. KI-17 sekarang `FIXED`; tabel terminologi
   resmi ada di `docs/USER_MANUAL_SOURCE_OF_TRUTH.md`.
4. ~~**Golden path & role-by-role** diverifikasi per-segmen~~ — **SUDAH
   DITANGANI.** Final Pre-Merge Verification menambahkan `GoldenPathTest`
   (satu alur berkesinambungan: golden, rejection, dan revision path) serta
   `RoleAccessMatrixTest` (6 role × 10 halaman + client scope crafted
   request). Batasan yang tersisa: verifikasinya lewat automated integration
   test, bukan klik manual di browser — melekat pada desain login
   Google-OAuth-only.
5. **KI-08 (Instagram/TikTok) tidak bisa dipastikan berfungsi ujung-ke-ujung**
   sampai App Review Meta/TikTok selesai dan akun tester tersedia — murni
   eksternal, tapi tetap risiko yang nyata bagi user yang mengikuti buku
   panduan nanti sebelum App Review kelar.

## 9. Documentation Readiness

**`DOCUMENTATION_READY`**

Tidak ada `KNOWN_ISSUE` user-impacting yang tersisa. Tidak ada authorization
leak yang diketahui (3 ditemukan tambahan di Phase L, semuanya diperbaiki).
Tidak ada silent failure pada core workflow yang diketahui. Testing database
aman dan terisolasi permanen (safeguard di `tests/TestCase.php`, bukan cuma
config yang bisa ke-revert diam-diam). Tidak ada dead-end business workflow
yang tidak disengaja (KI-13 diperbaiki).

Penulis buku panduan boleh mulai kerja dari `docs/USER_MANUAL_SOURCE_OF_TRUTH.md`
(pakai kotak **"KONDISI TERKINI"** di bagian atas dokumen itu sebagai acuan,
bukan bagian yang berlabel HISTORIS), dengan catatan: gunakan §5 (External
Blockers) di laporan ini sebagai basis kalimat peringatan integrasi
Instagram/TikTok di buku, dan JANGAN mendokumentasikan KI-08 sebagai "selesai" -
dokumentasikan sebagai konseptual dengan peringatan App Review. Status resmi
KI-08 sekarang: `EXTERNAL_BLOCKED`.

---

# Final Pre-Merge Verification

**Tanggal:** 26 Agustus 2026
**Branch:** `stabilization/pre-user-manual`
**Commit awal sesi ini:** `ea8f94e` (working tree bersih, sudah tracking
`origin/stabilization/pre-user-manual` dari sesi stabilisasi sebelumnya)
**Commit dasar branch:** `d637369`

Sesi ini TIDAK mengulang full stabilization dari awal - fokus pada verifikasi
akhir sebelum `main` merge manual oleh user. Tidak ada merge/push/reset yang
dilakukan.

## Terminology Sweep

Laporan sebelumnya cuma menyelaraskan 5 istilah paling jelas. Sweep kali ini
**menyeluruh** ke seluruh `resources/views/` (sidebar, page title, browser
title, heading, tab, tombol, dropdown, modal, validation message, empty
state, table heading, filter, form label, tooltip, status label, Client
Portal, **dan PDF laporan client-facing**).

- **Issues found:** ~45 string user-facing berbahasa Inggris/campuran di 15
  file (termasuk 2 **dokumen PDF yang dikirim ke client** — `report/pdf.blade.php`
  dan `report/performance-pdf.blade.php` — sebelumnya 100% Bahasa Inggris),
  plus 1 celah validation message (`pic_user_id`/`rejection_note` fallback ke
  label Inggris auto-generate karena belum terdaftar di `lang/id/validation.php`).
- **Issues fixed:** Semua ~45 ditemukan, diperbaiki. Tabel referensi lengkap
  ada di section baru **"Terminologi Resmi untuk Dokumentasi"** di
  `docs/USER_MANUAL_SOURCE_OF_TRUTH.md`.
- **Sengaja TIDAK diubah:** "APPROVE KONTEN" (cocok dengan istilah baku yang
  sudah didokumentasikan Bagian 8 source of truth), istilah data analitik
  (Views/Engagement Rate/Reach/dst — dipertahankan sesuai glosarium Bagian
  25), nama fitur produk (AI Brief, AI Strategy), nama platform (Instagram/
  TikTok/CSV/OAuth).
- **Verifikasi:** `php artisan view:cache` sukses (seluruh blade template
  ter-compile tanpa error) + full test suite tetap hijau setelah sweep.

## Golden Path Verification

**Keterbatasan jujur dinyatakan di muka:** tidak ada verifikasi via browser
sungguhan. Login internal aplikasi ini SATU-SATUNYA lewat Google OAuth (tidak
ada email+password) - browser automation (Chrome tools tersedia di
lingkungan ini) tidak bisa melewati layar consent Google tanpa akun Google
nyata & interaksi manusia, PERSIS blocker eksternal yang sama dengan
Instagram/TikTok OAuth. Tidak ada verifikasi browser yang dipalsukan.

Pendekatan yang dipakai sebagai gantinya: **satu test otomatis
berkesinambungan** (`tests/Feature/GoldenPathTest.php`) yang dispatch lewat
routing/middleware/database Laravel SUNGGUHAN (bukan manipulasi model
langsung, bukan tersegmentasi per file test seperti sprint sebelumnya).
`actingAs()` cuma dipakai untuk melewati layar login internal — setiap
langkah setelahnya (permission check, client scope, transisi status,
notifikasi, generate PDF) berjalan lewat kode aplikasi asli. Portal Klien
diverifikasi TANPA `actingAs()` sama sekali (murni token, sama seperti klien
asli).

**Golden path utama** (Manager buat client → paket → assign PIC → undang
user → akses login aktif → Rencana Konten → tambah item → AI Brief (tanggal
divalidasi, KI-07 terbukti tetap bekerja) → finalisasi → Content Creator
kerjakan → isi link hasil → Menunggu Persetujuan → Portal Klien setuju →
internal Approve → SMO jadwalkan → catat publikasi → Sudah Tayang → import
performa → cek Performa → generate Laporan PDF → cek Riwayat Portal Klien):
**✅ Lulus, 43 assertion.**

**Rejection path** (Draf → Ajukan → Ditolak dengan alasan → buka kembali →
diperbaiki (tambah konten) → ajukan ulang → Disetujui): **✅ Lulus, 21
assertion.** Terverifikasi: riwayat status lengkap 5 entri, alasan penolakan
tidak hilang setelah reopen, TIDAK ada duplicate plan (row ID sama sebelum &
sesudah), notifikasi terkirim 2x (tiap kali diajukan).

**Revision path** (Menunggu Persetujuan → Minta Revisi → Perlu Revisi →
Kerjakan Revisi → Sedang Dikerjakan → Konten Telah Selesai → Menunggu
Persetujuan → Approve): **✅ Lulus, 18 assertion.** Terverifikasi: siapa yang
membuat revisi tercatat benar, status revisi ikut berubah otomatis
(open→in_progress→resolved) sinkron dengan status konten, tidak ada revisi
terbuka tersisa setelah approve, `ContentStatusLog` mencatat aktor yang benar
di tiap transisi.

**Bug ditemukan saat menyusun test ini (lalu dikonfirmasi BUKAN bug):**
`ContentItemController::transition()` (dipakai tombol status sederhana di
Detail Konten) sengaja TIDAK punya field `platform_id`/`published_at`/
`post_url`/`caption_final`/`revision_note` di validasinya — karena transisi
`uploaded` dan `revision` punya form/route/controller KHUSUS sendiri
(`content-publication.store`, `content-revision.store`) dengan validasi
lengkap. Diverifikasi dengan membaca kedua form Blade yang sebenarnya
dipakai user (submit ke route yang benar) - bukan bug, desain yang disengaja.

## Role-by-Role Verification

`tests/Feature/RoleAccessMatrixTest.php` (baru) — 63 test case, memakai
**`PermissionSeeder` produksi sungguhan** (bukan role ad-hoc), menguji 6 role
internal (CEO, Manager, SMO, Copywriter, Content Creator, Desain Grafis) ×
10 halaman utama = 60 kombinasi, plus 3 test client-scope dengan crafted
request. **Semua lulus, 68 assertion.**

| Role | Beranda | Dashboard | Performa | Rencana Konten | Produksi | Performa Tim | Kelola Pengguna | Kelola Klien | Laporan | Pengaturan |
|---|---|---|---|---|---|---|---|---|---|---|
| CEO | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 200 |
| Manager | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 200 |
| SMO | 200 | 200 | 200 | 200 | 200 | 403 | 403 | 403 | 200 | 200 |
| Copywriter | 200 | 403 | 403 | 200 | 200 | 403 | 403 | 403 | 403 | 403 |
| Content Creator | 200 | 403 | 403 | 200 | 200 | 403 | 403 | 403 | 403 | 403 |
| Desain Grafis | 200 | 403 | 403 | 200 | 200 | 403 | 403 | 403 | 403 | 403 |

Semua 403 di atas diverifikasi lewat **direct URL access** (bukan cuma
"tidak ada di sidebar") - membuktikan backend benar-benar jadi enforcement
utama, bukan cuma UI yang menyembunyikan tombol.

**Client scope dengan crafted request:** staf ter-scope ke Client A (1)
BISA lihat Client A lewat URL langsung, (2) mendapat 403 saat coba lihat
Client B, (3) mendapat 403 saat coba PATCH status content item Client B
langsung (status Client B terbukti TIDAK berubah), (4) mendapat 403 saat
coba lihat Content Plan Client B. Portal Klien sudah tercakup lewat
`ClientPortalTest` (24 test pre-existing, masih lulus) + langkah Portal
Klien di `GoldenPathTest`.

## Authorization Re-Audit

Systematic grep ulang untuk pola "`client_id` divalidasi dari request body
TANPA `AssignedClient`/`assertClientAccessible`" di SELURUH
`app/Http/Controllers/*.php` (bukan cuma file yang disentuh sprint
sebelumnya). **Tidak ada temuan baru** - 2 kandidat yang sempat dicurigai
(`SettingsController::syncInstagram()`/`syncTiktok()`) ternyata sudah
punya guard `abort_unless(canSeeAllClients() || assignedClients()->...)`
inline sejak sebelum sprint ini, cuma polanya beda dari `AssignedClient`
rule (tiga pola client-scope-guard berbeda kini hidup berdampingan di
codebase: inline `abort_unless`, `AssignedClient` rule, dan
`Controller::assertClientAccessible()` helper - semuanya benar secara
fungsional, TIDAK dikonsolidasi karena itu akan jadi refactor tidak perlu
terhadap kode yang sudah teruji, di luar cakupan sesi verifikasi ini).

Ketiga IDOR dari Phase L (AI Strategy History, Import Audience CSV, kanban
drag-drop Produksi) diverifikasi ULANG lewat `RoleAccessMatrixTest` dengan
crafted request - tetap `Fixed`, tidak regresi.

## Data Demo Provenance

**Klasifikasi: `KNOWN_SOURCE`.** `database/seeders/DemoSeeder.php` (sudah
ada sejak commit dasar `d637369`, bukan ditambahkan sesi manapun dalam
percakapan ini) match persis dengan seluruh 10 user, 5 client, dan pola
timestamp identik (`2026-08-26 15:32:58`) yang ditemukan di database
development. Data ini kemungkinan besar dijalankan (`php artisan db:seed
--class=DemoSeeder`) oleh user di terminal terpisah selama sprint
sebelumnya. Detail lengkap + rekomendasi pemakaian untuk screenshot ada di
`docs/USER_MANUAL_SOURCE_OF_TRUTH.md` (section "Checklist Keamanan Data
Dokumentasi").

## Test Result (Final)

| Metrik | Nilai |
|---|---|
| Total test | 148 |
| Passed | 148 |
| Failed | 0 |
| Skipped | 0 |
| Assertion | 363 |
| Test baru sesi ini | 63 (`RoleAccessMatrixTest`) + 3 (`GoldenPathTest`) |
| Database testing | `digidaw_testing` (diverifikasi eksplisit: `APP_ENV=testing`, `DB database: digidaw_testing`, mengandung "test") |

## External Blockers (dikonfirmasi ulang, TIDAK ada usaha "perbaiki")

- **Instagram** — seluruh code path yang bisa diuji tanpa consent manusia
  (redirect, state, callback validation, token exchange fake, persistence)
  tetap lulus (`SocialIntegrationOAuthTest`, tidak diubah, masih hijau).
  Live consent tetap bergantung Meta App Review. Status: `EXTERNAL_BLOCKED`.
- **TikTok** — code path OAuth+PKCE tetap lulus. TikTok Developer Portal
  masih berpotensi `unauthorized_client` (masalah provider/registrasi app,
  bukan kode) berdasarkan histori debugging sesi-sesi sebelumnya. Status
  TETAP `EXTERNAL_BLOCKED` — TIDAK diubah jadi "READY end-to-end".
- **Delay Risk** — *graceful degradation* dikonfirmasi ulang tidak berubah
  (model/script gagal → log+skip, bukan crash). Akurasi model TIDAK dinilai
  ulang sesi ini (di luar cakupan). Status: **"Operationally safe, model
  accuracy not validated in this sprint."**

## Remaining Risks (setelah Final Pre-Merge Verification)

1. Nama client demo (`DemoSeeder.php`) disebut "mendekati portofolio riil
   523 Studio" oleh komentar aslinya — perlu konfirmasi eksplisit dari user
   apakah nama-nama ini aman dipublikasikan di buku sebelum screenshot final.
2. Tiga pola client-scope-guard berbeda hidup berdampingan (lihat
   Authorization Re-Audit di atas) — bukan bug, tapi konsolidasi ke satu
   pola disarankan sebagai technical debt cleanup terpisah, di luar
   cakupan merge ini.
3. Golden path/role verification dijalankan lewat automated integration
   test (routing+middleware+database sungguhan), BUKAN klik manual di
   browser — batasan ini melekat pada desain login Google-OAuth-only
   aplikasi, bukan sesuatu yang bisa diperbaiki dari sisi testing.

---

*Sprint stabilisasi awal + Final Pre-Merge Verification, keduanya dijalankan
di branch `stabilization/pre-user-manual`, dari commit dasar `d637369`.
Tidak ada push ke remote, tidak ada merge ke `main`, tidak ada reset/rebase
branch manapun. Semua perubahan tersedia untuk direview oleh user sebelum
merge manual.*
