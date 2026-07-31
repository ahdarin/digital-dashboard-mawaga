<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentWorkflow;
use App\Models\Notification;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Dummy data untuk Publishing Tracker, Revision Log, dan Notification
 * (domain PIC 2). Aman dijalankan berkali-kali; item baru akan ditambah
 * setiap kali dijalankan.
 *
 * Jalankan sendiri: php artisan db:seed --class=Database\\Seeders\\ProductionWorkflowExtendedSeeder
 */
class ProductionWorkflowExtendedSeeder extends Seeder
{
    public function run(): void
    {
        $picUser = User::where('email', 'ahdaalamin2506@gmail.com')->first() ?? User::first();
        $secondUser = User::whereNull('client_id')->where('id', '!=', $picUser->id)->first() ?? $picUser;

        $client = Client::first();
        $platform = Platform::first();

        if (!$client || !$platform) {
            $this->command->warn('Client atau Platform belum ada, jalankan seeder dasar dulu.');
            return;
        }

        $now = Carbon::now();

        // ==============================
        // 1. Content item khusus untuk testing publikasi (status uploaded)
        // ==============================
        $plan = \App\Models\ContentPlan::where('client_id', $client->id)->first();

        if ($plan) {
            for ($i = 0; $i < 3; $i++) {
                $item = ContentItem::create([
                    'content_plan_id' => $plan->id,
                    'client_id' => $client->id,
                    'platform_id' => $platform->id,
                    'title' => 'Demo Publikasi #' . ($i + 1),
                    'brief' => 'Konten dummy untuk testing Publishing Tracker.',
                    'deadline_at' => $now->copy()->subDays(rand(5, 20)),
                    'is_posted' => true,
                ]);

                ContentWorkflow::create([
                    'content_item_id' => $item->id,
                    'current_pic_id' => $picUser->id,
                    'current_status' => 'uploaded',
                    'is_overdue' => false,
                ]);

                ContentPublication::create([
                    'content_item_id' => $item->id,
                    'platform_id' => $platform->id,
                    'published_by' => $picUser->id,
                    'published_at' => $now->copy()->subDays(rand(1, 10)),
                    'post_url' => 'https://instagram.com/p/demo' . ($i + 1),
                    'caption_final' => 'Caption final untuk demo publikasi #' . ($i + 1),
                ]);
            }

            // ==============================
            // 2. Content item khusus untuk testing revisi (status revision, mixed open/resolved)
            // ==============================
            for ($i = 0; $i < 3; $i++) {
                $item = ContentItem::create([
                    'content_plan_id' => $plan->id,
                    'client_id' => $client->id,
                    'platform_id' => $platform->id,
                    'title' => 'Demo Revisi #' . ($i + 1),
                    'brief' => 'Konten dummy untuk testing Revision Log.',
                    'deadline_at' => $now->copy()->addDays(rand(1, 10)),
                ]);

                ContentWorkflow::create([
                    'content_item_id' => $item->id,
                    'current_pic_id' => $picUser->id,
                    'current_status' => 'revision',
                    'is_overdue' => false,
                ]);

                ContentRevision::create([
                    'content_item_id' => $item->id,
                    'requested_by' => $secondUser->id,
                    'revision_round' => 1,
                    'revision_note' => 'Tolong perbaiki warna background sesuai brand guideline.',
                    'status' => $i === 0 ? 'resolved' : 'open',
                ]);

                if ($i === 2) {
                    // Item ketiga sudah 2 ronde revisi
                    ContentRevision::create([
                        'content_item_id' => $item->id,
                        'requested_by' => $secondUser->id,
                        'revision_round' => 2,
                        'revision_note' => 'Caption masih terlalu formal, tolong disesuaikan lagi.',
                        'status' => 'open',
                    ]);
                }
            }
        }

        // ==============================
        // 3. Notifikasi dummy tipe PIC 2 (overdue, revision, approval, publish)
        // ==============================
        $notifTemplates = [
            ['title' => 'Demo Publikasi #1 sudah melewati deadline', 'type' => 'overdue', 'body' => 'Deadline sudah lewat 2 hari, segera tindak lanjuti.'],
            ['title' => 'Revisi baru untuk "Demo Revisi #2"', 'type' => 'revision', 'body' => 'Tolong perbaiki warna background sesuai brand guideline.'],
            ['title' => 'Klien meminta revisi untuk "Demo Revisi #3"', 'type' => 'revision', 'body' => 'Caption masih terlalu formal, tolong disesuaikan lagi.'],
            ['title' => '"Demo Publikasi #2" telah disetujui klien', 'type' => 'approval', 'body' => null],
            ['title' => '"Demo Publikasi #3" berhasil dipublikasikan', 'type' => 'publish', 'body' => null],
        ];

        foreach ($notifTemplates as $i => $tpl) {
            $notif = Notification::create([
                'user_id' => $picUser->id,
                'title' => $tpl['title'],
                'type' => $tpl['type'],
                'body' => $tpl['body'],
                'is_read' => $i > 2,
            ]);
            $notif->forceFill(['created_at' => $now->copy()->subMinutes($i * 30)])->save();
        }

        $this->command->info('Dummy data Publishing Tracker, Revision Log, dan Notification (PIC 2) berhasil dibuat.');
    }
}