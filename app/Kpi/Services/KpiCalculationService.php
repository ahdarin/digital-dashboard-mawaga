<?php

namespace App\Kpi\Services;

use App\Enums\CoverageStatus;
use App\Enums\MeasurementWindow;
use App\Kpi\Dto\CompositeKpiResult;
use App\Kpi\Dto\ProcessScoreBreakdown;
use App\Kpi\Formula\KpiFormulaConfig;
use App\Kpi\Support\RobustStats;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentOutcomeResult;
use App\Models\ContentPublication;
use App\Models\ContentStatusLog;
use App\Models\KpiCalculationRun;
use App\Models\Role;
use App\Models\UserKpiResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orkestrator utama KPI (docs/kpi/FORMULAS.md).
 *
 * Koreksi lanjutan produk 2026-09-02 - atribusi SEKARANG murni berbasis
 * AKTIVITAS AKTOR yang terbukti dari data existing DI DALAM periode yang
 * dihitung (lihat KpiRoleContextResolver):
 * - Copywriter: brief yang dia buat di periode ini (tidak perlu jadi PIC).
 * - Content Creator/Graphic Designer/Manager-CEO-sbg-PIC: PIC pada content
 *   item yang MEMANG ada aktivitas produksi tercatat di periode ini.
 * - SMO: publication yang BENAR-BENAR dia publikasikan sendiri di periode
 *   ini (bukan sekadar jadi PIC content).
 * - Manager/CEO leadership: approval/revision NYATA yang dia lakukan di
 *   periode ini.
 *
 * Setiap baris hasil SEKARANG per (user, role, client) - client_id TIDAK
 * PERNAH null untuk baris operasional (koreksi #4: dulu selalu null,
 * membuat filter klien tidak pernah menampilkan staf produksi). Satu user
 * boleh punya banyak baris (beberapa role, beberapa client) dalam run yang
 * sama - TIDAK PERNAH satu overall score gabungan.
 *
 * Manager/CEO yang PADA PERIODE+KLIEN YANG SAMA punya AKTIVITAS PRODUKSI
 * (PIC) DAN AKTIVITAS LEADERSHIP (decision log) - kedua sinyal itu di-MERGE
 * jadi SATU baris (bukan salah satu overwrite yang lain secara diam-diam -
 * lihat mergeProcessBreakdowns()), karena kunci (run,user,role,client) di
 * `user_kpi_results` cuma bisa menampung satu baris per kombinasi itu.
 *
 * TIDAK PERNAH menimpa run lama - setiap eksekusi (background job/command)
 * buat KpiCalculationRun BARU, histori penuh tetap ada untuk audit lintas
 * versi formula.
 */
class KpiCalculationService
{
    /**
     * Cache in-memory per pemanggilan calculate() - banyak user/role bisa
     * berbagi client yang SAMA dalam satu run (mis. 5 Content Creator di
     * klien yang sama), tanpa ini scoreClient() (analytics, cukup mahal)
     * akan dihitung ULANG persis sama berkali-kali untuk client yang sama.
     *
     * @var array<string, array{score: ?float, coverage: CoverageStatus}>
     */
    private array $portfolioScoreCache = [];

    public function __construct(
        private readonly KpiAttributionService $attribution,
        private readonly KpiRoleContextResolver $roleContext,
        private readonly RoleProcessKpiService $process,
        private readonly ContentOutcomeScoringService $outcome,
        private readonly ClientPortfolioScoringService $portfolio,
        private readonly KpiCoverageService $coverage,
    ) {}

