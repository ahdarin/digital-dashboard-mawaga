# Audit Sistem Menyeluruh — 523 Studio Digital Dashboard

**Tanggal audit:** 16 Agustus 2026
**Target:** `digital-dashboard-mawaga` (Laravel 13.19 / PHP 8.3 / MySQL)
**Metode:** White-box — telaah source code, analisis statis Blade/CSS/JS, inspeksi skema & migrasi, eksekusi terisolasi fungsi bisnis, dan rendering halaman untuk verifikasi struktur.
**Perubahan:** **Nihil.** `git status --porcelain` = 0 baris setelah audit. Tidak ada file, baris database, atau konfigurasi yang diubah.

---

## 0. Ruang Lingkup & Apa yang Sengaja Dikecualikan

Isi database saat ini adalah **data seeder untuk testing**, sehingga tidak merepresentasikan kondisi produksi. Karena itu audit ini **hanya menilai kode, skema, dan konfigurasi** — bukan isi data.

### Yang dikeluarkan dari laporan (temuan berbasis isi database)

| Dikeluarkan | Alasan |
|---|---|
| Klaim "papan Produksi kosong untuk role produksi" | Konsekuensi dari tabel pivot yang belum terisi di data seeder, bukan cacat kode |
| Angka "overdue 18 vs 42 (salah 57%)" | Hasil hitung atas data seeder |
| Dugaan "scheduler `workflow:update-overdue` tidak jalan" | Disimpulkan dari data, bukan dari kode |
| Jumlah query & berat HTML per halaman (93 query, 512 KB, dsb.) | Skalanya mengikuti jumlah baris data seeder |
| "4 token magic-login ada di log saat ini" | Artefak pemakaian testing |
| Sebaran nilai kolom status, jumlah baris per tabel, `profile_visit` 0/571 | Observasi data |
| "Fitur absensi belum pernah dipakai (0 baris)" | Observasi data |

### Yang tetap dipertahankan (dan alasannya)

Sebagian temuan tadi **akar masalahnya ada di kode**, hanya dulu saya buktikan lewat data. Temuan itu dirumuskan ulang sebagai cacat kode dan diverifikasi ulang tanpa menyentuh isi database:

- **Dua model visibilitas yang saling bertentangan di kode** → sekarang ARCH-01 (bukan lagi "board kosong").
- **`doneStatuses` diduplikasi 8× dengan satu pencilan** → sekarang LOGIC-01 (bukan lagi "angka overdue salah"). Verifikasi ulang justru menunjukkan masalahnya lebih luas dari laporan awal.
- **Pola N+1 di dalam loop** → dirumuskan sebagai kompleksitas algoritmik (O(n) query), bukan angka pengukuran.
- **Token mentah ditulis ke log** → cacat kode di `Log::info(...)`, berlaku di lingkungan mana pun.
- **Bug `AttendanceService`** → diverifikasi dengan memanggil fungsinya langsung memakai objek Carbon buatan, tanpa membaca database.

---

## 1. Ringkasan Eksekutif

Sistem ini **kuat di fondasi, lemah di permukaan**. Model data disiplin, mesin workflow tersentralisasi dengan benar, otorisasi tingkat route tepat, dan desain visualnya matang — semuanya hal yang mahal untuk dibetulkan belakangan, dan semuanya sudah benar.

Yang bermasalah adalah dua lapisan "perekat":

1. **Antara route dan query — pembatasan data (scoping).** Sebagian besar route yang memakai route-model binding tidak memeriksa kepemilikan klien sama sekali.
2. **Antara desain dan markup — sistem komponen.** Tailwind lewat CDN tanpa file konfigurasi berarti tidak ada component layer, sehingga setiap tombol/badge/input ditulis tangan dan sudah melenceng satu sama lain.

Pola keduanya sama: **pola yang benar sudah ada di dalam codebase, hanya belum diterapkan merata.** Karena itu mayoritas perbaikan berskala kecil (S) — ini pekerjaan **propagasi, bukan perancangan ulang**.

### Skor

| Dimensi | Skor | Dasar penilaian |
|---|---:|---|
| **Keseluruhan** | **57 / 100** | Tertahan oleh keamanan & aksesibilitas |
| UI (kualitas visual) | 72 | Disiplin palet sangat baik; drift komponen berat |
| UX (alur) | 62 | Jargon teknis, kegagalan senyap, input hilang di satu modal |
| Responsive | 76 | Pengerjaan terbaru rapi; 3 celah nyata |
| Aksesibilitas | 32 | Tanpa `<html lang>`, 11 tabel mati keyboard, kontras gagal AA |
| Kualitas Kode | 60 | Service layer bagus; duplikasi & konstanta tersebar |
| Arsitektur | 56 | Tanpa Policy; scoping ad hoc; dua model visibilitas |
| Keamanan | 38 | IDOR lintas klien pada mayoritas route ber-binding |
| Konsistensi | 58 | 11 varian tombol, 14 varian badge, 4 tingkat judul |

### Lima masalah terbesar

1. **SEC-01 / SEC-02 (P0)** — Route detail konten tidak memeriksa kepemilikan klien; `/profile/{user}` bahkan tanpa permission middleware, dan `copywriterQueue($user)` mengabaikan parameternya.
2. **ARCH-01 (P1)** — Dua definisi "pekerjaan saya" yang bertentangan hidup berdampingan di kode.
3. **A11Y-01/02/03 (P1)** — `layouts/app.blade.php` tanpa `<!DOCTYPE>`/`<html lang="id">`; 11 tabel inti tidak bisa dioperasikan keyboard; `#9aa0a4` (semua header tabel, label form, empty state) rasio 2.64:1 → gagal WCAG AA.
4. **BUG-01 (P1)** — `SearchController.php:48` memanggil nama route yang tidak terdaftar → 500, dan frontend menelannya jadi "tidak ada hasil".
5. **SEC-03 (P1)** — Token magic-login mentah ditulis ke log aplikasi pada level INFO.

### Lima perbaikan paling berdampak

| Perbaikan | Effort | Dampak |
|---|---|---|
| Middleware `client.scope` untuk model ber-binding | **M** | Menutup SEC-01, 04, 05, 06, 09, 10 sekaligus |
| Ganti `client-onboarding.show` → `client-management.show` | **S** | Menghilangkan 500 di kotak pencarian semua halaman |
| Tambah `<!DOCTYPE html><html lang="id">` | **S** | Memperbaiki WCAG 3.1.1 di 40+ halaman |
| Tambah 5 utility class di `layouts/app.blade.php` | **S** | Meruntuhkan ~15 temuan konsistensi + UI-19/A11Y-01 |
| Satu konstanta `DONE_STATUSES` menggantikan 8 salinan | **S** | Menghapus kelas bug "definisi ganda" secara permanen |

---

## 2. Gambaran Sistem

| Lapisan | Teknologi |
|---|---|
| Framework | Laravel 13.19 (PHP 8.3) |
| Database | MySQL — 38 tabel, 59 FK constraint |
| View | Blade — 55 file, tanpa build step |
| CSS | Tailwind via **CDN** — tanpa config, tanpa layer `@apply` |
| JS | Alpine.js 3 (core saja) + flatpickr, keduanya CDN |
| Auth internal | Google OAuth (Socialite) — **invite-only**, tanpa registrasi mandiri |
| Auth klien | Magic link via WhatsApp (Fonnte), token di-hash SHA-256, TTL 10 menit |
| AI | Google Gemini (brief, strategi, chat) + subprocess Python untuk skor risiko |
| Ekspor | dompdf, maatwebsite/excel |
| Otorisasi | 3 middleware (`internal`, `client.user`, `permission:module,action`) — **tanpa Policy, tanpa Gate** |
| Testing | PHPUnit 12 terpasang; **tidak ditemukan test suite yang berarti** |

**Skala kode:** 92 route · 23 controller · 34 model · 13 service · 3 middleware · 55 view.

**Role (7):** CEO, Manager, Content Creator, Graphic Designer, SMO, Copywriter, Client Owner.

---

## 3. Peta Sistem

```
CEO / Manager  ──► Dashboard ──► kartu KPI, Insight AI, Perlu Perhatian
               ├─► Produksi ──► Kanban (8 kolom) / List ──► drag-drop ──► WorkflowStatusService ──► status log
               │                └─► tab Revisi, tab Sudah Tayang
               ├─► Rencana Konten ──► Kalender / Tabel ──► buat ──► ajukan ──► setujui
               ├─► Performa ──► Overview / Performance Table / Audience ──► AI Strategy ──► apply → buat plan+item
               ├─► Performa Tim ──► tab Performa / tab Kehadiran
               ├─► Kelola Klien ──► CRUD + akun owner
               ├─► Kelola Pengguna ──► undang / assign klien / aktivasi
               ├─► Laporan ──► generate PDF / Excel
               └─► Pengaturan ──► Umum / Master Data / Integrasi ──► impor CSV

Content Creator / Graphic Designer / Copywriter / SMO
               ├─► Beranda   ──► daftar tugas  (scope: penugasan per-ITEM)
               └─► Produksi  ──► papan Kanban   (scope: penugasan per-KLIEN)   ⚠ ARCH-01

Client Owner ──► magic link ──► Portal ──► Dashboard / Approval ──► setujui | minta revisi
                 (terisolasi dengan benar ke kliennya sendiri — terverifikasi aman)
```

### 3.1 Otorisasi tingkat route — terverifikasi benar

Setiap route dijalankan sebagai setiap role melalui HTTP kernel sungguhan. **Lapisan ini benar sepenuhnya.**

| Route | Manager | CEO | Content Creator | Graphic Designer | SMO | Copywriter | Client Owner |
|---|---|---|---|---|---|---|---|
| `/dashboard` | 200 | 200 | 403 | 403 | 200 | 403 | 403 |
| `/beranda` | 200 | 200 | 200 | 200 | 200 | 200 | 403 |
| `/content-plan` | 200 | 200 | 200 | 200 | 200 | 200 | 403 |
| `/production-workflow` | 200 | 200 | 200 | 200 | 200 | 200 | 403 |
| `/team-performance` | 200 | 200 | 403 | 403 | 403 | 403 | 403 |
| `/client-management` | 200 | 200 | 403 | 403 | 403 | 403 | 403 |
| `/user-management` | 200 | 200 | 403 | 403 | 403 | 403 | 403 |
| `/analytics` | 200 | 200 | 403 | 403 | 200 | 403 | 403 |
| `/report` | 200 | 200 | 403 | 403 | 200 | 403 | 403 |
| `/settings` | 200 | 200 | 403 | 403 | 200 | 403 | 403 |
| `/client/dashboard` | 403 | 403 | 403 | 403 | 403 | 403 | 200 |
| `/client/approval` | 403 | 403 | 403 | 403 | 403 | 403 | 200 |

**Kesimpulan: LULUS.** Pemisahan internal/klien dan gerbang permission bekerja persis sesuai rancangan. Seluruh temuan di laporan ini berada **di bawah** lapisan ini — pada pembatasan data, bukan akses halaman.

---

## 4. Temuan Arsitektur

### ARCH-01 — Dua model visibilitas yang bertentangan hidup berdampingan
- **Severity: P1 / High** · **Kategori:** Arsitektur · **Status:** CONFIRMED (dibuktikan dari kode, bukan data)

Kode punya **dua jawaban berbeda** atas pertanyaan "konten mana yang milik saya":

```php
// A. Per-KLIEN — ProductionWorkflowController.php:47-49 (juga :142-143, :183-184)
if (!$user->canSeeAllClients()) {
    $assignedClientIds = $user->assignedClients()->pluck('clients.id');
    $itemsQuery->whereIn('client_id', $assignedClientIds);
}

// B. Per-ITEM — UserWorkSummaryService.php:40
$assignments = ContentItemAssignment::where('user_id', $user->id) ...
```

