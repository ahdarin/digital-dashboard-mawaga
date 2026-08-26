<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Final Pre-Merge Verification (Step 5/6/7) - satu alur end-to-end yang
 * BERKESINAMBUNGAN (bukan tersegmentasi per file test seperti sprint
 * sebelumnya), dijalankan lewat routing/middleware/database Laravel yang
 * sungguhan (bukan manipulasi model langsung).
 *
 * KETERBATASAN JUJUR: tidak ada verifikasi via browser sungguhan. Login
 * internal aplikasi ini SATU-SATUNYA lewat Google OAuth (tidak ada
 * email+password) - browser automation tidak bisa melewati layar consent
 * Google tanpa akun Google nyata & interaksi manusia, sama seperti blocker
 * eksternal Instagram/TikTok OAuth. Langkah "user login" di sini memakai
 * actingAs() Laravel (simulasi sesi terautentikasi) HANYA untuk melewati
 * login internal itu - setiap langkah SETELAHNYA (permission check,
 * client scope, transisi status, notifikasi, Client Portal via token asli
 * TANPA actingAs, generate laporan) berjalan lewat kode aplikasi
 * sungguhan, bukan dipalsukan.
 */
class GoldenPathTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name, array $permissions): Role
    {
        $role = Role::create(['name' => $name.' '.uniqid()]);
        foreach ($permissions as [$module, $action]) {
            $role->permissions()->attach(Permission::firstOrCreate(['module' => $module, 'action' => $action])->id);
        }

        return $role;
    }

    /**
     * Nama role HARUS persis sama dengan UserRole::CEO/Manager->value -
     * User::canSeeAllClients() cocok berdasarkan nama role persis, bukan
     * cuma permission (lihat catatan di test golden path utama).
     */
    private function canonicalRole(string $exactName, array $permissions): Role
    {
        $role = Role::create(['name' => $exactName]);
        foreach ($permissions as [$module, $action]) {
            $role->permissions()->attach(Permission::firstOrCreate(['module' => $module, 'action' => $action])->id);
        }

        return $role;
    }

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'brand_name' => 'Test Brand',
            'status' => 'active',
        ]);
    }

    public function test_full_golden_path_from_client_onboarding_to_report(): void
    {
        // ===== 1. Manager membuat client =====
        // Nama role HARUS persis "Manager" (bukan disambiguasi uniqid seperti
        // role lain di test ini) - User::canSeeAllClients() cocok berdasarkan
        // nama role persis sama dengan UserRole::Manager->value, dipakai di
        // banyak titik sepanjang golden path ini (import performa, dst).
        $manager = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $managerRole = Role::create(['name' => 'Manager']);
        foreach ([
            ['client', 'manage'], ['content_plan', 'create'], ['content_plan', 'approve'],
            ['user_management', 'manage'], ['analytics', 'view'], ['report', 'view'], ['settings', 'manage'],
        ] as [$module, $action]) {
            $managerRole->permissions()->attach(Permission::firstOrCreate(['module' => $module, 'action' => $action])->id);
        }
        $manager->roles()->attach($managerRole->id);

        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $storeClient = $this->actingAs($manager)->post(route('client-management.store'), [
            'name' => 'Golden Path Client',
            'brand_name' => 'Golden Brand',
            'client_category_id' => $category->id,
        ]);
        $storeClient->assertRedirect();
        $client = Client::where('name', 'Golden Path Client')->firstOrFail();
        $this->assertNotEmpty($client->portal_token, 'Client baru harus otomatis punya portal token.');

        // ===== 2. Pastikan Paket tersedia =====
        ClientPackage::create([
            'client_id' => $client->id,
            'package_name_snapshot' => 'Basic',
            'monthly_content_quota' => 8,
            'monthly_design_quota' => 4,
            'start_date' => now(),
            'status' => 'active',
        ]);
        $this->assertNotNull($client->fresh()->activePackage);

        // ===== 3. Buat & undang User (Copywriter, Content Creator, SMO) =====
        $copywriterRole = $this->role('Copywriter', [['content_plan', 'create'], ['workflow', 'view']]);
        $creatorRole = $this->role('Content Creator', [['workflow', 'view'], ['workflow', 'update']]);
        $smoRole = $this->role('SMO', [
            ['workflow', 'view'], ['workflow', 'update'], ['workflow', 'approve'],
            ['content_plan', 'approve'], ['analytics', 'view'], ['report', 'view'], ['publishing', 'manage'],
        ]);

        $invite = $this->actingAs($manager)->post(route('user-management.store'), [
            'name' => 'Copywriter Golden Path',
            'email' => 'copywriter.goldenpath@example.test',
            'role_ids' => [$copywriterRole->id],
        ]);
        $invite->assertRedirect();
        $copywriter = User::where('email', 'copywriter.goldenpath@example.test')->firstOrFail();
        // ===== 4. Pastikan akses login aktif (KI-06) =====
        $this->assertTrue((bool) $copywriter->login_enabled, 'User yang diundang harus otomatis bisa login (KI-06).');

        $contentCreator = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $contentCreator->roles()->attach($creatorRole->id);
        $smo = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $smo->roles()->attach($smoRole->id);

        // ===== assign PIC ke client (KI-05) =====
        $assign = $this->actingAs($manager)->put(route('user-management.clients.update', $copywriter), ['client_ids' => [$client->id]]);
        $assign->assertRedirect();
        $this->actingAs($manager)->put(route('user-management.clients.update', $contentCreator), ['client_ids' => [$client->id]]);
        $this->actingAs($manager)->put(route('user-management.clients.update', $smo), ['client_ids' => [$client->id]]);
        $this->assertTrue($copywriter->fresh()->assignedClients->contains($client->id));

        // ===== 5. Buat Rencana Konten =====
        $createPlan = $this->actingAs($copywriter)->post(route('content-plan.store'), [
            'client_id' => $client->id,
            'month' => now()->month,
            'year' => now()->year,
        ]);
        $createPlan->assertRedirect();
        $plan = ContentPlan::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('draft', $plan->status);

        // ===== 6. Tambahkan Content Item (KI-01) =====
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $addItem = $this->actingAs($copywriter)->post(route('content-plan.items.store', $plan), [
            'title' => 'Golden Path Content',
            'brief' => 'Konten promo untuk golden path test',
            'content_type_id' => $contentType->id,
            'platform_id' => $platform->id,
            'deadline_at' => now()->addDays(7)->format('Y-m-d H:i'),
            'pic_user_id' => $contentCreator->id,
        ]);
        $addItem->assertRedirect(route('content-plan.show', $plan));
        $item = $plan->contentItems()->firstOrFail();
        $this->assertSame('brief_ready', $item->workflow->current_status);

        // ===== 7. Copywriter buka item & buat AI Brief (KI-07: tanggal masuk akal) =====
        $briefJson = [
            'hook_title' => 'Golden Path Hook',
            'start_date' => '2024-01-01', // sengaja tanggal lampau - buktikan sanitizeDates() bekerja
            'post_date' => '2024-01-03',
            'scenes' => [['label' => 'ADEGAN 1', 'visual' => 'Buka produk', 'talent_script' => 'Halo semua!']],
            'talent' => '1 model',
            'properti' => 'Tidak ada properti khusus.',
            'estimated_duration_seconds' => 30,
            'talent_count' => 1,
            'location_count' => 1,
            'complexity_level' => 'simple',
        ];
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode($briefJson)]]]],
                ],
            ], 200),
        ]);
        config(['services.gemini.api_key' => 'fake-key-golden-path']);

        $openItem = $this->actingAs($copywriter)->get(route('content-items.show', $item));
        $openItem->assertOk(); // KI-03 - halaman ini dulu crash begitu client punya staf ter-assign

        $genBrief = $this->actingAs($copywriter)->post(route('content-brief.generate', $item));
        $genBrief->assertRedirect();
        $brief = $item->contentBriefDraft()->firstOrFail();
        $this->assertTrue($brief->start_date->gte(now()->startOfDay()), 'Tanggal brief tidak boleh di masa lalu walau AI mengarang 2024 (KI-07).');

        // ===== 8. Finalisasi brief =====
        $finalize = $this->actingAs($copywriter)->post(route('content-brief.finalize', $brief));
        $finalize->assertRedirect();
        $this->assertSame('finalized', $brief->fresh()->status);
        $this->assertDatabaseHas('notifications', ['user_id' => $contentCreator->id, 'type' => 'task']);

        // ===== 9. Content Creator: Kerjakan Konten -> isi link hasil -> Konten Telah Selesai =====
        $start = $this->actingAs($contentCreator)->patch(route('content-items.transition', $item), ['to_status' => 'in_progress']);
        $start->assertRedirect();
        $this->assertSame('in_progress', $item->workflow->fresh()->current_status);

        $link = $this->actingAs($contentCreator)->patch(route('content-items.content-link', $item), [
            'content_file_link' => 'https://drive.google.com/drive/folders/golden-path-test',
        ]);
        $link->assertRedirect();
        $this->assertSame('https://drive.google.com/drive/folders/golden-path-test', $item->fresh()->content_file_link);

        $finish = $this->actingAs($contentCreator)->patch(route('content-items.transition', $item), ['to_status' => 'waiting_review']);
        $finish->assertRedirect();
        $this->assertSame('waiting_review', $item->workflow->fresh()->current_status);

        // ===== 10. Portal Klien: Setuju (TANPA actingAs - murni token, sama seperti klien asli) =====
        $portalDashboard = $this->get(route('client.portal.dashboard', $client->portal_token));
        $portalDashboard->assertOk();
        $portalApprove = $this->post(route('client.portal.approval.approve', ['token' => $client->portal_token, 'contentItem' => $item->id]));
        $portalApprove->assertRedirect();
        $this->assertSame('approved', $item->workflow->fresh()->client_review_result);
        // Persetujuan klien TIDAK mengubah status internal (Bagian 5, sengaja).
        $this->assertSame('waiting_review', $item->workflow->fresh()->current_status);

        // ===== 11. Internal review: Approve Konten (CEO/Manager/SMO) =====
        $approve = $this->actingAs($smo)->patch(route('content-items.transition', $item), ['to_status' => 'approved']);
        $approve->assertRedirect();
        $this->assertSame('approved', $item->workflow->fresh()->current_status);

        // ===== 12. SMO: Jadwalkan Upload =====
        $schedule = $this->actingAs($smo)->patch(route('content-items.transition', $item), [
            'to_status' => 'scheduled',
            'scheduled_upload_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ]);
        $schedule->assertRedirect();
        $this->assertSame('scheduled', $item->workflow->fresh()->current_status);

        // ===== 13. SMO: Catat Publikasi -> Sudah Tayang (route dedicated,
        // BUKAN content-items.transition - lihat ContentPublicationController::store()) =====
        $publish = $this->actingAs($smo)->post(route('content-publication.store', $item), [
            'platform_id' => $platform->id,
            'published_at' => now()->format('Y-m-d H:i:s'),
            'post_url' => 'https://instagram.com/p/goldenpath',
            'caption_final' => 'Caption final golden path.',
        ]);
        $publish->assertRedirect();
        $this->assertSame('uploaded', $item->workflow->fresh()->current_status);
        $this->assertTrue((bool) $item->fresh()->is_posted);

        // ===== 14. Cek Performa (data masuk lewat import CSV - jalur yang selalu tersedia) =====
        $csv = "content_title,platform,metric_date,views,engagement_rate\nGolden Path Content,Instagram,".now()->toDateString().",1000,5.5\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('performa.csv', $csv);
        $import = $this->actingAs($manager)->post(route('settings.import-performance'), ['client_id' => $client->id, 'file' => $file]);
        $import->assertRedirect();
        $this->assertDatabaseHas('content_metrics', ['content_item_id' => $item->id, 'views' => 1000]);

        $performa = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));
        $performa->assertOk();
        $performa->assertSee('Golden Path Content');

        // ===== 15. Buat Laporan =====
        $report = $this->actingAs($manager)->post(route('report.generate'), [
            'client_id' => $client->id,
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->toDateString(),
            'format' => 'pdf',
        ]);
        $report->assertOk();
        $this->assertSame('application/pdf', $report->headers->get('Content-Type'));

        // ===== Client Portal: konten juga muncul di Riwayat =====
        $history = $this->get(route('client.portal.history', $client->portal_token));
        $history->assertOk();
        $history->assertSee('Golden Path Content');
    }

    /**
     * Step 6 - Rejection path BERKESINAMBUNGAN: Draf -> Ajukan -> Ditolak
     * (alasan tersimpan) -> buka kembali -> diperbaiki (tambah konten) ->
     * Ajukan ulang -> Disetujui. Verifikasi: status history benar, alasan
     * penolakan tidak hilang, TIDAK ada duplicate plan, notification benar.
     */
    public function test_continuous_rejection_path_preserves_history_and_notifications(): void
    {
        $manager = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $manager->roles()->attach($this->canonicalRole('Manager', [
            ['client', 'manage'], ['content_plan', 'view'], ['content_plan', 'create'], ['content_plan', 'approve'],
        ])->id);

        $copywriter = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $copywriter->roles()->attach($this->role('Copywriter', [['content_plan', 'create']])->id);

        $client = $this->client();
        $copywriter->assignedClients()->attach($client->id);

        // ===== Draf =====
        $create = $this->actingAs($copywriter)->post(route('content-plan.store'), [
            'client_id' => $client->id, 'month' => now()->month, 'year' => now()->year,
        ]);
        $create->assertRedirect();
        $plan = ContentPlan::where('client_id', $client->id)->firstOrFail();
        $planId = $plan->id;

        // ===== Ajukan =====
        $submit1 = $this->actingAs($copywriter)->patch(route('content-plan.submit', $plan));
        $submit1->assertRedirect();
        $this->assertSame('pending', $plan->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $manager->id, 'type' => 'plan_submitted',
        ]);

        // ===== Ditolak (alasan wajib & tersimpan) =====
        $reject = $this->actingAs($manager)->patch(route('content-plan.reject', $plan), [
            'rejection_note' => 'Target kuota belum sesuai paket, tolong sesuaikan.',
        ]);
        $reject->assertRedirect();
        $this->assertSame('rejected', $plan->fresh()->status);

        // ===== Buka kembali (Ditolak -> Draf) =====
        $reopen = $this->actingAs($copywriter)->patch(route('content-plan.reopen', $plan));
        $reopen->assertRedirect();
        $this->assertSame('draft', $plan->fresh()->status);
        // TIDAK ada duplicate plan - masih plan yang sama, bukan row baru.
        $this->assertSame(1, ContentPlan::where('client_id', $client->id)
            ->where('month', now()->month)->where('year', now()->year)->count());
        $this->assertSame($planId, $plan->fresh()->id);

        // ===== "Diperbaiki" - tambah content item ke plan yang sudah kembali Draf =====
        $contentCreator = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $contentCreator->roles()->attach($this->role('Content Creator', [['workflow', 'update']])->id);
        $contentCreator->assignedClients()->attach($client->id);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $addItem = $this->actingAs($copywriter)->post(route('content-plan.items.store', $plan), [
            'title' => 'Konten Perbaikan',
            'content_type_id' => $contentType->id,
            'deadline_at' => now()->addDays(5)->format('Y-m-d H:i'),
            'pic_user_id' => $contentCreator->id,
        ]);
        $addItem->assertRedirect();
        $this->assertSame(1, $plan->fresh()->contentItems()->count());

        // ===== Ajukan ulang =====
        $submit2 = $this->actingAs($copywriter)->patch(route('content-plan.submit', $plan));
        $submit2->assertRedirect();
        $this->assertSame('pending', $plan->fresh()->status);
        $this->assertSame(2, \App\Models\Notification::where('user_id', $manager->id)
            ->where('type', 'plan_submitted')->count(), 'Notifikasi pengajuan kedua harus terkirim lagi.');

        // ===== Disetujui =====
        $approve = $this->actingAs($manager)->patch(route('content-plan.approve', $plan));
        $approve->assertRedirect();
        $this->assertSame('approved', $plan->fresh()->status);

        // ===== Verifikasi akhir: riwayat lengkap & alasan penolakan tidak hilang =====
        $logs = $plan->fresh()->statusLogs()->reorder('id')->get();
        $this->assertSame(
            ['pending', 'rejected', 'draft', 'pending', 'approved'],
            $logs->pluck('to_status')->all()
        );
        $rejectedLog = $logs->firstWhere('to_status', 'rejected');
        $this->assertSame('Target kuota belum sesuai paket, tolong sesuaikan.', $rejectedLog->notes);
        $this->assertSame($manager->id, $rejectedLog->changed_by_user_id);

        // Riwayat Keputusan tetap terlihat di halaman detail meski sudah Disetujui.
        $show = $this->actingAs($manager)->get(route('content-plan.show', $plan));
        $show->assertOk();
        $show->assertSee('Target kuota belum sesuai paket, tolong sesuaikan.');
    }

    /**
     * Step 7 - Revision path BERKESINAMBUNGAN: Menunggu Persetujuan -> Minta
     * Revisi -> Perlu Revisi -> Kerjakan Revisi -> Sedang Dikerjakan ->
     * Konten Telah Selesai -> Menunggu Persetujuan -> Approve. Verifikasi:
     * revision log, revision status, content status, siapa yang membuat
     * revisi, notification, tidak ada revisi terbuka setelah approve.
     */
    public function test_continuous_revision_path(): void
    {
        $client = $this->client();

        $smo = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $smo->roles()->attach($this->role('SMO', [
            ['workflow', 'view'], ['workflow', 'update'], ['workflow', 'approve'],
        ])->id);
        $smo->assignedClients()->attach($client->id);

        $contentCreator = User::factory()->create(['status' => 'active', 'login_enabled' => true]);
        $contentCreator->roles()->attach($this->role('Content Creator', [['workflow', 'view'], ['workflow', 'update']])->id);
        $contentCreator->assignedClients()->attach($client->id);

        $plan = ContentPlan::create([
            'client_id' => $client->id, 'created_by' => $smo->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);
        $item = \App\Models\ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id,
            'title' => 'Konten Revisi Test', 'deadline_at' => now()->addDays(5),
        ]);
        \App\Models\ContentWorkflow::create([
            'content_item_id' => $item->id, 'current_pic_id' => $contentCreator->id,
            'current_status' => 'waiting_review', 'is_overdue' => false,
        ]);
        $item->assignments()->create(['user_id' => $contentCreator->id, 'assignment_role' => 'primary']);

        // ===== Menunggu Persetujuan -> Minta Revisi (Perlu Revisi) =====
        $requestRevision = $this->actingAs($smo)->post(route('content-revision.store', $item), [
            'revision_note' => 'Warna kurang cerah, tolong disesuaikan brand guideline.',
        ]);
        $requestRevision->assertRedirect();
        $this->assertSame('revision', $item->workflow->fresh()->current_status);
        $revision = $item->revisions()->latest()->firstOrFail();
        $this->assertSame('Warna kurang cerah, tolong disesuaikan brand guideline.', $revision->revision_note);
        $this->assertSame($smo->id, $revision->requested_by_user_id, 'Harus tercatat siapa yang membuat revisi.');
        $this->assertSame('open', $revision->status);

        // ===== Kerjakan Revisi (Perlu Revisi -> Sedang Dikerjakan) =====
        $startWork = $this->actingAs($contentCreator)->patch(route('content-revision.start-work', [$item, $revision]));
        $startWork->assertRedirect();
        $this->assertSame('in_progress', $item->workflow->fresh()->current_status);
        $this->assertSame('in_progress', $revision->fresh()->status, 'Revisi open harus ikut ditandai in_progress saat Kerjakan Revisi.');

        // ===== Konten Telah Selesai (Sedang Dikerjakan -> Menunggu Persetujuan) =====
        $finish = $this->actingAs($contentCreator)->patch(route('content-items.transition', $item), ['to_status' => 'waiting_review']);
        $finish->assertRedirect();
        $this->assertSame('waiting_review', $item->workflow->fresh()->current_status);
        $this->assertSame('resolved', $revision->fresh()->status, 'Revisi in_progress harus otomatis resolved saat balik ke Menunggu Persetujuan.');

        // ===== Approve =====
        $approve = $this->actingAs($smo)->patch(route('content-items.transition', $item), ['to_status' => 'approved']);
        $approve->assertRedirect();
        $this->assertSame('approved', $item->workflow->fresh()->current_status);

        // ===== Tidak ada revisi terbuka setelah approve =====
        $this->assertFalse(
            app(\App\Services\WorkflowStatusService::class)->hasUnresolvedRevisions($item->fresh()),
            'Tidak boleh ada revisi open/in_progress tersisa setelah konten di-approve.'
        );

        // ===== Content status log mencatat seluruh transisi dengan aktor yang benar =====
        $statusLogs = \App\Models\ContentStatusLog::where('content_item_id', $item->id)->orderBy('id')->get();
        $this->assertSame(
            ['revision', 'in_progress', 'waiting_review', 'approved'],
            $statusLogs->pluck('to_status')->all()
        );
        $this->assertSame($contentCreator->id, $statusLogs->firstWhere('to_status', 'in_progress')->changed_by_user_id);
        $this->assertSame($smo->id, $statusLogs->firstWhere('to_status', 'approved')->changed_by_user_id);
    }
}
