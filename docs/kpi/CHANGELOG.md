# KPI System - Changelog

Dikelompokkan per fase, lalu per domain. Semua perubahan sesi ini (2026-09-02), belum di-commit (menunggu instruksi eksplisit).

## Koreksi Lanjutan 2026-09-02 (Period Attribution, Multi-Role Aktivitas, Client Breakdown, Lock, Trigger, UI)

Audit ulang atas "Koreksi Produk 2026-09-02" (bagian di bawah) menemukan implementasi itu MASIH menyimpan beberapa bug arsitektur nyata, walau sudah benar soal "tidak ada role/tabel baru". Koreksi ini memperbaikinya TANPA mengubah keputusan arsitektur dasar (tetap tidak ada role/tabel/proses assignment baru).

### Bug yang Diperbaiki

1. **Scope periode bocor** - `KpiAttributionService::eligibleAssignments()` memfilter `created_at <= period_end` TANPA batas bawah, jadi assignment/konten lama ikut terhitung ulang SETIAP bulan berikutnya. Diganti total: atribusi sekarang berbasis AKTIVITAS AKTOR yang benar-benar terbukti DI DALAM periode (brief dibuat, status log berubah, publication dibuat, decision dilakukan) - lihat `ATTRIBUTION_RULES.md`.
2. **Multi-role dipaksa satu bucket** - `KpiRoleContextResolver` lama memilih satu role "utama" per user dengan priority fallback. Diganti dengan atribusi PER AKTIVITAS (`copywriterActivities()`, `productionActivities()`, `smoActivities()`) - satu user dengan beberapa aktivitas berbeda yang bisa dibuktikan mendapat beberapa baris KPI terpisah.
3. **SMO dikreditkan lewat status PIC, bukan aksi publish nyata** - SMO dulu dapat kredit publish HANYA kalau jadi PIC content item (dan sebaliknya, PIC otomatis dapat kredit publish walau bukan dia yang publish). Diganti: atribusi SMO SEKARANG murni dari `content_publications.published_by`, dan HANYA baris `recorded_via='manual'` (aksi manusia langsung) yang dipercaya - baris `auto_sync` (dibuat otomatis saat analytics sync mencocokkan post historis) TIDAK dipakai karena `published_by`-nya cuma user yang kebetulan memicu sync, bukan publisher asli. Instrumentasi baru: `content_publications.recorded_via` (migration `add_recorded_via_to_content_publications_table`, default `auto_sync`, diisi otomatis `manual` di 3 titik existing - TIDAK ADA langkah pengguna baru).
4. **Copywriter wajib jadi PIC untuk dapat KPI** - diperbaiki: atribusi Copywriter sekarang murni dari `content_brief_drafts.created_by` (brief dibuat di periode ini), tidak pernah mensyaratkan status PIC.
5. **`content_brief_drafts.take_by_user_id` diverifikasi TIDAK PERNAH diisi** oleh alur manapun (grep seluruh controller/service) - sengaja TIDAK dipakai sebagai sinyal aktor (kolom mati, mengikuti "jangan menebak").
6. **Filter klien tidak bekerja untuk staf produksi** - baris operasional (Copywriter/Content Creator/Graphic Designer/SMO) SELALU disimpan `client_id=NULL`, sementara filter UI memfilter `where('client_id', ...)`. Diperbaiki: `client_id` SEKARANG selalu diisi dari klien content item yang jadi bukti aktivitas, untuk operasional MAUPUN leadership.
7. **Manager/CEO produksi+leadership di klien yang sama akan saling menimpa** - karena kunci `(run,user,role,client)` sekarang dipakai KEDUA jalur (dulu operasional selalu `client_id=NULL` jadi tidak pernah bentrok dengan leadership). Diperbaiki dengan MERGE eksplisit (`KpiCalculationService::mergeProcessBreakdowns()`) - weighted average process score berdasar sample size, bukan overwrite diam-diam.
8. **Formula bootstrap tidak historis-aman & tidak concurrency-safe** - `KpiFormulaVersion::resolveCurrent()` dulu men-set `effective_from => now()` (backfill periode lama tidak pernah menemukan formula ini) dan versi `'default-'.now()->format('Ymd')` (bisa membuat baris baru SETIAP HARI). Diperbaiki: `effective_from` sentinel tetap `2000-01-01`, versi stabil `'default'`, `firstOrCreate` + catch `QueryException` untuk race dua job pertama.
9. **Lock command developer vs job background berbeda** - `CalculateKpi` pakai `Cache::lock` sendiri, `RecalculateKpiPeriod` cuma `ShouldBeUnique` (beda key/mekanisme, bisa jalan bersamaan). Diperbaiki: `App\Kpi\Support\KpiCalculationLock` dipakai BERSAMA oleh keduanya.
10. **Semua 9+ titik trigger selalu menjadwalkan "bulan berjalan"**, termasuk halaman Team Performance (membuka periode historis diam-diam menghitung bulan sekarang) dan sync/import historis (data bulan lalu tidak pernah memicu recalculation bulan yang benar). Diperbaiki: `KpiRecalculationTrigger` dapat method baru (`scheduleForDate`, `scheduleForDateRange`, `scheduleForContentItem`) - setiap titik memilih method sesuai timestamp AKTIVITAS SEBENARNYA. Ditemukan sekalian: backfill reach audience (180 hari) TIDAK PERNAH memicu trigger sama sekali - sekarang diperbaiki.
11. **Migration residual** - `2026_09_02_000019_remove_kpi_operational_role_architecture.php` sudah jadi no-op murni (tabel yang dihapusnya tidak pernah dibuat lagi, `role_id` sudah dibuat langsung di migration `000018`) - DIHAPUS, fresh migration sekarang langsung ke schema final.
12. **UI terasa seperti panel developer** - label teknis (Process/Direct Outcome/Portfolio/Composite/Coverage/Sample size) diganti istilah Indonesia umum dengan tooltip (Kualitas Proses/Hasil Konten/Performa Klien/Nilai KPI/Kelengkapan Data/Jumlah Data - lihat `UI_AND_PERMISSIONS.md`); judul "Akurasi Prediksi AI Delay Risk" (menyiratkan accuracy keseluruhan) diganti "Ketepatan Prediksi Risiko Tinggi" (yang benar-benar dihitung adalah precision kelas Risiko Tinggi); referensi `docs/kpi/...` dan istilah "Access role (RBAC)" dihapus dari halaman pengguna (`show.blade.php`).
13. **`TeamPerformanceDashboardService::contentOutcomesForUserRole()` bergantung pada method yang sudah dihapus** (`groupContentItemIdsByRole()`) dan salah asumsi `client_id!==null` selalu berarti "leadership" (sekarang operasional juga selalu punya client_id). Diganti `contentOutcomesForResult(UserKpiResult $result)` - membaca `contributing_content_item_ids`/`leadership_decided_content_item_ids` LANGSUNG dari `component_breakdown` yang dipersist saat kalkulasi (lebih akurat untuk audit - snapshot apa adanya, tidak berubah walau assignment/role user berubah belakangan).
14. **(Ditemukan saat audit regresi, tidak terkait KPI)** `InstagramAudienceInsightsService::igUserId(): string` melempar `TypeError` mentah kalau `ApiIntegration.external_account_id` null (kolom nullable di skema) - dieksekusi *synchronous* lewat `AnalyticsSyncOrchestrator::dispatchOne()` (QUEUE_CONNECTION=sync di testing). Tidak reproduce di 4 percobaan (isolated + 3x full suite), tapi root cause nyata - diperbaiki melempar `InstagramApiException(AUTHENTICATION)` terkontrol (kategori sudah ada, ditangani `markFailed()`+`fail()` oleh pemanggil) alih-alih crash mentah.

