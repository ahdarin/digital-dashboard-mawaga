<?php

namespace Database\Seeders;

use App\Models\ApiIntegration;
use App\Models\AnalyticsSyncLog;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Notification;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Dummy data untuk modul Content Analytics, AI Advisor, Settings, dan
 * Notification (domain PIC 3) supaya semua halaman ada isinya pas dicoba
 * pakai akun CEO. Aman dijalankan berkali-kali (pakai firstOrCreate di
 * bagian master data), tapi content_items/metrics akan numpuk kalau
 * di-run berkali-kali - jalanin sekali aja cukup, atau php artisan
 * migrate:fresh --seed kalau mau reset total.
 *
 * Cara pakai, tambahkan ke DatabaseSeeder.php:
 *   $this->call([
 *       RoleSeeder::class,
 *       ClientUserDemoSeeder::class,
 *       ProductionWorkflowDemoSeeder::class,
 *       PermissionSeeder::class,
 *       AnalyticsDemoSeeder::class, // <-- tambahkan ini
 *   ]);
 *
 * Atau jalankan sendiri: php artisan db:seed --class=Database\\Seeders\\AnalyticsDemoSeeder
 */
class AnalyticsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $pillars = collect(['Edukasi', 'Storytelling', 'Branding', 'Hard Selling', 'Engagement'])
            ->map(fn ($name) => ContentPillar::firstOrCreate(['name' => $name]));

        $contentTypes = collect(['Design', 'Video', 'Copywriting', 'Carousel'])
            ->map(fn ($name) => ContentType::firstOrCreate(['name' => $name]));

        $platforms = collect(['Instagram', 'TikTok', 'Facebook', 'LinkedIn'])
            ->map(fn ($name) => Platform::firstOrCreate(['name' => $name]));

        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        $picUser = User::where('email', 'ahdaalamin2506@gmail.com')->first()
            ?? User::first();

        $clientNames = ['TechNova Inc.', 'Kopi Senja', 'Rumah Herbal', 'Bengkel Kreatif'];

        $clients = collect($clientNames)->map(function ($name) use ($category) {
            return Client::firstOrCreate(
                ['name' => $name],
                [
                    'client_category_id' => $category->id,
                    'brand_name' => str($name)->before(' ')->toString(),
                    'status' => 'active',
                ]
            );
        });

        $now = Carbon::now();

        foreach ($clients as $client) {
            $clientPackage = ClientPackage::firstOrCreate(
                ['client_id' => $client->id],
                [
                    'package_name_snapshot' => 'Paket Growth',
                    'monthly_content_quota' => 20,
                    'monthly_design_quota' => 20,
                    'start_date' => $now->copy()->subMonths(3)->startOfMonth(),
                    'status' => 'active',
                ]
            );

            // Content plan per bulan, 3 bulan terakhir
            $contentPlans = collect(range(2, 0))->map(function ($monthsAgo) use ($client, $clientPackage, $picUser) {
                $month = Carbon::now()->subMonths($monthsAgo);

                return ContentPlan::firstOrCreate(
                    ['client_id' => $client->id, 'month' => $month->month, 'year' => $month->year],
                    [
                        'client_package_id' => $clientPackage->id,
                        'created_by' => $picUser->id,
                        'status' => 'approved',
                    ]
                );
            });

            // 10 content item per client, tersebar 90 hari terakhir
            for ($i = 0; $i < 10; $i++) {
                $daysAgo = rand(0, 89);
                $deadline = $now->copy()->subDays($daysAgo);
                $plan = $contentPlans->firstWhere(function ($p) use ($deadline) {
                    return $p->month === $deadline->month && $p->year === $deadline->year;
                }) ?? $contentPlans->last();

                $isPosted = $daysAgo >= 3 ? (rand(1, 100) <= 85) : false; // konten lama kemungkinan besar udah posting
                $platform = $platforms->random();

                $item = ContentItem::create([
                    'content_plan_id' => $plan->id,
                    'client_id' => $client->id,
                    'content_pillar_id' => $pillars->random()->id,
                    'content_type_id' => $contentTypes->random()->id,
                    'platform_id' => $platform->id,
                    'title' => $client->brand_name.' - '.$contentTypes->random()->name.' #'.($i + 1),
                    'brief' => 'Dummy brief untuk seeding data analytics.',
                    'deadline_at' => $deadline,
                    'is_posted' => $isPosted,
                ]);

                $isOverdue = ! $isPosted && $deadline->isPast() && $daysAgo > 2;

                ContentWorkflow::create([
                    'content_item_id' => $item->id,
                    'current_pic_id' => $picUser->id,
                    'current_status' => $isPosted ? 'uploaded' : ($isOverdue ? 'revision' : 'in_progress'),
                    'is_overdue' => $isOverdue,
                ]);

                // Kalau sudah posting -> generate histori metrik harian
                if ($isPosted) {
                    $trackDays = min($daysAgo, rand(5, 21));
                    $baseViews = rand(400, 6000);

                    for ($d = 0; $d < $trackDays; $d++) {
                        ContentMetric::create([
                            'content_item_id' => $item->id,
                            'platform_id' => $platform->id,
                            'imported_by' => $picUser->id,
                            'metric_date' => $deadline->copy()->addDays($d),
                            'views' => max(0, $baseViews + rand(-200, 800) * $d),
                            'engagement_rate' => round(rand(150, 950) / 100, 2), // 1.50% - 9.50%
                        ]);
                    }
                }
            }

            // API integration per client (sebagian connected, sebagian belum)
            foreach ($platforms->take(2) as $platform) {
                ApiIntegration::firstOrCreate(
                    ['client_id' => $client->id, 'platform_id' => $platform->id],
                    [
                        'integration_name' => $platform->name.' Business API',
                        'status' => rand(0, 1) ? 'active' : 'inactive',
                    ]
                );
            }

            // Sync log per client
            foreach (range(1, 3) as $n) {
                $log = AnalyticsSyncLog::create([
                    'client_id' => $client->id,
                    'platform_id' => $platforms->random()->id,
                    'imported_by' => $picUser->id,
                    'source_type' => rand(0, 1) ? 'csv_import' : 'api_sync',
                    'status' => collect(['success', 'success', 'success', 'failed', 'pending'])->random(),
                ]);
                $log->forceFill(['created_at' => $now->copy()->subDays(rand(0, 14))])->save();
            }
        }

        // Notifications buat user CEO
        $notifTemplates = [
            ['title' => 'Trend Detected', 'type' => 'ai_insight', 'body' => "Konten '".($clients->first()->brand_name)."' tampil 45% di atas rata-rata minggu ini."],
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
                'is_read' => $i > 2, // 3 notif pertama unread
            ]);
            $notif->forceFill(['created_at' => $now->copy()->subMinutes($i * 45)])->save();
        }
    }
}
