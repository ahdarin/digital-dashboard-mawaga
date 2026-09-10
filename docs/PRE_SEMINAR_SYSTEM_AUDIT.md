# Audit Sistem Pra-Seminar — 523 Studio Platform

**Tanggal audit:** 10 September 2026, Asia/Jakarta.
**Baseline commit:** `2ffc3b9` (HEAD), tidak ada perubahan kode sejak baseline RTM `b4165ef` — hanya commit dokumentasi (`2ffc3b9`, "new prd and srs documentations"). Artinya seluruh temuan [REQUIREMENT_TRACEABILITY_MATRIX.md](REQUIREMENT_TRACEABILITY_MATRIX.md) dan [PRD_SRS_RECONCILIATION_REPORT.md](PRD_SRS_RECONCILIATION_REPORT.md) masih berlaku persis terhadap kode saat ini.
**Tujuan:** menyiapkan mahasiswa untuk demonstrasi seminar kerja praktik — bukan re-audit dari nol. Dokumen ini **menyintesis** temuan dari dokumentasi yang sudah ada ([RTM](REQUIREMENT_TRACEABILITY_MATRIX.md), [PRD](PRD_523_STUDIO_FINAL.md), [Reconciliation Report](PRD_SRS_RECONCILIATION_REPORT.md), [Post-Freeze Audit](POST_FREEZE_AUDIT_REPORT.md)) dan **menambah** verifikasi langsung baru pada empat aspek yang diminta: proses, keamanan, tampilan/responsivitas, dan kebutuhan fungsional/non-fungsional — lalu menutup dengan daftar tindak lanjut prioritas sebelum hari-H.

Dokumen ini **tidak** mengubah kode aplikasi. Temuan baru pada §4 adalah verifikasi langsung sesi ini (grep, pembacaan kode, percobaan test suite), terpisah dari yang sudah tercatat di dokumen lama.

---

## 1. Ringkasan Eksekutif

Sistem berada pada kondisi **matang untuk demonstrasi**: 19 modul produk terimplementasi (74 kebutuhan dipetakan RTM — 51 `IMPLEMENTED`, 12 `IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`, 11 `NEEDS_VERIFICATION`), RBAC teruji lewat matriks 6 role × 10 halaman, dan tiga celah IDOR yang pernah ditemukan sudah diperbaiki dengan regression test. Tidak ada `KNOWN_ISSUE` yang tersisa berdampak ke pengguna per [POST_FREEZE_AUDIT_REPORT.md](POST_FREEZE_AUDIT_REPORT.md).

Yang perlu diperhatikan **sebelum seminar**, bukan karena sistem rusak, tapi karena berdampak langsung ke kualitas demonstrasi:

| # | Area | Isu | Dampak ke seminar |
|---|---|---|---|
| 1 | Data demo | Database kerja (`digidaw`) berisi data klien & staf 523 Studio **asli** | Tidak boleh dipakai untuk demo di depan dosen — lihat §7 (seeder baru) |
| 2 | Keamanan operasional | Tidak ada rate limiting (`throttle`) di route manapun, termasuk login redirect dan pencarian | Bukan celah eksploitasi langsung (lihat §4.2), tapi layak disebut sebagai catatan hardening kalau ditanya dosen |
| 3 | Konfigurasi | `.env.example` default `APP_DEBUG=true`, `SESSION_SECURE_COOKIE` kosong | **Wajib** dipastikan `APP_DEBUG=false` dan cookie secure aktif pada environment yang dipakai demo publik (stack trace mentah = kebocoran informasi) |
| 4 | NFR belum terverifikasi | 4 NFR (`NFR-005` availability, `NFR-006` performance, `NFR-011` maintainability-test, `NFR-013` scalability) dan 3 FR (`RISK-001/002`, `ATT-001`) tidak punya test otomatis — status `NEEDS_VERIFICATION` | Siapkan jawaban jujur kalau dosen tanya "sudah diuji?" — lihat §6 |
| 5 | Delay Risk | Akurasi model ML belum divalidasi end-to-end (baru *operationally safe*: gagal → log+skip, bukan crash) | Jangan mengklaim akurasi prediksi di depan panel; sampaikan sebagai batasan yang disadari |