### File Diubah (Selain yang Tercantum di "Koreksi Produk" di Bawah)

- **Migration baru**: `2026_09_02_000020_add_recorded_via_to_content_publications_table.php`.
- **Migration dihapus**: `2026_09_02_000019_remove_kpi_operational_role_architecture.php` (no-op, residual).
- **Baru**: `app/Kpi/Support/KpiCalculationLock.php`.
- `app/Models/KpiFormulaVersion.php` - `resolveCurrent()` ditulis ulang (historis-aman, concurrency-safe).
- `app/Models/ContentPublication.php` - `recorded_via` + konstanta `RECORDED_VIA_MANUAL`/`RECORDED_VIA_AUTO_SYNC` + `isReliablyAttributedToPublisher()`.
- `app/Kpi/Services/KpiAttributionService.php` - disederhanakan jadi `contentItemIdsPublishedInPeriod()` saja (atribusi role pindah ke `KpiRoleContextResolver`).
- `app/Kpi/Services/KpiRoleContextResolver.php` - ditulis ulang total: `copywriterActivities()`, `productionActivities()`, `smoActivities()` (method lama `groupContentItemIdsByRole()` dihapus).
- `app/Kpi/Services/KpiCalculationService.php` - ditulis ulang total: `buildOperationalResults()`/`buildLeadershipResults()`/`persistResults()`/`composeMergedResult()`/`mergeProcessBreakdowns()`, cache portfolio per-run.
- `app/Kpi/Services/RoleProcessKpiService.php` - `scoreSmo()` menerima `Collection<ContentPublication>` milik user itu sendiri (bukan `contentItemIds` dari status PIC).
- `app/Kpi/Services/TeamPerformanceDashboardService.php` - `contentOutcomesForUserRole()` diganti `contentOutcomesForResult()`; dependency `KpiRoleContextResolver` dibuang (tidak dipakai lagi).
- `app/Kpi/Services/KpiRecalculationTrigger.php` - method baru `scheduleForDate()`/`scheduleForDateRange()`/`scheduleForContentItem()`.
- `app/Jobs/RecalculateKpiPeriod.php` - pakai `KpiCalculationLock` sebelum membuat run.
- `app/Console/Commands/CalculateKpi.php` - pakai `KpiCalculationLock` + `KpiFormulaVersion::resolveCurrent()`, pesan error `KpiReferenceSeeder` dihapus.
- `app/Http/Controllers/TeamPerformanceController.php` - `resolveRunWithAutoDispatch()` pakai `schedule($periodStart,$periodEnd)`; `show()` pakai `contentOutcomesForResult()`.
- `app/Http/Controllers/ContentItemController.php`, `ContentPublicationController.php`, `AudienceController.php`, `app/Jobs/SyncInstagramAnalyticsJob.php`, `SyncTikTokAnalyticsJob.php`, `SyncInstagramAudienceJob.php` - trigger call diganti method periode yang sesuai.
- `app/Services/InstagramAudienceInsightsService.php` - `igUserId()` melempar exception terkontrol, bukan TypeError mentah (bug pre-existing, tidak terkait KPI).
- Views `team-performance/**` - terminologi Indonesia + tooltip, judul AI Delay Risk, hapus referensi docs/RBAC.
- 14 test baru/ditulis-ulang di `tests/Feature/Kpi/` (lihat `TEST_MATRIX.md`).

