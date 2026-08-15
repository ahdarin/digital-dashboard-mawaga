<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentMetric;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\DelayRiskScore;
use App\Models\Notification;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Data contoh khusus buat akun ahdaalamin2506@gmail.com - semua content
 * item di seeder ini di-assign PIC ke dia (bukan pool staff acak kayak
 * DemoSeeder), lengkap dengan satu contoh di TIAP status workflow +
 * revisi + publikasi + brief + delay risk, biar gampang lihat struktur
 * tampilan tabel (Produksi/List, Revisi, Sudah Tayang, Beranda, dst)
 * dengan data yang realistis dari sudut pandang akun ini.
 *
 * TIDAK IDEMPOTEN (sengaja, biar gampang lihat variasi tiap kali
 * dijalankan) - jalankan sekali aja per kebutuhan testing.
 *
 * Jalankan sendiri:
 *   php artisan db:seed --class=Database\\Seeders\\AhdaShowcaseSeeder
 */
class AhdaShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $ahda = User::where('email', 'ahdaalamin2506@gmail.com')->first();

        if (! $ahda) {
            $this->command->error('User ahdaalamin2506@gmail.com belum ada. Jalankan RoleSeeder dulu.');
            return;
        }

        $now = Carbon::now();

        // ===== Master data (reuse kalau sudah ada dari seeder lain) =====
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $pillar = ContentPillar::firstOrCreate(['name' => 'Product Highlight']);
        $videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $designType = ContentType::firstOrCreate(['name' => 'Desain']);
        $igPlatform = Platform::firstOrCreate(['name' => 'Instagram']);
        $tiktokPlatform = Platform::firstOrCreate(['name' => 'TikTok']);

        // Client khusus showcase - biar gampang dikenali & dihapus lagi
        // tanpa mengganggu data client lain.
        $client = Client::firstOrCreate(
            ['name' => 'Client Contoh (Showcase Ahda)'],
            [
                'client_category_id' => $category->id,
                'brand_name' => 'Contoh Brand',
                'status' => 'active',
            ]
        );

        $package = ClientPackage::firstOrCreate(
            ['client_id' => $client->id],
            [
                'package_name_snapshot' => 'Paket Showcase',
                'monthly_content_quota' => 12,
                'monthly_design_quota' => 8,
                'start_date' => $now->copy()->subMonths(2)->startOfMonth(),
                'status' => 'active',
            ]
        );

        // Content Plan bulan berjalan - status approved & disetujui oleh
        // Ahda sendiri, biar badge "Disetujui Sendiri" juga kelihatan.
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'client_package_id' => $package->id,
            'created_by' => $ahda->id,
            'approved_by' => $ahda->id,
            'month' => $now->month,
            'year' => $now->year,
            'status' => 'approved',
        ]);

        // Content Plan kedua - masih diajukan (belum disetujui), biar
        // siklus persetujuan rencana juga ada contohnya.
        ContentPlan::create([
            'client_id' => $client->id,
            'client_package_id' => $package->id,
            'created_by' => $ahda->id,
            'month' => $now->copy()->addMonthNoOverflow()->month,
            'year' => $now->copy()->addMonthNoOverflow()->year,
            'status' => 'pending',
        ]);

        /**
         * Bikin satu ContentItem lengkap dengan workflow + assignment ke
         * Ahda + status log, lalu balikin item-nya buat ditambahin
         * data spesifik status (revisi/publikasi/brief/dst) di luar.
         */
        $makeItem = function (string $title, string $status, array $overrides = []) use ($plan, $client, $pillar, $designType, $igPlatform, $ahda, $now) {
            $daysAgo = $overrides['days_ago'] ?? rand(1, 10);
            $deadline = $overrides['deadline'] ?? $now->copy()->addDays(rand(-3, 10));

            $item = ContentItem::create([
                'content_plan_id' => $plan->id,
                'client_id' => $client->id,
                'content_pillar_id' => $pillar->id,
                'content_type_id' => $overrides['content_type_id'] ?? $designType->id,
                'platform_id' => $overrides['platform_id'] ?? $igPlatform->id,
                'title' => $title,
                'brief' => $overrides['brief'] ?? 'Brief contoh buat showcase tampilan tabel - '.$title.'.',
                'deadline_at' => $deadline,
                'is_urgent' => $overrides['is_urgent'] ?? false,
                'is_posted' => $status === 'uploaded',
                'estimated_slide_count' => $overrides['estimated_slide_count'] ?? rand(1, 6),
            ]);

            $statusChangedAt = $now->copy()->subDays($daysAgo);

            $workflow = ContentWorkflow::create([
                'content_item_id' => $item->id,
                'current_pic_id' => $ahda->id,
                'current_status' => $status,
                'is_overdue' => $overrides['is_overdue'] ?? false,
                'client_reviewed_at' => $overrides['client_reviewed_at'] ?? null,
                'client_reviewed_by' => $overrides['client_reviewed_by'] ?? null,
                'client_review_result' => $overrides['client_review_result'] ?? null,
            ]);
            $workflow->forceFill(['created_at' => $statusChangedAt])->save();

            ContentItemAssignment::create([
                'content_item_id' => $item->id,
                'user_id' => $ahda->id,
                'assignment_role' => 'primary',
            ]);

            ContentStatusLog::create([
                'content_item_id' => $item->id,
                'changed_by' => $ahda->id,
                'from_status' => null,
                'to_status' => $status,
                'notes' => 'Dibuat oleh seeder showcase.',
                'changed_at' => $statusChangedAt,
            ])->forceFill(['created_at' => $statusChangedAt])->save();

            return $item;
        };

        // ===== 1. Brief Ready - brief masih draft =====
        $item1 = $makeItem('Showcase - Brief Belum Final', 'brief_ready', ['days_ago' => 1]);
        ContentBriefDraft::create([
            'content_item_id' => $item1->id,
            'created_by' => $ahda->id,
            'hook_title' => 'Hook menarik buat produk unggulan',
            'status' => 'draft',
            'complexity_level' => 'simple',
        ]);

        // ===== 2. Brief Ready - brief sedang didiskusikan =====
        $item2 = $makeItem('Showcase - Brief Didiskusikan', 'brief_ready', ['days_ago' => 2]);
        ContentBriefDraft::create([
            'content_item_id' => $item2->id,
            'created_by' => $ahda->id,
            'hook_title' => 'Diskusi konsep sebelum syuting',
            'status' => 'discussing',
            'complexity_level' => 'medium',
            'chat_history' => [
                ['role' => 'user', 'message' => 'Talent-nya sebaiknya berapa orang ya?'],
                ['role' => 'assistant', 'message' => 'Berdasarkan brief, cukup 1 talent utama biar fokus ke produk. Mau saya sesuaikan?'],
            ],
        ]);

        // ===== 3. In Progress - brief sudah final (diterapkan ke produksi) =====
        $item3 = $makeItem('Showcase - Sedang Dikerjakan', 'in_progress', [
            'days_ago' => 3,
            'content_type_id' => $videoType->id,
            'platform_id' => $tiktokPlatform->id,
        ]);
        ContentBriefDraft::create([
            'content_item_id' => $item3->id,
            'created_by' => $ahda->id,
            'hook_title' => 'Behind the scene produksi',
            'status' => 'finalized',
            'complexity_level' => 'complex',
            'talent' => '2 talent',
            'estimated_duration_seconds' => 45,
            'finalized_at' => $now->copy()->subDays(3),
        ]);

        // ===== 4. Waiting Review - klien belum merespons =====
        $makeItem('Showcase - Menunggu Persetujuan (Belum Direspons Klien)', 'waiting_review', ['days_ago' => 1]);

        // ===== 5. Waiting Review - klien sudah setuju, menunggu cek internal =====
        $makeItem('Showcase - Menunggu Persetujuan (Klien Sudah Setuju)', 'waiting_review', [
            'days_ago' => 2,
            'client_reviewed_at' => $now->copy()->subHours(6),
            'client_review_result' => 'approved',
        ]);

        // ===== 6. Revision - 2 ronde revisi, 1 resolved + 1 masih open =====
        $item6 = $makeItem('Showcase - Perlu Revisi', 'revision', ['days_ago' => 4]);
        ContentRevision::create([
            'content_item_id' => $item6->id,
            'requested_by' => $ahda->id,
            'revision_round' => 1,
            'revision_note' => 'Tolong perbaiki warna background sesuai brand guideline.',
            'status' => 'resolved',
        ]);
        ContentRevision::create([
            'content_item_id' => $item6->id,
            'requested_by' => $ahda->id,
            'revision_round' => 2,
            'revision_note' => 'Caption masih terlalu formal, tolong disesuaikan lagi ke tone yang lebih santai.',
            'status' => 'open',
        ]);

        // ===== 7. Approved - siap dijadwalkan =====
        $makeItem('Showcase - Sudah Disetujui', 'approved', ['days_ago' => 1]);

        // ===== 8. Scheduled - sudah ada rencana tanggal upload =====
        $item8 = $makeItem('Showcase - Terjadwal Tayang', 'scheduled', ['days_ago' => 1]);
        $item8->update(['scheduled_upload_at' => $now->copy()->addDays(2)]);

        // ===== 9. Uploaded - sudah tayang + metrik performa 5 hari =====
        $item9 = $makeItem('Showcase - Sudah Tayang', 'uploaded', [
            'days_ago' => 8,
            'deadline' => $now->copy()->subDays(8),
        ]);
        ContentPublication::create([
            'content_item_id' => $item9->id,
            'platform_id' => $igPlatform->id,
            'published_by' => $ahda->id,
            'published_at' => $now->copy()->subDays(7),
            'post_url' => 'https://instagram.com/p/showcase-ahda',
            'caption_final' => 'Caption final contoh buat showcase Publishing Tracker.',
        ]);
        for ($d = 0; $d < 5; $d++) {
            ContentMetric::create([
                'content_item_id' => $item9->id,
                'platform_id' => $igPlatform->id,
                'imported_by' => $ahda->id,
                'metric_date' => $now->copy()->subDays(7 - $d),
                'views' => rand(500, 4000) + $d * 150,
                'engagement_rate' => round(rand(150, 850) / 100, 2),
            ]);
        }

        // ===== 10. Cancelled =====
        $makeItem('Showcase - Dibatalkan', 'cancelled', ['days_ago' => 5]);

        // ===== 11. Jobdesk Tambahan (is_urgent) - masih in_progress, overdue =====
        $makeItem('Showcase - Jobdesk Tambahan (Overdue)', 'in_progress', [
            'days_ago' => 6,
            'is_urgent' => true,
            'is_overdue' => true,
            'deadline' => $now->copy()->subDays(2),
        ]);

        // ===== Delay Risk Score buat 2 item yang masih aktif =====
        DelayRiskScore::create([
            'content_item_id' => $item3->id,
            'risk_score' => 72,
            'risk_level' => 'high',
            'top_factor' => 'Kompleksitas produksi tinggi (video, 2 talent)',
        ]);
        DelayRiskScore::create([
            'content_item_id' => $item6->id,
            'risk_score' => 38,
            'risk_level' => 'medium',
            'top_factor' => 'Sudah 2 ronde revisi',
        ]);

        // ===== Notifikasi buat Ahda =====
        $notifTemplates = [
            ['title' => 'Task Ditugaskan', 'type' => 'task', 'body' => 'Kamu ditugaskan sebagai Penanggung Jawab "Showcase - Sedang Dikerjakan".'],
            ['title' => 'Klien Sudah Setuju - Perlu Dicek', 'type' => 'client_approved', 'body' => '"Showcase - Menunggu Persetujuan (Klien Sudah Setuju)" sudah disetujui klien, tunggu pengecekan internal.'],
            ['title' => 'Revisi Baru', 'type' => 'revision', 'body' => 'Caption masih terlalu formal, tolong disesuaikan lagi ke tone yang lebih santai.'],
            ['title' => 'Konten Berhasil Tayang', 'type' => 'publish', 'body' => '"Showcase - Sudah Tayang" berhasil dipublikasikan ke Instagram.'],
        ];
        foreach ($notifTemplates as $i => $tpl) {
            $notif = Notification::create([
                'user_id' => $ahda->id,
                'title' => $tpl['title'],
                'type' => $tpl['type'],
                'body' => $tpl['body'],
                'is_read' => $i > 1,
            ]);
            $notif->forceFill(['created_at' => $now->copy()->subMinutes($i * 20)])->save();
        }

        $this->command->info('Data showcase untuk ahdaalamin2506@gmail.com berhasil dibuat: 11 content item lintas semua status, revisi, publikasi, brief, delay risk, dan notifikasi.');
    }
}
