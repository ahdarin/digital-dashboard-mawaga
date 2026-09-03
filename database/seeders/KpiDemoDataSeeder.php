<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\ContentBriefDraft;
use App\Models\ContentFormat;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPlan;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Models\ContentType;
use App\Models\Platform;
use App\Models\User;
use App\Services\TeamPerformanceKpiCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * HANYA untuk uji visual chart/tabel KPI Team Performance - BUKAN bagian
 * dari fitur, tidak dipanggil DatabaseSeeder. Jalankan eksplisit:
 *   php artisan db:seed --class=KpiDemoDataSeeder
 *
 * Aman dijalankan di `digidaw` (dev DB operasional): TIDAK PERNAH membuat
 * Client atau User fiktif (beda dari DemoSeeder) - cuma menambah
 * ContentPlan/ContentItem/assignment/publication/revision/brief/snapshot
 * sintetis yang menunjuk ke Client & User REAL yang sudah ada. Semua
 * ContentItem yang dibuat ditandai `import_source = 'kpi_demo_seed'`
 * supaya gampang dibedakan dari data asli dan gampang dihapus lagi:
 *
 *   $ids = ContentItem::where('import_source', 'kpi_demo_seed')->pluck('id');
 *   ContentMetricSnapshot::whereIn('content_item_id', $ids)->delete();
 *   $planIds = ContentItem::where('import_source', 'kpi_demo_seed')->pluck('content_plan_id')->unique();
 *   ContentItem::where('import_source', 'kpi_demo_seed')->forceDelete();
 *   ContentPlan::whereIn('id', $planIds)->whereDoesntHave('contentItems')->delete();
 *   UserMonthlyKpiResult::where('period_start', '>=', now()->subMonths(5)->startOfMonth())->delete();
 */
class KpiDemoDataSeeder extends Seeder
{
    private const TAG = 'kpi_demo_seed';
    private const MONTHS_BACK = 6;

