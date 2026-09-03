<?php

namespace Tests\Feature\Kpi;

use App\Models\KpiFormulaVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Koreksi produk 2026-09-02 (#5) - resolveCurrent() harus deterministic,
 * concurrency-safe, dan berlaku untuk periode HISTORIS mana pun (bukan
 * cuma bulan saat bootstrap pertama terjadi) - TIDAK PERNAH butuh seeder
 * manual (KpiReferenceSeeder sudah dihapus total).
 */
class KpiFormulaVersionTest extends TestCase
{
    use RefreshDatabase;

    /** Formula default yang di-bootstrap HARUS bisa dipakai untuk periode HISTORIS (bukan cuma "hari ini") - effective_from tidak boleh diset ke tanggal bootstrap terjadi. */
    public function test_default_formula_applies_to_historical_periods(): void
    {
        $historicalPeriodEnd = Carbon::parse('2020-01-31');

        $formula = KpiFormulaVersion::resolveCurrent($historicalPeriodEnd);

        $this->assertNotNull($formula);
        $this->assertTrue(
            $formula->effective_from->lte($historicalPeriodEnd),
            'Formula default harus effective_from di masa lalu jauh - bukan tanggal bootstrap - supaya backfill periode historis apa pun tetap menemukannya.'
        );
    }

    /** Memanggil resolveCurrent() berkali-kali (skenario: banyak job pertama sekaligus) tidak boleh membuat lebih dari satu baris formula default. */
    public function test_repeated_bootstrap_calls_do_not_create_duplicate_default_versions(): void
    {
        KpiFormulaVersion::resolveCurrent(Carbon::parse('2026-06-30'));
        KpiFormulaVersion::resolveCurrent(Carbon::parse('2026-07-31'));
        KpiFormulaVersion::resolveCurrent(Carbon::parse('2020-01-01'));

        $this->assertSame(1, KpiFormulaVersion::count(), 'Bootstrap berulang harus idempotent - satu baris default saja, bukan satu per pemanggilan/hari.');
    }

    /** Sekali bootstrap terjadi, versi yang SAMA (bukan baris baru) yang dipakai untuk pemanggilan berikutnya. */
    public function test_bootstrap_is_idempotent_across_separate_calls(): void
    {
        $first = KpiFormulaVersion::resolveCurrent();
        $second = KpiFormulaVersion::resolveCurrent();

        $this->assertSame($first->id, $second->id);
    }

    /** Formula version yang eksplisit dibuat dengan effective_from tertentu tetap dipilih (bukan default) untuk periode setelah tanggal itu. */
    public function test_explicit_formula_version_takes_precedence_over_default(): void
    {
        $explicit = KpiFormulaVersion::factory()->create(['effective_from' => '2026-01-01']);

        $resolved = KpiFormulaVersion::resolveCurrent(Carbon::parse('2026-06-30'));

        $this->assertSame($explicit->id, $resolved->id);
    }
}
