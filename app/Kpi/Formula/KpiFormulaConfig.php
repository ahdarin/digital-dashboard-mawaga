<?php

namespace App\Kpi\Formula;

/**
 * Value object TUNGGAL untuk seluruh bobot & parameter KPI - TIDAK PERNAH
 * ada bobot ditulis sebagai magic number langsung di service manapun.
 * Dibaca dari `KpiFormulaVersion.config` (JSON) via fromArray(), atau
 * default() untuk bobot awal sesuai spesifikasi produk (lihat
 * docs/kpi/FORMULAS.md untuk penjelasan penuh tiap angka).
 *
 * Immutable - setiap "perubahan" bobot berarti versi `KpiFormulaVersion`
 * BARU (formula versioning), bukan mutasi objek ini.
 */
final class KpiFormulaConfig
{
    public function __construct(
        /** @var array{process_role: array{process: float, direct_outcome: float, portfolio_outcome: float}, smo: array{process: float, direct_outcome: float, portfolio_outcome: float}, leadership: array{process: float, portfolio_outcome: float}} */
        public readonly array $compositeWeights,
        /** @var array{video: array{visibility: float, engagement: float, retention: float}, design: array{reach: float, saves: float, shares: float, comments: float, likes: float}, engagement_component_weights: array{likes: float, comments: float, shares: float, saves: float}} */
        public readonly array $contentOutcome,
        /** @var array{visibility_growth: float, engagement: float, follower_growth: float} */
        public readonly array $clientPortfolio,
        /** @var array{early: int, sustained: int} */
        public readonly array $measurementWindowDays,
        /** @var array{min_publications_for_client_platform_format: int, lookback_days: int, winsorize_low_pct: int, winsorize_high_pct: int} */
        public readonly array $baseline,
        /** @var array{min_content_items_for_personal_indicator: int, min_publications_for_peer_baseline: int} */
        public readonly array $sampleSize,
    ) {}

    public static function default(): self
    {
        return new self(
            compositeWeights: [
                // Copywriter, Content Creator, Graphic Designer - juga
                // dipakai Manager/CEO saat tercatat sebagai PIC operasional
                // (koreksi #7: role produksi APA PUN eligible untuk
                // portfolio_outcome 10%, bukan cuma SMO).
                'process_role' => ['process' => 0.70, 'direct_outcome' => 0.20, 'portfolio_outcome' => 0.10],
                // SMO (role existing - "Account Lead" sudah dihapus per
                // keputusan produk, TIDAK ADA role baru).
                'smo' => ['process' => 0.60, 'direct_outcome' => 0.15, 'portfolio_outcome' => 0.25],
                // Manager / CEO dalam konteks leadership (decision/approval
                // yang benar-benar dilakukan, BUKAN sekadar akses RBAC).
                'leadership' => ['process' => 0.70, 'portfolio_outcome' => 0.30],
            ],
            contentOutcome: [
                'video' => ['visibility' => 0.45, 'engagement' => 0.35, 'retention' => 0.20],
                'design' => ['reach' => 0.40, 'saves' => 0.25, 'shares' => 0.20, 'comments' => 0.10, 'likes' => 0.05],
                // Bobot interaksi meaningful engagement - comments/shares/saves
                // diberi pengaruh lebih besar daripada likes (default_ awal).
                'engagement_component_weights' => ['likes' => 1.0, 'comments' => 1.5, 'shares' => 2.0, 'saves' => 2.0],
            ],
            clientPortfolio: [
                'visibility_growth' => 0.45,
                'engagement' => 0.35,
                'follower_growth' => 0.20,
            ],
            measurementWindowDays: ['early' => 7, 'sustained' => 30],
            baseline: [
                'min_publications_for_client_platform_format' => 8,
                'lookback_days' => 180,
                'winsorize_low_pct' => 5,
                'winsorize_high_pct' => 95,
            ],
            sampleSize: [
                'min_content_items_for_personal_indicator' => 5,
                'min_publications_for_peer_baseline' => 8,
            ],
        );
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $default = self::default();

        return new self(
            compositeWeights: $config['composite_weights'] ?? $default->compositeWeights,
            contentOutcome: $config['content_outcome'] ?? $default->contentOutcome,
            clientPortfolio: $config['client_portfolio'] ?? $default->clientPortfolio,
            measurementWindowDays: $config['measurement_window_days'] ?? $default->measurementWindowDays,
            baseline: $config['baseline'] ?? $default->baseline,
            sampleSize: $config['sample_size'] ?? $default->sampleSize,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'composite_weights' => $this->compositeWeights,
            'content_outcome' => $this->contentOutcome,
            'client_portfolio' => $this->clientPortfolio,
            'measurement_window_days' => $this->measurementWindowDays,
            'baseline' => $this->baseline,
            'sample_size' => $this->sampleSize,
        ];
    }

    public function minContentItemsForPersonalIndicator(): int
    {
        return $this->sampleSize['min_content_items_for_personal_indicator'];
    }

    public function minPublicationsForPeerBaseline(): int
    {
        return $this->sampleSize['min_publications_for_peer_baseline'];
    }

    public function earlyWindowDays(): int
    {
        return $this->measurementWindowDays['early'];
    }

    public function sustainedWindowDays(): int
    {
        return $this->measurementWindowDays['sustained'];
    }
}
