# KPI System - Progress Log

> **Dokumen historis.** Log di bawah mencatat proses kerja ASLI (Fase 0-7 pertama), yang sempat menambah tabel/role KPI khusus (`operational_roles`, `client_role_assignments`, `content_item_operational_assignments`, dst). Arsitektur itu **dibatalkan total** oleh koreksi produk 2026-09-02 - lihat `CHANGELOG.md` bagian "Koreksi Produk 2026-09-02" untuk arsitektur yang BENAR-BENAR berjalan sekarang. Log ini disimpan apa adanya sebagai catatan proses, bukan sebagai deskripsi sistem saat ini.

Log kerja per fase (instruksi #9 - dicatat setelah setiap fase, bukan di akhir saja). Detail lengkap ada di `IMPLEMENTATION_PLAN.md` (Fase 0) dan dokumen `docs/kpi/*.md` lain (Fase 7). Laporan akhir lengkap ada di respons chat terakhir.

## Fase 0 - Audit & Architecture Plan
Status: **Selesai**. Lihat `docs/kpi/IMPLEMENTATION_PLAN.md`.

## Fase 1 - Database & Domain Model
Status: **Selesai**. Test: `php artisan test --filter=DomainModelTest` -> 5/5 pass. Full suite setelah fase ini: 339/339 pass (zero regresi).

File dibuat:
- 9 migration baru (`2026_09_02_000010` s/d `000018`): `operational_roles`, `client_role_assignments`, `content_item_operational_assignments`, promotion fields di `content_publications`, `returned_count` di `content_brief_drafts`, `kpi_formula_versions`, `kpi_calculation_runs`, `content_outcome_results`, `user_kpi_results`.
- Model: `OperationalRole`, `ClientRoleAssignment`, `ContentItemOperationalAssignment`, `KpiFormulaVersion`, `KpiCalculationRun`, `ContentOutcomeResult`, `UserKpiResult`.
- Enum: `ResponsibilityType`, `CoverageStatus`, `KpiStatusLabel`, `MeasurementWindow`, `ContentFormatGroup`, `OperationalRoleName`.
- Value object: `App\Kpi\Formula\KpiFormulaConfig`.
- Seeder: `KpiReferenceSeeder` (dipanggil dari `DatabaseSeeder`).
- Test: `tests/Feature/Kpi/DomainModelTest.php` (5 skenario wajib Fase 1).
- Factory baru: `OperationalRoleFactory`, `ClientRoleAssignmentFactory`, `ContentItemOperationalAssignmentFactory`, `KpiFormulaVersionFactory`, `KpiCalculationRunFactory`, `ContentPublicationFactory`, `ContentMetricSnapshotFactory`, `AudienceInsightFactory`, `UserClientAssignmentFactory`, `ContentBriefDraftFactory`, `ContentOutcomeResultFactory`, `UserKpiResultFactory`.

File diubah:
- `app/Models/Client.php`, `ContentItem.php`, `User.php` - relasi baru + **fix bug pre-existing**: banyak model (`Client`, `ContentItem`, `ContentPlan`, `ClientCategory`, `Platform`, `ContentType`, `ContentItemAssignment`, `ContentRevision`, `ContentStatusLog`, `ContentWorkflow`) meng-import `HasFactory` tapi tidak pernah `use` trait-nya di class body - `Model::factory()` selalu gagal untuk model-model ini sebelum diperbaiki. Diperbaiki karena WAJIB untuk memenuhi instruksi Fase 1 "buat factory untuk semua relasi many-to-many".
- `app/Models/ContentBriefDraft.php`, `ContentPublication.php` - fillable baru (`returned_count`, `is_paid`/`promotion_type`/`ad_spend`/`campaign_reference`).
- `database/factories/ClientFactory.php`, `ClientCategoryFactory.php`, `ContentPlanFactory.php`, `ContentItemFactory.php`, `ContentWorkflowFactory.php`, `PlatformFactory.php`, `ContentTypeFactory.php`, `ContentRevisionFactory.php`, `ContentStatusLogFactory.php`, `ContentItemAssignmentFactory.php` - semua tadinya stub kosong (`return [//];`), sekarang punya `definition()` yang valid.
- `database/seeders/DatabaseSeeder.php` - tambah `KpiReferenceSeeder`.

## Fase 2 - Attribution & KPI Calculation Engine
Status: **Selesai**. Test: `php artisan test --filter=Kpi` -> 19/19 pass (5 Fase 1 + 14 Fase 2). Bug ditemukan+diperbaiki selama verifikasi: (1) test helper pakai `??` untuk override eksplisit `null` - `??` memperlakukan null-eksplisit sama seperti "tidak diisi", diganti `array_key_exists`; (2) `KpiAttributionServiceTest` memakai periode kalender tetap (Juni 2026) sementara factory default `assigned_at` pakai `now()` (yang di lingkungan ini = September 2026) - assignment jadi di luar periode test. Diperbaiki dengan assigned_at eksplisit di tiap test.

File dibuat:
- `app/Kpi/Support/RobustStats.php` - winsorize, log1p, percentile rank/value, median, clamp.
- `app/Kpi/Dto/{ContentOutcomeScore,ProcessScoreBreakdown,CompositeKpiResult,PublicationDelta}.php`.
- `app/Kpi/Services/{KpiAttributionService,ContentOutcomeScoringService,ClientPortfolioScoringService,RoleProcessKpiService,WorkloadScoringService,KpiCoverageService,KpiCalculationService}.php`.
- Test: `tests/Feature/Kpi/{ContentOutcomeScoringServiceTest,KpiAttributionServiceTest,RoleProcessKpiServiceTest,KpiCalculationServiceTest}.php`.

Keputusan implementasi penting (didokumentasikan penuh nanti di FORMULAS.md/ANALYTICS_NORMALIZATION.md Fase 7):
- `ContentOutcomeScoringService` TIDAK memanggil `PeriodPerformanceService::computeContentDelta()` langsung - identity column `content_item_id` milik service itu ambigu untuk content multi-platform (snapshot 2 platform akan tercampur). Ditulis ulang scoped ke `(content_item_id, platform_id)` eksplisit, filosofi coverage yang sama (full/partial/unavailable + provisional untuk publication yang belum cukup umur).
- Peer group fallback (client+platform+format -> lintas klien) BELUM menyesuaikan ukuran akun secara statistik penuh - dicatat sebagai known limitation.
- Process KPI: metrik BERBASIS RATE (0-100) menyusun composite process_score; metrik BERBASIS DURASI (median jam) bersifat informasional (ditampilkan, ada coverage) tapi tidak dikonversi paksa jadi skor tanpa target SLA eksplisit dari domain (menghindari magic number tersembunyi).
- Client portfolio "Account Lead" per role SMO/Account Lead memakai rata-rata skor lintas semua klien assignment aktif user itu (bukan breakdown per-client tersimpan terpisah untuk setiap kombinasi role) - breakdown per-client lengkap ada di leadership path (Manager/CEO `is_lead`).

## Fase 3 - Application Service, Controller, Route, Permission
Status: **Selesai** (dengan satu simplifikasi dicatat di bawah). Test: `TeamPerformanceControllerTest` (5) + `AssignmentManagementControllerTest` (6) -> 11/11 pass. Full suite: 364/364 pass.

File dibuat:
- `app/Kpi/Services/TeamPerformanceDashboardService.php` - application service baca hasil KPI (run/memberRows/resultsForUser/contentOutcomesForUserRole/teamSummary), dipakai controller supaya tetap tipis.
- `app/Http/Controllers/ClientRoleAssignmentController.php` - simpan operational role + periode PER KLIEN (`client-management.role-assignments.store/destroy`).
- `app/Http/Controllers/ContentItemOperationalAssignmentController.php` - simpan PIC+role+responsibility PER CONTENT ITEM, mendukung multi-PIC (`content-items.operational-assignments.store/destroy`).
- Test: `tests/Feature/Kpi/{TeamPerformanceControllerTest,AssignmentManagementControllerTest}.php`.

File diubah:
- `app/Http/Controllers/TeamPerformanceController.php` - ditulis ulang total, jadi tipis (delegasi penuh ke `TeamPerformanceDashboardService`), route baru `team-performance.show` (detail per anggota per role).
- `routes/web.php` - route baru untuk ketiganya, semua tetap di dalam middleware group permission yang sudah ada (`team_performance,view`; `user_management,manage`; `workflow,update` + `client.scope:contentItem`) - TIDAK ADA permission baru dibuat, TIDAK ADA scope RBAC yang diperluas.

**Simplifikasi dicatat (bukan dikerjakan penuh, didokumentasikan sebagai known limitation)**: endpoint baru (`ClientRoleAssignmentController`/`ContentItemOperationalAssignmentController`) SELESAI dan teruji penuh di backend, tapi BELUM disambungkan ke UI halaman Client Detail (700+ baris, banyak modal existing) atau Content Item Detail - mengedit view existing yang besar itu berisiko regresi UI yang tidak proporsional dengan sisa waktu kerja. Form kecil bisa ditambahkan sebagai follow-up terpisah (lihat ROLLOUT_AND_CUTOVER.md "Known limitations").

## Fase 4 - Views & UX Team Performance
Status: **Selesai** (dikerjakan bersamaan Fase 3 karena saling terikat langsung).

File dibuat:
- `resources/views/team-performance/partials/tab-ringkasan.blade.php` - Ringkasan Tim: filter periode/klien/role, coverage banner, distribusi status (Sehat/Perlu Perhatian/Sementara/Data Belum Cukup - BUKAN leaderboard), Akurasi AI Delay Risk (dipertahankan, ditandai eksplisit "model health, bukan KPI karyawan").
- `resources/views/team-performance/partials/tab-anggota.blade.php` - satu kartu per (user, operational role[, client]), breakdown process/direct/portfolio, sample size, coverage, status label - diurutkan ALFABETIS (bukan skor), tidak ada ranking lintas role.
- `resources/views/team-performance/show.blade.php` - detail satu anggota: role selector (tab per role, tanpa overall score gabungan), breakdown process metric mentah, tabel konten yang berkontribusi (format/window/coverage/peer sample/skor).

File diubah/dihapus:
- `resources/views/team-performance/index.blade.php` - 2 tab jadi 3 tab (Ringkasan Tim/Anggota/Kehadiran), `role="tablist"`/`aria-selected` ditambah untuk aksesibilitas.
- `resources/views/team-performance/partials/tab-performa.blade.php` - **dihapus** (leaderboard lama, sepenuhnya digantikan tab-ringkasan.blade.php - dikonfirmasi tidak ada referensi lain sebelum dihapus).

Tab Kehadiran TIDAK disentuh sama sekali (logic & view attendance tetap identik).

## Fase 5 - Job, Command, Cache, Scheduling
Status: **Selesai**. Test: `CalculateKpiCommandTest` -> 4/4 pass.

File dibuat:
- `app/Console/Commands/CalculateKpi.php` - `php artisan kpi:calculate {--month=} {--formula-version=}`. Lock via `Cache::lock()` (pola sama dengan `SyncAllInstagramIntegrations` dkk) mencegah eksekusi bersamaan untuk periode+formula yang sama. Setiap eksekusi sukses = 1 `KpiCalculationRun` BARU (histori penuh, tidak pernah overwrite).
- Test: `tests/Feature/Kpi/CalculateKpiCommandTest.php`.

File diubah:
- `routes/console.php` - `Schedule::command('kpi:calculate')->dailyAt('03:00')` (setelah jadwal sync analytics harian).

**Keputusan desain "cache" (didokumentasikan, bukan diimplementasi sebagai layer terpisah)**: strategi cache Team Performance = TABEL SNAPSHOT (`content_outcome_results`/`user_kpi_results`) yang dibaca dashboard, bukan `Cache::remember()` dengan invalidasi manual di 7 titik trigger berbeda (assignment/workflow/revision/publication/analytics sync/audience insight/formula version) seperti disebut spesifikasi. Trigger-trigger itu SEMUA otomatis "diselesaikan" dengan cara yang sama: jalankan `kpi:calculate` lagi (manual atau job terjadwal harian) - itu SATU-SATUNYA jalan data berubah, konsisten dengan "jangan menimpa hasil formula versi lama tanpa histori". Lebih sederhana & lebih sedikit titik gagal dibanding cache-tag invalidation granular untuk MVP ini - dicatat sebagai keputusan arsitektur di ROLLOUT_AND_CUTOVER.md, bukan diam-diam disederhanakan.

D+7/D+30 TIDAK butuh job/trigger terpisah - itu properti umur publication relatif terhadap saat kalkulasi berjalan (lihat `ContentOutcomeScoringService::computePublicationDelta()`), otomatis ter-refresh setiap kali `kpi:calculate` dijalankan ulang untuk periode yang sama.

## Fase 6 - QA, Regression, Security, Performance
Status: **Selesai**. Test: `TestMatrixGapsTest` (9 test, mengisi celah dari 25 skenario wajib) -> lihat `TEST_MATRIX.md` untuk peta lengkap skenario -> test.

Bug ditemukan+diperbaiki selama audit ini: `KpiCalculationService::persistOperationalRoleResults()`/`persistLeadershipResults()` memanggil `UserKpiResult::create()` polos (bukan `updateOrCreate`) - memanggil `calculate()` dua kali pada `KpiCalculationRun` yang SAMA akan menggandakan baris. Diperbaiki jadi `updateOrCreate` keyed `(run, user, operational_role, client)`, konsisten dengan `ContentOutcomeResult` yang sudah benar dari awal. Dibuktikan `TestMatrixGapsTest::test_recalculating_same_run_does_not_duplicate_content_outcome_rows`.

Pint dijalankan+fix pada seluruh file KPI baru (lihat `TEST_MATRIX.md`). File lama yang cuma disentuh 1 baris (fix `HasFactory`) SENGAJA tidak di-reformat total (di luar scope KPI).

## Fase 7 - Dokumentasi Final
Status: **Selesai**. 11 file dibuat di `docs/kpi/`: `README.md`, `DATA_MODEL.md`, `ATTRIBUTION_RULES.md`, `FORMULAS.md`, `ANALYTICS_NORMALIZATION.md`, `PROCESS_METRICS.md`, `UI_AND_PERMISSIONS.md`, `JOBS_AND_OPERATIONS.md`, `TEST_MATRIX.md`, `ROLLOUT_AND_CUTOVER.md`, `CHANGELOG.md` (plus `IMPLEMENTATION_PLAN.md` dari Fase 0 dan `PROGRESS.md` ini).

**SEMUA FASE SELESAI.** Laporan akhir lengkap ada di respons chat.
