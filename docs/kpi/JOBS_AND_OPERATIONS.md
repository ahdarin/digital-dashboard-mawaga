# KPI System - Jobs & Operations

> **Koreksi produk 2026-09-02**: versi sebelumnya dokumen ini mengasumsikan `php artisan kpi:calculate` dijadwalkan harian sebagai SATU-SATUNYA cara angka ter-refresh, dan pengguna/administrator diharapkan menjalankannya secara manual saat butuh data terbaru. **Ini dibatalkan** - keputusan produk eksplisit melarang mensyaratkan command manual apa pun. Dokumen ini menjelaskan mekanisme yang BENAR-BENAR berjalan sekarang: kalkulasi otomatis di latar belakang, dipicu oleh aktivitas yang sudah ada.
>
> **Koreksi lanjutan 2026-09-02**: audit ulang menemukan SEMUA titik trigger (termasuk halaman Team Performance dan sync historis) sebelumnya selalu menjadwalkan BULAN BERJALAN, terlepas dari periode aktivitas yang sebenarnya - membuka periode historis di halaman, atau sync data bulan lalu, diam-diam menghitung ulang bulan sekarang, bukan periode yang sebenarnya terdampak. Dan lock eksekusi command developer (`Cache::lock` sendiri) TIDAK sama dengan lock job background (`ShouldBeUnique` saja) - keduanya bisa jalan bersamaan untuk periode+formula yang sama. **Keduanya sudah diperbaiki** - lihat bagian "Trigger Berbasis Timestamp Aktivitas" dan "Lock Eksekusi Bersama" di bawah.

## Prinsip

*"Perhitungan harus berjalan otomatis di latar belakang. Jangan meminta user/administrator menjalankan kalkulasi manual."* - satu-satunya jalan angka KPI berubah adalah: (1) aktivitas relevan terjadi (assignment berubah, workflow bergerak, dst), yang otomatis memicu job di background, atau (2) halaman Team Performance dibuka dan hasilnya basi/belum ada, yang otomatis memicu job yang sama. Tidak ada jalur ketiga yang mengharuskan seseorang menjalankan command.

## Titik Trigger Otomatis (`App\Kpi\Services\KpiRecalculationTrigger`)

Satu baris `KpiRecalculationTrigger::schedule*()` ditambahkan di titik-titik EXISTING berikut, TANPA mengubah alur/return value titik itu sendiri:

| Titik | File | Method dipakai | Kenapa |
|---|---|---|---|
| Transisi status workflow apa pun | `WorkflowStatusService::transition()` | `scheduleCurrentPeriod()` | `changed_at` selalu `now()` di jalur ini - tidak pernah bisa backdated |
| Konten dilepas ke produksi (batch) | `WorkflowStatusService::releaseToProduction()` | `scheduleCurrentPeriod()` | Sama, selalu terjadi "sekarang" |
| PIC content diganti | `ContentItemController::reassign()` | `scheduleForContentItem($contentItem)` | Bulan berjalan (assignment baru relevan mulai sekarang) DAN setiap bulan publication content itu yang sudah ada (koreksi PIC pada konten yang sudah tayang bulan lalu harus menghitung ulang bulan itu juga) |
| Revisi baru ditambahkan (jalur "sudah dalam revision, tambah catatan") | `ContentRevisionController::store()` | `scheduleCurrentPeriod()` | `ContentRevision::create()` tidak pernah backdated |
| Publication Instagram/TikTok ditautkan manual | `ContentPublicationController::linkInstagramMedia()` / `linkTiktokMedia()` | `scheduleForDate($publication->published_at)` | Media yang ditautkan BISA post lama - bulan `published_at`-nya, bukan bulan berjalan |
| Sync analytics Instagram/TikTok SELESAI (bukan gagal) | `SyncInstagramAnalyticsJob`, `SyncTikTokAnalyticsJob` | `scheduleForDateRange($since, $until)` | Rentang sync ("Sync Selected Month"/historis) bisa mencakup beberapa bulan kalender sekaligus |
| Sync audience harian SELESAI | `SyncInstagramAudienceJob` (mode reguler) | `scheduleCurrentPeriod()` | Sync harian selalu untuk data HARI INI |
| Backfill reach audience (one-time saat integration baru connect) | `SyncInstagramAudienceJob` (mode `backfill`) | `scheduleForDateRange()` 180 hari ke belakang | Backfill mengisi histori jauh ke belakang, sering melintasi beberapa bulan - dulu tidak memicu trigger sama sekali |
| Import audience CSV berhasil (>=1 baris) | `AudienceController::importCsv()` | `scheduleForDateRange(min, max snapshot_date)` | CSV sering berisi data historis, rentang tanggalnya bebas |
| Halaman Team Performance dibuka (stale/belum ada) | `TeamPerformanceController::resolveRunWithAutoDispatch()` | `schedule($periodStart, $periodEnd)` | Periode yang DIPILIH pengguna (bisa bulan historis lewat filter), bukan selalu bulan berjalan |

