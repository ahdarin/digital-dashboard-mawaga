<?php

namespace App\Kpi\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Satu mekanisme lock EKSEKUSI KPI, dipakai BERSAMA oleh command developer
 * (`kpi:calculate`) dan job background (`RecalculateKpiPeriod`) - sebelum
 * ini keduanya punya lock terpisah (`Cache::lock` command sendiri vs
 * `ShouldBeUnique` job sendiri, format key beda), jadi command manual dan
 * job otomatis untuk PERIODE+FORMULA yang SAMA bisa jalan bersamaan tanpa
 * saling tahu.
 *
 * `ShouldBeUnique` pada job TETAP dipakai terpisah (lihat RecalculateKpiPeriod)
 * - itu mencegah DUPLIKASI DISPATCH sebelum job mulai jalan (debounce).
 * Lock di sini melindungi EKSEKUSI aktual (dari titik lock diambil sampai
 * kalkulasi selesai/gagal) - dua sumber trigger berbeda (command manual vs
 * job) untuk periode+formula yang SAMA TIDAK PERNAH menghitung bersamaan.
 */
class KpiCalculationLock
{
    private const TTL_SECONDS = 600;

    public static function key(Carbon $periodStart, Carbon $periodEnd, int $formulaVersionId): string
    {
        return "kpi-calculate-{$periodStart->toDateString()}-{$periodEnd->toDateString()}-{$formulaVersionId}";
    }

    public static function acquire(Carbon $periodStart, Carbon $periodEnd, int $formulaVersionId): Lock
    {
        return Cache::lock(self::key($periodStart, $periodEnd, $formulaVersionId), self::TTL_SECONDS);
    }
}
