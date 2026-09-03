# KPI System - Process Metrics

> **Koreksi produk 2026-09-02 (#4/#5)**: `first_handoff_on_time_rate` DIPERBAIKI - dulu salah mengukur dari `brief_ready -> in_progress` (itu "mulai kerja", bukan handoff), sekarang dari `in_progress -> waiting_review` (staf BENAR-BENAR menyerahkan hasil untuk direview). `internal_revision_rate` sekarang DIBATASI ke periode KPI yang diminta (dulu menghitung revisi dari kapan pun dalam histori content, termasuk dari luar periode).

Diimplementasikan `App\Kpi\Services\RoleProcessKpiService`. Source of truth: `ContentStatusLog` (waktu/aktor transisi, `approval_type='correction'` SELALU dikecualikan), `ContentRevision` (internal vs client sudah terpisah struktural), `ContentBriefDraft` (waktu brief), `content_item_assignments` EXISTING (PIC - bukan tabel assignment khusus KPI).

## Aturan Umum

1. **Median/percentile, bukan average** - `RobustStats::median()` dipakai di semua metrik durasi.
2. **Waktu tunggu klien TIDAK PERNAH masuk active production time PIC** - `scoreProductionRole()` menjumlahkan HANYA segmen `in_progress -> waiting_review berikutnya`; waktu di status `revision`/`waiting_review` itu sendiri secara konstruksi berada DI LUAR span manapun yang dihitung. Dibuktikan `RoleProcessKpiServiceTest::test_client_waiting_time_is_excluded_from_active_production_duration`.
3. **Client revision vs internal revision terpisah** - `ContentRevision.requested_by_client_id` (client) vs `requested_by_user_id` (internal). HANYA internal revision yang jadi komponen process quality individu. Client revision jadi konteks kualitas tim/klien (bisa ditampilkan terpisah), TIDAK otomatis menurunkan KPI individu. Dibuktikan `RoleProcessKpiServiceTest::test_client_revision_is_separated_from_internal_revision_rate`.
4. **Revisi dihitung sebagai rasio UNIQUE content item**, bukan total baris/komentar mentah - `distinct('content_item_id')`.
5. **Draft bukan active workload** - `WorkflowTransitions::INACTIVE_STATUSES` (`draft`, `uploaded`, `cancelled`) dikecualikan di `WorkloadScoringService`.
6. **Workload dihitung sebagai jumlah content item aktif** (`WorkloadScoringService::activeWorkloadCount()`, unweighted) - kolom pembobotan (`planned_effort_points`) SENGAJA tidak ditambahkan karena butuh tabel/kolom assignment KPI khusus baru, dilarang keputusan produk.
7. **Attendance terpisah total**, tidak digabung ke productivity score (tab Kehadiran tidak disentuh).
8. **Akurasi AI Delay-Risk = model health, bukan employee KPI** - tetap ditampilkan di tab Ringkasan Tim sebagai kartu terpisah dengan keterangan eksplisit, TIDAK masuk composite score siapa pun.
9. **Content Creator/Graphic Designer/Manager-CEO-sbg-PIC HANYA eligible untuk content item yang MEMANG punya aktivitas status log di periode ini** (koreksi lanjutan #1) - assignment yang dibuat kapan pun tapi kontennya sudah tidak aktif bulan ini tidak ikut terhitung. Lihat `ATTRIBUTION_RULES.md`.

## Copywriter

> **Koreksi lanjutan 2026-09-02**: atribusi Copywriter TIDAK PERNAH mensyaratkan jadi PIC `content_item_assignments` - brief yang dia tulis (`content_brief_drafts.created_by`) di periode ini sudah cukup untuk dapat baris KPI Copywriter, termasuk `direct_outcome_score`/`portfolio_outcome_score` dari content item yang briefnya dia tulis. Kolom `content_brief_drafts.take_by_user_id` ADA di skema tapi diverifikasi (grep seluruh controller/service) TIDAK PERNAH diisi oleh alur manapun yang berjalan - tidak dipakai sebagai sinyal aktor (mengikuti prinsip "jangan menebak dari data yang tidak pernah benar-benar tercatat").

| Metrik | Formula | Sumber |
|---|---|---|
| `median_brief_duration_hours` | median(`finalized_at - created_at`) untuk brief yang di-lock user ini | `ContentBriefDraft` (informasional, lihat catatan bobot di bawah) |
| `first_pass_acceptance_rate` | `finalized dengan returned_count=0 / total finalized * 100` | `ContentBriefDraft.returned_count` (instrumentasi BARU, default 0 untuk baris lama - lihat `IMPLEMENTATION_PLAN.md` §3.2) |

## Content Creator / Graphic Designer (Production Role)

| Metrik | Formula | Sumber |
|---|---|---|
| `first_handoff_on_time_rate` | `(in_progress->waiting_review PERTAMA changed_at <= deadline_at) / total * 100` (koreksi #4 - BUKAN `brief_ready->in_progress`, itu "mulai kerja" bukan handoff) | `ContentStatusLog` + `ContentItem.deadline_at` |
| `median_active_production_hours` | median SEMUA segmen `in_progress -> waiting_review` (bisa >1 kalau ada siklus revisi) | `ContentStatusLog` (informasional) |
| `internal_revision_rate` (disimpan sebagai `100 - rate`) | `content item dengan >=1 internal revision DI DALAM periode KPI / total content item` (koreksi #5 - dibatasi periode, bukan sepanjang histori content) | `ContentRevision.requested_by_user_id`, `created_at` di dalam `[period_start, period_end]` |

## SMO

> **Koreksi lanjutan 2026-09-02**: kedua metrik di bawah SEKARANG dihitung HANYA dari publication yang BENAR-BENAR dipublikasikan sendiri oleh user ini (`published_by`, `recorded_via='manual'`) - BUKAN dari seluruh publication pada content item di mana user ini kebetulan jadi PIC (desain lama). `RoleProcessKpiService::scoreSmo()` menerima `Collection<ContentPublication>` langsung (bukan `contentItemIds`) - lihat `ATTRIBUTION_RULES.md`.

| Metrik | Formula | Sumber |
|---|---|---|
| `publication_schedule_adherence_rate` | `|scheduled_upload_at - published_at| <= 24 jam / total * 100`, dihitung dari publication PALING AWAL milik user ini sendiri per content item | `ContentItem.scheduled_upload_at` + `ContentPublication.published_at` (milik user ini) |
| `publication_analytics_match_rate` | PER PUBLICATION/PLATFORM (koreksi #6): `publication dengan ContentMetric platform yang SAMA terhubung / total publication * 100` - content dengan 2 platform (mis. Instagram matched, TikTok belum) TIDAK LAGI dihitung "matched" seluruhnya, hanya publication yang benar-benar matched yang dihitung | proxy "unmatched publication/analytics rate" (dibalik: tinggi = baik) |

**Konsekuensi konkret**: PIC (Content Creator/Graphic Designer) yang TERDAFTAR di content item tapi TIDAK mempublikasikannya sendiri TIDAK mendapat baris KPI SMO untuk content itu sama sekali. Sebaliknya, SMO yang mempublikasikan content TANPA pernah jadi PIC content_item_assignments tetap dapat KPI penuh dari aktivitas publish-nya.

## Manager / CEO (Leadership)

| Metrik | Formula | Sumber |
|---|---|---|
| `median_decision_turnaround_hours` | median(`waiting_review->approved|revision changed_at - waiting_review start`) untuk user ini | `ContentStatusLog` (`approval_type` NULL, exclude koreksi) - informasional |
| `pic_assignment_completeness_rate` | `content TANPA PIC assignment / total * 100` (dibalik: tinggi = baik) | `content_item_assignments` EXISTING (`whereDoesntHave('assignments')`) - BUKAN tabel assignment khusus KPI |

## Kenapa Metrik Durasi Tidak Otomatis Jadi Skor 0-100

Spesifikasi eksplisit melarang magic number tersembunyi. Mengonversi "median 6 jam" jadi skor 0-100 butuh THRESHOLD ("berapa jam itu bagus?") yang TIDAK ADA sumbernya di domain saat ini (tidak ada SLA eksplisit yang disepakati tim). Daripada mengarang angka target, metrik durasi ditampilkan **informasional** (dengan coverage & sample size lengkap) dan TIDAK ikut menyusun `process_score` composite - hanya metrik yang SECARA ALAMI berupa rate/persentase (0-100) yang disusun jadi composite.

**Rencana lanjutan**: kalau tim menetapkan target SLA eksplisit (mis. "median active production <= 48 jam = skor penuh"), tambahkan `target_thresholds` ke `KpiFormulaConfig` dan formula konversi durasi->skor tanpa mengubah struktur data yang sudah ada.

## Blocker (Definisi Derived, Bukan Field Eksplisit)

Sistem TIDAK punya konsep "blocker" eksplisit (bukan field, bukan tabel) saat audit Fase 0. Kartu "Active Blocker" di tab Ringkasan Tim (`TeamPerformanceDashboardService::activeBlockerCount()`) memakai proxy derived (content aktif yang overdue) - TIDAK ADA field "tandai blocker manual" ditambahkan (itu akan jadi langkah pengguna baru, dilarang keputusan produk).
