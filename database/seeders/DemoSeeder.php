<?php

namespace Database\Seeders;

use App\Models\AiStrategyInsight;
use App\Models\AiStrategyMessage;
use App\Models\AnalyticsSyncLog;
use App\Models\ApiIntegration;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentMetric;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Notification;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Models\UserClientAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * DemoSeeder v5 -- satu-satunya seeder demo data, gantikan v4 ke bawah.
 *
 * Update dari v4:
 * - Digabung dari ClientUserDemoSeeder.php + ProductionWorkflowDemoSeeder.php
 *   (dulu 3 file terpisah tapi saling tumpang tindih bikin client & data
 *   yang sama - misal ClientPackage/ContentPlan TechNova sempat dibikin
 *   duluan sama ProductionWorkflowDemoSeeder dengan payload beda, bikin
 *   firstOrCreate() di sini jadi no-op diam-diam). Sekarang 1 sumber
 *   kebenaran per client: login user (kalau ada) + paket + content
 *   plan/item + metrik + audience + sync log + (buat 2 client pertama)
 *   AI Strategy Insight, semua di loop yang sama.
 * - Jumlah client demo dikurangi dari 6 jadi 3 (TechNova Inc., Kopi Senja,
 *   FreshBite Indonesia) biar data seeder nggak kebanyakan.
 * - AiStrategyInsight sekarang diisi performance_data (dihitung beneran
 *   dari content_metrics yang digenerate di seeder ini, bukan kosong) -
 *   ini yang bikin fitur "Diskusi dengan AI" bisa langsung dicoba tanpa
 *   generate ulang dulu. content_ideas-nya juga udah include field
 *   "type"/"platform" dan jumlahnya ngikutin target_content_count
 *   (kuota content+design client), samain sama AiStrategyService beneran.
 * - Nambah AiStrategyMessage: contoh percakapan pendek (4 bubble chat)
 *   di insight yang statusnya "sudah diterapkan", biar UI chat-nya nggak
 *   kosong pas pertama kali dicoba.
 *
 * Nyakup semua fitur PIC 3 sampai sekarang:
 * - Content Plan (status bervariasi: approved/pending/draft)
 * - Content Item + Content Workflow + metrik video (watch_time_avg dkk)
 * - Audience Insights, Analytics Sync Log, API Integration, Notifications
 * - AI Strategy Insights + AI Strategy Messages (diskusi)
 * - Login user client portal (Client Owner) buat client yang didefinisiin
 *   punya 'login' di $clientDefs
 * - Board Production Workflow dengan status lengkap (brief_ready s/d
 *   approved) buat TechNova, biar ada 1 client yang bisa langsung dipakai
 *   testing kanban tanpa perlu klik-klik ubah status manual dulu
 *
 * Cara pakai, di DatabaseSeeder.php:
 *   $this->call([
 *       RoleSeeder::class,
 *       PermissionSeeder::class,
 *       DemoSeeder::class,
 *   ]);
 *
 * TIDAK IDEMPOTEN buat content_items/content_metrics/audience_insights
 * (sengaja, biar datanya variatif). Jalankan sekali abis migrate:fresh,
 * jangan diulang tanpa reset.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $lastMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonthNoOverflow()->endOfMonth();

        // ===== Master data =====
        $pillars = collect(['Edukasi', 'Storytelling', 'Branding', 'Hard Selling', 'Engagement'])
            ->map(fn ($name) => ContentPillar::firstOrCreate(['name' => $name]));

        $contentTypes = collect(['Design', 'Video', 'Copywriting', 'Carousel'])
            ->map(fn ($name) => ContentType::firstOrCreate(['name' => $name]));

        $platforms = collect(['Instagram', 'TikTok', 'Facebook', 'LinkedIn'])
            ->map(fn ($name) => Platform::firstOrCreate(['name' => $name]));

        $videoType = $contentTypes->firstWhere('name', 'Video');
        $videoPlatforms = $platforms->whereIn('name', ['Instagram', 'TikTok']);

        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        $picUser = User::where('email', 'ahdaalamin2506@gmail.com')->first() ?? User::first();
        $clientOwnerRole = Role::firstOrCreate(['name' => 'Client Owner']);

        // ===== Clients =====
        // 'login' opsional - kalau diisi, dibikinin 1 User (role Client
        // Owner) buat testing login client portal (magic link/approval
        // dashboard). Client pertama (index 0) otomatis jadi "full demo
        // client": kebagian board Production Workflow dengan status
        // lengkap di bawah, bukan cuma item random.
        //
        // 'package' divariasikan biar ngerepresentasiin jenis paket yang
        // beneran ada di agensi (bukan 20+20 rata semua kayak sebelumnya,
        // itu bikin AI Strategy Analysis nyaranin sampe 40 ide konten/bulan
        // - kejauhan dari paket riil yang kebanyakan 9-12 konten/bulan).
        $clientDefs = [
            [
                'name' => 'TechNova Inc.',
                'login' => ['name' => 'Client Demo', 'email' => 'client-demo@technova.test', 'phone' => '6281275471093'],
                'package' => ['name' => 'Paket Growth', 'content_quota' => 9, 'design_quota' => 9],
            ],
            [
                'name' => 'Kopi Senja',
                'login' => null,
                'package' => ['name' => 'Paket Konten', 'content_quota' => 12, 'design_quota' => 0],
            ],
            [
                'name' => 'FreshBite Indonesia',
                'login' => ['name' => 'Budi Santoso', 'email' => 'budi@freshbite.test', 'phone' => '6282288706114'],
                'package' => ['name' => 'Paket Starter', 'content_quota' => 6, 'design_quota' => 6],
            ],
        ];

        $clients = collect($clientDefs)->map(function ($def) use ($category, $clientOwnerRole) {
            $client = Client::firstOrCreate(
                ['name' => $def['name']],
                [
                    'client_category_id' => $category->id,
                    'brand_name' => str($def['name'])->before(' ')->toString(),
                    'status' => 'active',
                ]
            );

            if ($def['login']) {
                User::firstOrCreate(
                    ['phone_number' => $def['login']['phone']],
                    [
                        'role_id' => $clientOwnerRole->id,
                        'client_id' => $client->id,
                        'name' => $def['login']['name'],
                        'email' => $def['login']['email'],
                        'status' => 'active',
                    ]
                );
            }

            return $client;
        });

        foreach ($clients as $clientIndex => $client) {
            $packageDef = $clientDefs[$clientIndex]['package'];

            $clientPackage = ClientPackage::firstOrCreate(
                ['client_id' => $client->id],
                [
                    'package_name_snapshot' => $packageDef['name'],
                    'monthly_content_quota' => $packageDef['content_quota'],
                    'monthly_design_quota' => $packageDef['design_quota'],
                    'start_date' => $now->copy()->subMonths(3)->startOfMonth(),
                    'status' => 'active',
                ]
            );

            // ===== Content Plan: 3 bulan lalu approved, bulan ini status campur =====
            $planStatuses = ['approved', 'approved', collect(['pending', 'draft'])->random()];
            $contentPlans = collect(range(2, 0))->map(function ($monthsAgo, $i) use ($client, $clientPackage, $picUser, $planStatuses) {
                $month = Carbon::now()->subMonths($monthsAgo);

                return ContentPlan::firstOrCreate(
                    ['client_id' => $client->id, 'month' => $month->month, 'year' => $month->year],
                    [
                        'client_package_id' => $clientPackage->id,
                        'created_by' => $picUser->id,
                        'approved_by' => $planStatuses[$i] === 'approved' ? $picUser->id : null,
                        'status' => $planStatuses[$i],
                    ]
                );
            });

            $currentPlan = $contentPlans->last();

            // ===== Content Items =====
            $createdItems = collect();
            for ($i = 0; $i < 10; $i++) {
                $daysAgo = rand(0, 89);
                $deadline = $now->copy()->subDays($daysAgo);
                $plan = $contentPlans->first(fn ($p) => $p->month === $deadline->month && $p->year === $deadline->year)
                    ?? $contentPlans->last();

                $isPosted = $daysAgo >= 3 ? (rand(1, 100) <= 85) : false;
                $contentType = $contentTypes->random();
                if (rand(1, 100) <= 40) {
                    $contentType = $videoType;
                }
                $platform = $contentType->id === $videoType->id
                    ? $videoPlatforms->random()
                    : $platforms->random();

                $item = ContentItem::create([
                    'content_plan_id' => $plan->id,
                    'client_id' => $client->id,
                    'content_pillar_id' => $pillars->random()->id,
                    'content_type_id' => $contentType->id,
                    'platform_id' => $platform->id,
                    'title' => $client->brand_name.' - '.$contentType->name.' #'.($i + 1),
                    'brief' => 'Dummy brief untuk seeding data demo.',
                    'deadline_at' => $deadline,
                    'is_posted' => $isPosted,
                ]);

                $createdItems->push($item);

                $isOverdue = ! $isPosted && $deadline->isPast() && $daysAgo > 2;

                ContentWorkflow::create([
                    'content_item_id' => $item->id,
                    'current_pic_id' => $picUser->id,
                    'current_status' => $isPosted ? 'uploaded' : ($isOverdue ? 'revision' : 'in_progress'),
                    'is_overdue' => $isOverdue,
                ]);

                if ($isPosted) {
                    $trackDays = min($daysAgo, rand(5, 21));
                    $baseViews = rand(400, 6000);
                    $isVideoContent = $contentType->id === $videoType->id;

                    for ($d = 0; $d < $trackDays; $d++) {
                        ContentMetric::create([
                            'content_item_id' => $item->id,
                            'platform_id' => $platform->id,
                            'imported_by' => $picUser->id,
                            'metric_date' => $deadline->copy()->addDays($d),
                            'views' => max(0, $baseViews + rand(-200, 800) * $d),
                            'engagement_rate' => round(rand(150, 950) / 100, 2),
                            'watch_time_avg' => $isVideoContent ? rand(8, 45) : null,
                            'completion_rate' => $isVideoContent ? round(rand(3000, 8500) / 100, 2) : null,
                            'shares' => $isVideoContent ? rand(5, 300) : null,
                            'saves' => $isVideoContent ? rand(10, 500) : null,
                        ]);
                    }
                }
            }

            // ===== Board Production Workflow status lengkap (client pertama aja) =====
            // Biar ada 1 client yang board-nya kelihatan semua status
            // (brief_ready -> in_progress -> waiting_review -> approved)
            // buat testing, bukan random murni kayak $createdItems di atas.
            if ($clientIndex === 0) {
                $designType = $contentTypes->firstWhere('name', 'Design');
                $igPlatform = $platforms->firstWhere('name', 'Instagram');
                $boardStatuses = ['brief_ready', 'brief_ready', 'in_progress', 'waiting_review', 'approved'];

                foreach ($boardStatuses as $i => $status) {
                    $item = ContentItem::create([
                        'content_plan_id' => $currentPlan->id,
                        'client_id' => $client->id,
                        'content_type_id' => $designType->id,
                        'platform_id' => $igPlatform->id,
                        'title' => 'Demo Content Item #'.($i + 1),
                        'brief' => 'Contoh brief untuk testing board.',
                        'deadline_at' => $now->copy()->subDay()->addDays($i),
                    ]);

                    ContentWorkflow::create([
                        'content_item_id' => $item->id,
                        'current_pic_id' => $picUser->id,
                        'current_status' => $status,
                    ]);

                    ContentItemAssignment::create([
                        'content_item_id' => $item->id,
                        'user_id' => $picUser->id,
                        'assignment_role' => 'content_creator',
                    ]);

                    if ($i === 0) {
                        ContentItemAssignment::create([
                            'content_item_id' => $item->id,
                            'user_id' => $picUser->id,
                            'assignment_role' => 'designer',
                        ]);
                    }
                }

                UserClientAssignment::firstOrCreate([
                    'user_id' => $picUser->id,
                    'client_id' => $client->id,
                ]);
            }

            // ===== API Integration =====
            foreach ($platforms->take(2) as $platform) {
                ApiIntegration::firstOrCreate(
                    ['client_id' => $client->id, 'platform_id' => $platform->id],
                    [
                        'integration_name' => $platform->name.' Business API',
                        'status' => rand(0, 1) ? 'active' : 'inactive',
                    ]
                );
            }

            // ===== Audience Insight =====
            $genderSplit = ['male' => $mp = rand(30, 60), 'female' => 100 - $mp];
            $ageBrackets = ['13-17', '18-24', '25-34', '35-44', '45+'];
            $ageRaw = collect($ageBrackets)->map(fn () => rand(5, 30));
            $ageSum = $ageRaw->sum();
            $ageBreakdown = $ageRaw->mapWithKeys(fn ($v, $i) => [$ageBrackets[$i] => round($v / $ageSum * 100)])->all();

            $cityPool = collect(['Jakarta', 'Bandung', 'Surabaya', 'Yogyakarta', 'Medan', 'Makassar', 'Semarang'])->shuffle();
            $locRaw = $cityPool->take(5)->map(fn () => rand(10, 40));
            $locSum = $locRaw->sum();
            $topLocations = $cityPool->take(5)->values()->map(fn ($city, $i) => [
                'city' => $city,
                'percentage' => round($locRaw->values()[$i] / $locSum * 100),
            ])->all();

            $activeHours = collect(range(0, 23))->mapWithKeys(function ($hour) {
                $base = in_array($hour, range(18, 23)) ? rand(50, 100)
                    : (in_array($hour, range(9, 17)) ? rand(20, 60) : rand(0, 15));
                return [(string) $hour => $base];
            })->all();

            foreach ($platforms->take(2) as $platform) {
                $baseFollowers = rand(3000, 40000);

                for ($d = 89; $d >= 0; $d--) {
                    $date = $now->copy()->subDays($d);
                    $growthSoFar = (int) ((89 - $d) * rand(3, 25));

                    AudienceInsight::firstOrCreate(
                        ['client_id' => $client->id, 'platform_id' => $platform->id, 'snapshot_date' => $date->toDateString()],
                        [
                            'follower_count' => $baseFollowers + $growthSoFar,
                            'gender_breakdown' => $genderSplit,
                            'age_breakdown' => $ageBreakdown,
                            'top_locations' => $topLocations,
                            'active_hours' => $activeHours,
                        ]
                    );
                }
            }

            // ===== Sync Log =====
            foreach (range(1, 3) as $n) {
                $log = AnalyticsSyncLog::create([
                    'client_id' => $client->id,
                    'platform_id' => $platforms->random()->id,
                    'imported_by' => $picUser->id,
                    'source_type' => collect(['performance_csv_import', 'audience_csv_import', 'api_sync'])->random(),
                    'status' => collect(['success', 'success', 'success', 'failed', 'pending'])->random(),
                ]);
                $log->forceFill(['created_at' => $now->copy()->subDays(rand(0, 14))])->save();
            }

            // ===== AI Strategy Insight + performance_data + Messages (BARU) =====
            if ($clientIndex < 2) {
                $lastMonthMetrics = ContentMetric::whereHas('contentItem', fn ($q) => $q->where('client_id', $client->id))
                    ->with(['contentItem.contentPillar', 'contentItem.contentType', 'platform'])
                    ->whereBetween('metric_date', [$lastMonthStart, $lastMonthEnd])
                    ->get();

                $byPillar = $lastMonthMetrics->groupBy(fn ($m) => $m->contentItem->contentPillar->name ?? 'Tanpa Pilar')
                    ->map(fn ($rows) => [
                        'total_views' => (int) $rows->sum('views'),
                        'avg_engagement' => round($rows->avg('engagement_rate'), 2),
                        'content_count' => $rows->pluck('content_item_id')->unique()->count(),
                    ]);

                $byPlatform = $lastMonthMetrics->groupBy(fn ($m) => $m->platform->name ?? '-')
                    ->map(fn ($rows) => ['total_views' => (int) $rows->sum('views')]);

                $topContentFromMetrics = $lastMonthMetrics->groupBy('content_item_id')
                    ->map(fn ($rows) => [
                        'title' => $rows->first()->contentItem->title ?? '-',
                        'pillar' => $rows->first()->contentItem->contentPillar->name ?? '-',
                        'type' => $rows->first()->contentItem->contentType->name ?? '-',
                        'platform' => $rows->first()->platform->name ?? '-',
                        'views' => (int) $rows->sum('views'),
                        'engagement_rate' => round($rows->avg('engagement_rate'), 2),
                    ])
                    ->sortByDesc('views')->take(5)->values();

                // Ngikutin AiStrategyService::buildPerformanceSummary() beneran -
                // dipakai buat nentuin jumlah content_ideas di bawah, biar
                // insight seeder ini juga aman kalau langsung dicoba
                // "Terapkan ke Content Plan" tanpa generate ulang dulu.
                $targetItemCount = ($clientPackage->monthly_content_quota + $clientPackage->monthly_design_quota) ?: 10;

                $performanceData = [
                    'client_name' => $client->name,
                    'period' => $lastMonthStart->format('d M Y').' - '.$lastMonthEnd->format('d M Y'),
                    'total_views' => (int) $lastMonthMetrics->sum('views'),
                    'avg_engagement_rate' => $lastMonthMetrics->count() > 0 ? round($lastMonthMetrics->avg('engagement_rate'), 2) : 0,
                    'trend_vs_previous_period_percent' => rand(-20, 60),
                    'content_published_count' => $lastMonthMetrics->pluck('content_item_id')->unique()->count(),
                    'tracked_days' => $lastMonthMetrics->pluck('metric_date')->unique()->count(),
                    'period_days' => $lastMonthStart->daysInMonth,
                    'performance_by_pillar' => $byPillar,
                    'performance_by_platform' => $byPlatform,
                    'top_5_content' => $topContentFromMetrics,
                    'target_content_count' => $targetItemCount,
                ];

                $pillarNames = $byPillar->isNotEmpty() ? $byPillar->keys() : $pillars->pluck('name')->shuffle()->take(4);
                $splitValues = [40, 30, 20, 10];
                $suggestedSplit = $pillarNames->take(4)->values()->map(fn ($name, $i) => [
                    'label' => $name,
                    'value' => $splitValues[$i] ?? 10,
                ])->all();

                $topPillars = collect($suggestedSplit)->take(3)->map(fn ($row) => [
                    'name' => $row['label'],
                    'reasoning' => 'Pillar '.$row['label'].' mencatatkan performa terbaik selama '.$lastMonthStart->translatedFormat('F Y').' (data contoh seeder).',
                ])->all();

                // Platform buat ide dibatasin ke yang beneran ke-track buat
                // client ini (performance_by_platform) - fallback ke semua
                // platform kalau bulan lalu kosong data-nya sama sekali.
                $ideaPlatforms = $byPlatform->isNotEmpty()
                    ? $platforms->whereIn('name', $byPlatform->keys())->values()
                    : $platforms;
                if ($ideaPlatforms->isEmpty()) {
                    $ideaPlatforms = $platforms;
                }

                $splitSumForIdeas = collect($suggestedSplit)->sum('value') ?: 100;
                $contentIdeas = collect($suggestedSplit)->flatMap(function ($row) use ($targetItemCount, $splitSumForIdeas, $contentTypes, $ideaPlatforms) {
                    $count = max(1, (int) round(($row['value'] / $splitSumForIdeas) * $targetItemCount));
                    return collect(range(1, $count))->map(fn ($n) => [
                        'pillar' => $row['label'],
                        'title' => '[Contoh] Ide konten '.$row['label'].' #'.$n,
                        'brief' => 'Brief contoh dari seeder buat pillar '.$row['label'].' - generate ulang lewat tombol di halaman Analytics buat hasil AI beneran.',
                        'type' => $contentTypes->random()->name,
                        'platform' => $ideaPlatforms->random()->name,
                    ]);
                })->values()->all();

                $isApplied = $clientIndex === 0;

                $insight = AiStrategyInsight::create([
                    'client_id' => $client->id,
                    'generated_by' => $picUser->id,
                    'period_start' => $lastMonthStart,
                    'period_end' => $lastMonthEnd,
                    'performance_data' => $performanceData,
                    'summary' => '[Contoh Seeder] Performa '.$client->name.' selama '.$lastMonthStart->translatedFormat('F Y').' menunjukkan pillar '.$suggestedSplit[0]['label'].' sebagai yang paling efektif. Disarankan fokus produksi konten ke pillar tersebut bulan berikutnya. (Ini data contoh, generate ulang lewat tombol di halaman Analytics buat hasil AI yang beneran.)',
                    'action_items' => [
                        'Tingkatkan frekuensi konten pillar '.$suggestedSplit[0]['label'].' yang terbukti performanya paling tinggi.',
                        'Uji coba format Video/Reels di platform TikTok untuk menjangkau audience baru.',
                        'Lakukan review mingguan terhadap engagement rate untuk deteksi dini penurunan performa.',
                    ],
                    'suggested_split' => $suggestedSplit,
                    'top_pillars' => $topPillars,
                    'content_ideas' => $contentIdeas,
                    'data_completeness_percent' => rand(70, 98),
                    'status' => 'completed',
                    'applied_at' => $isApplied ? $now->copy()->subDays(2) : null,
                    'applied_by' => $isApplied ? $picUser->id : null,
                ]);

                if ($isApplied) {
                    $createdItems->where('content_plan_id', $currentPlan->id)
                        ->take(2)
                        ->each(fn ($item) => $item->update(['ai_strategy_insight_id' => $insight->id]));

                    $pillarLabel = $suggestedSplit[0]['label'];
                    $conversation = [
                        ['role' => 'user', 'message' => 'Kenapa pillar '.$pillarLabel.' disaranin paling banyak? apa datanya udah cukup buat disimpulin gitu?'],
                        ['role' => 'assistant', 'message' => 'Bagus pertanyaannya. Dari data yang saya punya, pillar '.$pillarLabel.' memang unggul di views/engagement dibanding pillar lain bulan itu, tapi jumlah konten yang tercatat masih terbatas, jadi ini indikasi awal, bukan kesimpulan final. Kalau ada konteks tambahan (misal ada campaign khusus bulan itu), kasih tau aja biar saya sesuaikan analisisnya.'],
                        ['role' => 'user', 'message' => 'Oh iya bulan itu kita emang lagi promo khusus, jadi views-nya agak boosted, bukan organik murni.'],
                        ['role' => 'assistant', 'message' => 'Noted, itu konteks penting. Kalau lonjakan views-nya didorong promo (bukan organik), rekomendasi fokus ke pillar ini perlu dilihat lebih hati-hati karena performanya belum tentu bertahan tanpa promo lanjutan. Klik "Perbarui Analisis dari Diskusi Ini" kalau mau saya sesuaikan rekomendasinya berdasarkan konteks ini.'],
                    ];

                    foreach ($conversation as $i => $msg) {
                        $m = AiStrategyMessage::create([
                            'ai_strategy_insight_id' => $insight->id,
                            'user_id' => $msg['role'] === 'user' ? $picUser->id : null,
                            'role' => $msg['role'],
                            'message' => $msg['message'],
                        ]);
                        $m->forceFill(['created_at' => $now->copy()->subDays(2)->addMinutes($i * 3)])->save();
                    }
                }
            }
        }

        // ===== Notifications =====
        $notifTemplates = [
            ['title' => 'Trend Detected', 'type' => 'ai_insight', 'body' => 'Konten '.$clients->first()->brand_name.' tampil 45% di atas rata-rata minggu ini.'],
            ['title' => 'Performa Turun', 'type' => 'ai_insight', 'body' => 'Konten Video di '.$clients->last()->brand_name.' turun 55% dari rata-rata 30 hari terakhir.'],
            ['title' => 'Task Assigned', 'type' => 'task', 'body' => 'Kamu ditugaskan untuk review Content Plan bulan ini.'],
            ['title' => 'Export Complete', 'type' => 'system', 'body' => 'Laporan analytics mingguan berhasil diexport.'],
            ['title' => 'David Chen', 'type' => 'mention', 'body' => 'Menyebut kamu di komentar revisi konten.'],
            ['title' => 'Sync Gagal', 'type' => 'system', 'body' => 'Sinkronisasi data performa Instagram gagal, cek Settings > Analytics Integration.'],
        ];

        foreach ($notifTemplates as $i => $tpl) {
            $notif = Notification::create([
                'user_id' => $picUser->id,
                'title' => $tpl['title'],
                'type' => $tpl['type'],
                'body' => $tpl['body'],
                'is_read' => $i > 3,
            ]);
            $notif->forceFill(['created_at' => $now->copy()->subMinutes($i * 45)])->save();
        }
    }
}