| Model | Tabel | Dipakai oleh |
|---|---|---|
| **Per-item** | `content_item_assignments` | Beranda, Profil (`UserWorkSummaryService`) |
| **Per-klien** | `user_client_assignments` | Produksi (3 tab), Revision Log, Publishing Tracker, Search, Report |

Keduanya tidak pernah direkonsiliasi, dan tidak ada aturan di kode yang menjaga keduanya sinkron. Akibatnya **Beranda dan Produksi dapat menampilkan himpunan pekerjaan yang berbeda untuk user yang sama** — dua halaman di sidebar yang sama memberi jawaban berbeda atas pertanyaan yang sama. Selisihnya bergantung pada sejauh mana kedua tabel dijaga konsisten secara operasional, yang saat ini sepenuhnya manual.

Ini juga akar dari kelas kerentanan di §5: karena tidak ada satu mekanisme scoping yang otoritatif, setiap controller menerapkannya sendiri-sendiri — dan sebagian besar route detail tidak menerapkannya sama sekali.

**Rekomendasi.** Tetapkan **satu** definisi visibilitas dan wujudkan sebagai satu helper/scope yang dipakai semua pemanggil:

```php
// Contoh: gabungan (paling toleran) — item yang di-assign ke saya
// ATAU milik klien yang di-assign ke saya
public function scopeVisibleTo(Builder $q, User $user): Builder
{
    if ($user->canSeeAllClients()) return $q;
    return $q->where(fn ($w) => $w
        ->whereHas('assignments', fn ($a) => $a->where('user_id', $user->id))
        ->orWhereIn('client_id', $user->assignedClients()->select('clients.id'))
    );
}
```

**Ini prasyarat untuk perbaikan keamanan** (lihat SEC-A). **Effort: M**

### ARCH-02 — Tidak ada Policy/Gate; otorisasi objek dikerjakan ad hoc
- **Severity: P1 / High** · CONFIRMED

Tidak ada direktori `app/Policies` dan tidak ada Gate. Otorisasi *route* ditangani middleware dengan baik, tetapi otorisasi *objek* (bolehkah user ini menyentuh record ini) tidak punya rumah — sehingga ditulis ulang per controller, dan lebih sering tidak ditulis sama sekali. Inilah alasan struktural di balik SEC-01/04/05/06/09/10.

### ARCH-03 — Logika bisnis dan query di dalam view
- **Severity: P2 / Medium** · CONFIRMED
- **Lokasi:** `resources/views/components/attendance-widget.blade.php:2-6`

```blade
@php
    $attendanceService = app(\App\Services\AttendanceService::class);
    $attendance = $attendanceService->today(auth()->user());   // query DB di dalam view
    $lateMinutes = $attendance ? $attendanceService->lateMinutes($attendance) : 0;
@endphp
```

View me-resolve service dari container lalu mengeksekusi query. Saat ini dampaknya terbatas (satu widget per halaman), tetapi polanya berbahaya: begitu komponen ini dipakai di dalam loop, ia menjadi N+1 secara senyap — dan controller kehilangan kendali atas data yang dirender.

**Rekomendasi.** Pindahkan pengambilan data ke `HomeController`, kirim ke view sebagai prop. **Effort: S**

---

## 5. Audit Keamanan & Otorisasi

> Semua temuan di bawah adalah **ketiadaan pemeriksaan di dalam kode**, sehingga berlaku terlepas dari isi database. Diurutkan berdasarkan severity.

### SEC-01 — IDOR lintas klien pada seluruh permukaan content-item
- **Severity: Critical** · CONFIRMED
- **Lokasi:** `ContentItemController.php:17-47` (show), `:55-73`, `:75-89`, `:106-126`, `:135-144`, `:146-155`, `:161-174`, `:181-200`

Setiap route me-resolve `ContentItem` lewat route-model binding dan hanya memeriksa permission kasar (`workflow,view` / `workflow,update`). **Tidak satu pun method membandingkan `$contentItem->client_id` dengan klien yang boleh diakses pemanggil.**

```php
public function show(ContentItem $contentItem)
{
    $contentItem->load(['client', 'contentType', ... 'contentBriefDraft.takeByUser']);
    // tidak ada pemeriksaan scope klien di mana pun dalam method ini
```

**Skenario.** Pemegang `workflow.view` menelusuri `GET /content-items/{id}` dan membaca brief, draft caption, `content_file_link` (URL deliverable Drive/Canva), riwayat status beserta nama pelaku, catatan revisi, dan URL publikasi milik klien mana pun. Dengan `workflow.update` ia lalu dapat `PATCH .../caption` menimpa caption yang sedang menunggu persetujuan klien, `PATCH .../content-link` menukar deliverable, atau `PATCH .../reassign` memindahkan pekerjaan klien lain ke dirinya.

**Perbaikan:** guard bersama di awal setiap method (lihat SEC-A). **Effort: M**

### SEC-02 — `/profile/{user}` tanpa permission middleware, dan parameter scope diabaikan
- **Severity: Critical** · CONFIRMED
- **Lokasi:** `routes/web.php:206`; `ProfileController.php:10-21`; `UserWorkSummaryService.php:29-36`

Dua cacat yang saling memperparah.

**(1)** Route berada di `['auth','internal']` **tanpa `permission:` middleware dan tanpa scoping**, dengan binding `User` tanpa batasan — sehingga `/profile/{id}` juga me-resolve user berjenis *Client Owner*.

**(2)** `copywriterQueue(User $user)` **menerima `$user` lalu tidak pernah memakainya**:

```php
public function copywriterQueue(User $user): Collection
{
    return ContentItem::with(['client', 'contentType', 'contentBriefDraft', 'workflow'])
        ->whereDoesntHave('contentBriefDraft', fn ($q) => $q->where('status', 'finalized'))
        ->whereHas('workflow', fn ($q) => $q->whereNotIn('current_status', $this->doneStatuses))
        ->orderBy('deadline_at')->get();   // $user tidak pernah dirujuk
}
```

**Skenario.** User internal mana pun membuka profil seorang Copywriter, dan view merender **seluruh antrean brief organisasi** — judul, nama klien, status brief, deadline. Terhadap target non-copywriter, `productionSummary` membocorkan daftar tugas user tersebut beserta nama klien dan daftar klien yang ditanganinya.

Cacat serupa ada di `NextStepsService::forUser()` pada baris 21, 31, dan 44 (hitungan global tanpa filter klien).

**Perbaikan:** batasi query dengan klien milik *penonton*; batasi binding hanya ke user internal; tambahkan `permission:team_performance,view` atau batasi ke diri sendiri. **Effort: M**

### SEC-03 — Token magic-login mentah ditulis ke log aplikasi
- **Severity: High** · CONFIRMED
- **Lokasi:** `Auth/ClientMagicLinkController.php:91-99` (juga `:30-34`, `:70-75`, `:195-198`)

Controller sudah benar menyimpan hanya `hash('sha256', $rawToken)` di database — lalu **menulis URL login lengkap berisi token mentah** ke log pada level INFO:

```php
$link = route('client.magic-login.verify', ['token' => $rawToken]);
\Log::info('Sending WhatsApp via Fonnte', [
    'message' => $message,   // ← berisi token mentah
    'link'    => $link,      // ← berisi token mentah
]);
```

Siapa pun dengan akses baca ke file log (ops, backup, pipeline log-shipping, LFI) memperoleh tautan login yang masih berlaku dan berautentikasi sebagai Client Owner dalam jendela 10 menit. Baris `:195-198` juga menuliskan `session()->getId()` — sebuah kredensial aktif.

**Terkait, satu alur yang sama (CONFIRMED):**
- **Entropi token:** `Str::random(8)` (`:77`) ≈ 47 bit. Naikkan ke `Str::random(48)`.
- **Verifikasi tanpa throttle:** `GET /client/magic-login/{token}` (`routes/web.php:244`) tidak punya `throttle` sama sekali, sementara `POST /client/login` punya `throttle:5,1`. Tambahkan `throttle:10,1`.
- **Sudah benar:** single-use (`used_at`) dan kedaluwarsa keduanya ditegakkan; pencarian lewat kesetaraan hash SHA-256 sehingga tidak ada isu timing.

**Perbaikan:** hapus `message`/`link`/`token_preview`/`session_id` dari konteks log; sisakan `user_id`. **Effort: S**

### SEC-04 — Analytics sepenuhnya dikendalikan `client_id` dari request tanpa validasi kepemilikan
- **Severity: High** · CONFIRMED
- **Lokasi:** `AnalyticsController.php:37-38, 103-188, 199+, 249-289, 301-320, 372-380, 392-398, 466-474, 610-639, 651+, 701+, 758-762`

`permission:analytics,view` adalah satu-satunya gerbang. `$selectedClientId = $request->input('client_id')` dipakai mentah, dan `$clientOptions = Client::where('status','active')->get()` juga tidak dibatasi — **dropdown-nya sendiri menyebutkan seluruh klien**. Route AI-strategy hanya memeriksa status siklus hidup (`status !== 'completed'`, `applied_at !== null`), tidak pernah kepemilikan klien.

**Skenario.** Pemegang `analytics.view` membaca data performa klien yang bukan tanggung jawabnya, menghabiskan kuota Gemini untuk menghasilkan strategi bagi klien tersebut, lalu `POST /analytics/ai-strategy/{id}/apply` **menulis content plan beserta content item ke akun klien yang tidak berkaitan**. **Effort: M**

### SEC-05 — Modul Content Plan tanpa pembatasan klien di lapisan mana pun
- **Severity: High** · CONFIRMED
- **Lokasi:** `ContentPlanController.php:19-107, 109-133, 135-173, 180-207, 209-262, 273-330`

Berbeda dari index workflow, `index()` tidak menerapkan filter `canSeeAllClients()` pada `$plans`, query kalender, maupun `$clientOptions`. `show()`/`submit()`/`approve()`/`storeItem()` mem-binding `ContentPlan` tanpa pemeriksaan kepemilikan. `store()` dan `quickCreateUrgent()` menerima `client_id` sembarang (hanya `exists:clients,id`).

**Skenario.** Copywriter (punya `content_plan.view` + `content_plan.create`) membuka `/content-plan` dan langsung melihat rencana, kuota, serta kalender seluruh klien — **tanpa perlu menebak ID**. Dengan `content_plan.create` ia mengirim `POST /content-items/urgent {client_id: X}` untuk menyisipkan pekerjaan mendesak fiktif ke klien mana pun, yang otomatis membuat `ContentPlan` dan mengirim notifikasi ke PIC sembarang. SMO memegang `content_plan.approve` sehingga dapat menyetujui/menolak rencana klien mana pun. **Effort: M**

### SEC-06 — Route Content Brief: tanpa scoping + biaya AI tanpa batas
- **Severity: High** · CONFIRMED · `ContentBriefController.php:22-192`

Kedelapan route POST/PATCH hanya digerbangi `permission:content_plan,create`. Setiap method memeriksa status siklus hidup (`isLocked()`, `canRevert()`) tetapi tidak pernah kepemilikan klien.

```php
public function regenerate(ContentBriefDraft $contentBrief)
{
    abort_if($contentBrief->isLocked(), 422, '...');   // hanya pemeriksaan state
    $contentItem = $contentBrief->contentItem;
    $this->briefService->regenerate($contentBrief);
```

Pemegang izin dapat men-generate, menulis ulang, dan **men-finalisasi** brief pada item klien mana pun — mendorong brief yang sudah dimanipulasi ke tim produksi. Setiap panggilan juga merupakan request Gemini berbayar tanpa kuota per-user (*denial-of-wallet*). **Effort: M**

### SEC-07 — `/search` melempar `RouteNotFoundException` → stack trace debug ke browser
- **Severity: High** · CONFIRMED (lihat BUG-01)
- **Lokasi:** `SearchController.php:48`

`bootstrap/app.php:22-24` hanya merender JSON untuk `api/*`, sehingga XHR menerima **halaman debug HTML** Laravel — cuplikan source, path absolut, dan stack trace lengkap beserta argumen — dipicu oleh pemakaian normal, dengan `APP_DEBUG=true`. **Effort: S**

