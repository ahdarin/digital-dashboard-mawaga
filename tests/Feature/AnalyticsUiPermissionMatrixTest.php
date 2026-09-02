<?php

namespace Tests\Feature;

use App\Models\AiStrategyInsight;
use App\Models\AiStrategyMessage;
use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi Phase 4.4 (Langkah 3/4/5/6) - UI HARUS cocok dengan authorization
 * server di keempat kombinasi permission (view-only / analytics,manage-only /
 * settings,manage-only / both), plus server 403 tetap jadi defense-in-depth
 * (bukan cuma UI hiding). Test 1-7 dari spesifikasi.
 */
class AnalyticsUiPermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $permissions  list of [module, action] pairs
     */
    private function userWith(Client $client, array $permissions): User
    {
        $role = Role::create(['name' => 'Role Test '.uniqid()]);
        $ids = [];
        foreach ($permissions as [$module, $action]) {
            $ids[] = Permission::firstOrCreate(['module' => $module, 'action' => $action])->id;
        }
        $role->permissions()->attach($ids);
        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        $user->assignedClients()->attach($client->id);

        return $user;
    }

    private function analyticsViewOnly(Client $client): User
    {
        return $this->userWith($client, [['analytics', 'view']]);
    }

    private function analyticsManageOnly(Client $client): User
    {
        // Kombinasi realistis: analytics,view + analytics,manage, TANPA
        // settings,manage - route middleware Analytics page tetap
        // analytics,view (Phase 4.2), jadi "manage-only tanpa view" bukan
        // profil yang bisa buka halaman sama sekali (Manager/SMO asli
        // SELALU dapat kedua-duanya lewat PermissionSeeder).
        return $this->userWith($client, [['analytics', 'view'], ['analytics', 'manage']]);
    }

    private function settingsManageOnly(Client $client): User
    {
        return $this->userWith($client, [['analytics', 'view'], ['settings', 'manage']]);
    }

    private function bothPermissions(Client $client): User
    {
        return $this->userWith($client, [['analytics', 'view'], ['analytics', 'manage'], ['settings', 'manage']]);
    }

    /**
     * Fixture yang memicu SEMUA mutation control sekaligus dalam 1 page
     * load (kalau authorized): Generate Ulang (insight sudah ada), Apply
     * (belum applied_at, ada suggested_split), Refine (ada pesan diskusi
     * non-system), regenerate ide (belum applied_at, ada content_ideas).
     *
     * Phase 4.1 (v2) - period_start/period_end HARUS match EXACT context
     * default (bulan berjalan, platform_id=null/All Platforms) yang
     * dipakai route('analytics', ['client_id' => ...]) TANPA query string
     * analysis_month/platform_id di test-test ini (lihat
     * AnalyticsController::index()'s $latestAiInsight lookup context-exact)
     * - kalau tidak, insight ini dianggap context LAIN dan tidak akan
     * muncul sebagai $latestAiInsight.
     */
    private function insightWithAllMutationBranches(Client $client, User $generator): AiStrategyInsight
    {
        $insight = AiStrategyInsight::create([
            'client_id' => $client->id,
            'platform_id' => null,
            'generated_by' => $generator->id,
            'period_start' => now()->startOfMonth()->startOfDay(),
            'period_end' => now()->endOfDay(),
            'summary' => 'Ringkasan performa bulan lalu.',
            'action_items' => ['Tingkatkan frekuensi posting'],
            'suggested_split' => [['label' => 'Education', 'value' => 60], ['label' => 'Entertainment', 'value' => 40]],
            'top_pillars' => [['name' => 'Education', 'reasoning' => 'Engagement tertinggi']],
            'content_ideas' => [['pillar' => 'Education', 'title' => 'Tips Hemat', 'brief' => 'Konten edukasi', 'type' => 'Video', 'platform' => 'Instagram']],
            'data_completeness_percent' => 100,
            'status' => 'completed',
        ]);

        AiStrategyMessage::create([
            'ai_strategy_insight_id' => $insight->id,
            'user_id' => $generator->id,
            'role' => 'user',
            'message' => 'Coba fokus ke Reels dulu.',
        ]);

        return $insight;
    }

    private function tiktokIntegration(Client $client): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'TikTok'])->id,
            'integration_name' => 'TikTok API (OAuth)',
            'status' => 'active',
            'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    // ===== 1: analytics,view only =====

    public function test_view_only_user_sees_read_only_analytics_with_all_mutation_controls_hidden(): void
    {
        $client = $this->client();
        $viewer = $this->analyticsViewOnly($client);
        $generator = User::factory()->create(['status' => 'active']);
        $insight = $this->insightWithAllMutationBranches($client, $generator);
        $this->tiktokIntegration($client);

        $response = $this->actingAs($viewer)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        // Read-only tetap terlihat.
        $response->assertSee('Ringkasan performa bulan lalu.');
        $response->assertSee('Tingkatkan frekuensi posting');
        $response->assertSee('Coba fokus ke Reels dulu.'); // riwayat diskusi tetap kebaca

        // Mutation controls HARUS hilang total. Cek berdasarkan action URL
        // form (bukan cuma label teks tombol) - "Perbarui Analisis dari
        // Diskusi Ini" JUGA muncul di kalimat penjelasan biasa (bukan
        // tombol), jadi cek teks label saja rentan false positive.
        $response->assertDontSee('Generate Ulang');
        $response->assertDontSee('Generate Analisis');
        $response->assertDontSee('Terapkan Semua Ide Ini ke Content Plan');
        $response->assertDontSee(route('analytics.ai-strategy.refine', $insight), false);
        $response->assertDontSee('id="analytics-sync-button"', false);

        $html = $response->getContent();
        $this->assertStringNotContainsString('x-on:submit.prevent="send()"', $html, 'Input/send diskusi harus hilang buat view-only.');
        // Cek ATRIBUT tombol spesifik (bukan cuma nama fungsi JS -
        // regenerateIdea() sebagai DEFINISI method Alpine tetap ada di
        // <script> terlepas dari gating, itu bukan masalah keamanan;
        // yang harus hilang adalah TOMBOL yang memicunya).
        $this->assertStringNotContainsString('x-on:click="regenerateIdea()"', $html, 'Tombol regenerate ide harus hilang buat view-only.');
        $this->assertStringNotContainsString('Import Data Audiens (CSV)', $html, 'Form import CSV audiens harus hilang buat view-only.');
    }

    // ===== 2: analytics,manage -> AI mutation controls visible =====

    public function test_analytics_manage_user_sees_ai_mutation_controls(): void
    {
        $client = $this->client();
        $manager = $this->analyticsManageOnly($client);
        $insight = $this->insightWithAllMutationBranches($client, $manager);

        $response = $this->actingAs($manager)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('Generate Ulang');
        $response->assertSee('Terapkan Semua Ide Ini ke Content Plan');
        $response->assertSee(route('analytics.ai-strategy.refine', $insight), false);
    }

    // ===== 3: settings,manage only -> Sync visible, AI mutation hidden =====

    public function test_settings_manage_only_sees_sync_but_not_ai_mutation_controls(): void
    {
        $client = $this->client();
        $user = $this->settingsManageOnly($client);
        $insight = $this->insightWithAllMutationBranches($client, $user);

        $response = $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('id="analytics-sync-button"', false);
        $response->assertDontSee('Generate Ulang');
        $response->assertDontSee('Terapkan Semua Ide Ini ke Content Plan');
        $response->assertDontSee(route('analytics.ai-strategy.refine', $insight), false);
    }

    // ===== 4: analytics,manage only -> AI visible, Sync hidden =====

    public function test_analytics_manage_only_sees_ai_controls_but_not_sync(): void
    {
        $client = $this->client();
        $user = $this->analyticsManageOnly($client);
        $this->insightWithAllMutationBranches($client, $user);

        $response = $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('Generate Ulang');
        $response->assertDontSee('id="analytics-sync-button"', false);
    }

    // ===== 5: both permissions -> semua tampil =====

    public function test_both_permissions_sees_all_appropriate_controls(): void
    {
        $client = $this->client();
        $user = $this->bothPermissions($client);
        $insight = $this->insightWithAllMutationBranches($client, $user);

        $response = $this->actingAs($user)->get(route('analytics', ['client_id' => $client->id]));

        $response->assertOk();
        $response->assertSee('id="analytics-sync-button"', false);
        $response->assertSee('Generate Ulang');
        $response->assertSee('Terapkan Semua Ide Ini ke Content Plan');
        $response->assertSee(route('analytics.ai-strategy.refine', $insight), false);
    }

    // ===== 6: direct POST mutation tanpa analytics,manage -> 403 =====

    public function test_direct_post_ai_strategy_without_analytics_manage_is_forbidden(): void
    {
        $client = $this->client();
        $viewer = $this->analyticsViewOnly($client);

        $response = $this->actingAs($viewer)->post(route('analytics.ai-strategy'), ['client_id' => $client->id]);

        $response->assertForbidden();
    }

    public function test_direct_post_audience_import_without_analytics_manage_is_forbidden(): void
    {
        $client = $this->client();
        $viewer = $this->analyticsViewOnly($client);

        $response = $this->actingAs($viewer)->post(route('audience.import'), ['client_id' => $client->id]);

        $response->assertForbidden();
    }

    // ===== 7: direct POST sync tanpa settings,manage -> 403 =====

    public function test_direct_post_sync_without_settings_manage_is_forbidden(): void
    {
        $client = $this->client();
        $user = $this->analyticsManageOnly($client);

        $response = $this->actingAs($user)->postJson(route('analytics.sync'), ['client_id' => $client->id]);

        $response->assertForbidden();
    }
}