### Test

64/64 pass di `Tests\Feature\Kpi` (naik dari 42), **398/398 pass** full regression suite, fresh migration terverifikasi bersih (tanpa concurrent process lain menyentuh database testing yang sama).

## Koreksi Produk 2026-09-02 (Menggantikan Fase 1-6 di Bawah untuk Arsitektur Role/Assignment)

Implementasi awal (Fase 1-6 di bawah) menambah tabel/role KPI khusus - **DIBATALKAN TOTAL** setelah keputusan produk eksplisit: *"Jangan menambah role baru. Hapus konsep Account Lead, Reviewer, Publisher, dan operational role terpisah. Jangan membuat proses assignment khusus KPI."* Bagian ini adalah SATU-SATUNYA deskripsi arsitektur yang benar-benar berjalan sekarang - bagian "Fase 1-6" di bawah tetap disimpan sebagai catatan historis proses kerja, BUKAN deskripsi sistem saat ini.

### File Dihapus
- Migration: `create_operational_roles_table`, `create_client_role_assignments_table`, `create_content_item_operational_assignments_table`.
- Model: `OperationalRole`, `ClientRoleAssignment`, `ContentItemOperationalAssignment`.
- Enum: `OperationalRoleName`, `ResponsibilityType`.
- Controller: `ClientRoleAssignmentController`, `ContentItemOperationalAssignmentController` + route-nya (`client-management.role-assignments.*`, `content-items.operational-assignments.*`).
- Factory: `OperationalRoleFactory`, `ClientRoleAssignmentFactory`, `ContentItemOperationalAssignmentFactory`.
- Seeder: `KpiReferenceSeeder` (dan pemanggilannya di `DatabaseSeeder`).
- Dokumentasi: `docs/kpi/ROLLOUT_AND_CUTOVER.md` (strategi cutover manual - sudah tidak relevan, tidak ada cutover manual di arsitektur baru).
- Test: `DomainModelTest.php`, `KpiAttributionServiceTest.php`, `AssignmentManagementControllerTest.php`, `TestMatrixGapsTest.php` (menguji arsitektur yang dihapus - skenario yang masih relevan ditulis ulang, lihat `TEST_MATRIX.md`).