    public function run(): void
    {
        $workers = User::where('status', 'active')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'Admin'))
            ->get();

        $clients = Client::where('status', 'active')->get();

        if ($workers->count() < 2 || $clients->isEmpty()) {
            $this->command?->warn('KpiDemoDataSeeder: butuh minimal 2 user aktif (non-Admin) dan 1 client aktif - dilewati.');

            return;
        }

        $videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $desainType = ContentType::firstOrCreate(['name' => 'Desain']);
        $videoFormat = ContentFormat::firstOrCreate(['slug' => 'video'], ['name' => 'Video']);
        $singlePostFormat = ContentFormat::firstOrCreate(['slug' => 'single-post'], ['name' => 'Single Post']);
        $carouselFormat = ContentFormat::firstOrCreate(['slug' => 'carousel'], ['name' => 'Carousel']);
        $instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktok = Platform::firstOrCreate(['name' => 'TikTok']);

        // Bobot pemilihan PIC sengaja timpang: CEO (2 orang pertama diasumsikan
        // leadership kalau ada role CEO) jarang kebagian, mayoritas beban ke
        // worker biasa - biar contoh "leadership tanpa kontribusi operasional
        // tidak dapat skor" ikut kelihatan natural di data demo ini.
        $leadershipIds = $workers->filter(fn (User $u) => $u->hasAnyRole([UserRole::CEO, UserRole::Manager]))->pluck('id');
        $pool = $workers->flatMap(fn (User $u) => array_fill(0, $leadershipIds->contains($u->id) ? 1 : 6, $u));

        // Spesialisasi tetap per user (deterministik dari id) - supaya baseline
        // Bonus Performa per format konsisten sepanjang 6 bulan (Video tidak
        // pernah "kebetulan" ketemu campuran desain).
        $specialtyFor = fn (User $u) => $u->id % 2 === 0 ? 'video' : 'desain';

        $months = collect(range(self::MONTHS_BACK - 1, 0))
            ->map(fn ($i) => Carbon::now()->startOfMonth()->subMonths($i));

        $planCache = [];
        $totalItems = 0;

        foreach ($months as $monthIndex => $monthStart) {
            foreach ($workers as $worker) {
                if ($leadershipIds->contains($worker->id) && ! fake()->boolean(15)) {
                    continue; // leadership: kebanyakan tidak ikut produksi bulan ini
                }

                $itemCount = random_int(2, 6);

                for ($i = 0; $i < $itemCount; $i++) {
                    $client = $clients->random();
                    $specialty = $specialtyFor($worker);

                    $planKey = $client->id.'-'.$monthStart->format('Y-m');
                    if (! isset($planCache[$planKey])) {
                        $planCache[$planKey] = ContentPlan::create([
                            'client_id' => $client->id,
                            'client_package_id' => null,
                            'created_by' => $worker->id,
                            'month' => $monthStart->month,
                            'year' => $monthStart->year,
                            'status' => 'approved',
                        ])->id;
                    }

                    $deadline = $monthStart->copy()->addDays(random_int(0, $monthStart->daysInMonth - 1))->addHours(random_int(8, 18));

                    $item = ContentItem::create([
                        'content_plan_id' => $planCache[$planKey],
                        'client_id' => $client->id,
                        'content_type_id' => $specialty === 'video' ? $videoType->id : $desainType->id,
                        'content_format_id' => $specialty === 'video' ? $videoFormat->id : ($i % 2 === 0 ? $singlePostFormat->id : $carouselFormat->id),
                        'platform_id' => $specialty === 'video' && fake()->boolean(30) ? $tiktok->id : $instagram->id,
                        'title' => "{$client->name} - ".($specialty === 'video' ? 'Video' : 'Desain')." #{$totalItems}",
                        'deadline_at' => $deadline,
                        'import_source' => self::TAG,
                    ]);
                    $totalItems++;

                    ContentItemAssignment::create([
                        'content_item_id' => $item->id,
                        'user_id' => $worker->id,
                        'assignment_role' => 'primary',
                    ]);

                    if (fake()->boolean(20)) {
                        $secondPic = $workers->where('id', '!=', $worker->id)->random();
                        ContentItemAssignment::create([
                            'content_item_id' => $item->id,
                            'user_id' => $secondPic->id,
                            'assignment_role' => 'secondary',
                        ]);
                    }

                    if (fake()->boolean(50)) {
                        ContentBriefDraft::create([
                            'content_item_id' => $item->id,
                            'created_by' => $pool->random()->id,
                            'status' => 'finalized',
                        ]);
                    }

                    $timingRoll = fake()->numberBetween(1, 100);
                    $publishedAt = null;

                    if ($timingRoll <= 70) {
                        // Skenario 1: scheduled_upload_at vs publication.
                        $scheduled = $deadline->copy()->subHours(random_int(0, 12));
                        $item->update(['scheduled_upload_at' => $scheduled]);
                        $onTime = fake()->boolean(80);
                        $publishedAt = $onTime
                            ? $scheduled->copy()->addHours(random_int(0, 20))
                            : $scheduled->copy()->addDays(random_int(2, 5));
                    } elseif ($timingRoll <= 90) {
                        // Skenario 2: handoff in_progress -> waiting_review vs deadline.
                        $onTime = fake()->boolean(75);
                        $handoffAt = $onTime
                            ? $deadline->copy()->subHours(random_int(2, 30))
                            : $deadline->copy()->addHours(random_int(2, 30));
                        ContentStatusLog::create([
                            'content_item_id' => $item->id,
                            'changed_by_user_id' => $worker->id,
                            'from_status' => 'in_progress',
                            'to_status' => 'waiting_review',
                            'changed_at' => $handoffAt,
                        ]);
                        $publishedAt = $deadline->copy()->addHours(random_int(1, 40));
                    } else {
                        // Skenario 3: data waktu sengaja tidak lengkap.
                        $publishedAt = $deadline->copy()->addHours(random_int(1, 40));
                    }

                    ContentPublication::create([
                        'content_item_id' => $item->id,
                        'platform_id' => $item->platform_id,
                        'published_by' => $worker->id,
                        'published_at' => $publishedAt,
                    ]);

                    if (fake()->boolean(25)) {
                        ContentRevision::create([
                            'content_item_id' => $item->id,
                            'requested_by_user_id' => $pool->random()->id,
                            'revision_note' => 'Revisi internal (data demo).',
                            'status' => 'resolved',
                        ]);
                    } elseif (fake()->boolean(15)) {
                        ContentRevision::create([
                            'content_item_id' => $item->id,
                            'requested_by_client_id' => $client->id,
                            'revision_note' => 'Revisi dari klien (data demo).',
                            'status' => 'resolved',
                        ]);
                    }

                    if (fake()->boolean(60)) {
                        // Drift naik pelan tiap bulan biar tren chart kelihatan
                        // membaik - murni kosmetik buat demo, bukan aturan formula.
                        $driftFactor = 1 + ($monthIndex * 0.06);
                        $baseValue = $specialty === 'video'
                            ? random_int(3000, 12000)
                            : random_int(500, 3000);

                        ContentMetricSnapshot::create([
                            'client_id' => $client->id,
                            'platform_id' => $item->platform_id,
                            'content_item_id' => $item->id,
                            'snapshot_date' => $publishedAt->copy()->addDays(random_int(7, 9)),
                            'views' => (int) round($baseValue * $driftFactor),
                            'engagement_rate' => round(fake()->randomFloat(2, 1.5, 9.5) * $driftFactor, 2),
                        ]);
                    }
                }
            }
        }

        $calculator = app(TeamPerformanceKpiCalculator::class);
        foreach ($months as $monthStart) {
            $calculator->calculateForPeriod($monthStart);
        }

        $this->command?->info("KpiDemoDataSeeder: {$totalItems} ContentItem dibuat, KPI dihitung untuk ".self::MONTHS_BACK.' bulan terakhir.');
    }
}