Tidak ditemukan bug baru pada sesi ini. Seluruh temuan struktural (IDOR, guard hapus, dsb.) sudah tercatat **fixed** di dokumen sebelumnya dan diverifikasi ulang di sini secara langsung terhadap kode (§4).

---

## 2. Metodologi

| Sumber | Cara verifikasi |
|---|---|
| Dokumentasi existing | Dibaca penuh: PRD, RTM (74 baris kebutuhan + inventaris rute), Reconciliation Report (112 baris rekonsiliasi + Q-01–Q-12), Post-Freeze Audit, Documentation Dataset, KPI formula, Runtime setup, TikTok integration doc |
| Kode aplikasi | Dibaca langsung: seluruh middleware (`EnsurePermission`, `EnsureClientScope`, `ResolveClientPortal`), `bootstrap/app.php` (CSRF/proxy config), `config/session.php`, token generation (`Client::generateUniquePortalToken`), file upload validation, pemanggilan proses eksternal (`DelayRiskPredictionService`) |
| Git history | `git log`, `git diff --stat` terhadap baseline RTM untuk memastikan tidak ada drift kode vs dokumentasi |
| Test suite | `php artisan test` dijalankan ulang sesi ini untuk baseline segar (lihat §6) |
| Tampilan/responsivitas | Dibaca `resources/views/layouts/app.blade.php`, `layouts/client.blade.php`, `components/sidebar.blade.php`; dicek breakpoint Tailwind dan state Alpine (`sidebarOpen`) |
| Seeder | Seluruh 10 file `database/seeders/*.php` dibaca penuh (lihat §7 dan dokumen [SEEDER_INVENTORY.md](SEEDER_INVENTORY.md) — dibuat terpisah, checkpoint berikutnya) |

Audit ini **read-only** — tidak ada perubahan skema, data, atau kode yang dilakukan selama sesi.

---

## 3. Cakupan Fungsional (19 Modul)

Status detail per modul (route, controller/service, model, permission, test) sudah lengkap di [REQUIREMENT_TRACEABILITY_MATRIX.md](REQUIREMENT_TRACEABILITY_MATRIX.md) §2 — **tidak diulang di sini**. Ringkasan tingkat modul:

| Modul PRD | Kebutuhan RTM terkait | Status agregat |
|---|---|---|
| 7.1 Authentication & Beranda | AUTH-001..003, CNT-003 | Implemented; login bergantung Google OAuth live (`EXTERNAL_DEPENDENCY`) |
| 7.2 Client & Package | CLI-001..003 | Implemented |
| 7.3 User & Team Management | USR-001..003 | Implemented |
| 7.4 Content Planning | PLAN-001..005 | Implemented |
| 7.5 AI Brief | BRF-001..003 | Implemented; generate/assist butuh Gemini API hidup |
| 7.6 Production Workflow | WF-001..003, CNT-002 | Implemented |
| 7.7 Revision & Approval | APR-001, REV-001 | Implemented |
| 7.8 Client Portal | PORTAL-001..003 | Implemented |
| 7.9 Publication | PUB-001..002 | Implemented; hak lintas jalur (form vs Kanban) masih `NEEDS_CLARIFICATION` internal (Q-02) |
| 7.10 Content Analytics | ANL-001..006 | Implemented; sync butuh queue worker aktif (lihat [RUNTIME.md](RUNTIME.md)) |
| 7.11 Audience Analytics | AUD-001..002 | Implemented; TikTok tidak menyediakan demografi (batasan provider, bukan bug) |
| 7.12 AI Strategy | AISTRAT-001..003 | Implemented; dua jalur Apply (per-idea vs bulk legacy) masih hidup berdampingan — Q-03 |
| 7.13 Delay Risk | RISK-001..002 | Implemented tapi **`NEEDS_VERIFICATION`** — tidak ada test otomatis, akurasi model belum divalidasi |
| 7.14 Team Performance & KPI | KPI-001..004, TEAM-001 | Implemented, formula terdokumentasi lengkap di [KPI_TEAM_PERFORMANCE.md](KPI_TEAM_PERFORMANCE.md) |
| 7.15 Attendance | ATT-001..002 | ATT-001 (check-in/out) **tanpa test otomatis** |
| 7.16 Reports | REP-001..002 | Implemented; privasi file laporan masih terbuka (Q-09, lihat §4.2) |
| 7.17 Notification & Search | NOTIF-001, SEARCH-001 | Implemented; cakupan lintas-roster sebagian belum dibatasi kebijakan jelas (Q-07) |
| 7.18 Settings & Master Data | SET-001..002 | Implemented |
| 7.19 Social Media Integration | INT-001..004 | Kode lengkap & teruji sejauh mungkin tanpa consent manusia nyata; live OAuth **`EXTERNAL_BLOCKED`** (App Review Meta/TikTok) |