### File Dipertahankan (Tidak Diubah Skema/Perilakunya)
`content_item_assignments`, `roles`/`user_roles`, `user_client_assignments`, `content_status_logs`, `content_plan_status_logs`, `content_revisions`, `content_publications`, `content_metrics`, `content_metric_snapshots`, `audience_insights`, seluruh alur Content Plan/brief/produksi/review/revisi/scheduling/publishing/client management/user management EXISTING.

### File Diubah
- `database/migrations/2026_09_02_000018_create_user_kpi_results_table.php` - `operational_role_id` (FK ke tabel yang dihapus) diganti `role_id` (FK ke `roles.id` EXISTING).
- Migration baru: `2026_09_02_000019_remove_kpi_operational_role_architecture.php` - idempotent, menghapus sisa kolom/tabel arsitektur lama di environment mana pun yang sempat menjalankan migration lama.
- `app/Models/{Client,ContentItem,User}.php` - relasi ke tabel yang dihapus (`roleAssignments`, `operationalAssignments`, `clientRoleAssignments`) dibuang.
- `app/Models/UserKpiResult.php` - `operational_role_id`/`operationalRole()` -> `role_id`/`role()`.
- `app/Models/KpiFormulaVersion.php` - method baru `resolveCurrent()` (self-bootstrapping, tidak butuh seeder manual).
- `app/Kpi/Formula/KpiFormulaConfig.php` - bucket bobot `account_lead` -> `smo`.
- `app/Kpi/Services/KpiAttributionService.php` - sumber PIC: `ContentItemAssignment` EXISTING (bukan tabel yang dihapus).
- `app/Kpi/Services/KpiRoleContextResolver.php` (baru) - resolusi role KPI dari `roles`/`user_roles` EXISTING + jenis aktivitas, menggantikan tabel `operational_roles`.
- `app/Kpi/Services/RoleProcessKpiService.php` - koreksi #4 (first handoff = `in_progress->waiting_review`), #5 (internal revision dibatasi periode), #6 (analytics match rate per publication/platform), parameter `operationalRoleId` dibuang.
- `app/Kpi/Services/ContentOutcomeScoringService.php` - koreksi #9 (minimum peer sample dienforce), #10 (target dikecualikan dari peer pool sendiri), #11 (peer pool hanya Full coverage), #12 (gating sample sebelum percentile rank, tidak pernah fallback 50 netral).
- `app/Kpi/Services/ClientPortfolioScoringService.php` - koreksi #1/#2 (delta per content, bukan sum cumulative), #3 (engagement dari delta raw metric), #8 (loop semua platform aktif, bukan platform pertama), plus fix independen: `AudienceInsight` CSV-import (`TYPE_GENERIC`) sekarang ikut terbaca (dulu hanya `TYPE_SUMMARY`).
- `app/Kpi/Services/WorkloadScoringService.php` - sumber: `ContentItemAssignment` EXISTING, workload unweighted count (kolom `planned_effort_points` dihapus karena butuh tabel assignment baru).
- `app/Kpi/Services/KpiCalculationService.php` - koreksi #7 (SEMUA role produksi eligible untuk portfolio_outcome, bukan cuma SMO), leadership KPI murni dari `ContentStatusLog` decision log.
- `app/Kpi/Services/TeamPerformanceDashboardService.php` - koreksi #13 (Data Belum Cukup tidak pernah menampilkan composite), kartu baru (kuota, handoff, adherence, workload, bottleneck, blocker), `latestCompletedRunAnyPeriod()`/`isStale()` untuk fallback snapshot.
- `app/Kpi/Services/KpiRecalculationTrigger.php` + `app/Jobs/RecalculateKpiPeriod.php` (baru) - background job otomatis, debounce+unique lock, dipanggil dari `WorkflowStatusService`, `ContentItemController`, `ContentRevisionController`, `ContentPublicationController`, 3 job sync analytics, `AudienceController` (satu baris tambahan per titik, tidak mengubah alur/return value).
- `app/Http/Controllers/TeamPerformanceController.php` - auto-dispatch kalkulasi saat halaman dibuka & data stale/kosong, tanpa instruksi command manual.
- View `team-performance/**` - filter `role_id`, banner status otomatis, empty state "Data KPI sedang disiapkan otomatis" (bukan instruksi command).