*Terverifikasi aman di file yang sama:* `searchClients:39-41` dan `searchContentItems:73-75` **sudah** menerapkan filter scoping dengan benar.

### SEC-08 — `APP_DEBUG=true` / `APP_ENV=local` sebagai default yang di-commit
- **Severity: High** · CONFIRMED

`.env.example` mengirimkan `APP_DEBUG=true` sebagai template. Dengan SEC-07 sebagai pemicu yang pasti, ini bukan risiko teoretis.

Cakupan yang presisi: `spatie/laravel-ignition` **tidak** terpasang, jadi tidak ada tab dump environment. Yang terkonfirmasi terekspos: pesan exception, stack trace lengkap beserta argumen, dan cuplikan source. Secret (`GOOGLE_CLIENT_SECRET`, `FONNTE_TOKEN`, `GEMINI_API_KEY`, kredensial DB) bocor hanya bila muncul sebagai argumen dalam trace.

**Perbaikan:** `.env` produksi → `APP_DEBUG=false`, `APP_ENV=production`, `LOG_LEVEL=error`, `SESSION_SECURE_COOKIE=true`; ubah `.env.example` menjadi `false`.

### SEC-09 — `POST /report/generate` mengekspor seluruh klien bila `client_id` dikosongkan
- **Severity: Medium** · CONFIRMED · `ReportController.php:35-52, 55-64`

`index()` sudah membatasi `$clientOptions` dengan benar, tetapi itu hanya lapisan UI: `client_id` divalidasi `nullable|exists:clients,id` dan query hanya memfilter `if (!empty($filters['client_id']))`. Menghilangkan field tersebut menghasilkan ekspor lintas seluruh organisasi.

**Perbaikan:** untuk user non-`canSeeAllClients()`, jadikan `client_id` wajib dan validasi dengan `Rule::in($user->assignedClients()->pluck('clients.id'))`. **Effort: S**

### SEC-10 — Endpoint tulis revisi & publikasi tanpa scoping
- **Severity: Medium** · CONFIRMED · `ContentRevisionController.php:57-105, 115-124`; `ContentPublicationController.php:58+`

Kedua `index()` sudah membatasi dengan benar; method tulis ber-binding `{contentItem}` tidak. Seorang Graphic Designer dapat memaksa item klien mana pun keluar dari `waiting_review` menjadi `revision`, memblokir persetujuan klien tersebut. Cacat sekunder: `startWork()` tidak pernah memverifikasi `$revision->content_item_id === $contentItem->id`.

### SEC-11 — Cookie remember-me 5 tahun tanpa flag secure
- **Severity: Medium** · CONFIRMED · `ClientMagicLinkController.php:192`, `GoogleAuthController.php:51`, `config/session.php:50,172`

`Auth::login($user, remember: true)` tanpa syarat pada kedua alur; `SESSION_SECURE_COOKIE` tidak diset dan `SESSION_ENCRYPT=false`. Sesi portal klien tidak seharusnya menjadi kredensial multi-tahun yang bisa disadap. `same_site=lax` dan `http_only=true` sudah benar.

### SEC-12 — `user_management,manage` dapat mencetak Client Owner dan menonaktifkan akun mana pun
- **Severity: Low** · CONFIRMED · `UserManagementController.php:31-40, 61-68, 70-77`

`role_id` hanya divalidasi `exists:roles,id`; pengecualian Client Owner di UI (`:22`) sekadar kosmetik — POST yang dirakit manual dapat membuat Client Owner dengan `client_id = null`, yaitu user yang gagal di kedua middleware `internal` dan `client.user`. `destroy`/`activate` mem-binding `User` mana pun tanpa guard `whereNull('client_id')` dan tanpa proteksi diri, sehingga Manager dapat menonaktifkan CEO.

**Perbaikan:** `Rule::in(Role::whereNotIn('name',['Client Owner'])->pluck('id'))`; tambah `abort_if($user->isClientUser(), 404)` dan `abort_if($user->id === auth()->id(), 422)`.

### SEC-13 — `pic_id` menerima user klien
- **Severity: Low** · CONFIRMED · `ContentPlanController.php:228, 282`

`'pic_id' => 'required|exists:users,id'` menerima ID user klien. `ContentItemController::reassign:187` sudah benar (`User::whereNull('client_id')->where('status','active')->findOrFail(...)`) — inkonsistensinya justru menjadi petunjuk. Akibatnya Client Owner dapat ditetapkan sebagai PIC konten internal dan menerima notifikasi tugas internal.

### SEC-14 — Seeder menanam password yang diketahui pada role Manager
- **Severity: Low** · CONFIRMED · `RoleSeeder.php:32-42` — `bcrypt('password')`, status `active`.

Saat ini tidak terjangkau karena **tidak ada route login berbasis password**. Risikonya laten: menambahkan autentikasi password kelak langsung memberi akses Manager. **Perbaikan:** hapus password, atau kurung blok seeder itu dengan `app()->environment('local')`.

### SEC-15 — `role_id`/`client_id`/`status` mass-assignable pada `User`
- **Severity: Low (laten — TIDAK dapat dieksploitasi saat ini)** · CONFIRMED · `app/Models/User.php:15`

Grep menyeluruh atas `$request->all()`, `create($request`, `update($request`, `fill($request`, `->merge(`, `forceFill`, `unguard` menghasilkan **nol kecocokan**. Setiap penulisan meneruskan array yang dienumerasi eksplisit dari hasil `validate()`. Murni pertahanan berlapis.

### SEC-16 / SEC-17
- **PII berlebihan di log** (`ClientMagicLinkController.php:30-34…195-198`) — nomor telepon mentah, nama, status akun, dan session ID pada level INFO.
- **Impor CSV mencetak baris `Platform` baru tanpa whitelist** (`SettingsController.php:192`, `AudienceController.php:96`) — `Platform::firstOrCreate(['name' => trim($data['platform'])])` mencemari master data yang memberi makan dropdown seluruh aplikasi. Perbaikan: cari dengan `first()` lalu lewati baris dengan pesan, konsisten dengan penanganan `content_title` di `:199-202`.

### SEC-A — Perbaikan terkonsolidasi untuk SEC-01/04/05/06/09/10

Akar masalahnya arsitektural: scoping ditulis tangan di method `index()` dan dilewatkan di setiap tempat route-model binding dipakai. Karena tidak ada Policy, perbaikan minimal yang cocok dengan stack ini adalah **middleware `client.scope`** yang me-resolve `client_id` dari model ber-binding, sehingga route baru terlindungi secara default alih-alih harus mendaftar:

```php
abort_unless(
    $u->canSeeAllClients() || $u->assignedClients()->whereKey($model->client_id)->exists(),
    403
);
```

⚠️ **Catatan urutan penting.** Middleware ini dan **ARCH-01 harus dirancang bersamaan.** Menegakkan scoping per-klien sementara definisi visibilitas belum disatukan akan membatasi akses staf produksi ke *seluruh* modul, bukan hanya sebagian. **Selesaikan ARCH-01 lebih dulu, baru terapkan scoping secara seragam.**

### Terverifikasi Aman (sudah diperiksa dan benar — jangan ditandai ulang)

1. **Isolasi portal klien** — `Client/ApprovalController.php:31-99`: `show`, `approve`, `requestRevision` masing-masing dibuka dengan `abort_unless($contentItem->client_id === $request->user()->client_id, 403)`; `index:17-23` memfilter berdasarkan `client_id` pemanggil. Client Owner **tidak dapat** menjangkau konten klien lain. `approve` juga menegakkan `current_status === 'waiting_review'` dan idempotensi. **Ini permukaan paling berisiko dan implementasinya benar.**
2. **`PATCH /notifications/{notification}/read`** — tanpa middleware, tetapi `NotificationController.php:20` melakukan `abort_unless($notification->user_id === auth()->id(), 403)`. Tidak ada IDOR.
3. **Route `storage/{path}`** — array middleware kosong terlihat mengkhawatirkan, tetapi disk `local` tidak punya key `visibility` sehingga `ServeFile` dan `ReceiveFile` sama-sama mensyaratkan signature valid.
4. **CSRF** — nol `withoutMiddleware`, nol pengecualian token di seluruh aplikasi.
5. **SQL injection** — kelima lokasi raw SQL aman; `AnalyticsController.php:279` yang meng-interpolasi `orderByRaw` didahului whitelist ketat (`:255-259`).
6. **Google OAuth** — akun sembarang **tidak dapat** membuat akun internal; `GoogleAuthController.php:25-35` keluar lebih awal kecuali email sudah ada dengan status `active`/`invited`. Invite-only secara desain.
7. **Validasi status workflow** — `ContentItemController::transition:57-61` tidak punya daftar `in:`, tetapi `WorkflowStatusService::transition:33-37` menolak apa pun yang gagal `WorkflowTransitions::isValid()` sebelum penulisan, dan `correctStatus` memeriksa ulang Manager/CEO di lapisan service — pertahanan berlapis yang nyata.
8. **Upload file** — `nullable|image|max:2048` (aturan `image` Laravel mengecualikan SVG); CSV `mimes:csv,txt|max:5120`; semua disimpan lewat `->store(...)` dengan nama ter-hash — **path traversal tidak mungkin**.
9. **`AuthAuditLog`** — hanya menyimpan `user_id`, `event`, `method`, `ip_address`, `user_agent`. Bersih.
10. **Secret di git** — `.env` tidak dilacak; `git log --all --diff-filter=A` menunjukkan file itu tidak pernah di-commit.
11. **Implementasi rujukan untuk ditiru** — `ProductionWorkflowController::index:47-49`, `ContentRevisionController::index:22-24`, `ContentPublicationController::index:23-25`, `ReportController::index:23-25`.

---

## 6. Bug Kode Terkonfirmasi

### BUG-01 — Pencarian global melempar 500, lalu ditelan senyap oleh UI
- **Severity: P1 / High** · CONFIRMED
- **Lokasi:** `SearchController.php:48`; frontend `components/topbar.blade.php:194-198`

`SearchController.php:48` memanggil nama route yang **tidak terdaftar di mana pun**:

```php
'url' => route('client-onboarding.show', $client),
```

Verifikasi:
```
Route::has('client-onboarding.show')  →  NO
Route::has('client-management.show')  →  YES
grep -rn "client-onboarding" app/ routes/
   → hanya 1 kecocokan: SearchController.php:48   (0 di routes/web.php)
```

Setiap jalur eksekusi yang mencapai baris ini melempar `RouteNotFoundException` → HTTP 500. Jalur itu tercapai ketika hasil pencarian klien tidak kosong — yaitu bagi user yang memang berhak melihat klien.

Lebih buruk, kegagalannya **tak terlihat** karena frontend menelannya:

```js
// topbar.blade.php:194-198
.catch(() => {
    if (currentRequest !== this.requestId) return;
    this.results = [];     // ← 500 berubah menjadi "Tidak ada hasil"
    this.loading = false;
});
```

**Dampak.** Kotak pencarian ada di topbar **setiap halaman**. User yang mencari klien diberi tahu "tidak ada hasil" untuk klien yang jelas-jelas ada — menggiring kesimpulan bahwa datanya hilang, padahal endpoint-nya crash. Bersamaan dengan itu, halaman debug dikirim ke browser (SEC-07/08).

**Perbaikan:** ganti menjadi `route('client-management.show', $client)`. Tambahan: gerbangi kategori klien dengan `hasPermissionTo('client','manage')` (kalau tidak, user menerima tautan yang berujung 403), dan tampilkan kegagalan fetch di UI alih-alih merendernya sebagai kekosongan. **Effort: S**

### LOGIC-01 — Konstanta `doneStatuses` diduplikasi 8× dengan satu pencilan
- **Severity: P1 / High** · CONFIRMED (diverifikasi ulang dari kode)