Tidak ada satu pun controller/service di atas yang perilakunya berubah - method-method itu tetap mengembalikan response yang sama persis seperti sebelumnya, cuma menambah SATU pemanggilan trigger di titik akhir (biasanya sebelum `return`).

### Method `KpiRecalculationTrigger`

- `scheduleCurrentPeriod()` - bulan berjalan (Asia/Jakarta). Dipakai HANYA saat aktivitasnya dijamin selalu "sekarang".
- `scheduleForDate(Carbon $date)` - bulan kalender yang mencakup `$date`. Dipakai saat aktivitas punya SATU timestamp eksplisit yang bisa jadi masa lalu.
- `scheduleForDateRange(Carbon $from, Carbon $to)` - jadwalkan SETIAP bulan kalender yang tercakup `[$from, $to]` sebagai dispatch terpisah (masing-masing tetap didebounce/di-dedup sendiri). Dipakai event yang bisa memengaruhi lebih dari satu periode (sync/import historis).
- `scheduleForContentItem(ContentItem $item)` - bulan berjalan + setiap bulan publication content item itu.
- `schedule(Carbon $periodStart, Carbon $periodEnd)` - primitif dasar yang dipakai semua method di atas; dipanggil langsung oleh titik yang sudah punya period_start/period_end eksplisit (halaman Team Performance).

## Debounce & Unique Lock (`App\Jobs\RecalculateKpiPeriod`)

```php
class RecalculateKpiPeriod implements ShouldQueue, ShouldBeUnique
{
    public function uniqueId(): string { return "{$this->periodStart}:{$this->periodEnd}"; }
    public function uniqueFor(): int { return 300; } // 5 menit
}
```

`KpiRecalculationTrigger::schedule()` men-dispatch job ini dengan `->delay(60 detik)`. Kombinasi delay + `ShouldBeUnique` (kunci per periode, TTL 5 menit) = **debounce**: banyak event beruntun dalam window yang sama (mis. staf memindahkan 10 kartu kanban berturut-turut) collapse jadi SATU eksekusi kalkulasi yang benar-benar jalan, bukan 10 job duplikat. Dibuktikan `KpiRecalculationTriggerTest`.

`RecalculateKpiPeriod::handle()`:
1. Resolve `KpiFormulaVersion::resolveCurrent()` (self-bootstrapping - membuat versi default otomatis kalau belum pernah ada satu pun, tidak butuh seeder manual).
2. Membuat `KpiCalculationRun` BARU (histori penuh, tidak pernah menimpa run lama).
3. Memanggil `KpiCalculationService::calculate($run)`.

## Auto-Dispatch Saat Halaman Dibuka (`TeamPerformanceController::resolveRunWithAutoDispatch()`)

```php
$run = $dashboard->latestCompletedRun($periodStart, $periodEnd);
$isStale = $dashboard->isStale($run); // null, atau finished_at > 30 menit lalu
if ($isStale) {
    KpiRecalculationTrigger::schedule($periodStart, $periodEnd); // periode YANG DIPILIH, bukan bulan berjalan
}
if ($run === null) {
    $run = $dashboard->latestCompletedRunAnyPeriod(); // fallback: snapshot periode lain
}
```

Perilaku halaman `/team-performance`:
- **Ada run yang fresh (<=30 menit)**: tampilkan langsung, tidak ada trigger tambahan.
- **Ada run tapi basi (>30 menit)**: tampilkan run itu APA ADANYA (snapshot lama tetap terlihat, TIDAK diganti kosong) + banner "sedang diperbarui otomatis di latar belakang" + trigger kalkulasi baru di background.
- **Tidak ada run untuk periode ini SAMA SEKALI**: tampilkan run periode LAIN yang paling baru (kalau ada) sebagai fallback + banner + trigger kalkulasi periode ini.
- **Tidak pernah ada run sama sekali** (instalasi baru): tampilkan **"Data KPI sedang disiapkan otomatis"** - TIDAK ADA instruksi command developer di layar mana pun.

Dibuktikan `TeamPerformanceControllerTest` (`test_index_renders_without_error_when_no_calculation_run_exists`, `test_stale_run_still_shows_previous_snapshot_while_recalculation_is_dispatched`).

## Idempotency & Determinism

- **Idempotent PER RUN**: memanggil `KpiCalculationService::calculate()` dua kali pada `KpiCalculationRun` yang SAMA tidak menggandakan data (`ContentOutcomeResult::updateOrCreate` + `UserKpiResult::updateOrCreate`, keyed by kombinasi run+content/run+user+role+client).
- **Deterministic**: input yang sama (assignment, metric snapshot, formula version) SELALU menghasilkan composite score yang sama, terlepas dari kapan/berapa kali dihitung. Dibuktikan `KpiCalculationServiceTest::test_recalculation_with_same_input_is_deterministic`.
- **Recalculation = run BARU** (bukan update run lama) - histori lintas waktu/formula version tetap ada untuk audit.