    public function calculate(KpiCalculationRun $run): void
    {
        $run->update(['status' => KpiCalculationRun::STATUS_RUNNING, 'started_at' => now()]);

        try {
            DB::transaction(function () use ($run) {
                $config = KpiFormulaConfig::fromArray($run->formulaVersion->config);
                $periodStart = $run->period_start->copy();
                $periodEnd = $run->period_end->copy();

                $publishedItemIds = $this->attribution->contentItemIdsPublishedInPeriod($periodStart, $periodEnd);
                $this->persistContentOutcomes($run, $publishedItemIds, $config);

                $this->portfolioScoreCache = [];

                $operational = $this->buildOperationalResults($run, $config, $periodStart, $periodEnd);
                $leadership = $this->buildLeadershipResults($config, $periodStart, $periodEnd);

                $this->persistResults($run, $config, $operational, $leadership, $periodStart, $periodEnd);
            });

            $run->update(['status' => KpiCalculationRun::STATUS_COMPLETED, 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => KpiCalculationRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Hitung & simpan ContentOutcomeResult untuk content item yang punya
     * publication di periode ini - D7 dan D30 sekaligus. Idempotent.
     */
    private function persistContentOutcomes(KpiCalculationRun $run, Collection $contentItemIds, KpiFormulaConfig $config): void
    {
        $items = ContentItem::whereIn('id', $contentItemIds)->with('contentType')->get();

        foreach ($items as $item) {
            foreach ([MeasurementWindow::D7, MeasurementWindow::D30] as $window) {
                $score = $this->outcome->scoreContentItem($item, $window, $config);

                ContentOutcomeResult::updateOrCreate(
                    [
                        'kpi_calculation_run_id' => $run->id,
                        'content_item_id' => $item->id,
                        'measurement_window' => $window->value,
                    ],
                    $score->toPersistArray($run->id)
                );
            }
        }
    }

    /**
     * Bangun SEMUA hasil operasional (Copywriter, Content Creator/Graphic
     * Designer/Manager-CEO-sbg-PIC, SMO) - dikelompokkan (userId, roleName,
     * clientId) supaya breakdown per-client benar (koreksi #4).
     *
     * @return array<string, array{userId:int, roleName:string, clientId:int, process: ProcessScoreBreakdown, directOutcome: array, portfolio: array, contentItemIds: Collection}>
     */
    private function buildOperationalResults(KpiCalculationRun $run, KpiFormulaConfig $config, Carbon $periodStart, Carbon $periodEnd): array
    {
        $min = $config->minContentItemsForPersonalIndicator();
        $results = [];

        // --- Copywriter ---
        $copywriterGroups = $this->roleContext->copywriterActivities($periodStart, $periodEnd)
            ->groupBy(fn (array $a) => $a['user_id'].'|'.$a['client_id']);

        foreach ($copywriterGroups as $group) {
            $userId = $group->first()['user_id'];
            $clientId = $group->first()['client_id'];
            $contentItemIds = $group->pluck('content_item_id')->unique()->values();

            $process = $this->process->scoreCopywriter($userId, $periodStart, $periodEnd, $min);
            $direct = $this->averageOutcomeScore($run, $contentItemIds);
            $portfolioScore = $this->portfolioScoreForClient($clientId, $periodStart, $periodEnd, $config);

            $results["{$userId}|Copywriter|{$clientId}"] = [
                'userId' => $userId, 'roleName' => 'Copywriter', 'clientId' => $clientId,
                'process' => $process, 'directOutcome' => $direct, 'portfolio' => $portfolioScore,
                'contentItemIds' => $contentItemIds,
            ];
        }

        // --- Content Creator / Graphic Designer / Manager-CEO-sbg-PIC ---
        $productionGroups = $this->roleContext->productionActivities($periodStart, $periodEnd)
            ->groupBy(fn (array $a) => $a['user_id'].'|'.$a['role_name'].'|'.$a['client_id']);

        foreach ($productionGroups as $group) {
            $first = $group->first();
            $userId = $first['user_id'];
            $roleName = $first['role_name'];
            $clientId = $first['client_id'];
            $contentItemIds = $group->pluck('content_item_id')->unique()->values();

            $process = $this->process->scoreProductionRole($userId, $periodStart, $periodEnd, $contentItemIds, $min);
            $direct = $this->averageOutcomeScore($run, $contentItemIds);
            $portfolioScore = $this->portfolioScoreForClient($clientId, $periodStart, $periodEnd, $config);

            $results["{$userId}|{$roleName}|{$clientId}"] = [
                'userId' => $userId, 'roleName' => $roleName, 'clientId' => $clientId,
                'process' => $process, 'directOutcome' => $direct, 'portfolio' => $portfolioScore,
                'contentItemIds' => $contentItemIds,
            ];
        }

        // --- SMO ---
        $smoActivities = $this->roleContext->smoActivities($periodStart, $periodEnd);
        $smoGroups = $smoActivities->groupBy(fn (array $a) => $a['user_id'].'|'.$a['client_id']);
        $publicationsById = ContentPublication::whereIn('id', $smoActivities->pluck('publication_id')->unique())
            ->with('contentItem')
            ->get()
            ->keyBy('id');

        foreach ($smoGroups as $group) {
            $userId = $group->first()['user_id'];
            $clientId = $group->first()['client_id'];
            $contentItemIds = $group->pluck('content_item_id')->unique()->values();
            $publications = $group->map(fn (array $a) => $publicationsById->get($a['publication_id']))->filter()->values();

            $process = $this->process->scoreSmo($userId, $periodStart, $periodEnd, $publications, $min);
            $direct = $this->averageOutcomeScore($run, $contentItemIds);
            $portfolioScore = $this->portfolioScoreForClient($clientId, $periodStart, $periodEnd, $config);

            $key = "{$userId}|SMO|{$clientId}";
            $results[$key] = [
                'userId' => $userId, 'roleName' => 'SMO', 'clientId' => $clientId,
                'process' => $process, 'directOutcome' => $direct, 'portfolio' => $portfolioScore,
                'contentItemIds' => $contentItemIds,
            ];
        }

        return $results;
    }

    /**
     * Bangun hasil leadership Manager/CEO - HANYA dari client yang MEMANG
     * punya approval/decision NYATA dari user ini di periode ini
     * (content_status_logs.changed_by_user_id), BUKAN dari tabel assignment
     * leadership terpisah (tidak pernah ada) atau akses RBAC global.
     *
     * @return array<string, array{userId:int, roleName:string, clientId:int, process: ProcessScoreBreakdown, portfolio: array, decidedItemIds: Collection}>
     */
    private function buildLeadershipResults(KpiFormulaConfig $config, Carbon $periodStart, Carbon $periodEnd): array
    {
        $min = $config->minContentItemsForPersonalIndicator();
        $results = [];

        $decisionLogs = ContentStatusLog::whereIn('to_status', ['approved', 'revision'])
            ->whereNull('approval_type')
            ->whereBetween('changed_at', [$periodStart, $periodEnd->copy()->endOfDay()])
            ->whereHas('changedByUser', fn ($q) => $q->whereHas('roles', fn ($qq) => $qq->whereIn('name', ['Manager', 'CEO'])))
            ->with(['contentItem', 'changedByUser.roles'])
            ->get()
            ->filter(fn (ContentStatusLog $log) => $log->changed_by_user_id !== null && $log->contentItem !== null && $log->changedByUser !== null);

        $groups = $decisionLogs->groupBy(fn (ContentStatusLog $log) => $log->changed_by_user_id.'|'.$log->contentItem->client_id);

        foreach ($groups as $logsForGroup) {
            $leader = $logsForGroup->first()->changedByUser;
            $clientId = $logsForGroup->first()->contentItem->client_id;
            $leaderRoleName = $leader->roles->pluck('name')->first(fn ($n) => in_array($n, ['CEO', 'Manager'], true)) ?? 'Manager';

            $decidedItemIds = $logsForGroup->pluck('content_item_id')->unique()->values();

            $process = $this->process->scoreLeadership($leader->id, $periodStart, $periodEnd, $decidedItemIds, $min);
            $portfolioScore = $this->portfolioScoreForClient($clientId, $periodStart, $periodEnd, $config);

            $results["{$leader->id}|{$leaderRoleName}|{$clientId}"] = [
                'userId' => $leader->id, 'roleName' => $leaderRoleName, 'clientId' => $clientId,
                'process' => $process, 'portfolio' => $portfolioScore, 'decidedItemIds' => $decidedItemIds,
            ];
        }

        return $results;
    }

    /**
     * Gabungkan hasil operasional + leadership jadi baris UserKpiResult -
     * kalau (user, role, client) yang SAMA muncul di KEDUANYA (Manager/CEO
     * yang PADA KLIEN INI juga langsung mengerjakan produksi DAN melakukan
     * approval/decision), di-MERGE jadi satu baris (lihat
     * mergeProcessBreakdowns()) - bukan salah satu menimpa yang lain diam-diam.
     */
    private function persistResults(KpiCalculationRun $run, KpiFormulaConfig $config, array $operational, array $leadership, Carbon $periodStart, Carbon $periodEnd): void
    {
        $roleIdsByName = Role::pluck('id', 'name');
        $keys = array_unique(array_merge(array_keys($operational), array_keys($leadership)));

        foreach ($keys as $key) {
            $op = $operational[$key] ?? null;
            $lead = $leadership[$key] ?? null;

            if ($op && $lead) {
                $result = $this->composeMergedResult($op, $lead, $roleIdsByName, $config);
            } elseif ($op) {
                $result = $this->composeOperationalResult($op, $roleIdsByName, $config);
            } else {
                $result = $this->composeLeadershipResult($lead, $roleIdsByName, $config);
            }

            $this->persistUserKpiResult($run, $result, $periodStart, $periodEnd);
        }
    }

    private function composeOperationalResult(array $op, Collection $roleIdsByName, KpiFormulaConfig $config): CompositeKpiResult
    {
        return $this->composeResult(
            userId: $op['userId'],
            roleId: $roleIdsByName[$op['roleName']] ?? null,
            roleName: $op['roleName'],
            clientId: $op['clientId'],
            processScore: $op['process']->processScore,
            processCoverage: $op['process']->overallCoverage,
            directOutcomeScore: $op['directOutcome']['score'],
            directOutcomeCoverage: $op['directOutcome']['coverage'],
            portfolioScore: $op['portfolio']['score'],
            portfolioCoverage: $op['portfolio']['coverage'],
            weightsKey: $op['roleName'] === 'SMO' ? 'smo' : 'process_role',
            hasDirectOutcome: true,
            hasPortfolioComponent: true,
            sampleSize: max($op['process']->sampleSize, $op['contentItemIds']->count()),
            config: $config,
            breakdown: [
                'role_name' => $op['roleName'],
                'process' => $op['process']->metrics,
                'direct_outcome' => $op['directOutcome'],
                'portfolio_outcome' => $op['portfolio'],
                'contributing_content_item_ids' => $op['contentItemIds']->all(),
            ],
        );
    }

    private function composeLeadershipResult(array $lead, Collection $roleIdsByName, KpiFormulaConfig $config): CompositeKpiResult
    {
        return $this->composeResult(
            userId: $lead['userId'],
            roleId: $roleIdsByName[$lead['roleName']] ?? null,
            roleName: $lead['roleName'],
            clientId: $lead['clientId'],
            processScore: $lead['process']->processScore,
            processCoverage: $lead['process']->overallCoverage,
            directOutcomeScore: null,
            directOutcomeCoverage: CoverageStatus::Unavailable,
            portfolioScore: $lead['portfolio']['score'],
            portfolioCoverage: $lead['portfolio']['coverage'],
            weightsKey: 'leadership',
            hasDirectOutcome: false,
            hasPortfolioComponent: true,
            sampleSize: max($lead['process']->sampleSize, $lead['decidedItemIds']->count()),
            config: $config,
            breakdown: [
                'role_name' => $lead['roleName'],
                'process' => $lead['process']->metrics,
                'portfolio_outcome' => $lead['portfolio'],
                'leadership_decided_content_item_ids' => $lead['decidedItemIds']->all(),
            ],
        );
    }

    /**
     * Manager/CEO dengan aktivitas produksi DAN leadership pada (role,
     * client) yang SAMA di periode yang sama - kedua process score
     * digabung (weighted average berdasar sample size, bukan ditebak),
     * direct_outcome dari produksi (leadership tidak punya), portfolio
     * IDENTIK dari kedua sisi (dihitung sekali per client, dipakai ulang).
     */
    private function composeMergedResult(array $op, array $lead, Collection $roleIdsByName, KpiFormulaConfig $config): CompositeKpiResult
    {
        $mergedProcess = $this->mergeProcessBreakdowns($op['process'], $lead['process']);

        return $this->composeResult(
            userId: $op['userId'],
            roleId: $roleIdsByName[$op['roleName']] ?? null,
            roleName: $op['roleName'],
            clientId: $op['clientId'],
            processScore: $mergedProcess->processScore,
            processCoverage: $mergedProcess->overallCoverage,
            directOutcomeScore: $op['directOutcome']['score'],
            directOutcomeCoverage: $op['directOutcome']['coverage'],
            portfolioScore: $op['portfolio']['score'],
            portfolioCoverage: $op['portfolio']['coverage'],
            weightsKey: 'process_role',
            hasDirectOutcome: true,
            hasPortfolioComponent: true,
            sampleSize: $mergedProcess->sampleSize,
            config: $config,
            breakdown: [
                'role_name' => $op['roleName'],
                'merged_production_and_leadership' => true,
                'process' => $mergedProcess->metrics,
                'direct_outcome' => $op['directOutcome'],
                'portfolio_outcome' => $op['portfolio'],
                'contributing_content_item_ids' => $op['contentItemIds']->all(),
                'leadership_decided_content_item_ids' => $lead['decidedItemIds']->all(),
            ],
        );
    }

    /**
     * Gabungkan dua ProcessScoreBreakdown (production + leadership) untuk
     * (user, role, client) yang SAMA - weighted average process score
     * berdasar sample size masing-masing (angka NYATA yang sudah dihitung,
     * bukan tebakan), metrics digabung apa adanya (nama metrik production
     * vs leadership tidak pernah bentrok), coverage ambil yang PALING LEMAH.
     */
    private function mergeProcessBreakdowns(ProcessScoreBreakdown $production, ProcessScoreBreakdown $leadership): ProcessScoreBreakdown
    {
        $metrics = array_merge($production->metrics, $leadership->metrics);
        $totalSampleSize = $production->sampleSize + $leadership->sampleSize;

        $components = [];
        if ($production->processScore !== null) {
            $components[] = ['score' => $production->processScore, 'weight' => max($production->sampleSize, 1)];
        }
        if ($leadership->processScore !== null) {
            $components[] = ['score' => $leadership->processScore, 'weight' => max($leadership->sampleSize, 1)];
        }

        $processScore = null;
        if (! empty($components)) {
            $totalWeight = array_sum(array_column($components, 'weight'));
            $sum = 0.0;
            foreach ($components as $c) {
                $sum += $c['score'] * ($c['weight'] / $totalWeight);
            }
            $processScore = RobustStats::clampScore($sum);
        }

        $coverage = CoverageStatus::weakest($production->overallCoverage, $leadership->overallCoverage);

        return new ProcessScoreBreakdown($production->userId, $metrics, $processScore, $coverage, $totalSampleSize);
    }

    /**
     * updateOrCreate keyed by (run, user, role, client) - WAJIB untuk
     * idempotency: memanggil calculate() dua kali pada KpiCalculationRun
     * yang SAMA tidak boleh menggandakan baris.
     */
    private function persistUserKpiResult(KpiCalculationRun $run, CompositeKpiResult $result, Carbon $periodStart, Carbon $periodEnd): void
    {
        UserKpiResult::updateOrCreate(
            [
                'kpi_calculation_run_id' => $run->id,
                'user_id' => $result->userId,
                'role_id' => $result->roleId,
                'client_id' => $result->clientId,
            ],
            $result->toPersistArray($run->id, $periodStart->toDateString(), $periodEnd->toDateString())
        );
    }

    /**
     * @return array{score: ?float, coverage: CoverageStatus, sample_size: int}
     */
    private function averageOutcomeScore(KpiCalculationRun $run, Collection $contentItemIds): array
    {
        if ($contentItemIds->isEmpty()) {
            return ['score' => null, 'coverage' => CoverageStatus::Unavailable, 'sample_size' => 0];
        }

        $results = ContentOutcomeResult::where('kpi_calculation_run_id', $run->id)
            ->whereIn('content_item_id', $contentItemIds)
            ->get()
            ->groupBy('content_item_id');

        $scores = [];
        foreach ($results as $itemId => $windows) {
            $d30 = $windows->firstWhere('measurement_window', 'd30');
            $d7 = $windows->firstWhere('measurement_window', 'd7');
            $chosen = ($d30 && $d30->isUsable()) ? $d30 : (($d7 && $d7->isUsable()) ? $d7 : null);
            if ($chosen) {
                $scores[] = (float) $chosen->normalized_score;
            }
        }

        if (empty($scores)) {
            return ['score' => null, 'coverage' => CoverageStatus::Unavailable, 'sample_size' => 0];
        }

        // Coverage "full" hanya kalau SEMUA content item yang dimaksud
        // benar-benar punya ContentOutcomeResult usable - item yang
        // ada di $contentItemIds tapi TIDAK muncul di $results sama sekali
        // (mis. belum dipublikasikan di periode ini) TETAP dihitung sebagai
        // "belum lengkap", bukan diabaikan diam-diam.
        $coverage = count($scores) === $contentItemIds->count() ? CoverageStatus::Full : CoverageStatus::Partial;

        return ['score' => RobustStats::clampScore(array_sum($scores) / count($scores)), 'coverage' => $coverage, 'sample_size' => count($scores)];
    }

    /**
     * @return array{score: ?float, coverage: CoverageStatus}
     */
    private function portfolioScoreForClient(?int $clientId, Carbon $periodStart, Carbon $periodEnd, KpiFormulaConfig $config): array
    {
        if ($clientId === null) {
            return ['score' => null, 'coverage' => CoverageStatus::Unavailable];
        }

        if (array_key_exists($clientId, $this->portfolioScoreCache)) {
            return $this->portfolioScoreCache[$clientId];
        }

        $client = Client::find($clientId);
        $result = $client
            ? $this->portfolio->scoreClient($client, $periodStart, $periodEnd, $config)
            : ['score' => null, 'coverage' => CoverageStatus::Unavailable];

        return $this->portfolioScoreCache[$clientId] = ['score' => $result['score'], 'coverage' => $result['coverage']];
    }

    /**
     * @param  array<string, mixed>  $breakdown
     */
    private function composeResult(
        int $userId,
        ?int $roleId,
        string $roleName,
        ?int $clientId,
        ?float $processScore,
        CoverageStatus $processCoverage,
        ?float $directOutcomeScore,
        CoverageStatus $directOutcomeCoverage,
        ?float $portfolioScore,
        CoverageStatus $portfolioCoverage,
        string $weightsKey,
        bool $hasDirectOutcome,
        bool $hasPortfolioComponent,
        int $sampleSize,
        KpiFormulaConfig $config,
        array $breakdown,
    ): CompositeKpiResult {
        $weights = $config->compositeWeights[$weightsKey];
        $minSampleSize = $config->minContentItemsForPersonalIndicator();

        $components = [];
        if ($processScore !== null) {
            $components['process'] = ['score' => $processScore, 'weight' => $weights['process']];
        }
        if ($hasDirectOutcome && $directOutcomeScore !== null) {
            $components['direct_outcome'] = ['score' => $directOutcomeScore, 'weight' => $weights['direct_outcome'] ?? 0];
        }
        if ($hasPortfolioComponent && $portfolioScore !== null) {
            $components['portfolio_outcome'] = ['score' => $portfolioScore, 'weight' => $weights['portfolio_outcome']];
        }

        $totalWeight = array_sum(array_column($components, 'weight'));
        $compositeScore = null;
        if (! empty($components) && $totalWeight > 0) {
            $sum = 0.0;
            foreach ($components as $c) {
                $sum += $c['score'] * ($c['weight'] / $totalWeight);
            }
            $compositeScore = RobustStats::clampScore($sum);
        }

        $overallCoverage = CoverageStatus::weakest(
            $processScore !== null ? $processCoverage : CoverageStatus::Full,
            ($hasDirectOutcome && $directOutcomeScore !== null) ? $directOutcomeCoverage : CoverageStatus::Full,
            ($hasPortfolioComponent && $portfolioScore !== null) ? $portfolioCoverage : CoverageStatus::Full,
        );

        if (empty($components)) {
            $overallCoverage = CoverageStatus::Unavailable;
        }

        $statusLabel = $this->coverage->determineStatusLabel($compositeScore, $overallCoverage, $sampleSize, $minSampleSize);

        return new CompositeKpiResult(
            userId: $userId,
            roleId: $roleId,
            roleName: $roleName,
            clientId: $clientId,
            processScore: $processScore,
            directOutcomeScore: $hasDirectOutcome ? $directOutcomeScore : null,
            portfolioOutcomeScore: $hasPortfolioComponent ? $portfolioScore : null,
            compositeScore: $compositeScore,
            coverageStatus: $overallCoverage,
            sampleSize: $sampleSize,
            statusLabel: $statusLabel,
            componentBreakdown: $breakdown,
        );
    }
}