**Kesimpulan cakupan:** tidak ada modul yang "kosong kodenya" — seluruh gap yang tersisa bersifat verifikasi (butuh test/benchmark) atau eksternal (butuh App Review/akun tester), bukan fitur yang belum dibangun.

---

## 4. Keamanan

### 4.1 Yang sudah solid (diverifikasi ulang langsung terhadap kode sesi ini)

- **RBAC bertingkat tiga lapis** — `EnsurePermission` (modul×aksi), `EnsureClientScope` (kepemilikan client, IDOR guard), `ResolveClientPortal` (token capability-URL untuk klien). Dikonfirmasi lewat `RoleAccessMatrixTest` (63 kombinasi role×halaman) dan `CrossClientIdorTest`.
- **Portal token klien**: `bin2hex(random_bytes(32))` = 256-bit entropy, dicek collision eksplisit sebelum dipakai (`Client::generateUniquePortalToken()`). Token tidak valid dan token yang di-disable sengaja dikembalikan **404, bukan 403** — mencegah kebocoran informasi "token ini pernah ada". Praktik yang baik.
- **CSRF**: aktif secara default (`validateCsrfTokens`), dikecualikan hanya untuk `webhooks/instagram` dengan alasan eksplisit dan valid (server-to-server, diverifikasi lewat `hub.verify_token`, bukan CSRF token Laravel).
- **Tidak ada command injection**: satu-satunya pemanggilan proses eksternal (`DelayRiskPredictionService` → Python) memakai `Process::input($payload)->run([$pythonBin, $scriptPath])` — array argumen, bukan interpolasi string ke shell.
- **Enkripsi token API**: `access_token`/`refresh_token` Instagram & TikTok pakai Eloquent cast `encrypted` dan masuk `$hidden` di model `ApiIntegration` — tidak pernah ter-serialize ke JSON/log secara tidak sengaja (dikonfirmasi di [TIKTOK_INTEGRATION.md](TIKTOK_INTEGRATION.md) §11 dan RTM).
- **Upload file**: validasi `image|max:2048` pada logo klien, disimpan lewat `Storage::disk('public')->store()` (nama file random, tidak ada path traversal).
- **Isolasi database testing**: `phpunit.xml` menunjuk `digidaw_testing` terpisah dari `digidaw` (dev), dengan hard safeguard di `tests/TestCase.php` yang abort kalau environment/nama database salah — mencegah `RefreshDatabase` menghapus data development secara tidak sengaja.

### 4.2 Temuan baru sesi ini (belum tercatat di dokumen sebelumnya)

