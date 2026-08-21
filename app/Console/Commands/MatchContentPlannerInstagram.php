<?php

namespace App\Console\Commands;

use App\Models\ApiIntegration;
use App\Models\ContentItem;
use App\Models\InstagramMediaSnapshot;
use App\Models\Platform;
use App\Services\HistoricalContentMatcher;
use Illuminate\Console\Command;

/**
 * Audit rekonsiliasi HISTORIS: ContentItem hasil "Content Planner Import"
 * (import_source=content_planner_xlsx) <-> InstagramMediaSnapshot lama yang
 * belum ter-link, per client. Scope SENGAJA sempit - TIDAK menyentuh
 * ContentItem operasional biasa (yang dibuat manual/AI Strategy), dan TIDAK
 * pernah menulis ContentPublication (lihat HistoricalContentMatcher - service
 * ini murni read-only, terpisah dari ContentPublicationMatcher).
 *
 * SAAT INI HANYA MENDUKUNG --dry-run - real auto-link BELUM diimplementasikan
 * sengaja (Langkah "ZERO AUTO WRITE FIRST" - keputusan threshold aman ditunggu
 * approval eksplisit dulu). Kalau flag ini dihilangkan, command menolak jalan.
 */
class MatchContentPlannerInstagram extends Command
{
    protected $signature = 'content-planner:match-instagram
        {--dry-run : WAJIB - command ini belum punya mode tulis sama sekali}';

    protected $description = 'Audit historical matching ContentItem hasil import planner <-> InstagramMediaSnapshot unmatched (read-only)';

    public function handle(HistoricalContentMatcher $matcher): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Command ini belum punya mode tulis - wajib pakai --dry-run. Auto-link akan dibangun terpisah setelah threshold disetujui.');
            return self::FAILURE;
        }

        $instagramPlatformId = Platform::where('name', 'Instagram')->value('id');

        $integrations = ApiIntegration::where('platform_id', $instagramPlatformId)
            ->where('status', 'active')
            ->with('client')
            ->get();

        $report = [
            'generated_at' => now()->toDateTimeString(),
            'mode' => 'dry-run',
            'clients' => [],
        ];

        $totalItems = 0;
        $totalSnapshots = 0;
        $totalHigh = 0;
        $totalMedium = 0;
        $totalAmbiguous = 0;
        $totalNoMatch = 0;

        foreach ($integrations as $integration) {
            $items = ContentItem::where('import_source', 'content_planner_xlsx')
                ->where('client_id', $integration->client_id)
                ->whereDoesntHave('publications', fn ($q) => $q->where('platform_id', $instagramPlatformId))
                ->with('contentType')
                ->get();

            $snapshots = InstagramMediaSnapshot::where('api_integration_id', $integration->id)
                ->whereIn('match_status', ['unmatched', 'ambiguous'])
                ->get();

            $totalItems += $items->count();
            $totalSnapshots += $snapshots->count();

            if ($items->isEmpty() || $snapshots->isEmpty()) {
                $report['clients'][] = [
                    'client_id' => $integration->client_id,
                    'client_name' => $integration->client?->name,
                    'items_considered' => $items->count(),
                    'snapshots_considered' => $snapshots->count(),
                    'note' => $items->isEmpty()
                        ? 'Tidak ada ContentItem hasil import planner yang belum ter-link untuk client ini.'
                        : 'Tidak ada InstagramMediaSnapshot unmatched/ambiguous untuk client ini.',
                    'results' => [],
                ];
                continue;
            }

            $candidatesBySnapshot = [];
            foreach ($snapshots as $snapshot) {
                $candidatesBySnapshot[$snapshot->id] = $matcher->candidatesForSnapshot($snapshot, $items);
            }

            $candidatesBySnapshot = $matcher->applyUniqueDateBonus($candidatesBySnapshot, $items, $snapshots);

            $clientResults = [];
            foreach ($snapshots as $snapshot) {
                $candidates = $candidatesBySnapshot[$snapshot->id];
                $classification = $matcher->classify($candidates);

                switch ($classification['status']) {
                    case 'HIGH': $totalHigh++; break;
                    case 'MEDIUM': $totalMedium++; break;
                    case 'AMBIGUOUS': $totalAmbiguous++; break;
                    default: $totalNoMatch++;
                }

                $clientResults[] = [
                    'snapshot_id' => $snapshot->id,
                    'external_post_id' => $snapshot->external_post_id,
                    'published_at' => $snapshot->published_at?->toDateTimeString(),
                    'caption_excerpt' => mb_substr($snapshot->caption ?? '', 0, 80),
                    'classification' => $classification['status'],
                    'reason' => $classification['reason'],
                    'candidates' => array_map(fn ($c) => [
                        'content_item_id' => $c['item']->id,
                        'content_item_title' => $c['item']->title,
                        'diff_days' => $c['diff_days'],
                        'similarity_pct' => $c['similarity'],
                        'date_score' => $c['date_score'],
                        'sim_score' => $c['sim_score'],
                        'format_score' => $c['format_score'],
                        'total_score' => $c['score'],
                        'signals' => $c['signals'],
                    ], array_slice($candidates, 0, 5)),
                ];
            }

            $report['clients'][] = [
                'client_id' => $integration->client_id,
                'client_name' => $integration->client?->name,
                'items_considered' => $items->count(),
                'snapshots_considered' => $snapshots->count(),
                'results' => $clientResults,
            ];
        }

        $report['totals'] = [
            'items_considered' => $totalItems,
            'snapshots_considered' => $totalSnapshots,
            'high' => $totalHigh,
            'medium' => $totalMedium,
            'ambiguous' => $totalAmbiguous,
            'no_match' => $totalNoMatch,
        ];

        $this->printSummary($report);
        $this->writeReport($report);

        $this->warn("\nIni DRY RUN - TIDAK ADA ContentPublication yang ditulis/di-link.");

        return self::SUCCESS;
    }

    private function printSummary(array $report): void
    {
        $this->newLine();
        $this->info('=== HISTORICAL MATCH AUDIT (dry-run) ===');
        foreach ($report['clients'] as $c) {
            $this->line("\nClient: {$c['client_name']} (id={$c['client_id']})");
            $this->line("  items_considered: {$c['items_considered']}, snapshots_considered: {$c['snapshots_considered']}");
            if (isset($c['note'])) {
                $this->line("  {$c['note']}");
                continue;
            }
            foreach ($c['results'] as $r) {
                $this->line("  snapshot={$r['snapshot_id']} [{$r['classification']}] {$r['reason']}");
            }
        }

        $this->newLine();
        $this->info('=== TOTAL ===');
        foreach ($report['totals'] as $k => $v) {
            $this->line(sprintf('%-20s %d', $k, $v));
        }
    }

    private function writeReport(array $report): void
    {
        $dir = storage_path('app/import-reports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = "{$dir}/content-planner-match-instagram-dry-run-".now()->format('Ymd-His').'.json';
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("\nReport tersimpan:");
        $this->line("  {$path}");
    }
}
