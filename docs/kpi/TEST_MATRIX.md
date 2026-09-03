# KPI System - Test Matrix

> **Koreksi produk 2026-09-02**: `DomainModelTest`, `KpiAttributionServiceTest`, `AssignmentManagementControllerTest`, `TestMatrixGapsTest` (menguji arsitektur `operational_roles`/dst yang sudah dihapus) dihapus, skenario relevan ditulis ulang.
>
> **Koreksi lanjutan 2026-09-02**: `KpiRoleContextResolverTest` ditulis ULANG SEKALI LAGI (API lama `groupContentItemIdsByRole()` diganti `copywriterActivities()`/`productionActivities()`/`smoActivities()` berbasis aktivitas aktor - lihat `ATTRIBUTION_RULES.md`); ditambah `KpiFormulaVersionTest` (formula bootstrap historis+concurrency) dan `ArchitectureIntegrityTest` (jaminan struktural tidak ada role/tabel baru); `KpiCalculationServiceTest`/`TeamPerformanceControllerTest`/`KpiRecalculationTriggerTest` mendapat banyak skenario baru untuk period eligibility, multi-role via aktivitas, atribusi SMO berbasis publish, merge Manager/CEO produksi+leadership, filter klien, dan trigger berbasis timestamp aktivitas.

Semua test memakai data SINTETIS (factory) - tidak ada dependency ke database lokal. Menjalankan `php artisan test --filter=Kpi` mengeksekusi seluruh namespace `Tests\Feature\Kpi`.

## Skenario Wajib -> Test