Definisi "sudah selesai" disalin ke **delapan file**, dan **satu di antaranya berbeda**:

```
app/Console/Commands/UpdateOverdueContentItems.php:14
    ['approved', 'scheduled', 'uploaded', 'cancelled']    ← 4 status  (PENCILAN — ini yang MENULIS flag)

app/Console/Commands/RecomputeDelayRiskScores.php:18      ['uploaded', 'cancelled']
app/Console/Commands/SendDelayRiskNotifications.php:16    ['uploaded', 'cancelled']
app/Http/Controllers/ContentItemController.php:15         ['uploaded', 'cancelled']
app/Http/Controllers/DashboardController.php:18           ['uploaded', 'cancelled']
app/Http/Controllers/TeamPerformanceController.php:16     ['uploaded', 'cancelled']
app/Services/DelayRiskPredictionService.php:21            ['uploaded', 'cancelled']
app/Services/UserWorkSummaryService.php:18                ['uploaded', 'cancelled']
```

Yang **menulis** flag `is_overdue` mengecualikan empat status; ketujuh yang **membaca** hanya mengecualikan dua. Secara struktural ini berarti item berstatus `approved` atau `scheduled` yang melewati deadline **tidak akan pernah ditandai overdue**, padahal setiap pembaca tetap memperlakukannya sebagai pekerjaan aktif. Query reset juga hanya membersihkan flag saat `deadline_at >= now()`, sehingga flag tidak pernah dibersihkan ketika pekerjaan benar-benar selesai.

Ini cacat kode murni: perilakunya salah untuk *setiap* dataset, bukan hanya dataset tertentu. Delapan salinan konstanta yang sama juga menjamin divergensi berikutnya hanya soal waktu.

**Perbaikan:** satu konstanta `WorkflowTransitions::DONE_STATUSES` dirujuk kedelapan tempat; bersihkan flag secara eksplisit saat workflow mencapai status selesai. Lebih baik lagi: ganti kolom denormalisasi dengan `scopeOverdue()` yang tidak bisa basi. **Effort: S**

### BUG-02 — Menghapus klien mempromosikan user-nya menjadi staf internal
- **Severity: P0 / Critical** · **Kategori:** Integritas Data · CONFIRMED
- **Lokasi:** `ClientManagementController::destroy()`; FK `users.client_id → clients.id ON DELETE SET NULL`

```php
$client->owner?->delete();      // hasOne — menghapus PALING BANYAK SATU user
$client->packages()->delete();
$client->delete();
```

`Client::owner()` adalah `hasOne(...)->whereHas('role', 'Client Owner')` sehingga hanya menghapus satu user. User lain dengan `client_id` yang sama tetap hidup, dan FK mengubah `client_id` mereka menjadi `NULL` — yang merupakan **definisi staf internal** di codebase ini (`User::isClientUser()`).

Konsekuensinya berantai: mereka lolos `EnsureInternalUser`, muncul di `AttendanceService::dailyRecords()`/`monthlySummary()` (keduanya menyeleksi `whereNull('client_id')`), serta di hitungan tim pada Dashboard dan roster Performa Tim. Ini kegagalan klasifikasi hak akses, bukan sekadar kosmetik.

**Masalah sekunder di method yang sama:** guard `hasHistory` hanya memeriksa `contentItems` dan `contentPlans`. `analytics_sync_logs.client_id` ber-`ON DELETE NO ACTION` sehingga klien yang punya sync log tetapi tanpa content item akan memicu MySQL 1451 → 500 tanpa penanganan. Sementara `api_integrations`, `audience_insights`, `generated_reports`, dan `ai_strategy_insights` semuanya CASCADE — menghancurkan riwayat analitik secara senyap tanpa memberi tahu operator.

**Perbaikan:** ganti dengan `$client->users()->delete()` (atau reassign/nonaktifkan); perluas `hasHistory`; tampilkan apa saja yang akan ikut terhapus pada langkah konfirmasi. **Effort: S**

### BUG-03 — `AttendanceService`: truncation float, cabang tanggal masa depan hilang, dan race condition
- **Severity: P1 / High** · CONFIRMED (diverifikasi dengan memanggil fungsi memakai objek Carbon buatan, tanpa membaca database)
- **Lokasi:** `app/Services/AttendanceService.php:105`, `:129-137`, `:47-70`

**(a) `lateMinutes()` dideklarasikan `: int` tetapi mengembalikan float bertanda dari Carbon 3.**

```php
public function lateMinutes(Attendance $attendance): int
{
    return $this->shiftStart($attendance->check_in_at->copy())
                ->diffInMinutes($attendance->check_in_at);
}
```

Hasil eksekusi langsung:
```
check-in 11:30      diffInMinutes = 30.0     type=double
check-in 10:45      diffInMinutes = -15.0    ← bertanda negatif
check-in 11:30:45   diffInMinutes = 30.75
  → PHP DIAGNOSTIC [8192]: Implicit conversion from float 30.75 to int loses precision
  → dikembalikan: int(30)
```

File ini tidak punya `declare(strict_types=1)`, jadi PHP melakukan koersi diam-diam. Karena check-in nyata membawa detik, praktis **setiap** catatan keterlambatan memicu `E_DEPRECATED` — yang oleh Laravel diarahkan ke channel `deprecations` (driver null secara default) dan **dibuang tanpa jejak**. Teks "Anda telat X menit" selalu dibulatkan ke bawah. Sisi negatifnya hanya tertutupi oleh early-return `check_in_status === 'late'` — penjaga yang menanggung beban besar tanpa didokumentasikan.

**(b) `statusLabel()` tidak punya cabang untuk tanggal masa depan.** Matriksnya mencakup akhir pekan, hari ini sebelum jam pulang, hari ini setelah jam pulang, dan masa lalu — tetapi **tidak masa depan**, sehingga jatuh ke `return 'Tidak Hadir'`:

```
statusLabel(hari kerja masa depan, null) = "Tidak Hadir"
```

`TeamPerformanceController.php:24` menerima parameter `date` tanpa batas atas, sehingga memilih tanggal mana pun di masa depan menandai **seluruh tim sebagai tidak hadir**.

**(c) `monthlySummary()` tidak punya penjaga "jam kerja belum berakhir"** yang dimiliki `dailyRecords()`. Akibatnya kedua tabel di layar yang sama saling bertentangan setiap pagi: yang satu menulis "Belum Check-In", yang lain sudah menghitungnya sebagai tidak hadir. Untuk bulan di masa depan, `$limit` jatuh sebelum `$start` sehingga loop tidak pernah jalan dan tabel tampil nol semua tanpa penjelasan. `Carbon::parse()` atas parameter `date`/`month` yang tak divalidasi juga berujung 500 pada input tak berformat.

**(d) Race condition check-in.** `checkIn()` melakukan baca (`today()`) lalu tulis tanpa penguncian. Constraint unique menangkapnya dengan benar, tetapi `AttendanceController` hanya menangkap `\RuntimeException` — sehingga `QueryException` lolos dan klik ganda menghasilkan **500** alih-alih pesan ramah.

**Perbaikan:** `(int) round(...->diffInMinutes(..., absolute: true))`; tambahkan cabang `isFuture()` beserta kelas badge-nya; samakan penjaga di `monthlySummary()`; pakai `firstOrCreate` atau tangkap `QueryException`; validasi kedua parameter request. **Effort: S**

**Terverifikasi BUKAN bug — zona waktu.** Dugaan ketidakcocokan UTC/WIB tidak terbukti: `app.timezone = Asia/Jakarta`, PHP dan MySQL sepakat hingga detik, `date` adalah kolom DATE sungguhan dan `check_in_at` bertipe `datetime` (bukan `timestamp`) sehingga tidak ada konversi implisit. Catatan: MySQL `@@time_zone = SYSTEM` — sebaiknya dipatok eksplisit di produksi.

### LOGIC-02 — Penyebut rasio overdue menyertakan pekerjaan yang sudah selesai
- **Severity: P2 / Medium** · CONFIRMED · `DashboardController.php:32-34`

```php
$overdueCount  = ContentWorkflow::where('is_overdue', true)->count();
$totalWorkflow = ContentWorkflow::count();
$overdueRate   = $totalWorkflow > 0 ? round(($overdueCount / $totalWorkflow) * 100, 1) : 0;
```

Penyebutnya mencakup workflow `uploaded` dan `cancelled`, yang secara definisi tidak mungkin overdue. Rasio ini karenanya **selalu terdilusi oleh riwayat** dan akan cenderung menuju nol seiring waktu, terlepas dari performa tim sebenarnya. Penjaga pembagian nol-nya sendiri sudah benar.

**Perbaikan:** batasi pembilang dan penyebut ke `whereNotIn('current_status', DONE_STATUSES)`. **Effort: S**

### LOGIC-03 — Anak dari content item tidak sadar soft-delete
- **Severity: P2 / Medium** · CONFIRMED (laten) · `ContentItem.php:11` vs `ContentWorkflow`/`ContentRevision`/`ContentMetric`/`ContentStatusLog`

`ContentItem` memakai `SoftDeletes`, tetapi seluruh model anak tidak. Query mana pun yang berangkat dari `ContentWorkflow` alih-alih `ContentItem` melewati global scope — misalnya `DashboardController.php:32`, `NextStepsService.php:59,68`, `DelayRiskAccuracyService.php:32`. Selain itu FK `content_workflows.content_item_id` ber-CASCADE, tetapi soft delete tidak pernah memicu cascade sehingga baris workflow bertahan selamanya.

**Perbaikan:** tambahkan `->whereHas('contentItem')` pada query yang berangkat dari workflow, atau terapkan `SoftDeletes` pada model anak. **Effort: M**

### LOGIC-04 — `NextStepsService` memotong 3 teratas dengan urutan prioritas tetap
- **Severity: P2 / Medium** · CONFIRMED · `NextStepsService.php:76` — `return array_slice($steps, 0, 3);`

Langkah ditambahkan dengan urutan tetap: persetujuan rencana → persetujuan workflow → brief copywriter → tugas overdue *milik sendiri* → revisi *milik sendiri*. Manager yang memegang kedua izin persetujuan dan punya antrean tidak kosong **tidak akan pernah** melihat dua item paling personal dan paling bisa ditindaklanjuti, karena keduanya selalu berada di posisi 4 dan 5. Selain itu Client Owner tidak cocok dengan satu pun gerbang izin dan menerima array kosong tanpa pesan fallback.

**Perbaikan:** beri skor lalu urutkan berdasarkan urgensi sebelum memotong; sediakan langkah default. **Effort: S**

### LOGIC-05 — `array_search` mengembalikan `false` untuk status tak dikenal
- **Severity: P3 / Low** · CONFIRMED · `ProductionWorkflowController.php:91`

```php
'status' => fn ($item) => array_search($item->workflow->current_status, $this->statuses),
```

`array_search` mengembalikan `false` (bukan `-1`/`null`) bila tidak ditemukan. Dalam komparator sort, `false` dikoersi menjadi `0`, sehingga status di luar delapan yang dikenal akan diurutkan **mendahului** `brief_ready`. Karena `current_status` adalah varchar tanpa constraint (SCHEMA-02), kondisi ini terjangkau.

**Perbaikan:** `array_search(...) ?: PHP_INT_MAX`, atau gunakan peta ordinal eksplisit. **Effort: S**

### LOGIC-06 — `DelayRiskAccuracyService` mengukur presisi, bukan akurasi, dan mempercayai key tanpa validasi
- **Severity: P2 / Medium** · CONFIRMED · `DelayRiskAccuracyService.php:69-75`

`high_risk_accuracy = high.late / high.total` sebenarnya adalah **presisi kelas high-risk**; ia mengabaikan false negative sepenuhnya. Model yang melabeli satu item `high` dan benar akan mencetak 100%, sementara setiap item `low` yang ternyata terlambat tidak terlihat sama sekali.

