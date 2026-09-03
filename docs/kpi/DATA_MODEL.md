# KPI System - Data Model

> **Koreksi produk 2026-09-02**: versi sebelumnya dokumen ini menjelaskan tiga tabel/role baru (`operational_roles`, `client_role_assignments`, `content_item_operational_assignments`, plus role "Account Lead"/"Reviewer"/"Publisher"). **Semuanya sudah dihapus total**, termasuk migration penghapusnya sendiri (jadi migration residual, sudah tidak diperlukan setelah tabel `create`-nya juga dihapus - fresh migration sekarang langsung menghasilkan schema final tanpa artefak arsitektur lama). Dokumen ini menjelaskan arsitektur yang BENAR-BENAR berjalan sekarang: KPI membaca murni dari tabel EXISTING, tidak ada satu pun tabel baru untuk assignment atau role.
>
> **Koreksi lanjutan 2026-09-02**: atribusi tidak lagi bergantung pada `content_item_assignments.created_at` sebagai penentu periode (lihat `ATTRIBUTION_RULES.md`), dan `user_kpi_results.client_id` SEKARANG selalu terisi untuk baris operasional juga (bukan cuma leadership).

## Kenapa Tidak Ada Tabel Baru untuk Role/Assignment

Keputusan produk final: *"jangan menambah role baru"*, *"jangan membuat proses assignment khusus KPI"*. Semua yang dulunya coba diselesaikan lewat tabel baru sudah punya jawaban dari data yang sudah ada:

| Kebutuhan | Dulu (dihapus) | Sekarang (EXISTING, tidak diubah) |
|---|---|---|
| "Siapa mengerjakan konten ini" (PIC) | `content_item_operational_assignments` | `content_item_assignments` (`content_item_id`, `user_id`, `assignment_role`) - hasMany dari `ContentItem`, mendukung multi-PIC secara alami (banyak baris, `content_item_id` sama) |
| "Role apa yang berlaku untuk aktivitas ini" | `operational_roles` + `OperationalRoleName` enum | `roles`/`user_roles` (`App\Enums\UserRole`) + jenis aktivitas (tipe konten, aksi yang dilakukan) - lihat `App\Kpi\Services\KpiRoleContextResolver` |
| "User ini role apa di klien ini" | `client_role_assignments` | `ContentItem.client_id` dari content yang di-assign user (`content_item_assignments`) - relasi klien diturunkan dari AKTIVITAS NYATA, bukan tabel role-per-klien terpisah |
| "Manager/CEO memimpin klien mana" | `client_role_assignments.is_lead` | `ContentStatusLog.changed_by_user_id` - approval/decision yang BENAR-BENAR dilakukan Manager/CEO itu pada content milik klien tersebut (lihat `ATTRIBUTION_RULES.md`) |

`roles` (global/access role, dipakai `Role::hasPermission()` + middleware `permission:module,action`) **TIDAK diubah sama sekali** dan TIDAK pernah dipakai langsung sebagai otorisasi KPI - KPI membaca role yang sama ini hanya sebagai LABEL KONTEKS ("konten ini dihitung sebagai pekerjaan Content Creator si user"), bukan untuk cek hak akses.

## Tabel yang Dipakai KPI (Semuanya EXISTING, Tidak Diubah Skemanya)

- `roles` / `user_roles` - role EXISTING, sumber SATU-SATUNYA untuk label konteks KPI (lihat `KpiRoleContextResolver`).
- `user_client_assignments` - roster akses klien EXISTING (dipakai kalau butuh scoping klien di luar konteks content langsung).
- `content_item_assignments` - PIC EXISTING. Sumber SATU-SATUNYA "siapa mengerjakan konten ini". **Catatan keterbatasan yang diterima secara sadar**: tabel ini tidak punya kolom periode (`assigned_at`/`ended_at`) - `ContentItemController::reassign()` MENIMPA baris `'primary'` yang ada (bukan menutup lalu membuat baris baru), jadi histori "siapa PIC sebelum pergantian" tidak tersedia lewat tabel ini. KPI menerima keterbatasan ini apa adanya (bukan "diperbaiki" dengan menambah kolom/tabel baru khusus KPI - itu melanggar larangan "proses assignment khusus KPI").
- `content_status_logs` - waktu & aktor SETIAP transisi status (termasuk keputusan approve/revision Manager/CEO). Sumber SATU-SATUNYA leadership KPI, dan penentu "content item ini aktif di periode ini" untuk atribusi Content Creator/Graphic Designer/Manager-CEO-sbg-PIC.
- `content_brief_drafts.created_by` - sumber SATU-SATUNYA atribusi Copywriter (brief dibuat di periode ini) - TIDAK bergantung pada status PIC sama sekali.
- `content_publications.published_by` + `recorded_via` (kolom baru, koreksi lanjutan) - sumber SATU-SATUNYA atribusi SMO. `recorded_via` (`manual`/`auto_sync`, default `auto_sync`) membedakan publication yang dicatat lewat aksi manusia langsung (Record Publication/link media unmatched - `published_by` reliable) dari publication yang dibuat otomatis saat analytics sync (`published_by` cuma user yang memicu sync, BUKAN publisher asli) - hanya baris `manual` yang dipakai atribusi SMO.
- `content_plan_status_logs`, `content_revisions`, `content_metrics`, `content_metric_snapshots`, `audience_insights` - sumber process/outcome/portfolio metric (lihat `PROCESS_METRICS.md`, `ANALYTICS_NORMALIZATION.md`).

