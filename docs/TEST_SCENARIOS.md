# Skenario Pengujian Akhir — 523 Studio Platform

**Tanggal disusun:** 10 September 2026. **Checkpoint 4 dari 4** (lihat [PRE_SEMINAR_SYSTEM_AUDIT.md](PRE_SEMINAR_SYSTEM_AUDIT.md) §9 dan [SEEDER_INVENTORY.md](SEEDER_INVENTORY.md) §5).

**Sumber:** Setiap skenario di dokumen ini diturunkan langsung dari acceptance criteria (Given/When/Then), alur utama, dan alur alternatif/error yang sudah tercatat di [SRS_523_STUDIO_FINAL.md](SRS_523_STUDIO_FINAL.md) §4 (63 kebutuhan fungsional), §5 (5 state machine), §6 (matriks otorisasi) dan §9 (14 NFR) — bukan ditulis ulang dari nol. Route/permission per baris dicek-silang ke [REQUIREMENT_TRACEABILITY_MATRIX.md](REQUIREMENT_TRACEABILITY_MATRIX.md) §2 dan §6.

## 1. Cara Menggunakan Dokumen Ini

1. **Siapkan data uji** — jalankan `SeminarDemoSeeder` (lihat §4 [SEEDER_INVENTORY.md](SEEDER_INVENTORY.md)) di database terpisah/testing, BUKAN di `digidaw`. Beberapa skenario di bawah mengacu ke kode konten (`KS-08`, `NA-05`, dst.) yang sudah tersedia otomatis dari seeder itu — cukup dicari dari judulnya di Produksi/Rencana Konten, tidak perlu tahu ID database-nya. Skenario yang tidak menyebut kode tertentu bisa dijalankan dengan data apa pun yang sesuai kondisinya.
2. **Login** — seeder demo hanya mengaktifkan login live untuk **satu** akun (CEO, lewat `SEMINAR_DEMO_LOGIN_EMAIL`). Untuk skenario yang secara eksplisit menyebut role lain (Manager/SMO/Copywriter/Content Creator/Graphic Designer/Admin), Anda perlu salah satu dari dua cara:
   - **Cara cepat (disarankan, khusus sesi pengujian — jangan dipakai untuk seminar):** pakai [alias Gmail](https://support.google.com/mail/answer/12096) (`nama+manager@gmail.com`, `nama+smo@gmail.com`, dst — semua masuk ke inbox yang sama, tapi Google memperlakukannya sebagai alamat login berbeda), lalu ganti email user fiktif terkait di database TESTING lewat `php artisan tinker`: `\App\Models\User::where('name','Nadia Putri')->update(['email' => 'nama+manager@gmail.com'])`. Jangan lakukan ini di database yang dipakai live seminar.
   - **Cara alternatif:** minta rekan/pembimbing meminjamkan akun Google untuk login sebagai role tersebut, atau uji lewat automated test yang sudah ada (lihat kolom "Automated test terkait" tiap skenario) sebagai bukti pendukung.
3. **Isi kolom "Lulus?"** — beri ✅/❌/⚠️ (parsial) tiap baris saat dijalankan, tambahkan catatan di baris itu kalau perlu. Dokumen ini dicetak dari markdown; boleh disalin ke spreadsheet untuk sesi pengujian.
4. **Urutan disarankan:** §2 (per modul, mengikuti urutan menu PRD 7.1–7.19) → §3 (RBAC) → §4 (state machine) → §5 (NFR) → §6 (Golden Path, skenario end-to-end lintas modul sebagai penutup).
5. **Batasan yang perlu diketahui sebelum menguji** (supaya hasil "gagal" tidak disalahartikan): 7 kebutuhan (RISK-001, RISK-002, ATT-001, NFR-005, NFR-006, NFR-011, NFR-013) tidak punya automated test — skenario manual di bawah untuk ID ini adalah satu-satunya lapisan verifikasi. 3 keputusan proses terbuka (Q-01, Q-02, Q-08 — lihat [PRE_SEMINAR_SYSTEM_AUDIT.md](PRE_SEMINAR_SYSTEM_AUDIT.md) §7) bisa terlihat seperti "bug" saat diuji tapi memang perilaku yang disengaja/belum diputuskan — ditandai catatan khusus di baris terkait.

---

## 2. Skenario per Modul

### 7.1 Authentication & Beranda

#### AUTH-001 — Login Google untuk pengguna terdaftar
*Route: `auth.google` (redirect) → callback `GET auth/google/callback` · Evidence: `GoogleAuthController.php` · Automated: `GoogleAuthTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-AUTH-001-01 | Login berhasil (jalur utama) | Akun terdaftar (mis. akun CEO dari `SeminarDemoSeeder`), status `active`/`invited`, `login_enabled=true` | 1. Buka `/login`. 2. Klik tombol login Google. 3. Pilih akun Google yang emailnya cocok user terdaftar. | Sesi internal terbentuk, diarahkan ke `/beranda`. Kalau akun tadinya `invited`, status berubah jadi `active`, `google_id` terisi. | ☐ |
| TC-AUTH-001-02 | Email tidak terdaftar ditolak | Akun Google yang dipakai TIDAK ada di tabel `users` | 1. Buka `/auth/google`. 2. Login pakai akun Google yang emailnya belum pernah didaftarkan. | Ditolak, kembali ke `/login` dengan pesan "Akun tidak ditemukan. Hubungi Admin untuk didaftarkan." Jumlah baris `users` **tidak bertambah** (pastikan lewat Kelola Pengguna — tidak ada auto-registrasi). | ☐ |

#### AUTH-002 — Sesi, logout dan pembatasan akses
*Route: `logout` (POST), `profile.me` · Evidence: `EnsureInternalUser`, `EnsurePermission`, `EnsureClientScope` · Automated: `GoogleAuthTest.php`, `RoleAccessMatrixTest.php`, `CrossClientIdorTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-AUTH-002-01 | Logout menghentikan sesi | Sudah login | 1. Klik menu profil → Logout. 2. Coba akses `/beranda` langsung lewat address bar tanpa login ulang. | Diarahkan ke `/login`; sesi lama tidak berlaku, token CSRF baru dipakai. | ☐ |
| TC-AUTH-002-02 | Staf roster-scoped tidak bisa buka detail klien lain | Login sebagai role roster-scoped (SMO/Copywriter/Content Creator/Graphic Designer) yang hanya di-assign ke Client A | 1. Login. 2. Coba buka `/client-management/{id-client-B}` langsung lewat URL (klien yang BUKAN roster-nya). | Akses ditolak (403/redirect), bukan menampilkan data Client B. | ☐ |

#### AUTH-003 — Beranda pribadi dan Dashboard operasional
*Route: `profile.me`, `dashboard` · Evidence: `HomeController.php`, `DashboardController.php` · Automated: `DashboardScopeTest.php`, `GlobalCrossPageConsistencyTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-AUTH-003-01 | Beranda menampilkan pekerjaan pribadi | Login sebagai user manapun yang punya item ditugaskan (mis. `creator` di data seeder) | 1. Buka `/beranda`. | Tampil ringkasan pekerjaan pribadi, tindak lanjut, dan status kehadiran hari ini — bukan data global. | ☐ |
| TC-AUTH-003-02 | Dashboard hanya untuk role dengan `dashboard:view` | Login sebagai Content Creator/Graphic Designer/Copywriter (tidak punya `dashboard:view`) | 1. Coba buka `/dashboard` langsung lewat URL. | Ditolak — menu Dashboard memang tidak tampil di sidebar role ini, dan endpoint-nya sendiri juga menolak. | ☐ |

#### CNT-003 — Pin pekerjaan pribadi
*Route: `content-items.pin` (POST), `content-items.pin.unmark` (DELETE) · Evidence: `PinService.php` · Automated: `PostFreezeAuditRegressionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-CNT-003-01 | Sematkan pin pada konten aktif | Ada content item berstatus bukan `uploaded`/`cancelled` dalam scope | 1. Buka detail konten. 2. Klik Sematkan Pin. 3. Buka Beranda. | Konten muncul di daftar "disematkan" pada Beranda. | ☐ |
| TC-CNT-003-02 | Batas 8 pin aktif | Sudah ada 8 pin aktif milik user yang sama (pin 8 konten berbeda dulu) | 1. Coba sematkan pin ke konten ke-9. | Ditolak dengan pesan batas maksimal 8 pin. | ☐ |

### 7.2 Client & Package Management

#### CLI-001 — Data dan cakupan klien
*Route: `client-management.index/show/store/update` · Evidence: `ClientManagementController.php` · Automated: `DashboardScopeTest.php`, `CrossClientIdorTest.php`, `FinalQaEffectivePermissionMatrixTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-CLI-001-01 | Tambah klien baru lengkap | Login CEO/Manager; kategori klien tersedia | 1. Buka Kelola Klien → Tambah Klien. 2. Isi nama + kategori wajib, logo (opsional, ≤2048 KB), asset_link (opsional, ≤255 char). 3. Simpan. | Klien tersimpan dengan `portal_token` otomatis dibuat sistem (bukan diisi manual). | ☐ |
| TC-CLI-001-02 | Staf roster-scoped ditolak buka klien di luar roster | Login SMO/Copywriter/Content Creator/Graphic Designer, hanya roster ke Client A | 1. Buka detail Client B (bukan roster). | Ditolak. | ☐ |

#### CLI-002 — Paket aktif dan riwayat paket
*Route: `client-management.package.update` · Evidence: `ClientManagementController.php`, `ClientPackage.php` · Automated: `ContentPlanTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-CLI-002-01 | Ganti paket klien | Login CEO/Manager; klien punya paket aktif A; template paket B tersedia | 1. Buka detail klien → Ubah Paket → pilih template B. 2. Simpan. | Paket A jadi `ended` (kuota snapshot lamanya tetap tercatat di histori), paket B jadi `active` dengan snapshot nama/kuota barunya sendiri. | ☐ |
| TC-CLI-002-02 | Ubah Package Template tidak mengubah paket klien lama | Klien X sudah punya `ClientPackage` snapshot dari Template T | 1. Di Pengaturan, ubah kuota Template T. 2. Buka kembali detail Klien X. | Kuota di paket aktif Klien X **tidak berubah** (snapshot lama dipertahankan) — hanya klien baru yang pilih Template T ke depan yang dapat kuota baru. | ☐ |

#### CLI-003 — Hapus atau jeda klien berdasarkan histori
*Route: `client-management.destroy` · Evidence: `ClientManagementController.php` · Automated: `PostFreezeAuditRegressionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-CLI-003-01 | Hapus klien berhistori → jadi paused | Klien punya minimal 1 Content Plan/content item | 1. Buka Kelola Klien → Hapus Klien. 2. Konfirmasi. | Klien **tidak hilang** — status berubah jadi `paused`, seluruh rencana/konten tetap ada dan bisa dibuka. | ☐ |
| TC-CLI-003-02 | Hapus klien kosong (tanpa histori) | Klien baru, belum punya rencana/konten/integrasi sama sekali | 1. Hapus klien tersebut. | Klien benar-benar terhapus dari daftar. | ☐ |

### 7.3 User & Team Management

#### USR-001 — Tambah pengguna dan multi-role
*Route: `user-management.store`, `user-management.role.update` · Evidence: `UserManagementController.php` · Automated: `UserManagementTest.php`, `FinalQaEffectivePermissionMatrixTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-USR-001-01 | Tambah user dengan multi-role | Login CEO/Manager | 1. Kelola Pengguna → Tambah Pengguna. 2. Isi nama, email unik, pilih 2 role (mis. Graphic Designer + Copywriter). 3. Simpan. | User baru `active`, `login_enabled=true`, muncul di daftar Aktif dengan kedua role tercantum; izin efektifnya adalah gabungan (union) kedua role. | ☐ |
| TC-USR-001-02 | Simpan tanpa role ditolak | — | 1. Tambah Pengguna, isi nama+email, JANGAN centang role apapun. 2. Simpan. | Validasi gagal, user tidak dibuat. | ☐ |

#### USR-002 — Akses login dan siklus aktif/nonaktif
*Route: `user-management.toggle-login-access`, `user-management.destroy` (nonaktifkan), `user-management.activate` · Evidence: `UserManagementController.php`, `PicReassignmentService.php` · Automated: `UserManagementTest.php`, `PostFreezeAuditRegressionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-USR-002-01 | Nonaktifkan user dengan pengalihan pekerjaan | User target punya content item aktif (bukan draft/uploaded/cancelled) sebagai current PIC | 1. Kelola Pengguna → Nonaktifkan user target. 2. Sistem meminta pengganti — pilih user pengganti valid. 3. Konfirmasi. | User jadi `inactive`; pekerjaan aktifnya berpindah current PIC ke pengganti; riwayat lama tetap tercatat atas nama user lama. | ☐ |
| TC-USR-002-02 | Nonaktifkan tanpa pengganti saat ada pekerjaan aktif ditolak | Sama seperti di atas | 1. Coba Nonaktifkan TANPA memilih pengganti. | Ditolak. | ☐ |
| TC-USR-002-03 | Tidak bisa menonaktifkan diri sendiri | Login sebagai user X | 1. Coba nonaktifkan akun X (diri sendiri) dari Kelola Pengguna. | Ditolak. | ☐ |

#### USR-003 — Roster klien dan pengalihan penanggung jawab
*Route: `user-management.clients.update`, `client-management.pic.update/remove` · Evidence: `UserClientAssignmentController.php`, `PicReassignmentService.php` · Automated: `UserManagementTest.php`, `PostFreezeAuditRegressionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-USR-003-01 | Keluarkan PIC dari roster dengan pengganti | User X ada di roster Client A dan sedang jadi current PIC beberapa item aktif | 1. Keluarkan X dari roster Client A, pilih pengganti valid Y (juga roster Client A). | X tidak lagi punya akses Client A; current PIC item aktif berpindah ke Y. | ☐ |
| TC-USR-003-02 | Keluarkan PIC tanpa pengganti saat ada pekerjaan aktif ditolak | Sama seperti di atas, tapi tidak pilih pengganti | 1. Coba keluarkan X dari roster tanpa pengganti. | Ditolak. | ☐ |

### 7.4 Content Planning

#### PLAN-001 — Membuat rencana dan slot kuota
*Route: `content-plan.store` · Evidence: `ContentPlanItemGeneratorService.php` · Automated: `ContentPlanTest.php`, `PostFreezeAuditRegressionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PLAN-001-01 | Buat rencana → slot sesuai kuota paket | Login CEO/Manager/Copywriter; klien dengan paket aktif (mis. 2 Video, 1 Desain) belum punya rencana bulan tsb | 1. Rencana Konten → Buat Rencana → pilih klien & bulan. 2. Simpan. | Rencana `draft` terbentuk dengan slot **C1, C2, D1** semuanya berstatus Draf, tanpa PIC. | ☐ |
| TC-PLAN-001-02 | Duplikasi klien/bulan/tahun ditolak | Rencana klien+bulan+tahun yang sama sudah ada | 1. Coba Buat Rencana lagi untuk kombinasi klien+bulan+tahun yang sama. | Ditolak. | ☐ |

#### PLAN-002 — Kelengkapan brief dan pengajuan
*Route: `content-plan.submit` · Automated: `ContentPlanTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PLAN-002-01 | Ajukan rencana yang lengkap | Rencana draft, semua slot sudah diisi brief+judul bukan placeholder+pilar+platform+scenes/script | 1. Buka rencana → Ajukan Rencana. | Status jadi `pending`, log+notifikasi ke approver terkirim. | ☐ |
| TC-PLAN-002-02 | Ajukan dengan 1 slot tanpa pilar ditolak | Salah satu slot belum diisi pilar | 1. Ajukan Rencana. | Ditolak, tetap `draft`. | ☐ |

#### PLAN-003 — Keputusan rencana dan buka kembali
*Route: `content-plan.approve/reject/reopen` · Automated: `ContentPlanTest.php`, `GoldenPathTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PLAN-003-01 | Setujui rencana pending | Login CEO/Manager/SMO; rencana `pending` | 1. Setujui Rencana. | Status `approved`; slot **belum** otomatis masuk Produksi (masih butuh langkah PLAN-004). | ☐ |
| TC-PLAN-003-02 | Tolak → buka kembali → ajukan ulang mempertahankan histori | Rencana `pending` | 1. Tolak (isi alasan). 2. Buka Kembali (login CEO/Manager/Copywriter). 3. Perbaiki, Ajukan lagi. | ID rencana tetap sama sepanjang siklus; riwayat penolakan sebelumnya tetap terlihat di log keputusan (tidak hilang). | ☐ |

#### PLAN-004 — Deadline dan pengiriman batch ke Produksi
*Route: `content-plan.deadlines.update`, `content-plan.send-to-production` · Automated: `ContentPlanTest.php`, `ContentWorkflowTransitionTest.php`, `PostFreezeAuditRegressionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PLAN-004-01 | Atur deadline lengkap → Kirim ke Produksi | Rencana `approved`, masih ada slot draft | 1. Simpan Deadline (upload_deadline_at) untuk SEMUA slot draft. 2. Klik Kirim ke Produksi. | Semua slot draft berpindah ke `brief_ready` (muncul di Papan Produksi); deadline kerja otomatis = deadline upload − 2 hari; brief yang belum finalized ikut difinalisasi; notifikasi penugasan terkirim ke PIC (kalau ada). | ☐ |
| TC-PLAN-004-02 | Kirim ke Produksi dengan 1 slot belum ada deadline ditolak | Satu slot draft belum diisi upload_deadline_at | 1. Klik Kirim ke Produksi. | Ditolak, batch tidak dilepas. | ☐ |

#### PLAN-005 — Kalender dan Jobdesk Tambahan
*Route: `content-plan.index`, `content-items.quick-urgent` · Automated: `ContentPlanTest.php`, `PostFreezeAuditRegressionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PLAN-005-01 | Jobdesk Tambahan pada klien TANPA paket aktif | Login CEO/Manager/Copywriter; pilih klien tanpa paket aktif | 1. Rencana Konten → Jobdesk Tambahan → isi judul+deadline wajib. 2. Simpan. | Item urgent langsung `brief_ready` tanpa perlu paket/persetujuan batch — pengecualian kuota yang disengaja. | ☐ |
| TC-PLAN-005-02 | Kalender menampilkan berdasar deadline kerja, bukan tanggal plan dibuat | Ada content item Video/Desain non-draft dengan deadline di bulan tertentu | 1. Buka Kalender, pilih bulan tsb. | Item muncul di tanggal deadline kerja-nya (bukan tanggal plan/created_at). | ☐ |

#### CNT-001 — Informasi konten dan klasifikasi
*Route: `content-items.update-info`, `content-items.show`, `content-items.caption` · Automated: `ContentClassificationTest.php`, `ContentItemDetailTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-CNT-001-01 | Isi info konten dengan 2 platform sekaligus | Item berstatus draft dalam scope | 1. Ubah Info Konten → isi judul, brief, pilar, PIC, Link Referensi. 2. Pilih 2 platform (mis. Instagram + TikTok). 3. Simpan. | Kedua relasi platform tersimpan (bukan cuma salah satu). | ☐ |
| TC-CNT-001-02 | Update info pada item BUKAN draft ditolak | Item berstatus `in_progress` atau lebih lanjut | 1. Coba Ubah Info Konten. | Ditolak — endpoint ini hanya untuk item draft. | ☐ |

### 7.5 AI Brief

#### BRF-001 — Brief manual dan generasi AI
*Route: `content-brief.generate/store-manual/assist-field` · Automated: `BriefGenerationDateTest.php`, `ContentBriefApplyChangesTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-BRF-001-01 | Buat brief manual | Item belum punya brief | 1. Buka detail konten → Buat Brief Manual → isi hook/scenes/talent/properti. 2. Simpan. | Brief tersimpan berstatus `draft`, siap diperiksa manusia. | ☐ |
| TC-BRF-001-02 | Proposal AI berisi tanggal TIDAK mengubah tanggal produksi | Kunci Gemini tersedia (atau catat sebagai N/A kalau tidak dikonfigurasi di environment uji) | 1. Generate AI Brief. 2. Terapkan proposal yang (kalau ada) menyertakan field tanggal. | Tanggal produksi/upload (`deadline_at`/`upload_deadline_at`) TIDAK ikut berubah dari proposal AI — AI tidak pernah menentukan tanggal kerja. | ☐ |

#### BRF-002 — Edit, diskusi, apply dan revert brief
*Route: `content-brief.update/discuss/apply/regenerate/revert` · Automated: `ContentBriefApplyChangesTest.php`, `BriefGenerationDateTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-BRF-002-01 | Apply hanya menerapkan field allowlist | Brief dengan draft proposal AI/diskusi yang tersedia | 1. Di panel diskusi brief, minta perubahan. 2. Klik Terapkan Perubahan. | Hanya field yang diizinkan (scenes, hook, dst — bukan field asing) yang ikut terganti. | ☐ |
| TC-BRF-002-02 | Revert satu langkah mengembalikan ke snapshot sebelumnya | Sudah pernah Apply minimal 1x | 1. Klik Revert. | Field kembali ke `previous_snapshot` (revert cuma 1 langkah, bukan full history). | ☐ |

#### BRF-003 — Finalisasi, buka brief dan kelayakan
*Route: `content-brief.finalize/withdraw/set-upload-date` · Automated: `ContentBriefApplyChangesTest.php`, `BriefGenerationDateTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-BRF-003-01 | Finalisasi brief → notifikasi PIC | Brief `discussing`/`draft`, PIC sudah ditentukan | 1. Klik Finalisasi. | Brief terkunci `finalized`; PIC menerima notifikasi. | ☐ |
| TC-BRF-003-02 | Buka Kembali brief finalized TIDAK mengubah status workflow | Brief `finalized`, item workflow sudah lanjut ke status tertentu | 1. Klik Buka Kembali (withdraw). | Brief kembali `discussing`; status **workflow konten tidak ikut mundur otomatis**. | ☐ |

### 7.6 Production Workflow

#### WF-001 — Papan Produksi dan transisi normal
*Route: `production-workflow.index/update-status`, `content-items.transition` · Automated: `ContentWorkflowTransitionTest.php`, `ProductionWorkflowScopeTest.php`, `GoldenPathTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-WF-001-01 | Transisi normal brief_ready → in_progress → waiting_review | Login role W (CEO/Manager/SMO/Content Creator/Graphic Designer); item `brief_ready` | 1. Di Papan Produksi, pindahkan kartu ke "Sedang Dikerjakan". 2. Pindahkan lagi ke "Menunggu Persetujuan" (tanpa mengisi Link Konten Draft). | Kedua transisi diterima; log status tercatat aktor/waktu/from/to; transisi ke `waiting_review` **tidak mewajibkan** link hasil/PIC terisi. | ☐ |
| TC-WF-001-02 | Lompatan status tidak sah ditolak | Item `brief_ready` | 1. Coba transisi langsung ke `approved` (melewati in_progress/waiting_review) lewat endpoint transition. | Ditolak — bukan jalur graph sembilan status yang sah. | ☐ |

#### WF-002 — Koreksi status oleh manajemen
*Route: `content-items.correct-status` · Automated: `ContentWorkflowTransitionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-WF-002-01 | CEO/Manager koreksi status dengan alasan | Login CEO/Manager; item status apapun | 1. Koreksi Status → pilih status tujuan berbeda + isi alasan wajib. 2. Simpan. | Status berubah sesuai pilihan, log `approval_type=correction` + alasan tercatat. **Catatan:** ini override beralasan, TIDAK menyusun ulang publikasi/revisi/is_posted — bukan bug kalau data turunan tidak ikut berubah. | ☐ |
| TC-WF-002-02 | SMO (walau punya workflow:approve) ditolak koreksi status | Login SMO | 1. Coba akses endpoint Koreksi Status. | Ditolak — koreksi status khusus CEO/Manager, bukan siapa pun yang punya `workflow:approve`. | ☐ |

#### WF-003 — Penanda keterlambatan operasional
*Route: `production-workflow.index` (tampilan); command `workflow:update-overdue` · Automated: `PostFreezeAuditRegressionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-WF-003-01 | Item aktif lewat deadline ditandai overdue | Item aktif (bukan draft/uploaded/cancelled) dengan deadline_at di masa lalu | 1. Jalankan `php artisan workflow:update-overdue` (atau tunggu jadwal jam-an). 2. Buka Papan Produksi. | Item bertanda "Terlambat" (overdue). | ☐ |
| TC-WF-003-02 | Item draft dengan deadline lama TIDAK dianggap overdue | Slot draft dengan deadline placeholder lama | 1. Jalankan command yang sama. 2. Cek item draft tsb. | TIDAK ditandai overdue — draft dikecualikan dari pekerjaan aktif. | ☐ |

#### CNT-002 — Hasil produksi, footage dan pengalihan PIC
*Route: `content-items.content-link`, `content-items.footage-captured(.unmark)`, `content-items.reassign` · Automated: `ContentItemDetailTest.php`, `PostFreezeAuditRegressionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-CNT-002-01 | Simpan Link Konten Draft + tandai footage | Item aktif dalam scope, tipe Video | 1. Isi Link Konten (Draft). 2. Tandai footage sudah diambil. 3. Buka lagi halaman detail. | Kedua data tersimpan dan tetap terlihat setelah reload. | ☐ |
| TC-CNT-002-02 | Ganti Penanggung Jawab menyelaraskan current PIC | Item aktif, PIC pengganti aktif & ada di roster klien tsb | 1. Klik Ganti Penanggung Jawab → pilih PIC baru. | `current_pic_id` workflow dan assignment ikut berpindah ke PIC baru. | ☐ |

### 7.7 Revision & Approval

#### REV-001 — Permintaan dan pengerjaan revisi
*Route: `content-revision.store/start-work` · Automated: `ContentWorkflowTransitionTest.php`, `GoldenPathTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-REV-001-01 | Minta revisi pertama kali → status revision | Item `waiting_review` | 1. Minta Revisi, isi catatan wajib. | Catatan `open` round 1 tercatat; status konten berpindah `waiting_review` → `revision`. | ☐ |
| TC-REV-001-02 | Kerjakan Revisi memulai SEMUA catatan open bersamaan | Item `revision`, ada 2 catatan revisi `open` | 1. Klik Kerjakan Revisi. | Kedua catatan sekaligus berubah `in_progress` (bukan satu-satu). | ☐ |

#### APR-001 — Persetujuan internal konten
*Route: `content-items.transition`/`production-workflow.update-status` (ke `approved`) · Automated: `ContentWorkflowTransitionTest.php`, `ClientPortalTest.php`, `GoldenPathTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-APR-001-01 | Approve internal TANPA klien menekan Setuju | Item `waiting_review`, TIDAK ada revisi open/in_progress, klien BELUM menekan Setuju di portal | 1. Login CEO/Manager/SMO. 2. Approve konten dari Papan Produksi. | Diterima — persetujuan klien BUKAN syarat wajib approve internal di HEAD. | ☐ |
| TC-APR-001-02 | Approve ditolak kalau masih ada revisi terbuka | Item `waiting_review` dengan revisi `open`/`in_progress` | 1. Coba approve. | Ditolak. | ☐ |

### 7.8 Client Portal

#### PORTAL-001 — Akses portal berbasis tautan
*Route: `client.portal.dashboard`, `client-management.portal.regenerate/enable/disable` · Automated: `ClientPortalTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PORTAL-001-01 | Token lama tidak berlaku setelah rotasi | Login CEO/Manager; catat token portal klien saat ini | 1. Buka tautan portal lama di tab baru (harusnya berhasil). 2. Di Kelola Klien, klik Putar Ulang Token. 3. Refresh tab tautan lama. | Tautan lama sekarang **404** (bukan 403 — supaya tidak bocor info "token ini pernah ada"). Tautan baru berfungsi. | ☐ |
| TC-PORTAL-001-02 | Portal dinonaktifkan → 404 walau token benar | Portal aktif | 1. Nonaktifkan akses portal klien. 2. Buka tautan portal (token masih sama). | 404. | ☐ |

#### PORTAL-002 — Dashboard, kalender, riwayat dan analytics klien
*Route: `client.portal.dashboard/calendar/history/analytics/approval.show` · Automated: `ClientPortalTest.php`, `CrossClientIdorTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PORTAL-002-01 | Klien hanya lihat miliknya sendiri | Token Client A valid | 1. Buka portal Client A. 2. Jelajahi Dashboard/Kalender/Riwayat/Performa. | Semua data yang tampil milik Client A saja. | ☐ |
| TC-PORTAL-002-02 | Token A tidak bisa buka halaman approval konten Client B | Token A valid; ambil ID content item milik Client B | 1. Buka `/portal/{token-A}/approval/{id-konten-milik-B}` langsung lewat URL. | Ditolak. | ☐ |

#### PORTAL-003 — Setuju atau minta revisi dari klien
*Route: `client.portal.approval.approve/request-revision` · Automated: `ClientPortalTest.php`, `GoldenPathTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PORTAL-003-01 | Klien menekan Setuju | Item `waiting_review`, `client_reviewed_at` masih kosong (mis. item KS-08 di data seeder) | 1. Buka portal, buka item tsb. 2. Klik Setuju. | `client_reviewed_at`+hasil `approved` tercatat, **status konten tetap `waiting_review`** (Setuju bukan approve internal). | ☐ |
| TC-PORTAL-003-02 | Klien Minta Revisi dengan catatan | Item `waiting_review` (mis. item KS-09 di data seeder) | 1. Klik Minta Revisi, isi catatan wajib. | Revisi bersumber klien tercatat, status konten pindah ke `revision`. | ☐ |

### 7.9 Publication

#### PUB-001 — Penjadwalan tayang
*Route: `content-items.transition`/`production-workflow.update-status` (ke `scheduled`) · Automated: `ContentWorkflowTransitionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PUB-001-01 | Jadwalkan tayang dengan tanggal valid | Item `approved` | 1. Pindah ke "Terjadwal Tayang", isi `scheduled_upload_at`. | Status jadi `scheduled`, jadwal tersimpan. | ☐ |
| TC-PUB-001-02 | Tanpa jadwal valid ditolak | Item `approved` | 1. Coba transisi ke `scheduled` TANPA mengisi jadwal. | Ditolak. | ☐ |

#### PUB-002 — Mencatat publikasi multi-platform
*Route: `content-publication.store`, `production-workflow.update-status` (payload uploaded) · Automated: `ContentWorkflowTransitionTest.php`, `GoldenPathTest.php`, `PublishingTrackerPlatformRoutingTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-PUB-002-01 | Catat publikasi via formulir khusus (CEO/SMO) | Login CEO atau SMO; item `scheduled` | 1. Buka Formulir Publikasi → isi platform + waktu tayang (URL opsional). 2. Simpan. | Publikasi tercatat, status `uploaded`, `is_posted=true`, pin terkait terlepas otomatis. | ☐ |
| TC-PUB-002-02 | **Catatan Q-02:** Kanban menerima payload publikasi dari role lebih luas dari formulir | Login Manager/Content Creator/Graphic Designer (bukan CEO/SMO); item `scheduled` | 1. Coba tandai "Sudah Tayang" langsung dari Kanban (bukan formulir khusus). | Diterima — ini perilaku nyata (hak endpoint Kanban memang lebih luas dari formulir), **bukan bug**, siapkan penjelasan ini kalau ditanya dosen. | ☐ |

#### ANL-006 — Post belum tertaut dan manual matching
*Route: `publishing-tracker.instagram/tiktok.unmatched/link` · Automated: `PublishingTrackerPlatformRoutingTest.php`, `PublishingTrackerReturnToTest.php`, `CrossClientIdorTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-ANL-006-01 | Tautkan post belum tertaut secara manual | Login CEO/SMO; ada snapshot post API belum tertaut (butuh integrasi live — kalau tidak tersedia di environment uji, catat N/A dan andalkan automated test) | 1. Buka Post Belum Tertaut. 2. Pilih konten target klien yang sama, tautkan manual. | Publikasi, metrik, dan snapshot hari ini ikut selaras ke konten yang dipilih. | ☐ |
| TC-ANL-006-02 | Tautkan ke konten klien lain ditolak | ID eksternal post milik Client A, target konten dipilih milik Client B | 1. Coba tautkan. | Ditolak. | ☐ |

### 7.10 Content Analytics (Performa)

#### ANL-001 — Pemilihan klien, platform dan periode
*Route: `analytics` · Automated: `AnalyticsGlobalFilterTest.php`, `AiStrategyMonthSelectionTest.php`, `AnalyticsPageSmokeTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-ANL-001-01 | Tanpa klien dipilih → prompt pilih klien | Login role dengan `analytics:view` | 1. Buka Performa TANPA memilih klien. | Tampil ajakan pilih klien — BUKAN agregat semua klien. | ☐ |
| TC-ANL-001-02 | Rentang khusus dibatasi 366 hari | Klien dipilih | 1. Pilih Rentang Khusus, coba masukkan rentang >366 hari. | Ditolak/dibatasi maksimal 366 hari inklusif; effective end tidak melebihi hari ini. | ☐ |

#### ANL-002 — Cohort tayang dan nilai metrik terkini
*Route: `analytics`, `analytics.show` · Automated: `PublishCohortSemanticsTest.php`, `CurrentTotalVsPeriodGainTest.php`, `CrossConsumerDataAgreementTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-ANL-002-01 | Cohort bulan lama menampilkan nilai metrik TERKINI | Post lama (mis. bulan lalu) yang metriknya baru saja diperbarui | 1. Buka cohort bulan publikasi lama tsb. | Nilai metrik yang tampil adalah observasi TERBARU (bukan snapshot beku di akhir bulan itu). | ☐ |
| TC-ANL-002-02 | CSV-only content masuk cohort berdasar metric_date (bukan snapshot) | Konten yang metriknya cuma dari CSV import | 1. Buka Performa periode terkait. | Konten tetap muncul di cohort, dipilih berdasar `metric_date` record, bukan tanggal snapshot API (karena memang tidak ada snapshot). | ☐ |

#### ANL-003 — Pertumbuhan periode, harian dan coverage
*Route: `analytics`, `analytics.show` · Automated: `PeriodPerformanceServiceTest.php`, `AnalyticsPeriodEngineV2Test.php`, `CurrentTotalVsPeriodGainTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-ANL-003-01 | Coverage ditandai partial/unavailable sesuai data | Konten dengan cuma 1 observasi snapshot dalam periode | 1. Buka grafik pertumbuhan periode konten tsb. | Ditandai coverage rendah (partial/unavailable), BUKAN angka pertumbuhan penuh yang menyesatkan. | ☐ |
| TC-ANL-003-02 | Gap antar observasi TIDAK diinterpolasi | Dua observasi API terpisah 3 hari | 1. Buka grafik harian. | Tidak ada titik kenaikan buatan untuk hari-hari kosong di antaranya. | ☐ |

#### ANL-004 — Import dan export CSV performa
*Route: `settings.import-performance`, `analytics.export` · Automated: `ImportPerformanceScopeTest.php`, `AnalyticsGlobalFilterTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-ANL-004-01 | Import CSV valid | Login CEO/Manager/SMO; file CSV header `content_title,platform,metric_date,views,engagement_rate`, ≤5120 KB | 1. Pengaturan → Import Performa CSV → unggah. | Baris yang cocok ter-upsert, hasil/log import ditampilkan (sukses/dilewati). | ☐ |
| TC-ANL-004-02 | Staf import CSV untuk klien di luar scope ditolak | Login SMO roster-scoped ke Client A; CSV berisi data Client B | 1. Coba import CSV untuk Client B. | Ditolak — validasi cakupan. | ☐ |

#### ANL-005 — Status sinkronisasi progresif dan retry
*Route: `analytics.sync`, `analytics.sync-status`, `analytics.sync.retry-*` · Automated: `ProgressiveSyncEngineTest.php`, `AnalyticsUxV2Test.php`, `AnalyticsSyncOrchestratorTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-ANL-005-01 | Perbarui Data menampilkan progres run/task | Integrasi aktif tersedia (kalau tidak ada di environment uji, catat N/A, andalkan automated test) | 1. Klik Perbarui Data. 2. Amati status polling. | Progres per subjob/platform terlihat (discovery/berhasil/gagal), bukan status tunggal generik. | ☐ |
| TC-ANL-005-02 | Coba Lagi Task menghasilkan subjob pengganti yang sama | Ada task gagal (mis. TikTok gagal) | 1. Klik Coba Lagi Task pada task gagal tsb. | Task pengganti adalah subjob platform yang sama (TikTok), bukan subjob acak. | ☐ |

### 7.11 Audience Analytics

#### AUD-001 — Audiens per platform dan sumber
*Route: `analytics`, `client.portal.analytics` · Automated: `AudienceSourceTest.php`, `InstagramAudienceInsightsServiceTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-AUD-001-01 | Tab Audiens memisahkan sumber API vs CSV | Klien dengan data audiens dari kedua sumber (atau CSV saja kalau API tidak tersedia) | 1. Buka tab Audiens klien tsb. | Sumber data ditandai jelas (API/CSV), tidak dicampur seolah satu sumber. | ☐ |
| TC-AUD-001-02 | TikTok tanpa demografi TIDAK dikarang | Klien dengan integrasi TikTok (data followers saja, tanpa breakdown gender/usia — sesuai batasan provider) | 1. Buka Audiens platform TikTok. | Gender/usia TIDAK ditampilkan sebagai angka buatan — ditampilkan sebagai data tidak tersedia. | ☐ |

#### AUD-002 — Import audiens manual
*Route: `audience.import` · Automated: `AudienceSourceTest.php`, `PhaseLAuthorizationLeaksTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-AUD-002-01 | Import CSV audiens tidak menimpa baris API tanggal sama | Ada baris `audience_insights` sumber API di tanggal X (kalau tidak ada, catat N/A) | 1. Import CSV audiens dengan `snapshot_date` = tanggal X. | Baris API tanggal X tetap ada terpisah — baris CSV baru berdiri sendiri (`source=csv_import`). | ☐ |
| TC-AUD-002-02 | Header CSV hilang ditolak | File CSV tanpa header `platform,snapshot_date,follower_count` | 1. Import file tsb. | Ditolak. | ☐ |

### 7.12 AI Strategy

#### AISTRAT-001 — Analisis, riwayat dan refinement strategi
*Route: `analytics.ai-strategy(.history/.chat/.refine/.ideas.regenerate)` · Automated: `AiStrategyMonthSelectionTest.php`, `AiStrategyCorrectnessTest.php`, `AiStrategyLifecycleTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-AISTRAT-001-01 | Generate analisis bulan lalu | Login CEO/Manager/SMO; klien dengan data performa bulan lalu (data seeder sudah menyediakan ini untuk Kopi Senja) | 1. Buka Performa → AI Strategy → pilih bulan lalu → Generate. | Summary, action items, suggested split, top pilar, dan daftar ide konten terbentuk dan tersimpan sebagai riwayat bulan itu. | ☐ |
| TC-AISTRAT-001-02 | Ganti bulan analisis TIDAK diam-diam pakai riwayat bulan lain | Analisis Juli sudah ada, sekarang pilih bulan Agustus (kosong) | 1. Pilih bulan Agustus di filter analisis. | Panel menampilkan "belum ada analisis" untuk Agustus — TIDAK diam-diam menampilkan hasil Juli. | ☐ |

#### AISTRAT-002 — Terapkan satu ide ke slot draft
*Route: `analytics.ai-strategy.ideas.apply` · Automated: `AiStrategyLifecycleTest.php`, `AiStrategyCorrectnessTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-AISTRAT-002-01 | Terapkan ide ke slot draft klien yang sama | Insight `completed`; ada slot draft klien yang sama | 1. Pilih satu ide → Terapkan → pilih slot draft target. | Slot terisi judul/brief/klasifikasi/platform dari ide; **jumlah content item TIDAK bertambah** (mengisi yang sudah ada). | ☐ |
| TC-AISTRAT-002-02 | Ide yang sudah diterapkan tidak bisa diterapkan ulang | Ide index X sudah pernah di-apply | 1. Coba Terapkan ide index X lagi. | Ditolak. | ☐ |

#### AISTRAT-003 — Endpoint Apply massal dan Revert yang masih aktif
*Route: `analytics.ai-strategy.apply/revert` (legacy) · Automated: `AiStrategyLifecycleTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-AISTRAT-003-01 | **Catatan Q-03:** Revert legacy ditolak kalau hanya per-idea Apply yang pernah dipakai | Insight yang sudah dipakai via AISTRAT-002 (per-idea), belum pernah lewat bulk Apply legacy | 1. Coba panggil endpoint Revert legacy. | Ditolak (guard `applied_at` dari jalur legacy tidak terpenuhi) — **bukan bug**, dua jalur apply memang punya jejak terpisah, siapkan penjelasan ini. | ☐ |
| TC-AISTRAT-003-02 | Bulk Apply legacy menambah item baru brief_ready | Insight `completed`, belum pernah `applied_at` | 1. Panggil endpoint bulk Apply legacy (kalau UI-nya masih ada) atau catat sebagai kompatibilitas lama saja. | Item baru dibuat langsung `brief_ready` di rencana bulan berjalan, `applied_at`/`applied_by` terisi. | ☐ |

### 7.13 Delay Risk

*Catatan penting sebelum menguji modul ini: RISK-001 dan RISK-002 **tidak punya automated test** — dua skenario di bawah adalah satu-satunya lapisan verifikasi untuk modul ini. Setelah menjalankan `SeminarDemoSeeder`, cek log konsolnya: kalau tertulis "X dari Y item aktif berhasil dihitung SUNGGUHAN", berarti model ML memang berjalan di environment Anda dan skenario berikut bisa diuji langsung.*

#### RISK-001 — Prediksi risiko keterlambatan ML
*Evidence: `DelayRiskPredictionService.php`, `ContentWorkflowObserver.php` · Automated: tidak ada, inspeksi manual*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-RISK-001-01 | Item baru masuk brief_ready mendapat skor | Model & Python tersedia (lihat catatan di atas) | 1. Lepas slot ke Produksi (brief_ready) — mis. lewat PLAN-004. 2. Buka detail konten / Dashboard. | `risk_score`, `risk_level` (high ≥70 / medium ≥40 / low selainnya), dan `top_factor` tampil. | ☐ |
| TC-RISK-001-02 | Model/script tidak tersedia → tidak ada angka palsu | Simulasikan `PYTHON_BIN` salah atau file model dipindah sementara (opsional, hanya kalau ingin menguji jalur gagal) | 1. Jalankan `php artisan workflow:recompute-delay-risk`. | Tidak ada skor baru tersimpan (log error+skip) — sistem TIDAK pernah menampilkan probabilitas buatan sebagai pengganti. | ☐ |

#### RISK-002 — Evaluasi model berbeda dari KPI pegawai
*Evidence: `DelayRiskAccuracyService.php` · Automated: tidak ada, inspeksi manual*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-RISK-002-01 | Panel evaluasi terpisah dari Nilai KPI | Login CEO/Manager/Admin | 1. Buka Performa Tim → panel "Ketepatan Prediksi Risiko Tinggi". | Panel menampilkan precision/recall model (kalau ada sampel) — terpisah total dari kartu Nilai KPI pegawai, tidak tertukar. | ☐ |
| TC-RISK-002-02 | Tanpa sampel prediksi historis → "belum ada cukup data" | **Berlaku by design** untuk data dari `SeminarDemoSeeder` (lihat catatan checkpoint 3: seeder ini tidak mensimulasikan histori prediksi sepanjang siklus hidup konten) | 1. Buka panel yang sama. | Menampilkan "belum ada cukup data" — **ini bukan bug**, siapkan penjelasan ini kalau ditanya dosen. | ☐ |

### 7.14 Team Performance & KPI

#### TEAM-001 — Performa Tim, perbandingan dan profil
*Route: `team-performance.index`, `profile.show/me` · Automated: `TeamPerformanceKpiOperationsTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-TEAM-001-01 | Performa Tim menampilkan tren 6 bulan | Login CEO/Manager/Admin; data KPI 6 bulan sudah dihitung `SeminarDemoSeeder` | 1. Buka Performa Tim. | Grafik "Tren 6 Bulan Terakhir" terisi, bisa dibandingkan antar anggota. | ☐ |
| TC-TEAM-001-02 | Staf tanpa `team_performance:view` tidak bisa buka KPI profil orang lain | Login Content Creator/Graphic Designer/Copywriter | 1. Coba buka `/profile/{id-user-lain}`. | KPI orang itu tidak disertakan/ditolak — staf hanya bisa lihat KPI dirinya sendiri (`profile.me`). | ☐ |

#### KPI-001 — Cohort dan atribusi KPI bulanan
*Evidence: `TeamPerformanceKpiCalculator.php` · Automated: `TeamPerformanceKpiCalculatorTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-KPI-001-01 | Dua PIC pada satu item masing-masing dapat 1 kontribusi | Item dengan 2 assignment (primary+secondary) yang tayang bulan tsb | 1. Buka breakdown KPI kedua user itu untuk bulan tsb. | Item yang sama muncul di breakdown KEDUA user, masing-masing dihitung satu kali (bukan nol, bukan dobel). | ☐ |
| TC-KPI-001-02 | Item tanpa publikasi bulan ini tidak masuk cohort | Item draft/belum tayang | 1. Cek breakdown KPI bulan berjalan. | Item tsb tidak muncul di sample size bulan ini. | ☐ |

#### KPI-002 — Nilai KPI, ketepatan dan kualitas
*Evidence: `TeamPerformanceKpiCalculator.php` · Automated: `TeamPerformanceKpiCalculatorTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-KPI-002-01 | Revisi klien TIDAK menurunkan skor kualitas | User dengan item yang hanya punya revisi bersumber klien (bukan internal) | 1. Buka detail KPI user tsb. | Skor kualitas tidak dipenalti oleh revisi klien tsb — hanya revisi internal (`requested_by_user_id`) yang memengaruhi kualitas. | ☐ |
| TC-KPI-002-02 | Formula final = min(100, 0.6×ketepatan + 0.4×kualitas + bonus) | Ambil satu baris `user_monthly_kpi_results` dengan `timeliness_score`, `quality_score`, `analytics_bonus` terisi | 1. Hitung manual 0.6×ketepatan + 0.4×kualitas + bonus, bandingkan dengan `final_score` yang tampil (dibatasi maks 100). | Angka cocok (2 desimal). | ☐ |

#### KPI-003 — Bonus Performa berbasis observasi D+7
*Evidence: `TeamPerformanceKpiCalculator.php` · Automated: `TeamPerformanceKpiCalculatorTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-KPI-003-01 | Bonus Performa terisi non-null saat baseline cukup | Bulan dengan item `KS-B3` (baseline padding dari `SeminarDemoSeeder`, lihat catatan seeder) sudah lewat D+7..D+10 | 1. Buka bulan terkait di Performa Tim / profil pegawai terkait. | Kolom Bonus Performa terisi angka (bukan "-"). | ☐ |
| TC-KPI-003-02 | CSV-only TANPA snapshot → Bonus Performa "-", bukan 0 | Item yang metriknya hanya dari CSV import (tidak ada `content_metric_snapshots`) | 1. Buka KPI bulan terkait item tsb. | Bonus tampil "-"/tidak tersedia — BUKAN angka 0 (0 berarti performanya buruk, "-" berarti datanya tidak ada, dua hal berbeda). | ☐ |

#### KPI-004 — Penyimpanan dan hitung ulang KPI
*Evidence: `RecalculateMonthlyKpi.php` · Automated: `TeamPerformanceKpiOperationsTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-KPI-004-01 | Buka bulan yang hasilnya basi memicu hitung ulang | `calculated_at` bulan tsb bukan hari ini (atau belum pernah dihitung) | 1. Buka Performa Tim, pilih bulan tsb. | Job hitung ulang ter-dispatch (perlu worker jalan untuk hasil langsung terlihat; kalau queue sync, hasil langsung update). | ☐ |
| TC-KPI-004-02 | Hasil KPI bulan lampau tidak berubah-ubah tanpa alasan | Bulan yang sudah dihitung dan tidak ada data baru | 1. Buka bulan tsb dua kali dengan jeda. | Angka konsisten (tidak berubah tanpa ada perubahan data pendukung). | ☐ |

### 7.15 Attendance

*Catatan: ATT-001 tidak punya automated test — skenario di bawah satu-satunya verifikasi.*

#### ATT-001 — Check-in dan check-out pribadi
*Route: `attendance.check-in/check-out` · Automated: tidak ada, inspeksi manual*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-ATT-001-01 | Check-in tepat waktu vs terlambat | Hari kerja (Senin–Jumat); login user manapun (termasuk Admin — TIDAK dikecualikan dari endpoint ini) | 1. Check-in sebelum jam 11:15. 2. (Hari lain / kondisi lain) Check-in setelah 11:15. | Kasus 1: status `on_time`. Kasus 2: status `late`. | ☐ |
| TC-ATT-001-02 | Check-out pulang awal/lembur | Sudah check-in hari ini | 1. Check-out sebelum 16:45 (pulang awal), ATAU setelah 17:15 (lembur), di sesi terpisah. | Status `early` atau `overtime` sesuai; check-out antara 16:45–17:15 → `normal`. | ☐ |

#### ATT-002 — Rekap kehadiran dan lupa checkout
*Route: `team-performance.index`, `profile.me` · Automated: `TeamPerformanceKpiOperationsTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-ATT-002-01 | Lupa checkout tetap kosong, bukan ditebak | User check-in kemarin, tidak check-out | 1. Buka rekap harian kemarin. | Kolom check-out tetap kosong/null, berlabel "Lupa Check-Out" — TIDAK ada waktu checkout hasil tebakan sistem. | ☐ |
| TC-ATT-002-02 | Kehadiran TIDAK memengaruhi Nilai KPI 60/40 | Ambil user dengan banyak keterlambatan tapi kualitas/ketepatan kerja tinggi | 1. Bandingkan Nilai KPI dengan rekap kehadirannya. | Nilai KPI tidak turun akibat keterlambatan absensi — formula KPI murni dari ketepatan+kualitas+bonus konten. | ☐ |

### 7.16 Reports

#### REP-001 — Laporan Progres Operasional
*Route: `report.generate`, `report.index` · Automated: `ReportGenerationTest.php`, `GoldenPathTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-REP-001-01 | Generate laporan progres PDF & Excel | Login CEO/Manager/SMO/Admin; periode & klien valid (klien opsional untuk role global) | 1. Laporan → Buat Laporan Progres → pilih periode → PDF. 2. Ulangi untuk Excel. | Kedua file berhasil dibuat, muncul di riwayat laporan pembuat. | ☐ |
| TC-REP-001-02 | Konten dengan deadline dalam periode tapi tayang di luar periode tetap masuk | Item dengan `deadline_at` dalam periode laporan, tapi `published_at` di luar periode | 1. Generate laporan progres periode tsb. | Item tetap masuk laporan (cohort progres pakai deadline kerja, bukan tanggal publikasi). | ☐ |

#### REP-002 — Laporan Performa Konten dan histori pribadi
*Route: `report.generate-performance`, `report.index` · Automated: `ReportGenerationTest.php`, `PublishCohortSemanticsTest.php`, `CrossConsumerDataAgreementTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-REP-002-01 | Riwayat laporan hanya milik pembuat | Login sebagai user A, buat 1 laporan; login sebagai user B (role sama) | 1. User A generate laporan. 2. Login sebagai B, buka Riwayat Laporan. | B TIDAK melihat laporan milik A — hanya `generated_by`-nya sendiri. | ☐ |
| TC-REP-002-02 | Periode >366 hari ditolak | — | 1. Coba generate laporan performa dengan rentang >366 hari. | Ditolak. | ☐ |

### 7.17 Notification & Search

#### NOTIF-001 — Notifikasi peristiwa yang benar-benar tersedia
*Route: `notifications.read/mark-all-read` · Automated: `GoldenPathTest.php`, `ClientPortalTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-NOTIF-001-01 | Tandai satu notifikasi dibaca | Login user dengan minimal 1 notifikasi belum dibaca (data seeder sudah menyediakan ini) | 1. Klik notifikasi → tandai dibaca. | `is_read=true` untuk notifikasi tsb saja. | ☐ |
| TC-NOTIF-001-02 | User A tidak bisa menandai notifikasi milik user B | Ambil ID notifikasi milik user B | 1. Login sebagai A, panggil endpoint tandai-dibaca dengan ID notifikasi B. | Ditolak. | ☐ |

#### SEARCH-001 — Pencarian global dengan cakupan kategori
*Route: `search` · Automated: `CrossClientIdorTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-SEARCH-001-01 | Pencarian mengembalikan maks 5 hasil per kategori | Ketik kata kunci yang cocok banyak (≥2 karakter) | 1. Ketik di kolom pencarian. | Maksimal 5 hasil ditampilkan per kategori (klien/pengguna/konten), bukan daftar penuh. | ☐ |
| TC-SEARCH-001-02 | Konten di luar scope tidak muncul di hasil | Login staf roster-scoped ke Client A; cari judul konten milik Client B | 1. Ketik judul konten Client B. | Konten B TIDAK muncul di hasil pencarian. | ☐ |

### 7.18 Settings & Master Data

#### SET-001 — Pengaturan dan data pilihan
*Route: `settings`, `master-data.store/destroy` · Automated: `ContentClassificationTest.php`, `FinalQaEffectivePermissionMatrixTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-SET-001-01 | Hapus Data Pilihan yang sedang dipakai ditahan | Login CEO/Manager/SMO; Content Pillar tsb dipakai minimal 1 content item | 1. Pengaturan → Data Pilihan → coba Hapus pilar tsb. | Ditolak — guard pemakaian mencegah penghapusan. | ☐ |
| TC-SET-001-02 | Admin hanya bisa Lihat, tidak bisa kelola Data Pilihan | Login Admin | 1. Buka Pengaturan → Data Pilihan. | Tampilan read-only (`settings:view`), tidak ada tombol tambah/hapus aktif untuk Admin. | ☐ |

#### SET-002 — Template paket dan preferensi tampilan
*Route: `package-templates.store/update/destroy`, `preferences.theme` · Automated: `FinalQaEffectivePermissionMatrixTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-SET-002-01 | Hapus template paket yang sudah dipakai klien ditolak | Template dipakai minimal 1 `client_packages` aktif | 1. Coba hapus template tsb. | Ditolak. | ☐ |
| TC-SET-002-02 | Ubah tema tampilan pribadi | Login user manapun (termasuk Admin) | 1. Ubah tema (terang/gelap) di preferensi pribadi. | Tersimpan, berlaku untuk sesi berikutnya — pengecualian mutasi pribadi yang boleh dilakukan Admin. | ☐ |

### 7.19 Social Media Integration

*Catatan: seluruh sub-modul ini butuh kredensial/App Review provider live (Meta/TikTok) yang di luar kendali environment uji lokal. Kalau tidak tersedia, jalankan skenario callback/guard (yang bisa disimulasikan dengan state salah) dan catat sisanya N/A + andalkan automated test.*

#### INT-001 — OAuth Instagram per klien
*Route: `client-management.instagram.connect/callback` · Automated: `SocialIntegrationOAuthTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-INT-001-01 | Connect Instagram (butuh akun tester nyata) | Login CEO/Manager; App Meta terkonfigurasi | 1. Klien → Hubungkan Instagram → login+consent akun tester. | Integrasi tersimpan, token terenkripsi, identitas akun tercatat. | ☐ |
| TC-INT-001-02 | Callback dengan state salah ditolak | — | 1. Panggil callback URL dengan parameter `state` yang tidak cocok sesi. | Integrasi TIDAK terbentuk. | ☐ |

#### INT-002 — OAuth TikTok dengan PKCE
*Route: `client-management.tiktok.connect/callback` · Automated: `SocialIntegrationOAuthTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-INT-002-01 | Connect TikTok (butuh akun tester nyata) | App TikTok Login Kit terkonfigurasi | 1. Klien → Hubungkan TikTok → login+consent. | Token akses/refresh + scope grant tersimpan terenkripsi. | ☐ |
| TC-INT-002-02 | State mismatch pada callback ditolak | — | 1. Panggil callback dengan `state` salah. | Ditolak, tidak ada koneksi terbentuk. | ☐ |

#### INT-003 — Observasi dan refresh konten sosial
*Route: `analytics.sync`, `settings.sync-instagram/tiktok` · Automated: `ProgressiveSyncEngineTest.php`, `RollingSyncCoverageTest.php`, `RefreshKnownContentTest.php`, `ContentMetricSnapshotCollectionTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-INT-003-01 | Sync ulang tidak membuat duplikat snapshot harian | Integrasi aktif dengan histori sync | 1. Jalankan sync 2x di hari yang sama. | Tidak ada baris `content_metric_snapshots`/`content_metrics` duplikat untuk identitas+tanggal yang sama (upsert idempotent). | ☐ |
| TC-INT-003-02 | Konten rolling 90 hari — konten >90 hari tidak terus di-refresh | Post lama (>90 hari) | 1. Jalankan sync mode default. | Post lama tetap tersimpan tapi tidak ikut ter-refresh rolling (kecuali mode histori khusus dipakai). | ☐ |

#### INT-004 — Refresh token dan webhook Instagram
*Route: `webhooks.instagram.verify/handle` · Automated: `InstagramWebhookTest.php`, `SocialIntegrationOAuthTest.php`*

| # | Skenario | Prasyarat | Langkah Pengujian | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-INT-004-01 | Webhook dengan signature tidak valid ditolak | — | 1. POST ke `webhooks/instagram` dengan signature palsu. | Payload ditolak, tidak diproses. | ☐ |
| TC-INT-004-02 | Refresh token harian (command) | Integrasi dengan token mendekati expiry (atau jalankan command langsung) | 1. Jalankan `analytics:refresh-instagram-tokens` / `analytics:refresh-tiktok-tokens`. | Token diperbarui sesuai provider, atau kebutuhan reconnect tercatat kalau gagal. | ☐ |

---

## 3. Matriks RBAC (Uji Lintas Role)

Ini bukan 63 skenario tambahan yang perlu ditulis ulang satu-satu — matriks otorisasi lengkap sudah tersedia di [SRS_523_STUDIO_FINAL.md](SRS_523_STUDIO_FINAL.md) §6 dan sudah diverifikasi otomatis oleh `RoleAccessMatrixTest.php` (63 kombinasi role×halaman) dan `FinalQaEffectivePermissionMatrixTest.php`. Tugas pengujian manual di sini adalah **spot-check** beberapa baris paling berisiko dari matriks itu secara langsung di browser (bukan cuma lewat sidebar), karena sidebar yang tersembunyi tidak selalu membuktikan endpoint-nya juga menolak.

**Legenda nilai (dari SRS §6):** G = diizinkan semua klien · S = diizinkan hanya klien roster · Y = diizinkan, konteks pribadi · T = portal sesuai token · T* = tampilan portal ekuivalen, bukan akses endpoint internal · — = tidak diizinkan sebagai role tunggal.

| # | Baris matriks yang diuji | Role yang dicoba | Langkah | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|---|
| TC-RBAC-01 | Daftar Kelola Klien — VIEW | SMO (harus **—**) | Login SMO → buka `/client-management` (daftar, bukan detail). | Ditolak — SMO cuma dapat `client:view` untuk **detail** klien roster-nya, bukan daftar kelola global. | ☐ |
| TC-RBAC-02 | Klien/paket/token — CREATE/EDIT/DELETE | Content Creator (harus **—**) | Login Content Creator → coba akses endpoint edit klien langsung lewat URL/form. | Ditolak. | ☐ |
| TC-RBAC-03 | Rencana/slot/brief — CREATE, EDIT, SUBMIT | Content Creator, Graphic Designer (harus **—**) | Login masing-masing → coba akses `content-plan.store`/`content-brief.store-manual`. | Ditolak untuk keduanya — hanya CEO/Manager/Copywriter yang punya `content_plan:create`. | ☐ |
| TC-RBAC-04 | Workflow/PIC/revisi — EDIT, TRANSITION | Copywriter (harus **—**) | Login Copywriter → coba transisi status konten di Papan Produksi. | Ditolak — Copywriter tidak punya `workflow:update`. | ☐ |
| TC-RBAC-05 | Publikasi — PUBLISH/RECORD formulir khusus | Manager (harus **—**, beda dari Kanban — lihat Q-02) | Login Manager → coba akses Formulir Publikasi khusus (`publishing:manage`). | Ditolak — formulir khusus hanya CEO/SMO, meskipun Manager bisa lewat Kanban (lihat TC-PUB-002-02). | ☐ |
| TC-RBAC-06 | Status — CORRECT override | SMO (harus **—**) | Login SMO → coba akses Koreksi Status. | Ditolak (lihat juga TC-WF-002-02). | ☐ |
| TC-RBAC-07 | Performa Tim/KPI orang lain — VIEW | Content Creator (harus **—** untuk KPI orang lain, **Y** untuk KPI sendiri) | Login Content Creator → (a) buka `/team-performance`, (b) buka `/profile/{id-sendiri}`. | (a) Ditolak. (b) Diizinkan — KPI profil sendiri selalu boleh. | ☐ |
| TC-RBAC-08 | Master data/paket — CREATE/DELETE/EDIT/MANAGE | Copywriter, Content Creator, Graphic Designer (harus **—**) | Login masing-masing → coba akses `master-data.store`/`package-templates.store`. | Ditolak untuk ketiganya — hanya CEO/Manager/SMO. | ☐ |
| TC-RBAC-09 | Admin — VIEW semua modul TANPA mutasi bisnis | Login Admin | 1. Jelajahi seluruh menu (semua harus terlihat, setara CEO). 2. Coba tombol Tambah/Edit/Hapus di modul bisnis manapun (client, plan, workflow, dst). | Semua menu terlihat (`view` di 11 modul); TIDAK ADA satu pun tombol mutasi bisnis yang berhasil — tapi Admin TETAP bisa membuat laporan (REP-001), pin (CNT-003), absen (ATT-001), dan ubah preferensi (SET-002) karena itu mutasi pribadi, bukan bisnis. **Ini disengaja (Q-01)**, bukan RBAC bocor. | ☐ |
| TC-RBAC-10 | Klien token — semua endpoint internal | Buka portal client (token) | 1. Dari sesi portal klien, coba akses URL internal manapun (mis. `/dashboard`, `/user-management`). | Ditolak — sesi token portal terpisah total dari sesi internal, tidak bisa "naik level" ke akses internal. | ☐ |

---

## 4. State Machine — Transisi yang Wajib Dicoba

Diturunkan langsung dari tabel transisi [SRS §5](SRS_523_STUDIO_FINAL.md#5-state-machine-formal). Tujuannya bukan mengulang skenario modul di §2, tapi memastikan **jalur transisi yang tidak sah benar-benar ditolak**, karena ini bagian yang paling sering luput saat testing manual hanya mengklik jalur normal.

### 4.1 Content Plan

| # | Transisi diuji | Langkah | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|
| TC-SM-PLAN-01 | `approved` → `draft` langsung (harus TIDAK ADA) | Coba cari cara mengembalikan rencana `approved` ke `draft` tanpa lewat siklus reject dulu. | Tidak ada endpoint/tombol yang melakukan ini — satu-satunya jalur balik ke draft adalah lewat `rejected` → Buka Kembali. | ☐ |
| TC-SM-PLAN-02 | `rejected` → `draft` (Buka Kembali) | Dari rencana `rejected`, klik Buka Kembali. | Berhasil, histori keputusan lama (termasuk alasan penolakan) tetap ada. | ☐ |

### 4.2 Content Workflow (9 status)

| # | Transisi diuji | Langkah | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|
| TC-SM-WF-01 | `draft` → status manapun lewat transisi generik (harus ditolak) | Coba transisi generik dari item `draft` (slot kosong) ke `in_progress`. | Ditolak — draft hanya bisa keluar lewat **release batch** (PLAN-004) atau Jobdesk Tambahan/bulk AI, bukan tombol transisi biasa. | ☐ |
| TC-SM-WF-02 | Transisi ke diri sendiri (harus ditolak) | Coba transisi item `in_progress` ke `in_progress` lagi lewat endpoint transition. | Ditolak. | ☐ |
| TC-SM-WF-03 | `uploaded`/`cancelled` → status manapun lewat transisi normal (harus ditolak) | Coba transisi item yang sudah `uploaded` ke status lain lewat endpoint transition biasa. | Ditolak — keduanya terminal normal. **Pengecualian:** CEO/Manager tetap BISA lewat Koreksi Status (WF-002) — itu bukan transisi normal, itu override beralasan. | ☐ |
| TC-SM-WF-04 | Semua 15 transisi sah pada tabel §5.2 SRS | Jalankan minimal satu contoh tiap baris tabel transisi (draft→brief_ready, brief_ready→in_progress, brief_ready→cancelled, in_progress→waiting_review, in_progress→cancelled, waiting_review→approved, waiting_review→revision, waiting_review→cancelled, revision→in_progress, revision→cancelled, approved→scheduled, approved→cancelled, scheduled→uploaded, scheduled→cancelled) dengan role W/A/P yang sesuai. | Semua 15 diterima ketika role+scope sesuai; log status (aktor/waktu/from/to) tercatat tiap kali. | ☐ |

### 4.3 Revision

| # | Transisi diuji | Langkah | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|
| TC-SM-REV-01 | `resolved` → dibuka lagi (harus TIDAK ADA reopen individual) | Coba cari tombol "buka lagi" pada satu catatan revisi `resolved`. | Tidak ada — permintaan revisi berikutnya selalu jadi round/record baru, bukan reopen record lama. | ☐ |

### 4.4 User

| # | Transisi diuji | Langkah | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|
| TC-SM-USR-01 | `invited` → `active` lewat login Google | User baru dibuat (status awal `invited` kalau alur begitu) lalu login Google pertama kali dengan email cocok. | Status otomatis `active` setelah callback berhasil. | ☐ |
| TC-SM-USR-02 | `login_enabled` terpisah dari `status` | User `active` dengan `login_enabled=false`. | Login Google ditolak dengan pesan spesifik "belum memiliki akses login" — BUKAN pesan "akun tidak ditemukan"/"belum aktif" yang generik. | ☐ |

### 4.5 Client

| # | Transisi diuji | Langkah | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|
| TC-SM-CLI-01 | `paused` client dengan `portal_access_enabled=true` tetap bisa diakses klien | Client di-set `paused` (via TC-CLI-003-01), TAPI portal tidak dinonaktifkan manual. | Portal klien **tetap bisa dibuka** — paused TIDAK otomatis mencabut akses portal (Q-06, catat sebagai perilaku disengaja bukan bug). | ☐ |

---

## 5. Checklist Kebutuhan Non-Fungsional (NFR)

Sebagian besar NFR **bukan** test klik-dan-lihat — statusnya di RTM sudah `NEEDS_VERIFICATION`/butuh keputusan target yang belum disahkan (lihat Q-11). Kolom "Bisa diuji manual sekarang?" membedakan mana yang layak dicoba sebelum seminar, dan mana yang di luar cakupan kerja praktik (butuh benchmark/keputusan pemilik produk terpisah).

| NFR | Yang diperiksa | Cara verifikasi manual | Bisa diuji manual sekarang? | Lulus? |
|---|---|---|---|---|
| NFR-001 Security | HTTPS + `APP_DEBUG=false` di environment demo | `php artisan tinker --execute="echo config('app.debug') ? 'DEBUG ON' : 'aman';"` — harus "aman". Cek URL pakai `https://`. | **Ya — wajib sebelum hari-H** | ☐ |
| NFR-002 Authorization | Matriks §6 SRS ditegakkan | Sudah tercakup di §3 dokumen ini (TC-RBAC-01 s.d. 10). | Ya (lihat §3) | ☐ |
| NFR-003 Privacy | Token portal terisolasi; histori laporan per-pembuat | Sudah tercakup TC-PORTAL-001/002, TC-REP-002-01. Tambahan: cek URL file laporan bisa diakses tanpa login (buka link `storage/...` di tab incognito) — **catat sebagai batasan diketahui (Q-09), bukan target lulus/gagal**. | Sebagian (lihat catatan) | ☐ |
| NFR-004 Reliability | Queue/worker untuk sync berjalan | Jalankan `php artisan queue:work` di environment demo sebelum live, pastikan tidak crash. | Ya, kalau integrasi live didemokan | ☐ |
| NFR-005 Availability | Uptime/SLA | Tidak ada target/benchmark disahkan. | **Tidak — di luar cakupan**, catat N/A | ☐ |
| NFR-006 Performance | Waktu respons halaman | Tidak ada target disahkan (klaim lama "<2 detik" eksplisit BUKAN target terverifikasi). Boleh dicatat impresi subjektif saja, bukan lulus/gagal formal. | **Tidak formal — di luar cakupan**, catat impresi saja | ☐ |
| NFR-007 Data Integrity | Guard duplikasi (plan/brief/integrasi) | Sudah tercakup TC-PLAN-001-02, TC-USR-001-01 (multi-role tanpa duplikat). | Ya | ☐ |
| NFR-008 Usability | Label status/empty state konsisten | Buka beberapa halaman kosong (klien baru tanpa data) dan pastikan label "belum ada data" bukan "0"/error. | Ya | ☐ |
| NFR-009 Accessibility/Responsiveness | Layout responsif; TANPA sertifikasi WCAG | Sudah direkomendasikan di [PRE_SEMINAR_SYSTEM_AUDIT.md](PRE_SEMINAR_SYSTEM_AUDIT.md) §5 — cek 5-6 halaman kunci di breakpoint mobile (≤430px) dan tablet (~768px), termasuk Kanban Produksi (paling rawan overflow). | Ya (layout), aksesibilitas formal tidak | ☐ |
| NFR-010 Auditability | Log aktor/waktu/from-to pada setiap transisi | Sudah tercakup §4 (state machine) dan TC-WF-002-01. | Ya | ☐ |
| NFR-011 Maintainability | Struktur kode/test | `php artisan test` hijau (lihat [PRE_SEMINAR_SYSTEM_AUDIT.md](PRE_SEMINAR_SYSTEM_AUDIT.md) §6.2). | Tidak ada test formal khusus — inspeksi struktur kode saja | ☐ |
| NFR-012 Recoverability | Backup/restore | `analytics:prune-content-metric-snapshots` jadwalnya nonaktif (disengaja); tidak ada restore drill. Catat sebagai batasan diketahui. | **Tidak — di luar cakupan** | ☐ |
| NFR-013 Scalability | Kapasitas beban | Tidak ada load test/target. | **Tidak — di luar cakupan**, catat N/A | ☐ |
| NFR-014 Validitas Intelligence | Delay Risk/KPI/AI dibedakan jelas di UI, akurasi tidak diklaim berlebihan | Sudah tercakup TC-RISK-002-01. Pastikan juga narasi demo TIDAK mengklaim "akurasi model X%" tanpa evaluasi berlabel — lihat catatan [PRE_SEMINAR_SYSTEM_AUDIT.md](PRE_SEMINAR_SYSTEM_AUDIT.md) §4.3. | Ya (narasi + pemisahan panel) | ☐ |

---

## 6. Skenario End-to-End (Golden Path)

Satu alur panjang yang melintasi hampir semua modul sekaligus — cocok untuk latihan presentasi demo seminar, bukan cuma pengujian. Ikuti berurutan; kalau satu langkah gagal, modul-modul berikutnya kemungkinan juga terdampak (dependensi berantai), jadi catat titik gagal paling awal.

| # | Langkah | Modul terkait | Hasil Diharapkan | Lulus? |
|---|---|---|---|---|
| 1 | Login sebagai CEO (akun `SEMINAR_DEMO_LOGIN_EMAIL`). | AUTH-001 | Masuk ke Beranda. | ☐ |
| 2 | Buka Kelola Klien, pastikan minimal satu klien fiktif terlihat (mis. Kopi Senja) dengan paket aktif. | CLI-001/002 | Detail klien lengkap: kategori, paket, token portal. | ☐ |
| 3 | Buka Rencana Konten bulan berjalan klien tsb. | PLAN-001/005 | Rencana + slot sesuai kuota paket terlihat. | ☐ |
| 4 | Buka salah satu slot, lengkapi info + brief (pakai AI Brief kalau Gemini tersedia, atau manual). | CNT-001, BRF-001 | Brief tersimpan. | ☐ |
| 5 | Ajukan Rencana → Setujui (kalau belum approved) → Atur Deadline semua slot → Kirim ke Produksi. | PLAN-002/003/004 | Slot masuk Papan Produksi berstatus Brief Ready. | ☐ |
| 6 | Buka Papan Produksi, pindahkan satu kartu brief_ready → in_progress → waiting_review. | WF-001 | Kartu berpindah kolom, log tercatat. | ☐ |
| 7 | Approve internal konten tsb. | APR-001 | Status `approved`. | ☐ |
| 8 | Jadwalkan tayang → Catat Publikasi. | PUB-001/002 | Status `uploaded`, `is_posted=true`. | ☐ |
| 9 | Buka Portal Klien (token klien tsb) di tab/browser terpisah, cek konten yang baru tayang muncul di Riwayat. | PORTAL-001/002 | Konten terlihat di portal klien. | ☐ |
| 10 | Buka Performa, pilih klien tsb, cek cohort bulan berjalan menampilkan konten yang baru dipublikasikan. | ANL-001/002 | Metrik konten (dari data seeder) tampil. | ☐ |
| 11 | Buka Performa Tim, pilih bulan berjalan, tunjukkan Nilai KPI + (kalau tersedia) Bonus Performa PIC yang mengerjakan konten tsb. | TEAM-001, KPI-001..004 | Nilai KPI konsisten dengan langkah 6–8 yang baru dilakukan. | ☐ |
| 12 | Buka Dashboard, tunjukkan ringkasan konten aktif/overdue. | AUTH-003 | Ringkasan konsisten dengan status Papan Produksi saat ini. | ☐ |
| 13 | Buka Delay Risk pada salah satu item aktif lain (bukan yang baru selesai), tunjukkan skor + top factor. | RISK-001 | Skor tampil — **verifikasi ini benar-benar dari model, bukan seeder**, dengan menunjukkan log console saat `SeminarDemoSeeder` dijalankan (lihat §1 poin 5.3 checkpoint 3). | ☐ |
| 14 | Buat Laporan Progres untuk klien tsb. | REP-001 | File PDF/Excel berhasil dibuat. | ☐ |

---

## 7. Checkpoint Selesai

1. ✅ Audit sistem — [PRE_SEMINAR_SYSTEM_AUDIT.md](PRE_SEMINAR_SYSTEM_AUDIT.md)
2. ✅ Analisis seluruh seeder lama — [SEEDER_INVENTORY.md](SEEDER_INVENTORY.md)
3. ✅ Seeder demo baru — `database/seeders/SeminarDemoSeeder.php` + `config/seminar_demo.php`
4. ✅ **Skenario pengujian lengkap per modul** (dokumen ini) — 126 skenario modul (§2, 63 kebutuhan fungsional × 2), 10 skenario RBAC (§3), 9 skenario state machine (§4), checklist 14 NFR (§5), dan 1 alur end-to-end 14 langkah (§6).

Seluruh 4 checkpoint audit pra-seminar selesai. Dokumen ini siap dipakai langsung sebagai lembar kerja pengujian akhir — isi kolom "Lulus?" tiap baris saat dijalankan.