Selain itu `$breakdown[$scoreBeforeUpload->risk_level]['total']++` memakai nilai yang berasal langsung dari proses Python eksternal (`DelayRiskPredictionService::callPredictScript()`, baris 172: `json_decode($result->output(), true) ?? []`) **tanpa validasi apa pun**. Nilai di luar `high|medium|low` akan membuat bucket keempat secara senyap. Kolom `delay_risk_scores.risk_score` juga bertipe `tinyint unsigned` (0–255): bila model Python kelak mengeluarkan probabilitas 0–1, seluruh skor terpotong menjadi `0`.

**Perbaikan:** ganti nama menjadi `high_risk_precision`, tambahkan angka recall, dan validasi payload Python terhadap `['high','medium','low']` + rentang 0–100 sebelum disimpan. **Effort: M**

---

## 7. Audit Performa (bersifat algoritmik, bukan pengukuran data)

Karena skala data saat ini tidak representatif, temuan berikut dirumuskan sebagai **kompleksitas kode** — biaya yang tumbuh mengikuti data produksi nanti.

### PERF-01 — Pemeriksaan permission menembak database setiap kali dipanggil
- **Severity: P1 / High** · CONFIRMED · `app/Models/Role.php:23-29`

```php
public function hasPermission(string $module, string $action): bool
{
    return $this->permissions()->where('module',$module)->where('action',$action)->exists();
}
```

Tidak ada memoisasi maupun eager-load. Setiap pemanggilan adalah satu query — termasuk pemanggilan berulang untuk pasangan modul/aksi yang **sama persis** dalam satu request. Sidebar (`components/sidebar.blade.php:46-56`) memanggilnya sekali per item menu, ditambah pemeriksaan middleware dan pemeriksaan per-view seperti `canUpdateWorkflow`. Biayanya konstan per render halaman, tetapi seluruhnya dapat dihilangkan.

**Perbaikan:** eager-load `role.permissions` sekali lalu periksa di memori:
```php
public function hasPermissionTo(string $m, string $a): bool {
    return ($this->permCache ??= $this->role?->permissions ?? collect())
        ->contains(fn ($p) => $p->module === $m && $p->action === $a);
}
```
**Effort: S**

### PERF-02 — Query per-iterasi di dalam loop (N+1)
- **Severity: P1 / High** · CONFIRMED · `TeamPerformanceController::index()`

Loop `$members->map()` menjalankan `ContentRevision::whereIn(...)->count()` **dan** agregat `DelayRiskScore` dengan subquery terkorelasi **untuk setiap user**. Biayanya O(n) terhadap jumlah anggota tim: setiap staf baru menambah dua query permanen ke halaman tersebut.

**Perbaikan:** dua query agregat ber-`groupBy('user_id')` sebelum loop, lalu petakan hasilnya. **Effort: M**

### PERF-03 — Papan Kanban memuat seluruh item tanpa paginasi, dan merender markup ganda
- **Severity: P2 / Medium** · CONFIRMED · `ProductionWorkflowController.php:64`

`$allItems = $itemsQuery->get()` mengambil seluruh content item yang cocok tanpa batas. Setiap item lalu dirender **dua kali** di HTML — sekali sebagai kartu Kanban desktop dan sekali sebagai kartu accordion mobile — yang dipilih lewat `hidden sm:block` / `sm:hidden`. Berat halaman karenanya tumbuh linear terhadap jumlah item, dengan faktor pengali dua, pada halaman yang paling sering dibuka staf produksi di ponsel.

**Perbaikan:** paginasi/virtualisasi papan; pertimbangkan merender hanya varian yang aktif. **Effort: M**

### PERF-04 — Indeks hilang pada jalur akses utama
- **Severity: P2 / Medium** · CONFIRMED via `EXPLAIN` (struktur indeks, bukan isi data)

| Query | Rencana eksekusi |
|---|---|
| `content_workflows WHERE current_status = ?` | `type=ALL`, `key=NULL` — full scan; dipanggil sekali per kolom Kanban |
| `content_workflows WHERE is_overdue = 1` | `type=ALL`, `key=NULL` |
| `content_items ... ORDER BY deadline_at` | `Using filesort` |
| `notifications WHERE user_id=? AND is_read=0 ORDER BY created_at` | `Using filesort` (hanya `user_id` terindeks) |

Selain itu `AttendanceService::dailyRecords():155` memakai `whereDate('date', ...)` yang membungkus kolom dalam fungsi sehingga **secara permanen melarang pemakaian indeks** — padahal `date` sudah bertipe DATE, jadi pembungkusnya tidak perlu.

**Perbaikan, satu migrasi:** `content_workflows(current_status, is_overdue)`, `content_items(client_id, deadline_at)`, `notifications(user_id, is_read, created_at)`, `attendances(date)`; ubah `whereDate` → `where`. **Effort: S**

---

## 8. Audit Aksesibilitas

**Ini dimensi terlemah produk.** Beberapa kegagalan berada di WCAG Level A dan berdampak ke setiap halaman. Seluruh temuan di bagian ini murni analisis markup.

### A11Y-01 — `layouts/app.blade.php` tanpa `<!DOCTYPE>` dan tanpa `<html lang>`
- **Severity: P1 / High** · CONFIRMED · WCAG 3.1.1 (Level A)

File dimulai pada baris 1 dengan `<head>` dan berakhir pada baris 321 dengan `</body>`. Tidak ada doctype, tidak ada `<html lang="id">`, tidak ada `</html>`. **Seluruh halaman terautentikasi — 40+ view — terdampak.** Screen reader jatuh ke bahasa default sistem dan melafalkan teks Indonesia dengan fonem Inggris; browser juga berpotensi masuk quirks mode.

Setiap view *standalone* justru sudah benar (`auth/login:1-2`, `welcome:1-2`, `errors/403:1-2`) — hanya layout utamanya yang terlewat.

**Perbaikan:** 2 baris. **Effort: S — rasio manfaat per karakter tertinggi di seluruh laporan ini.**

### A11Y-02 — 11 tabel inti tidak dapat dioperasikan keyboard
- **Severity: P1 / High** · CONFIRMED · WCAG 2.1.1 (Level A)

`client-management/index:58` · `content-plan/index:105` · `content-plan/show:110` · `home/index:85` · `home/index-copywriter:37` · `profile/show:116` · `profile/show-copywriter:68` · `list-tab:45` · `revisions-tab:55` · `revision-log:63` · `tab-performa:84`

```html
<tr class="… cursor-pointer" onclick="window.location='{{ route('content-items.show', $item) }}'">
```

`<tr>` tidak fokusabel, tanpa `tabindex`, tanpa `role="link"`, tanpa handler keyboard. Pengguna keyboard **tidak dapat membuka satu baris pun di 11 tabel utama**, dan pada beberapa halaman tidak ada jalur alternatif menuju halaman detail.

**Pola yang benar sudah ada di codebase** — `published-tab:48-49` dan `publishing-tracker:56-57` memakai `<a href>` sungguhan di sel pertama tanpa `onclick` pada baris; accordion mobile pun merender `<a>Lihat Detail</a>` (`list-tab:201`). Hanya belum diterapkan ke baris desktop. **Effort: M**

### A11Y-03 — `#9aa0a4` rasio 2.64:1 dan menyandang sepertiga teks aplikasi
- **Severity: P1 / High** · CONFIRMED (rasio dihitung)

| Depan | Latar | Rasio | AA normal (4.5:1) |
|---|---|---:|---|
| `#14181a` | `#ffffff` | 17.87:1 | ✅ |
| `#5c6266` | `#ffffff` | 6.19:1 | ✅ |
| **`#9aa0a4`** | **`#ffffff`** | **2.64:1** | ❌ |
| **`#9aa0a4`** | **`#f7f8fc`** | **2.49:1** | ❌ |
| **`#c3c7cb`** | **`#ffffff`** | **1.70:1** | ❌ |
| **`#b8873a`** | **`#fdf6ec`** | **2.98:1** | ❌ |
| `#ffffff` | `#044b46` | 10.00:1 | ✅ |

`#9aa0a4` dipakai untuk **setiap header tabel** (ukuran 11px di atas `#f7f8fc` = 2.49:1), **setiap label form**, **setiap pesan empty state**, dan badge "Dijeda/Draft/Nonaktif". `#c3c7cb` (1.70:1) menyandang teks "Drop cards here" — satu-satunya teks di kolom Kanban kosong — serta seluruh petunjuk "Belum ada…".

**Perbaikan:** `#9aa0a4` → `#767c80` dan `#c3c7cb` → `#767c80` **khusus untuk teks**; pertahankan warna asli untuk glyph dekoratif dan placeholder input. `#b8873a` → `#8a6423` untuk teks di atas `#fdf6ec`. **Effort: M**

### A11Y-04 — 67 label tanpa asosiasi, 20 gambar tanpa `alt`
- **Severity: P1 / High** · CONFIRMED · WCAG 1.3.1 / 3.3.2

Terdapat 73 elemen `<label>`; **nol** memakai `for=`/`id=` (6 di antaranya membungkus input sehingga sudah benar). Screen reader mengumumkannya sebagai field tanpa nama — pengguna tunanetra yang mengisi form "Tambah Client Baru" 7 field tidak tahu field mana yang mana.

20 dari 29 `<img>` tanpa `alt` — seluruhnya avatar dan logo klien. Screen reader membacakan URL avatar mentah (100+ karakter) per baris. Ironisnya, **avatar fallback berupa `<div>` berisi inisial justru terbaca baik**, sehingga pengalaman pengguna yang mengunggah foto malah lebih buruk.

### A11Y-05 — 11 modal tanpa `role="dialog"`, tanpa focus trap, hanya 1 menutup dengan Escape
- **Severity: P1 / High** · CONFIRMED

Hanya `analytics/index:431` yang punya `x-on:keydown.escape.window`. Tidak satu pun memiliki `role="dialog"`/`aria-modal`, tidak ada yang menjebak fokus (Tab berpindah ke *belakang* overlay), tidak ada yang memindahkan fokus saat dibuka. Termasuk dialog konfirmasi global di `layouts/app:162-184`, yang satu-satunya jalan keluarnya adalah klik presisi.

**Catatan:** `x-trap` memerlukan `@alpinejs/focus` yang **belum dimuat** — tambahkan script CDN-nya *sebelum* Alpine core. Lebih baik lagi: satukan 11 modal menjadi satu komponen `<x-modal>`. **Effort: M**

### A11Y-06 / A11Y-07 / A11Y-08 — ringkasan
- **~20 header accordion mobile berupa `<div>` yang diklik**, bukan `<button>` → tidak fokusabel, tanpa `aria-expanded`. Di mobile, accordion ini satu-satunya cara melihat Klien/Deadline/PIC. Pola benar sudah dipakai di `published-tab:88`.
- **3 halaman tanpa `<h1>`** — `home/index`, `home/index-copywriter`, `content-items/show` (halaman detail yang paling sering dibuka). Beberapa halaman melompat `h1 → h3`.
- **Target sentuh:** chevron accordion 28×28 di 10 layar mobile; tombol aksi 32×32 di `user-management:286,292,297`. Termitigasi jika seluruh header dapat disentuh — tetapi **tidak** di `published-tab:88` / `publishing-tracker:93`, di mana chevron adalah satu-satunya pemicu.

---

## 9. Audit Konsistensi UI

Akar masalah: **Tailwind via CDN tanpa konfigurasi berarti tidak ada component layer.** Setiap tombol, badge, input, dan header tabel ditulis tangan, sehingga design system-nya hanya hidup sebagai konvensi — dan sudah melenceng. Menariknya, satu-satunya komponen yang *diabstraksikan* (`.card` di `layouts/app.blade.php:52-57`) justru **tidak** melenceng.

### UI-01 — Tombol primer: 11 varian untuk satu komponen