## Tabel Formula & Hasil Kalkulasi (Baru, Bukan Tabel Assignment)

Tiga tabel ini TETAP baru (menyimpan HASIL kalkulasi, bukan menambah proses assignment/role) - tidak melanggar larangan produk karena tidak ada satu pun langkah pengguna baru yang mengisi tabel ini secara manual:

- `kpi_formula_versions` - `version` (unique), `config` (JSON, dibaca via `KpiFormulaConfig`), `effective_from`. Formula TIDAK PERNAH di-hardcode di service manapun. `KpiFormulaVersion::resolveCurrent()` membuat versi default otomatis kalau belum pernah ada satu pun (self-bootstrapping - tidak perlu seeder manual).
- `kpi_calculation_runs` - jejak SETIAP eksekusi kalkulasi (dibuat job background, lihat `JOBS_AND_OPERATIONS.md`). TIDAK ADA unique constraint per periode - re-run periode yang sama SELALU bikin baris baru (histori penuh, tidak pernah menimpa).
- `content_outcome_results` - hasil scoring outcome per content item per measurement window (d7/d30) per run. Unique: `(run, content_item, window)`.
- `user_kpi_results` - hasil composite per (user, role EXISTING via `role_id`, client) per run. `role_id` FK ke `roles.id` EXISTING (BUKAN tabel role baru). `client_id` nullable di skema, TAPI koreksi lanjutan membuatnya SELALU terisi baik untuk baris operasional (Copywriter/Content Creator/Graphic Designer/SMO/Manager-CEO-sbg-PIC) MAUPUN leadership - satu-satunya kasus `client_id` NULL adalah kalau suatu saat ada jenis baris yang genuinely tidak terkait klien mana pun (tidak ada saat ini). `component_breakdown` JSON menyimpan `contributing_content_item_ids`/`leadership_decided_content_item_ids` sebagai audit trail konten yang mendasari baris ini - dipakai UI drill-down (`TeamPerformanceDashboardService::contentOutcomesForResult()`), bukan diturunkan ulang secara live. Unique secara LOGIS (dijaga `updateOrCreate` di `KpiCalculationService`, bukan DB constraint): `(run, user, role_id, client_id)` - lihat `ATTRIBUTION_RULES.md` #10 untuk aturan merge saat Manager/CEO punya aktivitas produksi+leadership pada (role,client) yang sama.

## Relasi Many-to-Many yang Didukung (Semua dari Data EXISTING)

| # | Relasi | Implementasi |
|---|---|---|
| 1 | Content item <-> PIC (banyak PIC) | `content_item_assignments` (hasMany dari `ContentItem`, EXISTING) |
| 2 | User <-> role (banyak role EXISTING sekaligus) | `user_roles` pivot EXISTING - dipisah per aktivitas oleh `KpiRoleContextResolver` (content type Video -> Content Creator, Desain -> Graphic Designer, dst - lihat `ATTRIBUTION_RULES.md`) |
| 3 | User <-> client (many-to-many) | `user_client_assignments` (EXISTING) - untuk KPI, relasi klien paling sering diturunkan langsung dari `ContentItem.client_id` konten yang di-assign |
| 4 | Content item <-> platform | `content_item_platforms` (EXISTING, pivot) |
| 5 | Content item <-> publication (banyak) | `content_publications.content_item_id` (hasMany, EXISTING) |
| 6 | Publication <-> analytics/snapshot | `content_metrics`/`content_metric_snapshots` (EXISTING) |
| 7 | Manager/CEO <-> klien yang benar-benar dipimpin | `content_status_logs.changed_by_user_id` dikelompokkan per `content_item.client_id` - BUKAN tabel role-per-klien, BUKAN akses RBAC global (lihat `ATTRIBUTION_RULES.md` #8) |
| 8 | Global role != otorisasi untuk isi KPI | `KpiRoleContextResolver`/`KpiAttributionService` HANYA baca `roles`/`user_roles`/`content_item_assignments`/`content_status_logs` sebagai DATA, tidak pernah menyisipkan pengecekan permission di dalamnya |

## ERD Ringkas

```
roles ---< user_roles >--- users ---< content_item_assignments >--- content_items --- content_type
                                                                          |
                                                                          |--- client_id --> clients
                                                                          |--- content_status_logs (aktor & waktu transisi)
                                                                          |--- content_revisions (internal vs client)
                                                                          |--- content_publications --- content_metrics / content_metric_snapshots
                                                                          |--- (client) audience_insights

kpi_formula_versions --- kpi_calculation_runs --- content_outcome_results
                                                \-- user_kpi_results --- role_id --> roles
                                                                     \-- client_id --> clients (operasional & leadership, selalu terisi)
```

Tidak ada tabel baru untuk "assignment KPI" atau "role KPI" di diagram ini - satu-satunya tabel baru adalah tempat penyimpanan HASIL (`kpi_formula_versions`, `kpi_calculation_runs`, `content_outcome_results`, `user_kpi_results`).
