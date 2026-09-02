<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * PASS 2 - "PERIOD ENGINE V2". SATU-SATUNYA tempat input mentah (query
 * string dari controller mana pun) diubah jadi AnalyticsPeriod. TIDAK ADA
 * controller/service Analytics lain yang boleh parse date_from/date_to
 * atau subDays($period) sendiri (Langkah "ALL CONSUMERS MUST SHARE THE
 * SAME PERIOD").
 *
 * Kontrak input (Langkah "URL/QUERY-STRING CONTRACT"):
 * - period_mode=month & month=YYYY-MM -> calendar month.
 * - period_mode=custom & date_from=YYYY-MM-DD & date_to=YYYY-MM-DD -> custom range.
 * - Legacy: period=7|30|90 (TANPA period_mode) -> rolling N hari s/d hari
 *   ini, ditandai mode=legacy_days (Langkah "LEGACY 7/30/90
 *   COMPATIBILITY" - TETAP diterima, UI baru TIDAK PERNAH menghasilkan
 *   link ini lagi).
 * - Tidak ada input valid sama sekali -> default bulan kalender BERJALAN
 *   (bukan lagi period=30 - Langkah "PRIMARY PRODUCT CHANGE").
 *
 * Timezone: Carbon::now()/today() SUDAH konsisten pakai config('app.timezone')
 * (Laravel bootstrap men-set date_default_timezone_set() dari situ di
 * setiap request - dibuktikan PASS 1B "SCHEDULER TIMEZONE") - TIDAK ADA
 * penanganan timezone tambahan yang perlu ditulis di sini.
 */
class AnalyticsPeriodResolver
{
    // Rentang custom maksimal (Langkah "CUSTOM RANGE LIMITS") - 366 hari
    // (genap 1 tahun kalender, termasuk kabisat). Alasan: (1) ini query
    // REPORTING SAJA, tidak pernah memicu sync provider otomatis, jadi
    // batas ini murni soal kewajaran UX + biaya query lokal, bukan biaya
    // API eksternal; (2) computeDailyGainSeries() membangun 1 titik PHP
    // per hari kalender dalam rentang - 366 titik masih murah, multi-tahun
    // TIDAK; (3) content_metric_snapshots retention rolling 120 hari
    // (Pass 1) sudah jadi batas ALAMI buat coverage genuinely "full" -
    // rentang lebih lebar dari itu TETAP boleh diminta (akan honest
    // dilaporkan partial/insufficient_history buat bagian yang di luar
    // retention), tapi tidak ada alasan mengizinkan lebih dari 1 tahun.
    public const MAX_CUSTOM_RANGE_DAYS = 366;

    /**
     * @return array{period: AnalyticsPeriod, error: ?string}
     */
    public function resolveWithError(Request $request): array
    {
        $mode = $request->input('period_mode');

        if ($mode === AnalyticsPeriod::MODE_MONTH) {
            $month = (string) $request->input('month', '');
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                // PASS 2.1 - fallback TETAP terjadi (Langkah 2, "valid
                // calendar dates" - display page nggak boleh hard-error),
                // TAPI $error SEKARANG diisi (bukan null lagi) - consumer
                // yang butuh SIKAP TEGAS (export/report, lihat
                // AnalyticsController::export()) bergantung pada sinyal ini
                // buat MENOLAK request, bukan diam-diam export bulan lain.
                return ['period' => $this->currentMonth(), 'error' => 'Format bulan tidak valid.'];
            }

            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
            if ($start->isFuture()) {
                return ['period' => $this->currentMonth(), 'error' => 'Bulan yang dipilih belum dimulai.'];
            }

            return ['period' => $this->buildMonth($month), 'error' => null];
        }

        if ($mode === AnalyticsPeriod::MODE_CUSTOM) {
            $rawFrom = (string) $request->input('date_from', '');
            $rawTo = (string) $request->input('date_to', '');

            if (! $this->isValidDateString($rawFrom) || ! $this->isValidDateString($rawTo)) {
                return ['period' => $this->currentMonth(), 'error' => 'Format tanggal tidak valid.'];
            }

            $from = Carbon::createFromFormat('Y-m-d', $rawFrom)->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $rawTo)->startOfDay();

            if ($from->gt($to)) {
                return ['period' => $this->currentMonth(), 'error' => 'Tanggal mulai harus sebelum atau sama dengan tanggal akhir.'];
            }

            if ($from->isFuture()) {
                return ['period' => $this->currentMonth(), 'error' => 'Rentang tanggal belum dimulai.'];
            }

            if ($from->diffInDays($to) + 1 > self::MAX_CUSTOM_RANGE_DAYS) {
                return ['period' => $this->currentMonth(), 'error' => 'Rentang tanggal maksimal '.self::MAX_CUSTOM_RANGE_DAYS.' hari.'];
            }

            return ['period' => $this->buildCustom($from, $to), 'error' => null];
        }

        // Legacy - period=7/30/90 TANPA period_mode (Langkah "LEGACY
        // COMPATIBILITY"). filled() check dulu - period_mode absen DAN
        // period absen sama sekali (request paling pertama, belum pernah
        // ada query string apapun) HARUS jatuh ke default bulan berjalan,
        // BUKAN diam-diam diperlakukan sebagai "period=0".
        if ($request->filled('period')) {
            $days = (int) $request->input('period');
            if (in_array($days, [7, 30, 90], true)) {
                return ['period' => $this->buildLegacyDays($days), 'error' => null];
            }
        }

        return ['period' => $this->currentMonth(), 'error' => null];
    }

    public function resolve(Request $request): AnalyticsPeriod
    {
        return $this->resolveWithError($request)['period'];
    }

    public function currentMonth(): AnalyticsPeriod
    {
        return $this->buildMonth(Carbon::now()->format('Y-m'));
    }

    public function buildMonth(string $month): AnalyticsPeriod
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $naturalEnd = $start->copy()->endOfMonth()->startOfDay();
        $today = Carbon::now()->startOfDay();

        return new AnalyticsPeriod(
            dateFrom: $start,
            dateTo: $naturalEnd,
            effectiveDateTo: $naturalEnd->lt($today) ? $naturalEnd : $today,
            mode: AnalyticsPeriod::MODE_MONTH,
            month: $month,
        );
    }

    public function buildCustom(Carbon $from, Carbon $to): AnalyticsPeriod
    {
        $today = Carbon::now()->startOfDay();
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        return new AnalyticsPeriod(
            dateFrom: $from,
            dateTo: $to,
            effectiveDateTo: $to->lt($today) ? $to : $today,
            mode: AnalyticsPeriod::MODE_CUSTOM,
        );
    }

    public function buildLegacyDays(int $days): AnalyticsPeriod
    {
        $today = Carbon::now()->startOfDay();
        $from = $today->copy()->subDays($days - 1);

        return new AnalyticsPeriod(
            dateFrom: $from,
            dateTo: $today,
            effectiveDateTo: $today,
            mode: AnalyticsPeriod::MODE_LEGACY_DAYS,
            legacyDays: $days,
        );
    }

    /**
     * Previous-period comparison (Langkah 6) - SATU formula dipakai
     * SEMUA consumer (AnalyticsSummaryService dkk), bukan diulang lokal.
     *
     * month, bulan yang dilihat SUDAH fully elapsed (mis. lihat Agustus
     * dari bulan berjalan September, atau lihat bulan lampau manapun):
     * kalender bulan SEBELUMNYA UTUH (Sep -> Aug penuh, Jan -> Dec tahun
     * sebelumnya penuh - subMonthNoOverflow() Carbon sudah menangani year
     * boundary & 28/29/30/31 hari otomatis).
     *
     * month, bulan yang dilihat SEDANG BERJALAN/PARTIAL (PASS 2.1 fix -
     * bug ditemukan: versi lama SELALU bandingkan ke bulan sebelumnya
     * PENUH, walau bulan berjalan baru genuinely dievaluasi beberapa hari
     * - Sep 1-2 vs Agu 1-31 keliru, "penurunan" yang muncul cuma artefak
     * beda panjang, bukan performa asli) - HARUS apple-to-apple: hari 1
     * s/d N bulan ini VS hari 1 s/d N bulan lalu, N = jumlah hari yang
     * SUDAH genuinely dievaluasi bulan berjalan (dateFrom..effectiveDateTo).
     * Dibangun via buildCustom() (BUKAN buildMonth()) SENGAJA - biar
     * label()-nya jujur menunjukkan rentang tanggal yang benar-benar
     * dibandingkan ("01-02 Agu 2026"), bukan "Agustus 2026" yang menyiratkan
     * bulan penuh padahal cuma 2 hari yang dipakai. Kalau bulan sebelumnya
     * LEBIH PENDEK dari N hari (mis. bulan ini Maret hari ke-30, bulan lalu
     * Februari cuma 28/29 hari) - dipotong jujur ke akhir bulan lalu yang
     * sebenarnya (Langkah "handle differing month lengths honestly"), TIDAK
     * dipaksa sama panjang dengan overflow ke bulan sesudahnya.
     *
     * custom/legacy_days: "immediately preceding EQUAL-LENGTH range"
     * (requestedLengthInDays(), BUKAN effective) - contoh spec: Aug 10-20
     * (11 hari) -> previous Jul 30-Aug 9.
     */
    public function previousPeriod(AnalyticsPeriod $period): AnalyticsPeriod
    {
        if ($period->mode === AnalyticsPeriod::MODE_MONTH) {
            $prevMonthAnchor = Carbon::createFromFormat('Y-m-d', $period->month.'-01')->subMonthNoOverflow();
            $prevMonth = $this->buildMonth($prevMonthAnchor->format('Y-m'));

            if (! $period->isCurrentPeriodIncomplete()) {
                return $prevMonth;
            }

            $daysEvaluated = $period->dateFrom->diffInDays($period->effectiveDateTo) + 1;
            $prevEnd = $prevMonth->dateFrom->copy()->addDays($daysEvaluated - 1);
            if ($prevEnd->gt($prevMonth->dateTo)) {
                $prevEnd = $prevMonth->dateTo->copy();
            }

            return $this->buildCustom($prevMonth->dateFrom, $prevEnd);
        }

        $length = $period->requestedLengthInDays();
        $prevTo = $period->dateFrom->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($length - 1);

        return $period->mode === AnalyticsPeriod::MODE_LEGACY_DAYS
            ? $this->buildCustomKeepingMode($prevFrom, $prevTo, $period)
            : $this->buildCustom($prevFrom, $prevTo);
    }

    /**
     * previousPeriod() buat legacy_days TETAP dibangun via buildCustom()
     * secara MATEMATIS (rolling-N-hari-sebelum-rolling-N-hari-ini sudah
     * persis custom range biasa) - method ini cuma pembungkus tipis biar
     * nama mode di hasilnya tetap jelas asalnya dari legacy, bukan custom
     * baru genuine, buat keperluan audit/telemetry saja.
     */
    private function buildCustomKeepingMode(Carbon $from, Carbon $to, AnalyticsPeriod $original): AnalyticsPeriod
    {
        $built = $this->buildCustom($from, $to);

        return new AnalyticsPeriod(
            dateFrom: $built->dateFrom,
            dateTo: $built->dateTo,
            effectiveDateTo: $built->effectiveDateTo,
            mode: AnalyticsPeriod::MODE_LEGACY_DAYS,
            legacyDays: $original->legacyDays,
        );
    }

    private function isValidDateString(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        try {
            Carbon::createFromFormat('Y-m-d', $value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
