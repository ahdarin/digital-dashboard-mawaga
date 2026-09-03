<?php

namespace Database\Factories;

use App\Kpi\Formula\KpiFormulaConfig;
use App\Models\KpiFormulaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiFormulaVersion>
 */
class KpiFormulaVersionFactory extends Factory
{
    protected $model = KpiFormulaVersion::class;

    public function definition(): array
    {
        return [
            'version' => 'test-'.$this->faker->unique()->numerify('####'),
            'config' => KpiFormulaConfig::default()->toArray(),
            'effective_from' => now()->subYear()->toDateString(),
            'notes' => 'Synthetic factory default - untuk testing.',
        ];
    }
}