| Padding | Weight | Radius | Jumlah | Contoh |
|---|---|---|---:|---|
| **`px-5 py-2.5`** ← dominan | `font-medium` | `rounded-lg` | 12 | `client-management/index:14` |
| `px-6 py-2.5` | `font-medium` | `rounded-lg` | 4 | `client-management/edit:153` |
| `px-4 py-2.5` | `font-medium` | `rounded-lg` | 5 | `content-plan/show:54` |
| `px-4 py-2` | `font-medium` | `rounded-lg` | 3 | `analytics/index:17` |
| `px-5 py-2.5` | **`font-semibold`** | `rounded-lg` | 2 | `attendance-widget:16` |
| `py-3.5` full-width | `font-medium` | **`rounded-xl`** | 2 | `auth/login:48` |
| …5 varian lain | | | | |

Kasus paling jelas: **`client-management/index:14` (`px-5`) vs `edit:153` (`px-6`) vs `content-plan/show:54` (`px-4`)** — tiga CTA dalam satu fitur, tiga lebar berbeda. Ditambah 2 warna hover danger berbeda (`#96352f` ×3, `#9c3733` ×2) dan **4 gaya danger** tanpa aturan — `content-plan/show:64` merender "Reject" sebagai **tautan teks polos** di sebelah tombol solid "Approve Plan", sehingga aksi destruktifnya nyaris tidak terbaca sebagai tombol.

### UI-02 — Judul halaman terbelah 4 tingkat; 2 halaman memakai warna deskripsi yang gagal kontras

9 halaman memakai standar `text-[26px] sm:text-[32px]`; 7 memakai `text-2xl`; 2 memakai `text-[28px]` tetap; 2 memakai `text-[26px]` tetap. **`client-management/index:9` (32px) → `client-management/show:22` (24px)** berarti judul justru *mengecil* saat masuk ke detail entitas yang sama. Selain itu `revision-log:9` dan `publishing-tracker:9` memakai `text-[#9aa0a4]` untuk deskripsi halaman — **2.64:1, gagal AA** — sementara halaman lain memakai `#5c6266` (6.19:1).

### UI-03 — Tiga arketipe tabel yang tidak saling kompatibel

- **A (18 tabel):** thead `bg-[#f7f8fc]`, `px-6 py-3`/`px-4 py-3`, `border-t`
- **B (2 tabel):** thead abu, `px-6 py-3.5` seragam, `border-b` — `client-management/index:45`, `user-management/index:27`
- **C (6 tabel):** **tanpa latar thead**, hanya `pb-2.5` — `dashboard/index:108,180,251`, `client-management/show:67`, `analytics/index:569`, `report/index:131`

Pengguna melihat tiga tabel arketipe C di Dashboard, lalu berpindah ke Kelola Klien dan mendapat arketipe B. Di dalam tier C sendiri padding-nya `pb-2` / `pb-2.5` / `pb-3 pr-4` — tiga nilai untuk satu tier.

### UI-04 — 14 varian badge; status yang sama tampil beda ukuran antar halaman

```
home/index:90         text-xs font-medium px-2.5 py-1 rounded-full    ← Beranda
dashboard/index:127   text-xs             px-2   py-1 rounded-full    ← Dashboard (tanpa font-medium)
content-items/show:47 text-xs font-medium px-3   py-1.5 rounded-full  ← detail (lebih besar)
```

Badge status adalah token pengenal; merender "Sedang Dikerjakan" dalam tiga ukuran di tiga halaman meniadakan kemudahan pindai yang justru menjadi alasan keberadaannya. `settings/index:71` bahkan menciptakan badge **8px** (`text-[8px]`) demi bertahan pada `grid-cols-3` yang dipaksakan di mobile.

### UI-05 — Indikator fokus: varian dominan justru yang terlemah
- **Severity: P1 / High** (beririsan aksesibilitas) · WCAG 2.4.7

108 kemunculan `focus:outline-none` di 29 file; hanya 9 yang memasangkannya dengan `focus:ring-2` (semuanya di `analytics/*`). Pola dominan **mematikan focus ring bawaan browser dan menggantinya dengan pergeseran border 1px** dari `#eef0f4` ke hijau opasitas 40%. Pengguna keyboard yang menyusuri form tambah klien 9 field praktis tidak punya indikator fokus. Tim sudah menulis pola yang benar — hanya berada di 3 dari 29 file.

### UI-06 — Umpan balik validasi tidak konsisten, dan satu modal membuang input pengguna

| File | Border merah saat error | Pesan `@error` |
|---|---|---|
| `client-management/create` (7 field) | ✅ semua | ✅ semua |
| `client-management/edit` (7 field) | ❌ tidak ada | ✅ semua |
| `content-plan/create-item` (9 field) | ❌ tidak ada | ❌ **tidak ada** |
| `partials/urgent-content-modal` (7 field) | ❌ tidak ada | ❌ **tidak ada** |

Kasus terburuk: modal Jobdesk Tambahan memakai `x-data="{ urgentOpen: false }"` tanpa `$errors->any()` untuk membuka ulang dan tanpa `old()` untuk mengisi ulang — **kegagalan validasi menutup modal dan membuang seluruh isian pengguna**, dengan satu-satunya umpan balik berupa halaman yang me-reload. Pola perbaikannya sudah ada di `content-plan/index:4` dan `user-management/index:5`.

### Perbaikan UI dengan daya ungkit tertinggi

Menambahkan lima aturan ke blok `<style>` yang sudah ada di `layouts/app.blade.php` (bersebelahan dengan `.card`) — `.btn-primary`, `.btn-secondary`, `.btn-danger`, `.input`, `.badge` — meruntuhkan **UI-01…06 dan A11Y-01** menjadi segelintir suntingan dan mencegahnya berulang. File itu sudah membuktikan polanya berhasil.

---

## 10. Audit Responsive

**Ini dimensi terkuat** — pengerjaan terbaru cermat dan hasilnya terlihat.

**Terverifikasi baik:**
- `calendar-grid:67-113` mengganti grid bulan 7 kolom dengan **agenda vertikal khusus** di mobile, lengkap dengan komentar alasan di kode. Ini *redesign* responsif sungguhan, bukan sekadar dipaksa muat.
- 18 tabel memiliki pasangan `hidden sm:block` (desktop) + `sm:hidden` (accordion).
- Seluruh 12 modal memakai `p-4` pada overlay sehingga tidak menempel tepi layar.
- Lebar tetap (`w-[290px]`, `min-w-[700px]`, `w-[180px]`, `w-[150px]`) semuanya terkurung dengan benar oleh `overflow-x-auto`, `flex-wrap`, atau penjaga `sm:`.
- `prefers-reduced-motion` dihormati (`layouts/app:110-114`); `x-cloak` didefinisikan dan dipakai konsisten (90+ lokasi).

**Celah:**

| ID | Severity | Temuan |
|---|---|---|
| RESP-01 | High | `analytics/_table-section.blade.php:62-123` adalah **satu-satunya tabel besar tanpa penanganan mobile** — 8 kolom, seluruhnya `whitespace-nowrap`. Ia berada di halaman Performa yang dua tab lainnya *sudah* responsif. |
| RESP-02 | Medium | `grid-cols-3` dipaksakan di mobile pada `settings/index:64`, lalu diakali dengan `text-[8px]`, `truncate`, `hidden sm:block`, dan penggantian label jadi "OK"/"Off" — empat tambalan untuk bertahan dari satu keputusan layout. |
| RESP-03 | Medium | `production-workflow:214-255` — popover penanggung jawab hanya `mouseenter`, sehingga **di perangkat sentuh tidak ada cara melihat siapa yang ditugaskan** tanpa membuka halaman detail. Perbaikan satu baris: tambah `x-on:click`. |
| RESP-04 | Medium | 9 modal tanpa `max-h`/`overflow-y-auto`. Terburuk `content-items/show:412-465`: dalam orientasi landscape di ponsel, kedua tombol aksinya terpotong di luar layar tanpa bisa di-scroll. Dua modal lain sudah benar. |
| RESP-05 | Low | Toast `fixed top-6 right-6` tanpa `max-w`; string terpanjang meluber di viewport sempit. |
| RESP-06 | Low | Kanban `h-[calc(100vh-64px)]` — `100vh` menyertakan area URL bar mobile; gunakan `100dvh`. |

---

## 11. UX & Arsitektur Informasi

### UX-01 — Jargon dan label Inggris untuk audiens non-teknis

Sidebar sepenuhnya berbahasa Indonesia; halaman tujuannya tidak. `client-management/index.blade.php` saja mencampur keduanya **dalam satu layar**: `Kelola Klien` (h1, :9), `Tambah Klien` (:16), `Cari klien…` (:28) — tetapi `Nama Client` (`<th>`, :47), "Tidak ada client yang cocok" (:101), "Tambah client pertama" (:180).

| Istilah | Lokasi | Mengapa menyulitkan | Saran |
|---|---|---|---|
| **Delay Risk `72%`** | `home/index:69,101`, `profile/show:100`, `list-tab:28` | Staf tidak bisa tahu 72% itu baik atau buruk, dan persentase *dari apa*. Penjelasannya (`$risk->top_factor`) disembunyikan di atribut `title` — **tidak muncul sama sekali di perangkat sentuh**. | "Risiko Terlambat — Tinggi 72%", tampilkan `top_factor` sebagai teks |
| **Content Item** | 15 lokasi, termasuk semua header tabel tugas | Kata benda inti aplikasi, tak diterjemahkan. `topbar:202` sendiri sudah memetakannya ke "Konten". | Konten |
| **Overdue** | `home/index:22` (kartu KPI), `tab-performa:75` | Salah satu dari empat angka pertama yang dilihat pengguna | Terlambat |
| **Client vs Klien** | 13 header tabel vs navigasi Indonesia | Terbaca seolah dua entitas berbeda | Klien |
| **PIC** | `list-tab:167` (mobile) | Versi **desktop** tabel yang sama menulis "Penanggung Jawab" (`:26`) — file sama, berjarak 141 baris | Penanggung Jawab |
| **Enum mentah bocor** | `home/index:91`, `analytics/show:187` | `?? $current_status` jatuh ke `brief_ready`, `waiting_review`; `$log->status` mencetak `success`/`failed` tanpa peta label | Peta label + fallback `Str::headline()` |

Modul **Analytics** hampir seluruhnya Inggris (`Performance Table`, `Audience`, `Last 30 Days`, `Views Over Time`, `Traffic per Platform`, `Age Range`, `Top Locations`) — padahal *body copy*-nya Indonesia kolokial yang hangat, menghasilkan lompatan register yang tajam. `analytics/show:68-77` menaruh "Total Views", "Avg. Engagement", dan "Hari Terlacak" dalam satu baris.

### UX-02 — Kegagalan senyap dan umpan balik yang hilang

- **500 pada pencarian dirender sebagai "tidak ada hasil"** (BUG-01) — pengguna secara aktif diberi informasi keliru.
- **`ai-brief-discussion:79`** — panggilan Gemini yang memakan 5–15 detik **tanpa spinner, tanpa indikator mengetik, tanpa bubble optimistis**; hanya tombol kirim yang nonaktif. Chat AI *lainnya* (`analytics/index:387-395`) punya animasi titik-titik. Dua permukaan AI, kualitas persepsi berlawanan.
- **`ai-brief-discussion:104-123`** — `applyChanges()` memanggil `form.submit()` yang **tidak memicu event `submit`**, sehingga `#top-loading-bar` global tidak pernah muncul pada navigasi halaman penuh.
- **Penolakan drop di Kanban** — toast tanpa `role="status"`/`aria-live`, dan saat gagal modalnya *menutup*, membuang catatan revisi yang baru diketik.
- **`tab-performa:82,130`** memakai `@foreach` **tanpa `@empty` dan tanpa penjaga** — pada kondisi kosong ia merender baris header di atas ruang putih. Saudaranya `tab-kehadiran:58` melakukannya dengan benar.

### UX-03 — Usulan penyederhanaan alur