| # | Skenario | Test |
|---|---|---|
| 1 | Assignment lama (mis. Januari) tanpa aktivitas di periode yang dihitung (mis. September) TIDAK ikut terhitung | `KpiCalculationServiceTest::test_january_assignment_without_september_activity_is_excluded_from_september_kpi`, `KpiRoleContextResolverTest::test_production_activity_requires_status_log_activity_within_period` |
| 2 | Content yang dipublikasikan di bulan X masuk KPI bulan X, bukan bulan berikutnya | `KpiCalculationServiceTest::test_content_published_in_september_counts_toward_september_not_october` |
| 3 | Satu konten, multi-PIC - outcome IDENTIK untuk setiap PIC, company aggregate tetap 1x | `KpiCalculationServiceTest::test_multi_pic_content_item_yields_identical_direct_outcome_for_every_pic` |
| 4 | Satu user, beberapa AKTIVITAS berbeda (brief + produksi) pada content yang sama menghasilkan DUA atribusi terpisah | `KpiCalculationServiceTest::test_user_with_two_provable_activities_on_same_content_gets_two_attributions`, `KpiRoleContextResolverTest::test_user_with_multiple_roles_is_separated_by_content_type_activity` |
| 5 | Admin (view-only) tidak pernah dapat konteks role KPI | `KpiRoleContextResolverTest::test_admin_role_never_gets_a_kpi_role_context` |
| 6 | Copywriter mendapat KPI dari brief yang ditulis WALAU bukan PIC | `KpiCalculationServiceTest::test_copywriter_gets_kpi_without_being_pic`, `KpiRoleContextResolverTest::test_copywriter_activity_does_not_require_being_pic` |
| 7 | SMO mendapat KPI dari publication yang benar-benar dia publikasikan (`published_by`) WALAU bukan PIC | `KpiCalculationServiceTest::test_smo_gets_kpi_based_on_published_by_without_being_pic`, `KpiRoleContextResolverTest::test_smo_activity_does_not_require_being_pic` |
| 8 | PIC yang BUKAN publisher asli tidak mendapat baris/process score KPI SMO | `KpiCalculationServiceTest::test_pic_who_is_not_publisher_gets_no_smo_kpi_row`, `KpiRoleContextResolverTest::test_pic_who_is_not_the_publisher_gets_no_smo_activity` |
| 9 | Publication hasil auto-sync (bukan aksi manusia) tidak dipakai atribusi SMO | `KpiRoleContextResolverTest::test_auto_sync_publication_is_excluded_from_smo_activity` |
| 10 | Satu konten, Instagram dan TikTok - dihitung SATU KALI di `content_outcome_results` | `KpiCalculationServiceTest::test_multi_platform_content_item_counted_once_in_outcome_results` |
| 11-13 | Analytics coverage Full/Partial/Unavailable dibedakan tegas | `ContentOutcomeScoringServiceTest` |
| 14 | Konten viral/FYP tidak mendominasi peer percentile | `ContentOutcomeScoringServiceTest::test_viral_outlier_does_not_dominate_peer_percentile` |
| 15 | Carousel dinilai lewat percentile rank sendiri, bukan raw views | `ContentOutcomeScoringServiceTest::test_carousel_scored_via_own_percentile_rank_not_raw_views` |
| 16 | Paid content dikecualikan dari peer pool organic | `KpiCalculationServiceTest::test_paid_publication_is_excluded_from_organic_peer_pool` |
| 17 | Client revision terpisah dari internal revision | `RoleProcessKpiServiceTest::test_client_revision_is_separated_from_internal_revision_rate` |
| 18 | Internal revision DI LUAR periode KPI tidak ikut terhitung | `RoleProcessKpiServiceTest::test_internal_revision_outside_period_is_not_counted` |
| 19 | Waktu tunggu klien tidak masuk active production duration | `RoleProcessKpiServiceTest::test_client_waiting_time_is_excluded_from_active_production_duration` |
| 20 | First handoff = `in_progress->waiting_review`, BUKAN `brief_ready->in_progress` | `RoleProcessKpiServiceTest::test_first_handoff_is_in_progress_to_waiting_review_not_brief_ready_to_in_progress` |
| 21 | Access role TANPA aktivitas apa pun = tidak ada baris KPI | `KpiCalculationServiceTest::test_access_role_without_assignment_gets_no_kpi_result` |
| 22 | Manager/CEO dengan aktivitas produksi DAN leadership pada KLIEN BERBEDA mendapat baris role terpisah | `KpiCalculationServiceTest::test_manager_with_production_and_leadership_on_different_clients_gets_separate_rows` |
| 23 | Manager/CEO dengan aktivitas produksi DAN leadership pada KLIEN YANG SAMA di-merge jadi satu baris (bukan salah satu overwrite) | `KpiCalculationServiceTest::test_manager_with_production_and_leadership_on_same_client_merges_into_one_row` |
| 24 | Client portfolio outcome diberikan ke SETIAP PIC eligible, bukan satu "PIC utama" | `KpiCalculationServiceTest::test_client_portfolio_outcome_given_to_every_eligible_pic` |
| 25 | Satu user dengan aktivitas pada DUA klien berbeda di periode yang sama mendapat breakdown dua baris per-klien | `KpiCalculationServiceTest::test_user_with_activity_on_two_clients_gets_two_client_breakdown_rows` |
| 26 | Filter klien menampilkan Copywriter/Creator/Designer/SMO yang terlibat pada klien itu | `TeamPerformanceControllerTest::test_client_filter_shows_production_staff_involved_with_that_client` |
| 27 | Minimum peer sample DIENFORCE, target dikecualikan dari peer pool sendiri, peer pool hanya Full coverage, sample kurang tidak jadi skor netral 50 | `ContentOutcomeScoringServiceTest` |
| 28 | Formula default dapat dipakai untuk periode HISTORIS | `KpiFormulaVersionTest::test_default_formula_applies_to_historical_periods` |
| 29 | Dua/tiga bootstrap formula bersamaan tidak membuat duplicate version | `KpiFormulaVersionTest::test_repeated_bootstrap_calls_do_not_create_duplicate_default_versions` |
| 30 | Command dan background job untuk periode/formula SAMA tidak berjalan bersamaan (lock eksekusi bersama) | `KpiRecalculationTriggerTest::test_job_skips_when_command_already_holds_the_same_execution_lock` |
| 31 | Recalculation tidak menggandakan data (idempotent) | `KpiCalculationServiceTest::test_recalculation_with_same_input_is_deterministic`, `CalculateKpiCommandTest::test_running_twice_for_same_period_creates_two_separate_runs` |
| 32 | Unauthorized user tidak dapat melihat KPI di luar scope | `TeamPerformanceControllerTest::test_content_creator_cannot_access_team_performance`, `test_member_detail_route_requires_same_permission_as_index` |
| 33 | Data Belum Cukup TIDAK PERNAH menampilkan angka Nilai KPI | `TeamPerformanceControllerTest::test_data_belum_cukup_row_never_displays_a_composite_number` |
| 34 | Membuka periode HISTORIS di halaman Team Performance men-dispatch job untuk periode yang DIPILIH, bukan bulan berjalan | `KpiRecalculationTriggerTest::test_opening_a_historical_period_on_team_performance_page_dispatches_that_period` |
| 35 | Satu tanggal/rentang tanggal historis dijadwalkan sebagai bulan kalender yang tepat (termasuk rentang lintas beberapa bulan) | `KpiRecalculationTriggerTest::test_schedule_for_date_dispatches_the_calendar_month_containing_that_date`, `test_schedule_for_date_range_dispatches_every_calendar_month_covered`, `test_schedule_for_date_range_within_same_month_dispatches_once` |
| 36 | PIC diganti men-dispatch bulan berjalan + bulan publication content itu yang sudah ada | `KpiRecalculationTriggerTest::test_schedule_for_content_item_covers_current_period_and_its_publication_months` |
| 37 | Halaman tanpa run sama sekali menampilkan "Data KPI sedang disiapkan otomatis" TANPA instruksi command/path dokumentasi, dan otomatis men-dispatch kalkulasi | `TeamPerformanceControllerTest::test_index_renders_without_error_when_no_calculation_run_exists` |
| 38 | Run yang basi tetap menampilkan snapshot lama SAMBIL kalkulasi baru di-dispatch | `TeamPerformanceControllerTest::test_stale_run_still_shows_previous_snapshot_while_recalculation_is_dispatched` |
| 39 | UI (termasuk empty state member detail) tidak menampilkan `php artisan`, `kpi:calculate`, atau path `docs/kpi` | `TeamPerformanceControllerTest::test_index_renders_without_error_when_no_calculation_run_exists`, `test_member_detail_shows_kpi_results_once_a_run_exists`, `test_member_detail_empty_state_does_not_reference_developer_docs` |
| 40 | Fresh migration tidak membuat tabel operational role | `ArchitectureIntegrityTest::test_operational_role_tables_do_not_exist` |
| 41 | Tidak ada role Account Lead/Reviewer/Publisher, tidak ada class model/controller arsitektur lama | `ArchitectureIntegrityTest::test_operational_role_classes_do_not_exist`, `test_seeded_roles_never_include_invented_kpi_roles` |
| 42 | Full suite lulus tanpa menghapus test relevan | Lihat hasil run di `CHANGELOG.md` |