## Command Developer (Opsional, Bukan Syarat Pakai Fitur)

```
php artisan kpi:calculate {--month=Y-m} {--formula-version=version_string}
```

Berguna untuk debugging lokal (tidak perlu menunggu queue worker/delay 60 detik) atau backfill periode lama secara sengaja. **Tidak pernah menjadi syarat** untuk melihat data KPI di halaman - kalau command ini tidak pernah dijalankan sama sekali, sistem tetap bekerja lewat trigger otomatis di atas. Dibuktikan `TeamPerformanceControllerTest` (`assertDontSee('kpi:calculate')`, `assertDontSee('php artisan')`) dan `CalculateKpiCommandTest`.

- Tanpa `--month`: bulan berjalan (Asia/Jakarta).
- Tanpa `--formula-version`: `KpiFormulaVersion::resolveCurrent()` (self-bootstrapping - TIDAK PERNAH gagal karena "belum ada formula version").
- Dengan `--formula-version` eksplisit yang TIDAK ditemukan: gagal jelas (`FAILURE`, pesan menyebut versi yang dicari) - ini SATU-SATUNYA kondisi command ini gagal karena formula version.
- Membuat SATU `KpiCalculationRun` BARU per eksekusi sukses.
- Exit code non-zero kalau kalkulasi gagal (`status=failed`, `error_message` terisi).

### Lock Eksekusi Bersama (`App\Kpi\Support\KpiCalculationLock`)

Koreksi lanjutan 2026-09-02: command developer dan job background `RecalculateKpiPeriod` SEKARANG memakai **satu mekanisme lock EKSEKUSI yang sama**, keyed `(period_start, period_end, formula_version_id)`:

```php
class KpiCalculationLock
{
    public static function key(Carbon $periodStart, Carbon $periodEnd, int $formulaVersionId): string;
    public static function acquire(Carbon $periodStart, Carbon $periodEnd, int $formulaVersionId): Lock; // Cache::lock, TTL 600 detik
}
```

- Command manual DAN job otomatis untuk periode+formula yang SAMA TIDAK PERNAH menghitung bersamaan - siapa pun yang gagal mengambil lock SKIP (bukan gagal, bukan membuat run baru) supaya tidak dianggap error oleh scheduler/queue retry.
- Ini TERPISAH dari `ShouldBeUnique` pada job (yang mencegah duplikasi DISPATCH sebelum job mulai jalan, lihat bagian Debounce di atas) - lock ini melindungi EKSEKUSI aktual (dari titik lock diambil sampai kalkulasi selesai/gagal).
- Kalkulasi periode/formula BERBEDA tetap bisa berjalan independen/paralel.

Dibuktikan `KpiRecalculationTriggerTest::test_job_skips_when_command_already_holds_the_same_execution_lock`.

## Logging & Failure Handling

- `KpiCalculationRun.status`: `pending -> running -> completed|failed`.
- Kegagalan (exception di tengah kalkulasi) di-catch di `KpiCalculationService::calculate()`, disimpan ke `error_message`, transaction di-rollback (`DB::transaction()` membungkus seluruh kalkulasi satu run - kegagalan sebagian tidak meninggalkan data setengah jadi).
- Job `RecalculateKpiPeriod` punya `$tries = 3` + backoff `[30, 120, 300]` detik - kegagalan sementara (mis. deadlock DB) dicoba ulang otomatis oleh queue worker, tanpa campur tangan manual.

## Troubleshooting Developer

| Gejala | Kemungkinan penyebab | Cara cek |
|---|---|---|
| Halaman terus menampilkan "sedang disiapkan otomatis" | Queue worker tidak jalan (`php artisan queue:work`), atau `QUEUE_CONNECTION` di `.env` bukan driver yang diproses worker | `php artisan queue:work` manual sekali, lihat exception kalau ada; cek `jobs`/`failed_jobs` table kalau pakai driver database |
| Angka tidak berubah setelah aktivitas baru | Debounce 60 detik belum lewat, atau lock unique (5 menit) masih dipegang job sebelumnya yang masih jalan | Tunggu, atau cek `KpiCalculationRun::latest()->first()->status` - kalau `running`, sedang diproses |
| Ingin lihat hasil TANPA menunggu queue | Jalankan command developer secara langsung (lihat di atas) - hasilnya sama, cuma sinkron | `php artisan kpi:calculate --month=Y-m` |
| Formula berubah tapi run lama ikut berubah | Seharusnya TIDAK terjadi - kalau terjadi berarti ada baris `KpiFormulaVersion` yang DIEDIT (bukan dibuat baru) - selalu buat versi baru, jangan mengubah versi lama |