| Temuan | Detail | Tingkat | Rekomendasi |
|---|---|---|---|
| **Tidak ada rate limiting di seluruh aplikasi** | Grep menyeluruh `throttle` di `app/`, `bootstrap/`, `config/`, `routes/web.php` — nihil middleware `throttle` terpasang di route manapun (login, search, portal, webhook). `bootstrap/app.php` tidak mendaftarkan default throttle. | Rendah–Sedang | Bukan eksploitasi langsung (portal token 256-bit praktis tidak bisa di-brute-force, login lewat Google OAuth jadi lapisan pertahanan ada di Google). Tapi search/dashboard/report generation tanpa limit bisa jadi vektor resource-exhaustion kalau credential internal bocor. Rekomendasi: tambah `throttle:60,1` minimal di route publik (`auth.google`, `webhooks.instagram`) sebagai defense-in-depth, bukan blocker seminar. |
| **File laporan (Report) memakai public disk, URL tidak signed** | `ReportController` menulis PDF/Excel ke `Storage::disk('public')` dan `download()` langsung — konsisten dengan Q-09 di [Reconciliation Report](PRD_SRS_RECONCILIATION_REPORT.md). "Riwayat hanya milik pembuat" hanya berlaku di level query UI, bukan di level URL file. | Sedang | Sudah tercatat sebagai keputusan terbuka (Q-09), bukan regresi baru. Untuk demo tidak berisiko (data fiktif), tapi kalau pindah ke penggunaan produksi nyata perlu keputusan produk (signed URL / private disk) sebelum laporan berisi data sensitif klien. |
| **`.env.example` default `APP_DEBUG=true`** | Environment demo/seminar **wajib** memverifikasi `APP_DEBUG=false` di `.env` yang benar-benar dipakai — kalau lupa, error apapun saat demo langsung menampilkan stack trace lengkap (path server, query SQL) ke layar proyektor. | Tinggi (operasional, bukan kode) | Cek eksplisit sebelum hari-H: `php artisan tinker --execute="echo config('app.debug') ? 'DEBUG ON — BAHAYA' : 'aman';"` |
| **`SESSION_SECURE_COOKIE` kosong by default** | `config/session.php` membaca `env('SESSION_SECURE_COOKIE')` tanpa default eksplisit (null → Laravel treat sebagai false kalau APP_ENV bukan production-aware check manual). Kalau demo di-hosting HTTPS (Railway), cookie tanpa flag `Secure` masih berfungsi tapi tidak best-practice. | Rendah | Set eksplisit `SESSION_SECURE_COOKIE=true` di `.env` production/demo yang sudah HTTPS. |
| **Tiga pola client-scope-guard berbeda hidup berdampingan** | Dikonfirmasi ulang: inline `abort_unless`, `AssignedClient` validation rule, dan `Controller::assertClientAccessible()` helper — ketiganya fungsional benar (diverifikasi Phase L re-audit), tapi bukan satu pola konsisten. | Rendah (technical debt) | Bukan blocker; sudah dicatat di [PRE_DOCUMENTATION_STABILIZATION_REPORT.md](PRE_DOCUMENTATION_STABILIZATION_REPORT.md) sebagai konsolidasi disarankan di luar cakupan merge sebelumnya. |

### 4.3 Batasan yang harus disampaikan jujur ke dosen (bukan bug, tapi harus dinarasikan benar)

- **Golden path tidak pernah diverifikasi lewat klik browser manusia sungguhan** — desain login Google-OAuth-only membuat browser automation tidak bisa melewati consent screen tanpa akun Google nyata. Verifikasi dilakukan lewat `GoldenPathTest` (integration test lewat routing+middleware+database sungguhan, bukan mock model). Ini keterbatasan desain yang sudah dinyatakan eksplisit, bukan disembunyikan.
- **Instagram & TikTok live OAuth**: `EXTERNAL_BLOCKED` — App Review Meta/TikTok belum selesai. Kode OAuth+PKCE lengkap dan teruji untuk semua jalur yang bisa diuji tanpa consent manusia nyata.
- **Delay Risk**: *"Operationally safe, model accuracy not validated in this sprint."* Jangan mempresentasikan angka precision Delay Risk sebagai hasil final — itu hasil dari dataset seeder/dokumentasi, bukan validasi produksi.

---

## 5. Tampilan & Responsivitas

**Stack**: Tailwind CSS v4 + Alpine.js (Vite build), bukan SPA framework (Blade server-rendered).

Diverifikasi langsung:

- `resources/views/layouts/app.blade.php` memakai state Alpine `x-data="{ sidebarOpen: false }"` dengan breakpoint eksplisit `@media (min-width: 1024px)` — sidebar collapse di bawah 1024px (perilaku umum mobile/tablet-first untuk dashboard internal).
- `layouts/client.blade.php` (Portal Klien) dan `components/sidebar.blade.php` memakai kelas responsif Tailwind (`sm:`/`md:`/`lg:`) pada beberapa titik — portal klien kemungkinan besar sudah dioptimasi untuk device klien yang beragam (tidak semua klien akses dari desktop kantor).
- `prefers-reduced-motion: reduce` dihormati secara eksplisit di CSS — sinyal aksesibilitas dasar yang jarang ditemukan di proyek KP, layak disebut positif saat presentasi.