## Test Tambahan (Relevan, Bukan Bagian Skenario Wajib)

- `CalculateKpiCommandTest` - self-bootstrap formula (koreksi lanjutan #5, TIDAK PERNAH gagal karena "belum ada formula"), gagal jelas HANYA saat `--formula-version` eksplisit tidak ditemukan, idempotency, lock.
- `ContentOutcomeScoringServiceTest` - detail formula outcome per format (video/desain), coverage, peer pool.
- Test suite existing lain (route matrix, seeder, dst) - TIDAK diubah, dipastikan tetap hijau (full regression, lihat di bawah).

## Menjalankan

```bash
php artisan test --filter=Kpi   # seluruh test KPI (namespace Tests\Feature\Kpi)
php artisan test                # full regression suite
```

Status per commit terakhir koreksi lanjutan ini: **64/64 pass** di namespace Kpi (dari 42 sebelum koreksi lanjutan), **398/398 pass** full suite, zero regresi ke luar `Tests\Feature\Kpi`.

## Query Performance & N+1

- `KpiAttributionService::contentItemIdsPublishedInPeriod()` - SATU query untuk seluruh content item yang relevan periode ini.
- `KpiCalculationService::portfolioScoreForClient()` - memoized in-memory PER PEMANGGILAN `calculate()` (`$portfolioScoreCache`) - banyak user/role yang berbagi client yang sama dalam satu run tidak menghitung ulang analytics client itu berkali-kali (koreksi lanjutan, ditemukan saat audit ulang N+1 setelah atribusi jadi per-client).
- `ContentOutcomeScoringService::buildPeerPool()` - SATU query batch untuk semua kandidat publication + eager load (`with(['contentItem.contentType', 'platform'])`) - tidak ada query di dalam loop per peer.
- `KpiCalculationService::persistContentOutcomes()` - `ContentItem::whereIn('id', $contentItemIds)->with('contentType')->get()` sekali di awal, bukan `find()` per item di dalam loop.
