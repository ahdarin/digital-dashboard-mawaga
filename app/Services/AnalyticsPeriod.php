<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * PASS 2 - "PERIOD ENGINE V2". SATU representasi period yang dipakai
 * SEMUA consumer Analytics (Overview/Table/Audience/Export/Report/AI
 * Strategy) - TIDAK ADA controller/service lain yang boleh menghitung
 * date_from/date_to sendiri lagi (lihat AnalyticsPeriodResolver, satu-
 * satunya tempat object ini dibuat).
 *
 * dateFrom/dateTo = REQUESTED range (apa yang user minta - buat bulan
 * kalender, ini SELALU tanggal 1 s/d akhir bulan APA ADANYA, termasuk
 * hari yang belum terjadi kalau bulan berjalan). effectiveDateTo = batas
 * yang BOLEH dievaluasi genuine (min(dateTo, hari ini)) - INI yang
 * diteruskan ke PeriodPerformanceService, BUKAN dateTo, supaya bulan
 * berjalan TIDAK PERNAH dianggap "belum lengkap sampai akhir bulan"
 * hanya karena sisa harinya belum terjadi (Langkah "CURRENT MONTH/PARTIAL
 * CALENDAR MONTH").
 *
 * TIDAK PERNAH claim "covered" buat tanggal di masa depan - isCurrentPeriodIncomplete
 * SENGAJA eksplisit (bukan disimpulkan diam-diam dari effectiveDateTo !=
 * dateTo) biar UI/message layer selalu punya sinyal jujur.
 */
final class AnalyticsPeriod
{
    public const MODE_MONTH = 'month';

    public const MODE_CUSTOM = 'custom';

    // Legacy period=7/30/90 - SECARA MATEMATIS identik dengan custom
    // (rolling N hari s/d hari ini), tapi ditandai TERPISAH biar bisa
    // dibedakan dari custom range yang genuinely dipilih user lewat UI
    // baru (Langkah "LEGACY 7/30/90 COMPATIBILITY" - "the NEW UI should
    // stop generating legacy period values", jadi perlu tahu mana yang
    // legacy vs baru buat keperluan audit/telemetry, walau MATH-nya sama).
    public const MODE_LEGACY_DAYS = 'legacy_days';

    public function __construct(
        public readonly Carbon $dateFrom,
        public readonly Carbon $dateTo,
        public readonly Carbon $effectiveDateTo,
        public readonly string $mode,
        public readonly ?string $month = null,
        public readonly ?int $legacyDays = null,
    ) {
    }

    public function isCurrentPeriodIncomplete(): bool
    {
        return $this->effectiveDateTo->lt($this->dateTo->copy()->startOfDay());
    }

    /**
     * Jumlah hari kalender REQUESTED (bukan effective) - dipakai derivasi
     * previous-period buat mode custom/legacy_days ("immediately preceding
     * equal-length range").
     */
    public function requestedLengthInDays(): int
    {
        return $this->dateFrom->diffInDays($this->dateTo) + 1;
    }

    /**
     * Label siap-tampil - "September 2026" buat month, "10 Agu - 25 Agu
     * 2026" buat custom/legacy_days (tanggal sama disingkat jadi "10-25
     * Agu 2026").
     */
    public function label(): string
    {
        if ($this->mode === self::MODE_MONTH && $this->month) {
            return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->translatedFormat('F Y');
        }

        if ($this->dateFrom->isSameMonth($this->dateTo) && $this->dateFrom->isSameYear($this->dateTo)) {
            return $this->dateFrom->translatedFormat('d').'-'.$this->dateTo->translatedFormat('d M Y');
        }

        return $this->dateFrom->translatedFormat('d M Y').' - '.$this->dateTo->translatedFormat('d M Y');
    }

    /**
     * "Data through 2 Sep" style label (Langkah 5) - null kalau period
     * sudah genuinely lengkap (bukan bulan/rentang berjalan), biar caller
     * cuma menampilkan qualifier ini kalau memang relevan.
     */
    public function effectiveThroughLabel(): ?string
    {
        if (! $this->isCurrentPeriodIncomplete()) {
            return null;
        }

        return 'Data melalui '.$this->effectiveDateTo->translatedFormat('d M Y');
    }

    /**
     * Query-string kanonis buat URL BARU (Langkah "URL/QUERY-STRING
     * CONTRACT") - legacy_days SENGAJA tetap direpresentasikan sebagai
     * date_from/date_to eksplisit di sini (bukan period=N) - "the NEW UI
     * should stop generating legacy period values" berlaku buat SEMUA
     * mode, termasuk kalau resolver kebetulan resolve dari input legacy.
     *
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        if ($this->mode === self::MODE_MONTH) {
            return ['period_mode' => 'month', 'month' => $this->month];
        }

        return [
            'period_mode' => 'custom',
            'date_from' => $this->dateFrom->toDateString(),
            'date_to' => $this->dateTo->toDateString(),
        ];
    }
}