**Yang belum terverifikasi (bukan berarti rusak, tapi belum ada bukti otomatis):**

Per RTM, `NFR-009` (Accessibility/responsiveness) berstatus `NEEDS_VERIFICATION` — test yang ada (`ClientPortalTest`, `AnalyticsPageSmokeTest`) memverifikasi halaman **merespons 200 dan memuat konten**, bukan **layout benar di breakpoint tertentu**. Tidak ada uji perangkat nyata, keyboard-only navigation, atau screen reader yang tercatat.

**Rekomendasi sebelum seminar:** lakukan **QA manual cepat** (30–60 menit) di dua breakpoint — mobile (≤430px, mis. DevTools iPhone SE) dan tablet (~768px) — untuk 5–6 halaman yang paling mungkin didemokan: Beranda, Dashboard, Rencana Konten, Produksi (Kanban — kolom banyak, rawan overflow horizontal di layar kecil), Performa Tim, dan Portal Klien. Kanban khususnya layak dicek karena tabel/papan multi-kolom adalah pola yang paling sering pecah di layar sempit.

---

## 6. Kebutuhan Non-Fungsional & Test Suite

### 6.1 Ringkasan status NFR (14 total, per RTM §3)

| Status | Jumlah | Contoh |
|---|---|---|
| `IMPLEMENTED` | 5 | NFR-002 Authorization, NFR-007 Data Integrity, NFR-010 Auditability, NFR-011 Maintainability |
| `IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY` | 2 | NFR-001 Security (perlu TLS/config produksi tervalidasi), NFR-004 Reliability (perlu worker/scheduler live) |
| `NEEDS_VERIFICATION` | 7 | NFR-003 Privacy, NFR-005 Availability, NFR-006 Performance, NFR-008 Usability, NFR-009 Responsiveness, NFR-012 Recoverability, NFR-013 Scalability |

Tidak ada target angka yang disahkan (waktu respons, uptime, kapasitas) — PRD §11 eksplisit menyatakan ini sebagai keputusan terbuka, bukan kelalaian implementasi.

### 6.2 Test suite

RTM mencatat dua hasil run berbeda tergantung konfigurasi queue: sync (740/745 lulus, 2 failure + 3 error) vs database queue (744/745 lulus, 1 failure) — **bukan hasil yang bisa digabung sebagai "745 lulus"**, dicatat sebagai Q-12 (isolasi queue/HTTP per test perlu diperbaiki, di luar cakupan audit dokumentasi).

**Baseline segar sesi ini** (`php artisan test`, sequential, konfigurasi default `.env`/`phpunit.xml` — tanpa override queue manual): **745 test / 745 lulus / 2.304 assertion / 0 gagal**, durasi ±170 detik. Ini angka terbaik dari dua varian yang pernah dicatat RTM (740/745 sync vs 744/745 database queue) — kemungkinan Q-12 (isolasi queue/HTTP per test) sudah tidak terpicu pada kondisi lingkungan saat ini, tapi ini **bukan bukti Q-12 sudah tertutup permanen** karena akar masalahnya (isolasi antar test) bisa saja flaky tergantung urutan/timing eksekusi. Jangan diklaim sebagai "sudah diperbaiki" — cukup dicatat sebagai hasil run hijau penuh pada 10 September 2026.

**Rekomendasi sebelum seminar:** jalankan `php artisan test` sekali lagi H-1 di mesin yang akan dipakai demo, untuk memastikan tidak ada regresi lingkungan (PHP version, extension, koneksi Python untuk Delay Risk).

### 6.3 Kebutuhan tanpa test otomatis sama sekali

Per RTM §5: **7 ID** tidak punya file test khusus — `RISK-001`, `RISK-002`, `ATT-001` (FR), dan `NFR-005`, `NFR-006`, `NFR-011` (frasa "maintainability" bagian test-nya), `NFR-013` (NFR). Ini bukan celah coverage instrumentation, murni tidak ada test yang menulis assertion untuk area itu. Kalau dosen bertanya "bagaimana Anda tahu absensi/delay risk benar", jawaban jujurnya: **diverifikasi lewat inspeksi kode manual dan smoke test read-only**, bukan automated test — konsisten dengan yang sudah dicatat di RTM, bukan sesuatu yang perlu ditutupi.