**Menambah konten mendesak — sekarang:**
```
Produksi → modal "Jobdesk Tambahan" → 7 field → submit
  → validasi gagal → MODAL TERTUTUP, SELURUH INPUT HILANG → ulang dari awal
```
**Disarankan:**
```
Produksi → modal (terbuka lagi saat error, old() terisi, @error per field) → submit → toast sukses
```

**Melihat penanggung jawab kartu Kanban di mobile — sekarang:**
```
sentuh avatar → tidak terjadi apa-apa (hover-only) → buka halaman detail → scroll → kembali
```
**Disarankan:** tambahkan `x-on:click` pada popover yang sudah ada — 1 baris, menghapus 3 langkah memutar.

---

## 12. Audit Tugas End-to-End

| # | Role | Tugas | Hasil | Nilai |
|---|---|---|---|---|
| 1 | Manager | Cari klien dari topbar | **HTTP 500**, UI menampilkan "tidak ada hasil" | **Critical** |
| 2 | Manager | Akses seluruh route sesuai role | Matriks 200/403 benar seluruhnya (7 role × 12 route) | **Excellent** |
| 3 | Client Owner | Setujui konten sendiri; coba konten klien lain | Hanya miliknya — `abort_unless` di ketiga method | **Excellent** |
| 4 | Content Creator | Buka konten klien lain lewat ID | **200 + data penuh + hak mengubah** | **Critical** |
| 5 | User internal | Buka `/profile/{copywriter}` | **Antrean brief seluruh organisasi, tanpa gerbang izin** | **Critical** |
| 6 | Manager | Hapus klien yang punya user tambahan | **User berubah menjadi staf internal** | **Critical** |
| 7 | Siapa pun | Check-in / check-out | Truncation float; tanggal masa depan = tidak hadir; klik ganda = 500 | **Poor** |
| 8 | Pengguna keyboard | Buka baris di tabel inti mana pun | **Tidak ada fokus, tidak ada handler — mustahil** di 11 tabel | **Critical** |
| 9 | Pengguna screen reader | Kenali field di form tambah klien | "edit text, blank" ×7 | **Poor** |
| 10 | Pengguna mobile | Lihat penanggung jawab kartu Kanban | Popover hover-only — tak terjangkau | **Needs Improvement** |
| 11 | Manager | Pakai tab Performance Table di ponsel | Tabel 8 kolom tanpa varian mobile | **Needs Improvement** |
| 12 | Manager | Buat klien beserta logo + owner | Berjalan; panel preview dan "yang terjadi setelah submit" bagus | **Excellent** |
| 13 | Manager | Geser kartu di papan Kanban | Berjalan; satu jalur service, guard tepat | **Good** |

---

## 13. Daftar Isu

Severity: **P0** kritis · **P1** tinggi · **P2** sedang · **P3** rendah. Effort: **S/M/L/XL**.

| ID | Sev | Kategori | Lokasi | Masalah | Dampak | Effort |
|---|---|---|---|---|---|---|
| SEC-01 | P0 | Keamanan | `ContentItemController.php:17-200` | Tanpa scope klien di semua route ber-binding | Baca/ubah konten klien mana pun | M |
| SEC-02 | P0 | Keamanan | `routes/web.php:206`; `UserWorkSummaryService.php:29` | Tanpa permission mw; parameter `$user` diabaikan | Bocornya antrean brief se-organisasi | M |
| BUG-02 | P0 | Integritas Data | `ClientManagementController::destroy` | `owner?->delete()` melewatkan user lain | Eks-user klien jadi staf internal | S |
| ARCH-01 | P1 | Arsitektur | `ProductionWorkflowController:47` vs `UserWorkSummaryService:40` | Dua model visibilitas bertentangan | Beranda & Produksi bisa berbeda isi | M |
| ARCH-02 | P1 | Arsitektur | seluruh aplikasi | Tanpa Policy/Gate | Otorisasi objek tak punya rumah | M |
| A11Y-02 | P1 | Aksesibilitas | 11 file (`<tr onclick>`) | Baris tak dapat dioperasikan keyboard | Tabel inti mati bagi pengguna keyboard | M |
| A11Y-01 | P1 | Aksesibilitas | `layouts/app.blade.php:1` | Tanpa `<!DOCTYPE>` / `<html lang="id">` | 40+ halaman; bahasa SR salah | S |
| A11Y-03 | P1 | Aksesibilitas | seluruh palet | `#9aa0a4` 2.64:1, `#c3c7cb` 1.70:1 | ⅓ teks gagal AA | M |
| SEC-03 | P1 | Keamanan | `ClientMagicLinkController.php:91-99` | Token mentah + session ID ditulis ke log | Pengambilalihan akun klien | S |
| BUG-01 | P1 | Fungsional | `SearchController.php:48` | Nama route tak terdaftar | 500 di pencarian, ditelan senyap | S |
| LOGIC-01 | P1 | Logika Bisnis | 8 file (`doneStatuses`) | Konstanta terduplikasi, 1 pencilan | Item `approved`/`scheduled` tak pernah ditandai | S |
| SEC-04 | P1 | Keamanan | `AnalyticsController.php` (12 lokasi) | `client_id` mentah, dropdown tak dibatasi | Analitik + penulisan lintas klien | M |
| SEC-05 | P1 | Keamanan | `ContentPlanController.php` (6 lokasi) | Tanpa scoping di lapisan mana pun | Rencana semua klien terlihat & dapat dibuat | M |
| SEC-06 | P1 | Keamanan | `ContentBriefController.php:22-192` | Tanpa pemeriksaan kepemilikan | Manipulasi brief; biaya AI tak terbatas | M |
| SEC-07 | P1 | Keamanan | `SearchController.php:48` + `APP_DEBUG` | Stack trace ke browser | Pengintaian internal saat pemakaian normal | S |
| SEC-08 | P1 | Keamanan | `.env` / `.env.example` | Debug menyala secara default | Memperkuat setiap exception | S |
| BUG-03 | P1 | Logika Bisnis | `AttendanceService.php:105,129,47` | Float→int; tanpa cabang masa depan; race | Menit salah; tim ditandai absen; 500 | S |
| PERF-01 | P1 | Performa | `Role.php:23-29` | Permission tanpa cache | Query per pemanggilan | S |
| PERF-02 | P1 | Performa | `TeamPerformanceController::index` | Query per-user di dalam loop | Biaya O(n) terhadap jumlah staf | M |
| A11Y-04 | P1 | Aksesibilitas | 16 file, 67 label | Tanpa `for`/`id` | Field tak diumumkan | M |
| A11Y-05 | P1 | Aksesibilitas | 11 modal | Tanpa dialog role/trap/Escape | Modal mati bagi keyboard/SR | M |
| UI-05 | P1 | UI/A11y | 108 lokasi | `focus:outline-none` + border 1px | Fokus tak terlihat | M |
| RESP-01 | P1 | Responsive | `_table-section.blade.php:62` | Tanpa varian mobile | Tabel 8 kolom di ponsel | M |
| ARCH-03 | P2 | Arsitektur | `attendance-widget.blade.php:2-6` | Query di dalam view | Bibit N+1; controller kehilangan kendali | S |
| LOGIC-02 | P2 | Logika Bisnis | `DashboardController.php:32-34` | Penyebut termasuk yang selesai | Rasio overdue menuju nol | S |
| LOGIC-03 | P2 | Integritas Data | `ContentWorkflow` dkk | Tak sadar soft-delete | Item terhapus tetap dihitung | M |
| LOGIC-04 | P2 | Logika Bisnis | `NextStepsService.php:76` | Urutan tetap + potong 3 | Tugas personal tak pernah tampil | S |
| LOGIC-06 | P2 | Logika Bisnis | `DelayRiskAccuracyService.php:69` | Presisi disebut akurasi; key tanpa validasi | Metrik menyesatkan; bucket liar | M |
| UI-03 | P2 | Konsistensi | 26 tabel | 3 arketipe | Terasa seperti 3 produk berbeda | M |
| UI-04 | P2 | Konsistensi | 14 varian badge | Status sama, ukuran beda per halaman | Meniadakan kemudahan pindai | M |
| UI-01 | P2 | Konsistensi | 11 varian tombol | Aksi sama, padding beda | Ketidakpaduan visual | M |
| UI-06 | P2 | UX | `urgent-content-modal`, `create-item` | Tanpa `@error`, modal tak dibuka ulang | **Input pengguna hilang senyap** | M |
| PERF-03 | P2 | Performa | `ProductionWorkflowController:64` | Tanpa paginasi; markup ganda | Berat halaman tumbuh 2× linear | M |
| PERF-04 | P2 | Performa | 4 tabel | Indeks hilang | Full scan di jalur utama | S |
| SEC-09 | P2 | Keamanan | `ReportController.php:35-52` | `client_id` nullable | Ekspor lintas organisasi | S |
| SEC-10 | P2 | Keamanan | Tulis revisi/publikasi | Tanpa scoping | Memblokir persetujuan klien lain | S |
| SEC-11 | P2 | Keamanan | Kedua alur auth | Remember 5 thn + tanpa secure | Kredensial persisten dapat disadap | S |
| UX-02 | P2 | Umpan Balik | `ai-brief-discussion:79,104` | Tanpa loading state | Hening 5–15 detik | S |
| A11Y-06 | P2 | Aksesibilitas | ~20 accordion | `<div>` bukan `<button>` | Baris mobile tak bisa dibuka keyboard | M |
| A11Y-07 | P2 | Aksesibilitas | 3 halaman | Tanpa `<h1>` | Navigasi landmark SR gagal | S |
| RESP-04 | P2 | Responsive | 9 modal | Tanpa `max-h` | Tombol terpotong di landscape | S |
| RESP-03 | P2 | Responsive | `production-workflow:214` | Popover hover-only | Penanggung jawab tak terjangkau di sentuh | S |
| SCHEMA-01 | P2 | Skema | 8 kolom status | Varchar tanpa constraint/enum cast | Penyimpangan nilai senyap | M |
| MIG-01 | P2 | Migrasi | 2 tabrakan timestamp | Urutan FK benar karena kebetulan alfabetis | `migrate:fresh` rusak bila di-rename | S |
| MIG-02 | P2 | Migrasi | 4 migrasi | `down()` rusak; Eloquent di dalam migrasi | Rollback gagal / default tak valid | M |
| UI-07 | P2 | UI/A11y | `settings/index:71` | Badge `text-[8px]` | Di bawah ambang keterbacaan | S |
| UX-04 | P2 | UX | `tab-performa:82,130` | `@foreach` tanpa empty state | Ruang kosong, tampak rusak | S |
| SEC-12/13 | P3 | Keamanan | Validasi user & plan | Role & PIC tak dibatasi | Client user jadi PIC; CEO dinonaktifkan | S |
| SEC-14 | P3 | Keamanan | `RoleSeeder.php:32-42` | `bcrypt('password')` role Manager | Laten | S |
| SEC-15 | P3 | Keamanan | `User.php:15` | `role_id` fillable | Laten (tanpa jalur terjangkau) | S |
| SEC-17 | P3 | Keamanan | `SettingsController.php:192` | `firstOrCreate` platform tanpa whitelist | Master data tercemar CSV | S |
| LOGIC-05 | P3 | Logika Bisnis | `ProductionWorkflowController:91` | `array_search` → `false` | Status tak dikenal terurut paling atas | S |
| SCHEMA-02 | P3 | Skema | `ClientNote.php` | Model tanpa tabel/migrasi/referensi | Kode mati, akan fatal bila dipakai | S |
| UI-08 | P3 | Konsistensi | `client/*` (8 lokasi) | `.card` ditulis ulang, radius beda | Permukaan klien melenceng dari brand | M |
| TERM-01…12 | P3 | Terminologi | ~60 lokasi | Label Inggris di UI Indonesia | Membingungkan pengguna awam | M |

---

## 14. Quick Wins (dampak tinggi / effort rendah)

Semuanya berskala **S**:

