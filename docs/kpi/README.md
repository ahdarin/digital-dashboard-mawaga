# KPI System - README

Sistem KPI untuk Team Performance 523 Studio - menggantikan leaderboard sederhana lama dengan sistem yang transparan, dapat diaudit, dan mendukung struktur relasi kompleks agensi (multi-PIC, multi-role, multi-client, multi-platform).

> **Koreksi produk 2026-09-02**: implementasi awal (Fase 1-6 pertama) sempat menambah tabel/role KPI khusus (`operational_roles`, `client_role_assignments`, `content_item_operational_assignments`, role "Account Lead"/"Reviewer"/"Publisher"). Ini **DIBATALKAN TOTAL** - lihat `CHANGELOG.md` bagian "Koreksi Produk". Dokumen ini (dan seluruh `docs/kpi/*.md` lain) sudah menjelaskan arsitektur HASIL KOREKSI (satu-satunya yang berlaku sekarang): **tidak ada role baru, tidak ada tabel assignment baru, tidak ada command manual yang diwajibkan**.
>
> **Koreksi lanjutan 2026-09-02**: audit ulang menemukan atribusi masih bocor lintas periode (assignment lama ikut terhitung tiap bulan), memaksa satu role per user, dan menyimpan baris operasional selalu `client_id=NULL` (filter klien rusak untuk staf produksi). Semuanya sudah diperbaiki - lihat `ATTRIBUTION_RULES.md` untuk detail lengkap.

## Tujuan

Menjawab 6 pertanyaan produk (lihat spesifikasi asli):
1. Apakah alur kerja tim sehat? -> `docs/kpi/PROCESS_METRICS.md`
2. Tahap mana bottleneck? -> Process KPI per role + median durasi per stage (kartu "Bottleneck" di tab Ringkasan)
3. Apakah beban tim seimbang? -> `WorkloadScoringService` (kartu "Workload Imbalance")
4. Kontribusi tiap orang berdasarkan peran yang benar dijalankan? -> `docs/kpi/ATTRIBUTION_RULES.md`
5. Bagaimana hasil konten & pertumbuhan sosial berkontribusi ke KPI? -> `docs/kpi/ANALYTICS_NORMALIZATION.md`
6. Apakah data cukup lengkap & dapat dipercaya? -> bagian "Coverage" di `FORMULAS.md`/`ANALYTICS_NORMALIZATION.md`

KPI ini **BUKAN leaderboard**. Tidak ada ranking lintas role, tidak ada satu overall score gabungan, dan skor tidak pernah ditampilkan sebagai 0 ketika data belum cukup.

## Prinsip Arsitektur (Keputusan Produk Final)

1. **Tidak ada role baru.** Role KPI SELALU salah satu dari role EXISTING (`roles`/`user_roles`: CEO, Manager, Content Creator, Graphic Designer, SMO, Copywriter, Admin) - lihat `App\Enums\UserRole`.
2. **Tidak ada tabel/proses assignment khusus KPI.** PIC = `content_item_assignments` EXISTING (dipakai atribusi Content Creator/Graphic Designer/Manager-CEO-sbg-PIC). Copywriter diatribusikan dari `content_brief_drafts.created_by`, SMO dari `content_publications.published_by` - keduanya TIDAK PERLU jadi PIC sama sekali (lihat `ATTRIBUTION_RULES.md`). Relasi user-klien = `user_client_assignments` EXISTING/`ContentItem.client_id`. Tidak ada langkah "assign role KPI" terpisah dari cara tim sudah bekerja sehari-hari.
3. **Tidak ada command manual yang diwajibkan.** Kalkulasi berjalan otomatis di latar belakang, dipicu oleh aktivitas yang sudah ada (lihat `JOBS_AND_OPERATIONS.md`). `php artisan kpi:calculate` tetap ada untuk developer/debugging, BUKAN syarat memakai fitur.
4. **Tidak ada perubahan ke alur Content Plan/brief/produksi/review/revisi/scheduling/publishing/client management/user management.** KPI murni MEMBACA aktivitas yang sudah tercatat sistem.
5. **Perubahan yang terlihat pengguna hanya di halaman Team Performance** (`/team-performance`) dan status refresh otomatisnya.