---

## 7. Proses & Alur Kerja — Keputusan Terbuka yang Relevan untuk Demo

[PRD_SRS_RECONCILIATION_REPORT.md](PRD_SRS_RECONCILIATION_REPORT.md) §"Register keputusan terbuka" mencatat 12 pertanyaan produk (Q-01–Q-12) yang **sengaja tidak diperbaiki** karena butuh keputusan pemilik produk, bukan bug. Yang paling relevan untuk disiapkan jawabannya saat demo (karena kemungkinan besar akan terlihat/tertanya):

- **Q-01 — Batas Admin**: Admin hanya `view` di seluruh modul bisnis, tapi tetap bisa membuat laporan, absensi, pin, dan preferensi pribadi. Ini disengaja (perbedaan "mutasi data bisnis" vs "aksi pribadi"), bukan kebocoran RBAC — siapkan penjelasan ini karena "Admin kok masih bisa klik sesuatu" adalah pertanyaan wajar dari penguji.
- **Q-02 — Hak publikasi**: form publikasi resmi dibatasi CEO/SMO, tapi jalur Kanban (`workflow:update`) menerima payload publikasi dari role lebih luas (Manager, Content Creator, Graphic Designer). Perilaku nyata, bukan bug — kalau didemokan dari dua role berbeda, konsisten dengan ini.
- **Q-08 — KPI lintas platform**: bonus performa hanya terisi dari data sinkronisasi API (Instagram/TikTok), tidak pernah dari CSV/manual. Lihat §"Batasan yang disengaja" di [DOCUMENTATION_DATASET.md](DOCUMENTATION_DATASET.md) — **poin ini krusial untuk seeder baru** (lihat checkpoint berikutnya): kalau seeder demo hanya memakai data manual/CSV-style, kolom Bonus Performa akan tampil `-` untuk semua orang, dan itu **perilaku benar**, bukan bug seeder.

Tidak ada tindakan yang direkomendasikan pada bagian ini selain kesiapan narasi — sesuai instruksi awal, audit ini tidak mengubah kode.

---

## 8. Daftar Tindak Lanjut Prioritas (Sebelum Seminar)

| Prioritas | Tindakan | PIC yang disarankan |
|---|---|---|
| **Wajib** | Pastikan environment demo memakai `APP_DEBUG=false` dan **bukan** database `digidaw` (data asli) — pakai database terpisah + seeder baru (checkpoint berikutnya) | Mahasiswa |
| **Wajib** | Jalankan `php artisan test` H-1 di mesin demo, konfirmasi hijau | Mahasiswa |
| **Disarankan** | QA manual responsif 5–6 halaman kunci di viewport mobile/tablet (§5) | Mahasiswa |
| **Disarankan** | Siapkan jawaban singkat untuk Q-01, Q-02, Q-08 kalau ditanya dosen (§7) | Mahasiswa |
| **Opsional** | Set `SESSION_SECURE_COOKIE=true` eksplisit di `.env` demo (kalau HTTPS) | Mahasiswa |
| **Di luar cakupan seminar** | Tambah rate limiting, konsolidasi 3 pola client-scope-guard, validasi akurasi Delay Risk end-to-end | Technical debt jangka panjang |

---

## 9. Checkpoint Selanjutnya

Sesuai kesepakatan kerja bertahap, dokumen ini menutup **checkpoint 1 dari 4**:

1. ✅ **Audit sistem** (dokumen ini)
2. ⏭️ Analisis seluruh seeder lama — fungsi tiap seeder & rekomendasi keep/retire
3. ⏭️ Seeder demo baru bertahap (backdated) — KPI/Delay Risk dihitung sungguhan oleh sistem
4. ⏭️ Skenario pengujian lengkap per modul untuk pengujian akhir

Lanjut ke checkpoint 2 setelah dokumen ini dikonfirmasi.
