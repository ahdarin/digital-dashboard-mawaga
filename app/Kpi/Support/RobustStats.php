<?php

namespace App\Kpi\Support;

/**
 * Statistik robust murni (tanpa dependency Eloquent/DB) - dipakai
 * ContentOutcomeScoringService/ClientPortfolioScoringService untuk
 * normalisasi peer group (docs/kpi/ANALYTICS_NORMALIZATION.md). Semua method
 * static & tanpa side effect supaya mudah diuji terisolasi dan dipakai
 * ulang oleh service manapun tanpa duplikasi rumus.
 *
 * Kenapa robust (bukan mean/stddev polos): satu konten viral/FYP harus
 * meningkatkan skor tapi TIDAK BOLEH mendominasi seluruh KPI bulanan -
 * winsorization + median + percentile rank meredam pengaruh outlier tanpa
 * membuang datanya sama sekali (beda dari trimming yang membuang data).
 */
final class RobustStats
{
    private function __construct() {}

    /**
     * Winsorize: nilai di bawah persentil $lowPct dipotong ke nilai persentil
     * itu, nilai di atas persentil $highPct dipotong ke nilai persentil itu.
     * Default 5-95 sesuai spesifikasi. $values HARUS sudah numeric, non-null
     * (caller bertanggung jawab memfilter null sebelum ke sini - null bukan
     * 0, tidak pernah masuk hitungan statistik).
     *
     * @param  array<int, float>  $values
     * @return array<int, float>
     */
    public static function winsorize(array $values, float $lowPct = 5.0, float $highPct = 95.0): array
    {
        if (count($values) < 2) {
            return $values;
        }

        $low = self::percentileValue($values, $lowPct);
        $high = self::percentileValue($values, $highPct);

        return array_map(fn (float $v) => min(max($v, $low), $high), $values);
    }

    /**
     * log1p = ln(1+x) - dipakai untuk distribusi views/reach yang sangat
     * miring (heavy-tailed) SEBELUM winsorize/percentile rank, supaya satu
     * angka ekstrem tidak meregangkan skala secara tidak proporsional.
     */
    public static function log1p(float $value): float
    {
        return log(1 + max($value, 0));
    }

    /**
     * Nilai pada persentil $pct (0-100) dari kumpulan data - interpolasi
     * linear antar dua titik terdekat (metode "linear interpolation",
     * standar dan cukup untuk sample size kecil-menengah yang realistis
     * untuk konten agensi per bulan).
     *
     * @param  array<int, float>  $values
     */
    public static function percentileValue(array $values, float $pct): float
    {
        $sorted = $values;
        sort($sorted);
        $n = count($sorted);

        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return $sorted[0];
        }

        $rank = ($pct / 100) * ($n - 1);
        $lowerIndex = (int) floor($rank);
        $upperIndex = (int) ceil($rank);

        if ($lowerIndex === $upperIndex) {
            return $sorted[$lowerIndex];
        }

        $fraction = $rank - $lowerIndex;

        return $sorted[$lowerIndex] + $fraction * ($sorted[$upperIndex] - $sorted[$lowerIndex]);
    }

    /**
     * Percentile RANK (0-100) dari satu nilai relatif terhadap peer group -
     * "berapa persen peer yang dilampaui nilai ini". Ini yang jadi component
     * score 0-100 untuk desain (reach percentile, saves rate percentile,
     * dst) - BUKAN percentileValue() (itu kebalikannya: dari persentil ke
     * nilai, dipakai winsorize).
     *
     * @param  array<int, float>  $peerValues  termasuk nilai itu sendiri
     */
    public static function percentileRank(float $value, array $peerValues): float
    {
        $n = count($peerValues);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return 50.0; // satu-satunya data - tidak ada pembanding, netral
        }

        $countBelowOrEqual = count(array_filter($peerValues, fn (float $v) => $v <= $value));

        return round(($countBelowOrEqual / $n) * 100, 2);
    }

    /**
     * @param  array<int, float>  $values
     */
    public static function median(array $values): float
    {
        return self::percentileValue($values, 50);
    }

    /**
     * Clamp skor akhir ke rentang 0-100 - dipakai SATU KALI di titik akhir
     * komposisi skor (bukan di setiap komponen), supaya rounding tidak
     * terjadi berulang di tengah perhitungan (aturan: rounding hanya untuk
     * output/display, bukan di tengah kalkulasi).
     */
    public static function clampScore(float $score): float
    {
        return min(max($score, 0.0), 100.0);
    }
}