### Formula yang Diperbaiki
Lihat penjelasan lengkap di `FORMULAS.md` dan `ANALYTICS_NORMALIZATION.md`. Ringkas: #1/#2 visibility growth dari delta per content (bukan sum cumulative), #3 engagement dari delta raw metric, #4 first handoff diukur dari transisi yang benar, #5 internal revision dibatasi periode, #6 analytics match rate per publication/platform, #7 portfolio_outcome untuk semua role produksi, #8 client portfolio menghitung semua platform aktif, #9-#12 disiplin peer sample (minimum dienforce, target dikecualikan, hanya Full coverage, tidak ada fallback netral 50), #13 Data Belum Cukup tidak pernah menampilkan composite, #14 bucket bobot `smo` bukan `account_lead`, #15 filter per-klien dari relasi/aktivitas EXISTING.

### Event Background yang Dipasang
9 titik trigger (lihat tabel lengkap di `JOBS_AND_OPERATIONS.md`): transisi status workflow, release ke produksi, PIC diganti, revisi ditambahkan, publication ditautkan, 3 job sync analytics (Instagram/TikTok/audience), import audience CSV. Semua memanggil `KpiRecalculationTrigger::scheduleCurrentPeriod()` (debounce 60 detik + `ShouldBeUnique` 5 menit) - tidak ada perubahan pada return value/alur titik-titik itu.

### Test
36 test di `Tests\Feature\Kpi` (14 baru: `KpiRoleContextResolverTest`, `ArchitectureIntegrityTest`, `KpiRecalculationTriggerTest`, plus penambahan skenario di `KpiCalculationServiceTest`/`RoleProcessKpiServiceTest`/`TeamPerformanceControllerTest`) - seluruhnya pass. Full regression suite: **370/370 pass**, zero regresi ke fitur di luar KPI.

