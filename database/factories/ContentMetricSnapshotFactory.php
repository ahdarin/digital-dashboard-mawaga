<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ContentMetricSnapshot;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentMetricSnapshot>
 *
 * Synthetic fixture untuk KPI outcome scoring (Fase 2) - dipakai lewat
 * `content_item_id` sebagai identity column (didukung penuh oleh
 * PeriodPerformanceService::computeContentDelta(), lihat
 * getDistinctContentKeyAttribute()) SUPAYA test tidak perlu membuat baris
 * InstagramMediaSnapshot/TikTokVideoSnapshot penuh untuk setiap skenario -
 * data sintetis, bukan API asli.
 */
class ContentMetricSnapshotFactory extends Factory
{
    protected $model = ContentMetricSnapshot::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'platform_id' => Platform::factory(),
            'snapshot_date' => now()->toDateString(),
            'views' => $this->faker->numberBetween(100, 10000),
            'reach' => $this->faker->numberBetween(100, 10000),
            'likes' => $this->faker->numberBetween(1, 500),
            'comments' => $this->faker->numberBetween(0, 50),
            'shares' => $this->faker->numberBetween(0, 30),
            'saves' => $this->faker->numberBetween(0, 30),
        ];
    }
}