1. **`SearchController.php:48`** — `client-onboarding.show` → `client-management.show`. Satu kata; menghapus 500 di kotak pencarian setiap halaman.
2. **`layouts/app.blade.php:1`** — tambah `<!DOCTYPE html>` + `<html lang="id">` (+ `</html>`). Dua baris; memperbaiki WCAG 3.1.1 di 40+ halaman.
3. **Konstanta `DONE_STATUSES`** — satu array bersama menggantikan 8 salinan; menghapus kelas bug "definisi ganda" secara permanen.
4. **`ClientManagementController::destroy`** — `$client->owner?->delete()` → `$client->users()->delete()`. Satu baris; menutup kegagalan batas hak akses.
5. **`.env` / `.env.example`** — `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`.
6. **`ClientMagicLinkController.php:91-99`** — hapus `message`/`link`/`session_id` dari konteks log.
7. **`AttendanceService.php:105`** — `(int) round(...->diffInMinutes(..., absolute: true))`; tambah cabang `isFuture()`.
8. **Satu migrasi indeks** — keempat indeks pada PERF-04.
9. **`Role::hasPermissionTo`** — memoisasi per request.
10. **`production-workflow:214`** — tambah `x-on:click` pada popover penanggung jawab.
11. **9 modal** — tambah `max-h-[90vh] overflow-y-auto` (dua modal sudah menunjukkan polanya).
12. **`tab-performa:82,130`** — ubah `@foreach` menjadi `@forelse` dengan `@empty`.

---

## 15. Rencana Refactoring Teknis

**R1 — Satukan model visibilitas (prasyarat untuk semua yang lain).**
Tetapkan: visibilitas berbasis item, berbasis klien, atau gabungan? Lalu buat `ProductionWorkflowController`, `ContentRevisionController`, `ContentPublicationController`, `SearchController`, `ReportController`, dan `UserWorkSummaryService` memakai **satu** helper yang sama. Menyelesaikan ARCH-01 sekaligus menjadi prasyarat R2.

**R2 — Perkenalkan middleware `client.scope`.**
Pasang pada `{contentItem}`, `{contentPlan}`, `{contentBrief}`, `{aiStrategyInsight}`. Menutup SEC-01/04/05/06/09/10 dengan satu mekanisme dan membuat route baru aman secara default. *Harus setelah R1.*

**R3 — Tambahkan component layer di `layouts/app.blade.php`.**
Lima kelas di samping `.card`: `.btn-primary`, `.btn-secondary`, `.btn-danger`, `.input`, `.badge` — sekalian dengan perlakuan `focus:ring-2` dan outline `focus-visible`.

**R4 — Ekstrak partial bersama untuk view terduplikasi.**
`revision-log` ↔ `revisions-tab`, `publishing-tracker` ↔ `published-tab`, `profile/show` ↔ `home/index`, `settings/integrations` ↔ `integrations-panel`. Pengerjaan accordion mobile menggandakan tiap file, jadi biaya duplikasinya ikut berlipat — dan `integrations` vs `integrations-panel` **sudah berbeda** ("Sync Log" vs "Log Sinkronisasi"), bukti bahwa penyimpangan sudah terjadi.

**R5 — Konstanta dan enum.**
`DONE_STATUSES`; backed enum + `$casts` untuk 8 kolom status tanpa constraint; ganti string keras `'in_progress'`/`'uploaded'` dengan rujukan `WorkflowTransitions`.

**R6 — Higiene query.** PERF-01/02/04, plus paginasi papan Kanban.

**R7 — Hapus kode mati.** Model `ClientNote` (tanpa tabel, tanpa referensi), `ClientController` (tanpa route), churn migrasi `is_active` yang dibuat lalu dihapus dalam batch yang sama.

---

## 16. Peta Jalan Implementasi

**Fase 1 — Kritis (~2–3 hari)**
BUG-01 · BUG-02 · SEC-03 · SEC-08 · LOGIC-01 · BUG-03
→ *Kriteria selesai:* pencarian mengembalikan hasil; tidak ada token di log; penghapusan klien tidak menciptakan staf internal; satu definisi status selesai.

**Fase 2 — Pengetatan keamanan (~1 minggu)**
R1 lalu R2 · SEC-09/10/11 · SEC-12/13 · SEC-02
→ *Kriteria selesai:* skrip enumerasi ID sebagai setiap role non-CEO mengembalikan 403 pada semua route ber-binding.

**Fase 3 — Aksesibilitas (~1 minggu)**
A11Y-01 (dulu, sepele) · A11Y-02 · A11Y-03 · A11Y-04 · A11Y-05 (+ `@alpinejs/focus`) · A11Y-06/07
→ *Kriteria selesai:* seluruh tabel inti dapat ditelusuri keyboard; axe/Lighthouse bersih di Level A.

**Fase 4 — Konsistensi & UX (~1–2 minggu)**
R3 · R4 · UI-01/03/04 · UI-06 (input hilang) · UX-02 · TERM-01…05 · RESP-01/03/04

**Fase 5 — Performa & pembersihan (~3–5 hari)**
R6 · R7 · PERF-03 · SCHEMA-01 · MIG-01/02

---

## 17. Yang Sudah Dikerjakan dengan Baik

Audit yang objektif wajib mencatat kekuatan. Beberapa di antaranya benar-benar di atas rata-rata:

1. **Seluruh 59 foreign key adalah constraint database sungguhan**, dengan semantik `onDelete` yang disengaja — CASCADE untuk anak yang dimiliki, SET NULL untuk referensi opsional, RESTRICT untuk jejak audit. Tidak ada yang "hanya ditegakkan di kode aplikasi". Ini justru hal yang paling sering salah di codebase Laravel, dan di sini sudah benar.
2. **Mesin state workflow tersentralisasi dengan benar.** `WorkflowStatusService::transition()` adalah satu-satunya jalur tulis, dipakai bersama oleh drag-drop Kanban dan tombol halaman detail, dengan guard + efek samping + status log + publikasi seluruhnya di dalam satu transaksi. `correctStatus()` adalah jalan keluar yang dirancang baik: dibatasi role dan ditandai agar dapat dikecualikan dari pelaporan.
3. **Otorisasi tingkat route sepenuhnya benar** — terverifikasi pada 7 role × 12 route.
4. **Isolasi portal klien kokoh** — permukaan paling berisiko di aplikasi, dan setiap method memeriksa kepemilikan.
5. **Google OAuth invite-only secara desain**; magic link di-hash saat disimpan, sekali pakai, dan kedaluwarsa.
6. **Setiap kolom JSON punya cast `'array'`; setiap kolom tanggal punya date cast.** Nol kelalaian. Nilai uang/rasio memakai `decimal(5,2)`, bukan float.
7. **Penjaga pembagian nol nyaris universal** — 13 dari 14 lokasi, dengan teks berbeda untuk kasus nol.
8. **Unique constraint komposit di tempat yang tepat** — `content_metrics(item, platform, date)`, `attendances(user, date)`, `permissions(module, action)` — mencegah satu kelas penuh bug duplikasi impor.
9. **Disiplin palet brand sangat baik** — praktis nol warna di luar palet across 55 file.
10. **Abstraksi `.card` membuktikan polanya berhasil** — 100+ pemakaian, dan satu-satunya komponen yang *tidak* melenceng.
11. **Pola pill-toggle untuk tab konsisten** di 6 modul.
12. **`window.appConfirm()` menggantikan `confirm()` bawaan di seluruh aplikasi** — bergaya, dengan varian danger.
13. **Kalender mobile adalah redesign responsif sungguhan**, disertai alasan yang ditulis di kode.
14. **`prefers-reduced-motion` dihormati**; `x-cloak` dipakai konsisten; state sidebar disinkronkan sebelum Alpine boot untuk mencegah kedipan.
15. **Komentar berbahasa Indonesia di kode luar biasa bagus** — beberapa menjelaskan *mengapa* sebuah keputusan diambil, bukan sekadar apa yang dilakukan kode. Catatan di `AttendanceService` soal sengaja membiarkan check-out yang terlupa bernilai NULL alih-alih menebaknya, dan penjelasan `DelayRiskAccuracyService` soal menurunkan keterlambatan dari `ContentStatusLog` alih-alih `updated_at`, keduanya benar sekaligus tidak sepele.

---

## 18. Keterbatasan Audit

1. **Isi database sengaja tidak dinilai** (lihat §0). Konsekuensinya, temuan yang hanya akan muncul dengan data produksi nyata — misalnya distribusi nilai kolom, kualitas hasil impor CSV, atau akurasi model risiko — berada di luar cakupan.
2. **Pengujian visual berbasis browser bersifat parsial.** Halaman dirender dengan setia dan diperiksa pada viewport lebar serta sempit, disertai pemindaian overflow programatik. Sapuan screenshot lintas 1440/1024/768/390/375 terpotong batas sesi lingkungan, sehingga temuan responsive terutama bersandar pada analisis statis kelas CSS.
3. **Sapuan kode mati sistematis belum tuntas** (batas sesi yang sama). R7 karena itu bersifat *indikatif, bukan lengkap*.
4. **Autentikasi tidak dapat dijalankan lewat browser** karena login internal hanya Google OAuth. Halaman terautentikasi dirender melalui HTTP kernel sungguhan dengan `Auth::login()` — keluaran server setara, tetapi tidak menguji perjalanan OAuth itu sendiri.
5. **Tidak ada verifikasi destruktif.** Temuan keamanan dikonfirmasi dengan menelusuri jalur kode secara utuh; tidak ada eksploit yang dijalankan dan tidak ada penulisan data.
6. **Beban dan konkurensi tidak diuji.** Klaim penskalaan merupakan ekstrapolasi dari rencana `EXPLAIN` dan pembacaan kompleksitas algoritmik.

---

## 19. Penilaian Akhir

**Keseluruhan: 57 / 100.**

Ini produk dengan **rangka kuat dan permukaan lunak**. Model datanya disiplin, mesin workflow tersentralisasi dengan benar, otorisasi tingkat route tepat, dan desain visualnya matang. Itu semua bagian yang mahal untuk dibetulkan belakangan — dan semuanya sudah benar.

Yang hilang adalah lapisan *antara* route dan query — **pembatasan data** — serta lapisan *antara* desain dan markup — **sistem komponen**. Keduanya berbentuk sama: pola yang benar sudah ada di suatu tempat dalam codebase dan sekadar belum diterapkan merata.

- `ProductionWorkflowController::index` membatasi dengan benar; dua belas endpoint lain tidak.
- `.card` tidak melenceng; sebelas varian tombol melenceng.
- `published-tab` memakai `<a>` sungguhan; sebelas tabel lain memakai `onclick`.
- `analytics/*` memakai `focus:ring-2`; 29 file lain memakai `focus:outline-none` telanjang.

Karena itu perbaikannya sebagian besar **propagasi, bukan perancangan ulang** — dan itulah alasan begitu banyak temuan berskala S.

**Risiko bila dibiarkan:**
- **Keamanan:** user internal mana pun dapat membaca dan mengubah konten setiap klien. Bagi sebuah agensi, pemisahan data antar-klien bukan fitur tambahan — itu janji dasar produknya.
- **Legal/pengadaan:** kegagalan WCAG Level A di setiap halaman menjadi penghalang bagi klien sektor publik maupun korporasi.
- **Berlipat:** setiap tabel baru yang disalin dari tabel lama mewarisi baris `onclick`, header `#9aa0a4`, dan pemeriksaan scope yang hilang. Biaya temuan-temuan ini tumbuh seiring setiap fitur baru.

**Langkah berikutnya:** jalankan Fase 1 sebagai satu sprint terfokus — enam perbaikan, semuanya berskala S. Setelah itu ambil keputusan model penugasan (R1), karena R2 (perbaikan keamanan utama) **tidak dapat dikerjakan dengan aman sebelum keputusan itu diambil**.

---

*Audit bersifat read-only. `git status --porcelain` = 0 baris saat selesai. Tidak ada file sumber, baris database, maupun nilai konfigurasi yang diubah.*