### Bukti Tanpa Command Manual
`TeamPerformanceControllerTest::test_index_renders_without_error_when_no_calculation_run_exists` membuka halaman tanpa satu pun `KpiCalculationRun` di database, memverifikasi teks "Data KPI sedang disiapkan otomatis" tampil, `assertDontSee('kpi:calculate')` dan `assertDontSee('php artisan')` lolos, DAN memverifikasi (`Queue::assertPushed`) job kalkulasi background benar-benar ter-dispatch otomatis oleh controller - tanpa satu baris kode pun di luar test yang memanggil command.

---

## Fase 0 - Audit & Architecture Plan (Historis - Sebagian Digantikan Koreksi di Atas)

### Documentation
- `docs/kpi/IMPLEMENTATION_PLAN.md` (baru) - audit lengkap, source of truth per KPI, ERD Mermaid, risiko, rencana migrasi.

## Fase 1 - Database & Domain Model

### Database/Migration (baru, 9 file, `2026_09_02_000010` - `000018`)
- `create_operational_roles_table`
- `create_client_role_assignments_table`
- `create_content_item_operational_assignments_table`
- `add_promotion_fields_to_content_publications_table` (`is_paid`, `promotion_type`, `ad_spend`, `campaign_reference`)
- `add_returned_count_to_content_brief_drafts_table`
- `create_kpi_formula_versions_table`
- `create_kpi_calculation_runs_table`
- `create_content_outcome_results_table`
- `create_user_kpi_results_table`

### Model (baru)
`OperationalRole`, `ClientRoleAssignment`, `ContentItemOperationalAssignment`, `KpiFormulaVersion`, `KpiCalculationRun`, `ContentOutcomeResult`, `UserKpiResult`.

### Model (diubah)
- `Client.php`, `ContentItem.php`, `User.php` - relasi baru (`roleAssignments`/`operationalAssignments`/`clientRoleAssignments`).
- `ContentBriefDraft.php` - fillable `returned_count`.
- `ContentPublication.php` - fillable + cast promotion fields, method `isOrganic()`.
- **Bug fix (pre-existing, ditemukan tak sengaja, diperbaiki karena WAJIB untuk factory Fase 1)**: `Client`, `ContentItem`, `ContentPlan`, `ClientCategory`, `Platform`, `ContentType`, `ContentItemAssignment`, `ContentRevision`, `ContentStatusLog`, `ContentWorkflow` - semua meng-import `HasFactory` tapi tidak pernah `use` trait itu di class body, membuat `Model::factory()` selalu gagal.

### Enum (baru)
`ResponsibilityType`, `CoverageStatus`, `KpiStatusLabel`, `MeasurementWindow`, `ContentFormatGroup`, `OperationalRoleName`.

### Value Object (baru)
`App\Kpi\Formula\KpiFormulaConfig`.

### Seeder
- `KpiReferenceSeeder` (baru) - bootstrap `operational_roles` + formula version default.
- `DatabaseSeeder.php` (diubah) - panggil `KpiReferenceSeeder`.

### Factory
Baru: `OperationalRoleFactory`, `ClientRoleAssignmentFactory`, `ContentItemOperationalAssignmentFactory`, `KpiFormulaVersionFactory`, `KpiCalculationRunFactory`, `ContentPublicationFactory`, `ContentMetricSnapshotFactory`, `AudienceInsightFactory`, `UserClientAssignmentFactory`, `ContentBriefDraftFactory`, `ContentOutcomeResultFactory`, `UserKpiResultFactory`.
Diperbaiki (tadinya stub kosong): `ClientFactory`, `ClientCategoryFactory`, `ContentPlanFactory`, `ContentItemFactory`, `ContentWorkflowFactory`, `PlatformFactory`, `ContentTypeFactory`, `ContentRevisionFactory`, `ContentStatusLogFactory`, `ContentItemAssignmentFactory`.

### Test (baru)
`tests/Feature/Kpi/DomainModelTest.php` (5 skenario wajib Fase 1).

