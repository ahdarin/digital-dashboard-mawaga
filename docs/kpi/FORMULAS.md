# KPI System - Formulas

> **Koreksi produk 2026-09-02**: role "Account Lead" (bucket bobot `account_lead`) sudah dihapus - SMO memakai bucket `smo` sendiri, dan SEMUA role produksi (bukan cuma SMO) sekarang eligible untuk komponen `portfolio_outcome` (koreksi #7, lihat tabel bobot di bawah). Beberapa formula analytics juga diperbaiki (delta bukan cumulative sum, minimum peer sample dienforce, dst) - lihat `ANALYTICS_NORMALIZATION.md` dan `CHANGELOG.md` bagian "Koreksi Produk".

Semua bobot hidup di `KpiFormulaVersion.config` (JSON), dibaca lewat `App\Kpi\Formula\KpiFormulaConfig` - TIDAK PERNAH magic number tersebar di service. Untuk mengubah bobot: buat baris `KpiFormulaVersion` baru (jangan mengubah baris lama - itu akan merusak audit trail hasil kalkulasi lama).

## Cara Mengubah Bobot (Formula Versioning)

```php
KpiFormulaVersion::create([
    'version' => '2026.2',
    'config' => $newConfigArray, // struktur sama dengan KpiFormulaConfig::default()->toArray()
    'effective_from' => '2026-10-01',
    'notes' => 'Naikkan bobot direct outcome Content Creator dari 20% ke 25% - keputusan rapat 2026-09-30.',
]);
```

`CalculateKpi` command otomatis memilih formula version dengan `effective_from` terbaru yang `<= period_end`, kecuali `--formula-version` eksplisit diberikan. Hasil kalkulasi LAMA (run dengan formula version lama) TETAP tersimpan apa adanya - tidak pernah ditimpa.

## Skala

Semua score/skor berada di skala **0-100**. Rounding HANYA terjadi di titik akhir (display) - internal tetap float presisi penuh sampai `RobustStats::clampScore()` dipanggil di ujung komposisi.

## Bobot Composite Default (`composite_weights`)

| Role | Process | Direct Outcome | Portfolio Outcome |
|---|---|---|---|
| Copywriter (brief authorship), Content Creator/Graphic Designer/Manager-CEO (PIC produksi) (`process_role`) | 70% | 20% | 10% |
| SMO (publish activity) (`smo`) | 60% | 15% | 25% |
| Manager, CEO - leadership (`leadership`) | 70% (leadership process) | - | 30% |

**Koreksi #7**: portfolio_outcome (10%) SEKARANG dihitung untuk SEMUA role produksi yang eligible (Copywriter/Content Creator/Graphic Designer/Manager-CEO-sebagai-PIC), bukan cuma SMO - sebelumnya slot 10% itu diam-diam direnormalisasi ke 0 untuk role selain SMO karena kodenya cuma menghitung portfolio component kalau role-nya SMO.

Kalau salah satu komponen unavailable (mis. user baru, belum ada content outcome), bobot komponen yang TERSEDIA di-renormalisasi (dibagi ulang supaya total tetap 100%) - lihat `KpiCalculationService::composeResult()`. Kalau SEMUA komponen unavailable, `composite_score = null` (bukan 0).

## Content Outcome - Video (Reels/TikTok)

Bobot default (`content_outcome.video`): **45% visibility + 35% meaningful engagement + 20% retention**.

- **Visibility**: Instagram = reach (fallback views), TikTok = views. Di-percentile-rank-kan terhadap peer group (winsorized + log1p, lihat `ANALYTICS_NORMALIZATION.md`).
- **Meaningful engagement**: `(likes*w_likes + comments*w_comments + shares*w_shares [+ saves*w_saves utk Instagram]) / denominator * 100`, dengan bobot interaksi default (`engagement_component_weights`): likes=1.0, comments=1.5, shares=2.0, saves=2.0 (comments/shares/saves lebih berpengaruh dari likes, configurable). TikTok tidak punya `saves` - dikecualikan dari formula (bukan dianggap 0).
- **Retention**: `completion_rate` (dari `watch_time_avg`+`completion_rate` snapshot). Kalau TIDAK tersedia dari integration, bobotnya diredistribusi proporsional ke visibility+engagement (bukan dianggap 0) - lihat `ContentOutcomeScoringService::composeWeighted()`.

## Content Outcome - Desain (Carousel/Single Feed)

Bobot default (`content_outcome.design`): **40% reach percentile + 25% saves rate + 20% shares rate + 10% comments rate + 5% likes rate**.

Semua rate dihitung `metric / reach` lalu di-percentile-rank-kan terhadap peer PADA FORMAT YANG SAMA (Carousel vs Carousel, Single Feed vs Single Feed - lihat `ContentFormatGroup::resolve()`). Desain TIDAK PERNAH dibandingkan dengan Reels/TikTok pakai raw views.

## Multi-Platform

1. Outcome dihitung PER PUBLICATION (per platform) dulu - `ContentOutcomeScoringService::computePublicationDelta()`.
2. Digabung jadi outcome content-level pakai bobot SETARA antar platform yang usable (strategic platform weight per-client belum ada sumber data di domain saat ini - lihat "Known Limitations" di `ANALYTICS_NORMALIZATION.md`).
3. Company/client aggregate TETAP menghitung content item SEKALI (lihat `ATTRIBUTION_RULES.md`).
4. Publication per platform tetap terlihat di `component_scores`/`raw_metrics` (JSON) pada `ContentOutcomeResult` untuk detail analytics.
5. Publication yang belum matched analytics ditandai `status: 'unavailable'` per platform (lihat `component_scores[$platformId]['status']`) - bukan 0, dan TIDAK menggagalkan platform lain yang sudah matched.

## Client Portfolio Outcome

Bobot default (`client_portfolio`): **45% normalized visibility growth + 35% meaningful engagement performance + 20% follower growth**.

- **Visibility growth**: total views periode ini vs periode sebelumnya (durasi sama), growth% di-winsorize+percentile-rank terhadap growth% client+platform lain pada window yang sama (BUKAN perbandingan raw angka antarklien - cuma dipakai untuk membatasi outlier).
- **Meaningful engagement performance**: rata-rata `engagement_rate` (delta, rumus SAMA dengan `PeriodPerformanceService`) di-percentile-rank-kan.
- **Follower growth**: dari `AudienceInsight` (`demographic_type=summary`), dibandingkan tren AKUN ITU SENDIRI (self-trend) - `(follower_end - follower_start) / follower_start * 100`, TIDAK PERNAH dibandingkan raw follower count antarklien. Winsorize+percentile-rank dipakai HANYA untuk membatasi dominasi akun kecil dengan lonjakan % ekstrem, bukan untuk ranking antarklien.

Client portfolio outcome HANYA diberikan ke user yang benar-benar jadi PIC/leadership klien itu di periode ini, dan diberikan IDENTIK ke SETIAP PIC eligible (lihat `ATTRIBUTION_RULES.md` #6) - bukan hanya satu "PIC utama".

**Minimum peer wajib ada**: setiap komponen (visibility/engagement/follower growth) butuh MINIMAL 1 client+platform lain sebagai peer pool di window yang sama - kalau tidak ada peer sama sekali, komponen itu `null` (bukan skor netral 50). Komponen lain yang punya peer tetap dihitung dan bobotnya direnormalisasi.

## Contoh Hitung Lengkap (Raw Metric -> Composite KPI)

**Konteks**: Budi, Content Creator, 1 content item bulan ini ("Video A", Instagram Reels, D+7).

1. **Raw metric D+7**: views=8.000, reach=7.200, likes=400, comments=60, shares=25, saves=15. Retention tidak tersedia (integration tidak kirim `watch_time_avg`).
2. **Peer pool**: 12 publication Reels client yang sama, 90 hari terakhir (>= 8 minimum, pakai baseline client+platform+format).
3. **Visibility**: `log1p(7200)` di-winsorize dalam peer pool -> percentile rank = **72**.
4. **Engagement rate**: `(400*1.0 + 60*1.5 + 25*2.0 + 15*2.0) / 7200 * 100 = (400+90+50+30)/7200*100 = 7.92` -> percentile rank terhadap peer = **65**.
5. **Retention**: unavailable -> bobot redistribusi: visibility naik dari 45% jadi `45/(45+35)=56.25%`, engagement jadi `43.75%`.
6. **Content outcome score** = `72*0.5625 + 65*0.4375 = 40.5 + 28.4 = 68.9`.
7. **Direct outcome score Budi** (rata-rata semua content item Budi bulan ini, misal cuma 1 item) = **68.9**.
8. **Process score Budi** (dari `RoleProcessKpiService::scoreProductionRole`, misal `first_handoff_on_time_rate=100%`, `internal_revision_rate(inverse)=80%` -> rata-rata rate = **90**).
9. **Portfolio score** (koreksi #7 - SEMUA role produksi eligible, bukan cuma SMO): klien Budi bulan ini punya visibility growth percentile **60** terhadap peer client lain, engagement/follower data belum cukup peer -> portfolio score klien = **60** (hanya komponen visibility yang usable, direnormalisasi ke 100% bobot komponen itu sendiri - lihat `ANALYTICS_NORMALIZATION.md`).
10. **Composite** = `90*0.70 + 68.9*0.20 + 60*0.10 = 63.0 + 13.78 + 6.0 = 82.8`.
11. **Sample size** = 1 content item -> DI BAWAH `min_content_items_for_personal_indicator` (default 5) -> **status_label = Sementara/Data Belum Cukup** (bukan ditampilkan "85.3" seolah final), composite_score TETAP tersimpan untuk audit tapi UI menampilkan "Data belum cukup" (lihat `KpiCoverageService`).
