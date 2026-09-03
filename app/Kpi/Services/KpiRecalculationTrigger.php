<?php

namespace App\Kpi\Services;

use App\Jobs\RecalculateKpiPeriod;
use App\Models\ContentItem;
use Illuminate\Support\Carbon;

/**
 * Satu-satunya titik pemicu background job KPI - dipanggil (SATU baris)
 * dari titik-titik existing yang jadi trigger: assignment berubah,
 * workflow status berubah, revision dibuat/diselesaikan, publication
 * dibuat/diubah, analytics sync selesai, audience insight diperbarui,
 * halaman Team Performance dibuka. TIDAK mengubah alur/return value titik
 * pemanggilnya sama sekali.
 *
 * Koreksi lanjutan 2026-09-02 (#3): SEBELUMNYA semua titik trigger
 * (termasuk halaman Team Performance dan sync historis) selalu memanggil
 * `scheduleCurrentPeriod()` - membuka periode HISTORIS di halaman, atau
 * sync data BULAN LALU, tidak pernah benar-benar menjadwalkan ulang
 * periode yang sebenarnya terdampak. SEKARANG setiap titik pemanggil
 * memilih method yang sesuai dengan timestamp AKTIVITAS SEBENARNYA:
 * `scheduleForDate()` untuk satu tanggal, `scheduleForDateRange()` untuk
 * event yang bisa mencakup lebih dari satu bulan kalender (sync/import
 * historis) - dan `schedule()` langsung kalau titik pemanggil sudah punya
 * period_start/period_end eksplisit (mis. halaman yang memilih bulan).
 *
 * Debounce: dispatch dengan delay (bukan langsung) + `RecalculateKpiPeriod::
 * ShouldBeUnique` - banyak event beruntun dalam DEBOUNCE_SECONDS untuk
 * PERIODE YANG SAMA collapse jadi SATU job yang benar-benar jalan, bukan
 * job duplikat per event. Event yang mencakup beberapa periode berbeda
 * (`scheduleForDateRange()`) dijadwalkan sebagai beberapa dispatch
 * terpisah - deduplikasi ANTAR periode berbeda memang tidak relevan
 * (masing-masing genuinely periode yang berbeda), deduplikasi PER periode
 * yang sama tetap dijamin `ShouldBeUnique` seperti biasa.
 */
class KpiRecalculationTrigger
{
    private const DEBOUNCE_SECONDS = 60;

    /**
     * Jadwalkan kalkulasi ulang untuk BULAN BERJALAN (Asia/Jakarta) - dipakai
     * HANYA oleh titik trigger yang aktivitasnya SELALU terjadi "sekarang"
     * (mis. transisi status workflow, `changed_at`/`created_at` tidak pernah
     * bisa diisi tanggal masa lalu lewat alur manapun).
     */
    public static function scheduleCurrentPeriod(): void
    {
        self::scheduleForDate(Carbon::now('Asia/Jakarta'));
    }

    /**
     * Jadwalkan bulan kalender yang MENCAKUP $date - dipakai titik trigger
     * yang aktivitasnya punya SATU timestamp eksplisit yang bisa jadi masa
     * lalu (mis. `published_at` publication yang ditautkan manual).
     */
    public static function scheduleForDate(Carbon $date): void
    {
        self::schedule($date->copy()->startOfMonth(), $date->copy()->endOfMonth());
    }

    /**
     * Jadwalkan SETIAP bulan kalender yang tercakup [$from, $to] - dipakai
     * event yang bisa memengaruhi lebih dari satu periode sekaligus (sync
     * analytics/audience historis, import CSV dengan rentang tanggal bebas).
     * Setiap bulan yang tercakup dijadwalkan sebagai dispatch terpisah
     * (masing-masing tetap didebounce/di-dedup sendiri oleh ShouldBeUnique).
     */
    public static function scheduleForDateRange(Carbon $from, Carbon $to): void
    {
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            self::scheduleForDate($cursor);
            $cursor = $cursor->copy()->addMonthNoOverflow();
        }
    }

    public static function schedule(Carbon $periodStart, Carbon $periodEnd): void
    {
        RecalculateKpiPeriod::dispatch($periodStart->toDateString(), $periodEnd->toDateString())
            ->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
    }

    /**
     * Dipakai saat PIC content item diganti (`ContentItemController::
     * reassign()`) - jadwalkan bulan BERJALAN (assignment baru relevan
     * mulai sekarang) DAN bulan-bulan publication content item ini yang
     * SUDAH ada (mengoreksi PIC untuk konten yang sudah pernah tayang di
     * periode lalu harus menghitung ulang periode itu juga, bukan cuma
     * bulan sekarang).
     */
    public static function scheduleForContentItem(ContentItem $contentItem): void
    {
        self::scheduleCurrentPeriod();

        $publishedDates = $contentItem->publications()->pluck('published_at');
        foreach ($publishedDates as $publishedAt) {
            self::scheduleForDate(Carbon::parse($publishedAt));
        }
    }
}