## Fase 2 - Attribution & KPI Calculation Engine

### Service (baru, `App\Kpi\Services`)
`KpiAttributionService`, `ContentOutcomeScoringService`, `ClientPortfolioScoringService`, `RoleProcessKpiService`, `WorkloadScoringService`, `KpiCoverageService`, `KpiCalculationService`.

### Support/DTO (baru)
`App\Kpi\Support\RobustStats`; `App\Kpi\Dto\{ContentOutcomeScore,ProcessScoreBreakdown,CompositeKpiResult,PublicationDelta}`.

### Test (baru)
`ContentOutcomeScoringServiceTest`, `KpiAttributionServiceTest`, `RoleProcessKpiServiceTest`, `KpiCalculationServiceTest`.

## Fase 3 - Application Service, Controller, Route, Permission

### Service (baru)
`App\Kpi\Services\TeamPerformanceDashboardService`.

### Controller (baru)
`ClientRoleAssignmentController`, `ContentItemOperationalAssignmentController`.

### Controller (ditulis ulang)
`TeamPerformanceController` - dari query langsung di controller jadi delegasi penuh ke `TeamPerformanceDashboardService`; route baru `show()` (detail per anggota).

### Route (`routes/web.php`)
- `team-performance.show` (baru).
- `client-management.role-assignments.store/destroy` (baru).
- `content-items.operational-assignments.store/destroy` (baru).
- Semua di dalam middleware group permission YANG SUDAH ADA - tidak ada permission baru.

### Test (baru)
`TeamPerformanceControllerTest`, `AssignmentManagementControllerTest`.

## Fase 4 - Views & UX Team Performance

### View/Component (baru)
`team-performance/partials/tab-ringkasan.blade.php`, `team-performance/partials/tab-anggota.blade.php`, `team-performance/show.blade.php`.

### View (diubah)
`team-performance/index.blade.php` - 2 tab jadi 3 tab (Ringkasan Tim/Anggota/Kehadiran), aksesibilitas (`role="tablist"` dst).

### View (dihapus)
`team-performance/partials/tab-performa.blade.php` (leaderboard lama, digantikan tab-ringkasan.blade.php).

## Fase 5 - Job, Command, Cache, Scheduling

### Job/Command (baru)
`App\Console\Commands\CalculateKpi` (`php artisan kpi:calculate`).

### Route console (`routes/console.php`, diubah)
Tambah `Schedule::command('kpi:calculate')->dailyAt('03:00')`.

### Test (baru)
`CalculateKpiCommandTest`.

## Fase 6 - QA, Regression, Security, Performance

### Bug fix (ditemukan & diperbaiki selama verifikasi test)
- `KpiCalculationService` - `UserKpiResult::create()` diganti `updateOrCreate()` (keyed run+user+role+client) supaya recalculation pada run yang SAMA tidak menggandakan baris (idempotency yang tadinya cuma benar untuk `ContentOutcomeResult`, sekarang konsisten untuk `UserKpiResult` juga).
- Test helper `array_key_exists` vs `??` untuk override eksplisit `null` di test fixture.
- Test period kalender tetap vs factory default `now()`-based - assignment jadi di luar periode test.

### Test (baru)
`TestMatrixGapsTest` (9 skenario tambahan dari 25 skenario wajib yang belum tercakup file lain).

### Linter
`laravel/pint` dijalankan+fix pada seluruh file BARU sesi ini (lihat `TEST_MATRIX.md` "Formatter/Linter" untuk cakupan persis).

## Fase 7 - Dokumentasi Final

### Documentation (baru)
`docs/kpi/{README,PROGRESS,DATA_MODEL,ATTRIBUTION_RULES,FORMULAS,ANALYTICS_NORMALIZATION,PROCESS_METRICS,UI_AND_PERMISSIONS,JOBS_AND_OPERATIONS,TEST_MATRIX,ROLLOUT_AND_CUTOVER,CHANGELOG}.md`.