## Peta Dokumen

| Dokumen | Isi |
|---|---|
| `IMPLEMENTATION_PLAN.md` | *(historis)* Audit Fase 0 - source of truth tiap metrik, ERD awal sebelum koreksi |
| `PROGRESS.md` | *(historis)* Log kerja per fase - lihat `CHANGELOG.md` untuk ringkasan koreksi terbaru |
| `DATA_MODEL.md` | Skema EXISTING yang dipakai KPI (tidak ada tabel baru untuk assignment/role) |
| `ATTRIBUTION_RULES.md` | Aturan atribusi multi-PIC/multi-role/multi-client/multi-platform dari data EXISTING |
| `FORMULAS.md` | Formula lengkap + bobot default + contoh hitung angka |
| `ANALYTICS_NORMALIZATION.md` | Peer group, D+7/D+30, robust stats, viral, paid vs organic, coverage |
| `PROCESS_METRICS.md` | Process KPI per role (Copywriter/Production/SMO/Leadership) |
| `UI_AND_PERMISSIONS.md` | Route, permission, struktur tab dashboard, status refresh otomatis |
| `JOBS_AND_OPERATIONS.md` | Background job otomatis, titik trigger, debounce, troubleshooting developer |
| `TEST_MATRIX.md` | Skenario wajib -> file test yang membuktikannya |
| `CHANGELOG.md` | Riwayat perubahan per fase, termasuk Koreksi Produk 2026-09-02 |

## Cara Kerja Singkat (Tanpa Command Manual)

1. User beraktivitas seperti biasa (assignment PIC berubah, status workflow berubah, revisi dibuat, publication diisi, analytics sync jalan, audience insight diperbarui).
2. Setiap titik itu memicu `KpiRecalculationTrigger` (satu baris, tidak mengubah alur/return value titik itu) yang men-dispatch job `RecalculateKpiPeriod` ke background queue (debounced - banyak event beruntun jadi satu eksekusi, lihat `JOBS_AND_OPERATIONS.md`).
3. Membuka `/team-performance` selalu aman: kalau hasil untuk periode ini belum ada/basi (>30 menit), halaman OTOMATIS men-trigger kalkulasi lagi, sambil tetap menampilkan snapshot TERAKHIR yang tersedia (periode ini atau periode sebelumnya) - atau "Data KPI sedang disiapkan otomatis" kalau memang belum pernah ada sama sekali. **Tidak pernah ada instruksi command di layar.**

Untuk developer yang ingin memicu kalkulasi langsung tanpa menunggu queue (mis. debugging lokal):

```bash
php artisan kpi:calculate                                   # bulan berjalan, formula version terbaru
php artisan kpi:calculate --month=2026-06 --formula-version=2026.1
```

## Namespace Kode

Seluruh logika domain KPI hidup di `App\Kpi\*` (bukan tersebar di `App\Services`) supaya jelas terpisah dari domain lama:

- `App\Kpi\Formula` - `KpiFormulaConfig` (value object bobot, dibaca dari `KpiFormulaVersion.config`).
- `App\Kpi\Support` - `RobustStats` (winsorize/log1p/percentile/median, murni tanpa DB).
- `App\Kpi\Dto` - value object hasil kalkulasi (`ContentOutcomeScore`, `ProcessScoreBreakdown`, `CompositeKpiResult`, `PublicationDelta`).
- `App\Kpi\Services` - `KpiAttributionService`, `KpiRoleContextResolver`, `ContentOutcomeScoringService`, `ClientPortfolioScoringService`, `RoleProcessKpiService`, `WorkloadScoringService`, `KpiCoverageService`, `KpiCalculationService`, `TeamPerformanceDashboardService`, `KpiRecalculationTrigger`.
- `App\Jobs\RecalculateKpiPeriod` - satu-satunya job background yang menghitung KPI.
