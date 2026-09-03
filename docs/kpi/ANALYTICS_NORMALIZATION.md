# KPI System - Analytics Normalization

## Kenapa Normalisasi (Bukan Raw Metric)

Raw views/likes/reach/follower TIDAK PERNAH langsung jadi poin - agensi menangani klien dengan ukuran audience sangat berbeda, dan satu konten viral bisa mendominasi seluruh KPI bulanan kalau tidak ditangani. Semua metric dinormalisasi terhadap **peer group yang sebanding** sebelum jadi skor.

## Peer Group

Urutan baseline (`ContentOutcomeScoringService::buildPeerPool()`):

1. **Client + platform + format yang sama**, dalam `lookback_days` terakhir (default 180 hari), minimal `min_publications_for_client_platform_format` (default 8) publication USABLE (lihat aturan coverage di bawah).
2. Kalau tidak cukup: **platform + format yang sama LINTAS KLIEN** (tanpa filter client_id).
3. Kalau TETAP tidak cukup (peer pool di bawah minimum): outcome score `unavailable` - TIDAK PERNAH mengarang baseline dari peer pool kosong/terlalu kecil, dan TIDAK PERNAH fallback ke skor netral 50 (koreksi #12).

**Aturan peer pool (koreksi produk 2026-09-02, #9/#10/#11)**:
- **#9 minimum peer benar-benar dienforce.** `sample_size < min_required` -> publication itu `status: 'unavailable'` ("insufficient_peer_sample"), bukan diberi skor dari sample yang kurang dari minimum yang seharusnya.
- **#10 publication TARGET selalu dikecualikan dari peer pool-nya sendiri** (`excludePublicationId`) - publication tidak pernah dibandingkan dengan dirinya sendiri.
- **#11 peer pool HANYA berisi publication dengan coverage FULL** (`fullCoverageDeltas()`) - publication dengan data Partial (belum lengkap datanya sendiri) TIDAK layak jadi baseline pembanding "normal", walau publication itu sendiri (sebagai TARGET yang dinilai) tetap bisa diproses dengan coverage Partial.

**Known limitation (v1)**: fallback lintas klien (langkah 2) BELUM menyesuaikan ukuran akun/audience secara statistik penuh (spesifikasi menyebut "dinormalisasi terhadap baseline akun atau kelompok ukuran audience" - versi ini pakai raw metric peer lintas klien apa adanya, hanya melalui winsorize+log1p yang sama). Follow-up: kelompokkan peer fallback berdasarkan rentang follower count client (butuh data `AudienceInsight` yang lebih matang untuk semua client).

## Measurement Window: D+7 dan D+30

- **D+7** (`MeasurementWindow::D7`) - early performance.
- **D+30** (`MeasurementWindow::D30`) - sustained performance.

Publication yang usianya belum mencapai window (`now() < published_at + N hari`) berstatus **provisional** - TIDAK diproses seolah datanya final, TIDAK unavailable (beda makna: unavailable = data tidak ada; provisional = memang belum waktunya). `direct_outcome_score` memprioritaskan D+30 (sustained), fallback ke D+7 kalau D+30 belum usable untuk content tertentu (masih provisional atau publish terlalu baru).

**Kenapa tidak reuse `PeriodPerformanceService::computeContentDelta()` langsung**: identity column `content_item_id` di situ tercampur untuk content multi-platform (2 publication beda platform, snapshot sama-sama punya `content_item_id` yang sama). `ContentOutcomeScoringService::computePublicationDelta()` ditulis scoped eksplisit ke `(content_item_id, platform_id)`, dengan filosofi coverage yang SAMA (full/partial/unavailable, tidak fabricate baseline).

## Robust Normalization (`App\Kpi\Support\RobustStats`)

- **Winsorization (persentil 5-95)**: nilai ekstrem dipotong ke persentil 5/95 sebelum dipakai - outlier tetap mempengaruhi (bukan dibuang), tapi tidak mendominasi.
- **log1p**: `ln(1+x)` - diterapkan SEBELUM winsorize untuk distribusi views/reach yang sangat miring (satu konten viral bisa 100x lipat konten biasa).
- **Percentile rank (0-100)**: skor komponen = persentase peer yang dilampaui nilai ini (bukan z-score/mean-based) - robust terhadap distribusi tidak normal. `RobustStats::percentileRank()` sendiri punya fallback 50.0 untuk pool berukuran 1 (perilaku generik yang wajar untuk utility murni) - **tapi KPI TIDAK PERNAH membiarkan fallback itu bocor ke skor final** (koreksi #12): setiap titik panggil (`ContentOutcomeScoringService::percentileScoreFor()`, tiga metode growth di `ClientPortfolioScoringService`) mengecek jumlah peer LEBIH DULU dan mengembalikan `null` sebelum memanggil `percentileRank()` sama sekali kalau peer di bawah minimum yang seharusnya.
- **Median** dipakai sebagai pusat data di process metrics (bukan mean) - satu outlier ekstrem tidak menggeser "typical performance".

## Penanganan Viral/FYP

Satu konten viral MENINGKATKAN skor (percentile rank-nya otomatis tinggi), TAPI:
- Winsorize mencegah SATU titik ekstrem meregangkan skala talent lain dalam peer pool yang sama.
- `direct_outcome_score` seorang PIC adalah RATA-RATA seluruh content item-nya bulan itu - satu viral tidak otomatis membuat compsite bulanan jadi 100 kalau content lain biasa saja.

Dibuktikan: `ContentOutcomeScoringServiceTest::test_viral_outlier_does_not_dominate_peer_percentile`.

## Format Video vs Desain

`ContentFormatGroup` (`video`/`carousel`/`single_feed`/`unknown`) - diturunkan dari `ContentType` (Video/Desain, tidak diubah) + `ContentItem.content_format` (string bebas, nilai kanonik "Carousel Feed"/"Single Feed" dari Excel Coverage Audit). Peer pool SELALU difilter ke format group yang SAMA - Reels tidak pernah dibandingkan dengan Carousel, dan keduanya punya formula komponen yang BERBEDA TOTAL (lihat `FORMULAS.md`).

## Paid vs Organic

Audit Fase 0: sistem TIDAK punya penanda organic vs paid sama sekali. Ditambahkan (Fase 1): `content_publications.is_paid` (bool), `promotion_type`, `ad_spend`, `campaign_reference`.

- Publication `is_paid=true` **dikecualikan TOTAL** dari peer pool organic (`candidatePublications()` filter `where('is_paid', false)`).
- Publication paid TIDAK dihitung dalam `content_outcome_results` organic sama sekali di versi ini (bukan diberi baris terpisah "paid outcome" - itu pengembangan lanjutan yang belum diprioritaskan).

Dibuktikan: `KpiCalculationServiceTest::test_paid_publication_is_excluded_from_organic_peer_pool`.

## Client Portfolio: Delta, Bukan Cumulative Sum (Koreksi #1/#2/#3)

`ClientPortfolioScoringService` sempat salah menjumlahkan nilai snapshot MENTAH (`sum('views')` lintas baris/content/hari) - `views` di `ContentMetricSnapshot` bersifat KUMULATIF PER CONTENT, jadi menjumlahkannya lintas hari/content mencampur "total-to-date" dengan "pertumbuhan", bukan pertumbuhan yang sesungguhnya. Diperbaiki:

- **#1/#2 Visibility growth** dihitung `totalViewsDelta()` - PER CONTENT (snapshot terakhir DALAM window dikurangi snapshot terakhir SEBELUM window), baru dijumlah lintas content. Delta negatif (indikasi reset/koreksi metric) dilewati, bukan dianggap penurunan nyata.
- **#3 Meaningful engagement** dihitung dari DELTA RAW METRICS (`engagementRateFromDelta()`: jumlah delta likes/comments/shares[/saves] dibagi jumlah delta reach/views, PER CONTENT baru dijumlah), BUKAN rata-rata kolom `engagement_rate` kumulatif yang tersimpan per snapshot (dulu bias ke atas kalau ada banyak snapshot lama dengan rate tinggi yang sudah tidak relevan).

## Missing/Null Handling

Aturan keras di SETIAP lapisan (`PublicationDelta`, `ContentOutcomeScoringService::composeWeighted()`, `RoleProcessKpiService`, `ClientPortfolioScoringService`):
- Metric yang tidak diketahui (belum ada snapshot, platform tidak mendukung metric itu) = **NULL**, bukan 0.
- Komponen dengan raw metric NULL -> `component_scores[$key]['status'] = 'unavailable'`, `normalized = null`.
- Bobot komponen unavailable DIREDISTRIBUSI proporsional ke komponen yang tersedia (`composeWeighted()`) - kalau SEMUA unavailable, skor akhir NULL (bukan 0).

## Known Limitations (v1)

1. Peer pool fallback lintas klien belum disesuaikan ukuran akun (lihat atas).
2. Strategic platform weight per client (multi-platform combine) belum ada sumber data - dipakai bobot setara.
3. Publication paid belum punya jalur scoring terpisah (sekadar dikecualikan dari organic).
