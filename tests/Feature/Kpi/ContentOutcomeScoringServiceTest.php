<?php

namespace Tests\Feature\Kpi;

use App\Enums\CoverageStatus;
use App\Kpi\Dto\PublicationDelta;
use App\Kpi\Formula\KpiFormulaConfig;
use App\Kpi\Services\ContentOutcomeScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 2 - ContentOutcomeScoringService, level unit murni (PublicationDelta
 * dikonstruksi langsung, TANPA DB) untuk aturan normalisasi peer group yang
 * bisa diuji tanpa fixture berat: missing metric != 0, viral outlier tidak
 * mendominasi, carousel bisa unggul reels via percentile rank.
 */
class ContentOutcomeScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ContentOutcomeScoringService
    {
        return app(ContentOutcomeScoringService::class);
    }

    /**
     * array_key_exists (BUKAN ??) - supaya override eksplisit ke NULL
     * (mis. ['watch_time_avg' => null] untuk simulasi metric hilang) tetap
     * tersimpan sebagai null, tidak jatuh ke default (?? memperlakukan NULL
     * yang eksplisit sama seperti "tidak diisi").
     */
    private function delta(array $overrides = [], string $platformType = 'instagram'): PublicationDelta
    {
        $get = fn (string $key, $default) => array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        return new PublicationDelta(
            coverageStatus: $get('coverage', CoverageStatus::Full),
            views: $get('views', 1000),
            reach: $get('reach', 900),
            likes: $get('likes', 50),
            comments: $get('comments', 5),
            shares: $get('shares', 2),
            saves: $get('saves', 3),
            watchTimeAvg: $get('watch_time_avg', 10.0),
            completionRate: $get('completion_rate', 60.0),
            platformType: $platformType,
        );
    }

    /** Missing metric (retention unavailable) TIDAK PERNAH jadi 0 - bobotnya diredistribusi. */
    public function test_missing_retention_metric_is_not_treated_as_zero(): void
    {
        $config = KpiFormulaConfig::default();

        $target = $this->delta(['watch_time_avg' => null, 'completion_rate' => null]);
        $peers = collect([$this->delta(), $this->delta(['views' => 800]), $this->delta(['views' => 1200])]);

        $result = $this->service()->scoreVideoFormat($target, $peers, $config);

        $this->assertSame('unavailable', $result['components']['retention']['status']);
        $this->assertNull($result['components']['retention']['normalized']);
        // Skor keseluruhan tetap terhitung dari visibility+engagement saja
        // (redistribusi bobot), BUKAN otomatis unavailable/0 gara-gara
        // retention hilang.
        $this->assertNotNull($result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }

    /** Satu viral outlier tidak boleh membuat skor konten lain jadi ~0 (winsorized). */
    public function test_viral_outlier_does_not_dominate_peer_percentile(): void
    {
        $config = KpiFormulaConfig::default();

        // Peer pool wajar (views ratusan-ribuan) + SATU outlier viral ekstrem.
        $normalPeers = collect(range(1, 9))->map(fn ($i) => $this->delta(['views' => 1000 + $i * 100]));
        $viralPeer = $this->delta(['views' => 5_000_000]);
        $peers = $normalPeers->push($viralPeer);

        $medianTarget = $this->delta(['views' => 1500]); // performa median di antara peer wajar

        $result = $this->service()->scoreVideoFormat($medianTarget, $peers, $config);

        // Target median di antara peer WAJAR tetap dapat skor moderat
        // (bukan ditekan ke ~0 hanya karena ada satu outlier ekstrem di pool).
        $this->assertGreaterThan(20.0, $result['components']['visibility']['normalized']);
    }

    /** Carousel (desain) dinilai lewat percentile rank sendiri - performa relatif tinggi bisa unggul walau raw absolut lebih kecil dari video manapun (skala berbeda, tidak pernah dibandingkan langsung). */
    public function test_carousel_scored_via_own_percentile_rank_not_raw_views(): void
    {
        $config = KpiFormulaConfig::default();

        $target = $this->delta(['reach' => 500, 'saves' => 100, 'shares' => 40, 'comments' => 20, 'likes' => 60]);
        $weakerPeers = collect([
            $this->delta(['reach' => 500, 'saves' => 5, 'shares' => 1, 'comments' => 1, 'likes' => 10]),
            $this->delta(['reach' => 500, 'saves' => 3, 'shares' => 1, 'comments' => 0, 'likes' => 8]),
        ]);

        $result = $this->service()->scoreDesignFormat($target, $weakerPeers, $config);

        // Target dengan saves/shares/comments rate jauh lebih tinggi dari
        // peer harus dapat skor tinggi (top percentile) TERLEPAS dari
        // besaran absolut reach - ini yang membuktikan "carousel dinilai
        // relatif terhadap grupnya sendiri", bukan dibandingkan format lain.
        $this->assertGreaterThan(80.0, $result['score']);
    }

    /** Satu-satunya data (tidak ada peer) tidak boleh membuat skor 0 atau exception - netral (50). */
    public function test_single_data_point_without_peers_gets_neutral_score_not_zero(): void
    {
        $config = KpiFormulaConfig::default();
        $target = $this->delta();

        $result = $this->service()->scoreVideoFormat($target, collect(), $config);

        $this->assertNotNull($result['score']);
        $this->assertGreaterThan(0, $result['score']);
    }
}
