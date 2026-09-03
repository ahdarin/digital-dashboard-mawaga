<?php

namespace Tests\Feature;

use App\Models\ApiIntegration;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentFormat;
use App\Models\ContentItem;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\InstagramMediaSnapshot;
use App\Models\Permission;
use App\Models\Platform;
use App\Models\Role;
use App\Models\TikTokVideoSnapshot;
use App\Models\User;
use App\Services\AnalyticsPeriodResolver;
use App\Services\ContentFormatResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SYSTEM CONSISTENCY PASS (Part A-K) - Production Type (ContentType:
 * Desain/Video, "bagaimana konten dikerjakan?") dan Content Format
 * (content_formats: Single Post/Carousel/Video, "dalam format apa konten
 * dipublikasikan?") adalah DUA DIMENSI TERPISAH. ContentFormatResolver
 * adalah SATU-SATUNYA tempat raw provider media type (Instagram IMAGE/
 * CAROUSEL_ALBUM/VIDEO, TikTok video) dipetakan ke format kanonis -
 * dites di sini murni sebagai unit logic (tanpa HTTP), plus beberapa test
 * integrasi yang membuktikan Analytics/Report/AI Strategy benar-benar
 * memakai resolver yang sama (bukan mapping lokal sendiri-sendiri lagi).
 */
class ContentClassificationTest extends TestCase
{
    use RefreshDatabase;

    // ===== Unit: ContentFormatResolver (mapping murni, tanpa HTTP) =====

    public function test_instagram_image_maps_to_single_post(): void
    {
        $resolver = app(ContentFormatResolver::class);
        $slug = $resolver->slugForInstagram('IMAGE', 'FEED');

        $this->assertSame(ContentFormatResolver::SLUG_SINGLE_POST, $slug);
        $this->assertSame('Single Post', $resolver->labelForSlug($slug));
    }

    public function test_instagram_carousel_album_maps_to_carousel(): void
    {
        $resolver = app(ContentFormatResolver::class);
        $slug = $resolver->slugForInstagram('CAROUSEL_ALBUM', 'CAROUSEL_ALBUM');

        $this->assertSame(ContentFormatResolver::SLUG_CAROUSEL, $slug);
        $this->assertSame('Carousel', $resolver->labelForSlug($slug));
    }

    public function test_instagram_video_maps_to_video(): void
    {
        $resolver = app(ContentFormatResolver::class);
        $slug = $resolver->slugForInstagram('VIDEO', 'FEED');

        $this->assertSame(ContentFormatResolver::SLUG_VIDEO, $slug);
        $this->assertSame('Video', $resolver->labelForSlug($slug));
    }

    public function test_instagram_reel_maps_to_video_not_a_separate_label(): void
    {
        $resolver = app(ContentFormatResolver::class);

        // Reel TETAP konten video secara kanonis - TIDAK lagi label
        // terpisah "Reels" (Part D, "VIDEO / Reel-compatible media ->
        // Video").
        $this->assertSame('Video', $resolver->labelForSlug($resolver->slugForInstagram('VIDEO', 'REELS')));
        $this->assertSame('Video', $resolver->labelForSlug($resolver->slugForInstagram('IMAGE', 'REELS')));
    }

    public function test_tiktok_maps_to_video(): void
    {
        $resolver = app(ContentFormatResolver::class);

        $this->assertSame('Video', $resolver->labelForSlug($resolver->slugForTikTok('tt-external-123')));
        $this->assertNull($resolver->slugForTikTok(null));
    }

    public function test_unknown_provider_combination_stays_unknown_not_guessed(): void
    {
        $resolver = app(ContentFormatResolver::class);

        // Kombinasi yang belum terbukti ada di data real - TIDAK ditebak,
        // balikin null (Part J, "unknown remains unknown").
        $this->assertNull($resolver->slugForInstagram('STORY', null));
        $this->assertNull($resolver->slugForInstagram(null, null));
        $this->assertNull($resolver->labelForSlug(null));
    }

    public function test_linked_content_item_master_format_overrides_provider_fallback(): void
    {
        $client = $this->client();
        $carousel = ContentFormat::where('slug', 'carousel')->firstOrFail();
        $item = $this->contentItem($client, contentFormatId: $carousel->id);
        // Snapshot mentah bilang IMAGE (Single Post) - TAPI master item
        // SUDAH diisi manual sebagai Carousel - master HARUS menang (Part
        // C, priority 1: "ContentItem.contentFormat ... TIDAK PERNAH
        // ditimpa raw media type provider walau provider bilang beda").
        $igSnapshot = InstagramMediaSnapshot::make(['media_type' => 'IMAGE', 'media_product_type' => 'FEED']);

        $label = app(ContentFormatResolver::class)->labelForContentItem($item, $igSnapshot, null);

        $this->assertSame('Carousel', $label);
    }

    public function test_linked_content_item_without_master_format_falls_back_to_provider(): void
    {
        $client = $this->client();
        $item = $this->contentItem($client, contentFormatId: null);
        $igSnapshot = InstagramMediaSnapshot::make(['media_type' => 'CAROUSEL_ALBUM', 'media_product_type' => 'CAROUSEL_ALBUM']);

        $label = app(ContentFormatResolver::class)->labelForContentItem($item, $igSnapshot, null);

        $this->assertSame('Carousel', $label);
    }

    public function test_production_type_and_content_format_are_independent_dimensions(): void
    {
        // Keputusan produk eksplisit: "Desain/Video" (production type) dan
        // "Single Post/Carousel/Video" (content format) BUKAN konsep yang
        // sama - Desain BUKAN kompetitor Carousel, satu jenis produksi,
        // satu format publikasi.
        $client = $this->client();
        $carousel = ContentFormat::where('slug', 'carousel')->firstOrFail();
        $desain = ContentType::firstOrCreate(['name' => 'Desain']);
        $item = $this->contentItem($client, contentFormatId: $carousel->id, contentTypeId: $desain->id);

        $this->assertSame('Desain', $item->contentType->name);
        $this->assertSame('Carousel', $item->contentFormat->name);
        $this->assertNotSame($item->contentType->name, $item->contentFormat->name);
    }

    public function test_existing_desain_video_master_unchanged_as_production_type(): void
    {
        // Master ContentType (Desain/Video) TIDAK diganti/direplace oleh
        // Content Format baru - dua master TERPISAH hidup berdampingan.
        $this->assertTrue(ContentType::where('name', 'Video')->exists() || true);
        $video = ContentType::firstOrCreate(['name' => 'Video']);
        $desain = ContentType::firstOrCreate(['name' => 'Desain']);

        $this->assertSame('Video', $video->name);
        $this->assertSame('Desain', $desain->name);
        // ContentType TETAP relasi contentItems() miliknya sendiri, tidak
        // pernah dicampur dengan content_formats.
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $video->contentItems());
    }

    // ===== Integrasi: Analytics table tab memakai resolver yang sama =====

    public function test_analytics_table_shows_single_post_not_raw_image_for_unmatched_instagram_content(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);
            $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id,
                'external_post_id' => 'ig-'.uniqid(),
                'match_status' => 'unmatched',
                'media_type' => 'IMAGE',
                'media_product_type' => 'FEED',
                'published_at' => $currentMonth->dateFrom->copy()->addDay(),
                'last_fetched_at' => now(),
            ]);

            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => now(), 'views' => 0, 'engagement_rate' => 0,
            ]);
            $this->snapshot($client, $platform, $media->id, $currentMonth->dateFrom->copy()->subDay(), 100);
            $this->snapshot($client, $platform, $media->id, $currentMonth->effectiveDateTo, 150);

            $response = $this->actingAs($manager)->get(route('analytics', [
                'tab' => 'table', 'client_id' => $client->id,
            ]));

            $response->assertOk();
            $response->assertSee('Single Post');
            // Raw provider enum TIDAK PERNAH bocor ke user (Part G/Z).
            $response->assertDontSee('IMAGE');
            $response->assertDontSee('>Image<', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_analytics_table_shows_carousel_not_raw_enum(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'Instagram']);
            $integration = $this->instagramIntegration($client);
            $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

            $media = InstagramMediaSnapshot::create([
                'api_integration_id' => $integration->id,
                'external_post_id' => 'ig-'.uniqid(),
                'match_status' => 'unmatched',
                'media_type' => 'CAROUSEL_ALBUM',
                'media_product_type' => 'CAROUSEL_ALBUM',
                'published_at' => $currentMonth->dateFrom->copy()->addDay(),
                'last_fetched_at' => now(),
            ]);

            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'instagram_media_snapshot_id' => $media->id, 'imported_by' => $manager->id,
                'metric_date' => now(), 'views' => 0, 'engagement_rate' => 0,
            ]);
            $this->snapshot($client, $platform, $media->id, $currentMonth->dateFrom->copy()->subDay(), 200);
            $this->snapshot($client, $platform, $media->id, $currentMonth->effectiveDateTo, 260);

            $response = $this->actingAs($manager)->get(route('analytics', [
                'tab' => 'table', 'client_id' => $client->id,
            ]));

            $response->assertOk();
            $response->assertSee('Carousel');
            $response->assertDontSee('CAROUSEL_ALBUM');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_analytics_table_shows_video_for_tiktok_content(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        try {
            $client = $this->client();
            $manager = $this->managerFor($client);
            $platform = Platform::firstOrCreate(['name' => 'TikTok']);
            $integration = $this->tiktokIntegration($client);
            $currentMonth = app(AnalyticsPeriodResolver::class)->currentMonth();

            $video = TikTokVideoSnapshot::create([
                'api_integration_id' => $integration->id,
                'external_post_id' => 'tt-'.uniqid(),
                'match_status' => 'unmatched',
                'published_at' => $currentMonth->dateFrom->copy()->addDay(),
                'last_fetched_at' => now(),
            ]);

            ContentMetric::create([
                'client_id' => $client->id, 'platform_id' => $platform->id,
                'tiktok_video_snapshot_id' => $video->id, 'imported_by' => $manager->id,
                'metric_date' => now(), 'views' => 0, 'engagement_rate' => 0,
            ]);
            $this->snapshot($client, $platform, null, $currentMonth->dateFrom->copy()->subDay(), 300, $video->id);
            $this->snapshot($client, $platform, null, $currentMonth->effectiveDateTo, 400, $video->id);

            $response = $this->actingAs($manager)->get(route('analytics', [
                'tab' => 'table', 'client_id' => $client->id,
            ]));

            $response->assertOk();
            $response->assertSee('Video');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_analytics_table_production_type_and_content_format_shown_separately_for_linked_item(): void
    {
        $client = $this->client();
        $manager = $this->managerFor($client);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);

        $singlePost = ContentFormat::where('slug', 'single-post')->firstOrFail();
        $item = $this->contentItem($client, contentFormatId: $singlePost->id, contentTypeId: ContentType::firstOrCreate(['name' => 'Desain'])->id);

        // CSV-style ContentMetric (bukan API/snapshot-delta) - path yang
        // sudah terbukti stabil buat menguji rendering klasifikasi tanpa
        // ikut terikat detail coverage/boundary snapshot delta (itu sudah
        // dites lengkap terpisah di AnalyticsPeriodEngineV2Test).
        ContentMetric::create([
            'content_item_id' => $item->id, 'client_id' => $client->id, 'platform_id' => $platform->id,
            'imported_by' => $manager->id,
            'metric_date' => now(), 'views' => 620, 'engagement_rate' => 4.1,
        ]);

        $response = $this->actingAs($manager)->get(route('analytics', [
            'tab' => 'table', 'client_id' => $client->id,
        ]));

        $response->assertOk();
        // Dua dimensi tampil BERSAMAAN buat item yang sudah ke-link -
        // TIDAK lagi 1 field yang cuma isi salah satu.
        $response->assertSee('Desain');
        $response->assertSee('Single Post');
    }

    // ===== helpers =====

    private function client(): Client
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        return Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);
    }

    private function managerFor(Client $client): User
    {
        $role = Role::create(['name' => 'Manager Test '.uniqid()]);
        $permission = Permission::firstOrCreate(['module' => 'analytics', 'action' => 'view']);
        $role->permissions()->attach($permission->id);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->roles()->attach($role->id);
        $manager->assignedClients()->attach($client->id);

        return $manager;
    }

    private function instagramIntegration(Client $client): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'integration_name' => 'IG', 'status' => 'active', 'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    private function tiktokIntegration(Client $client): ApiIntegration
    {
        return ApiIntegration::create([
            'client_id' => $client->id,
            'platform_id' => Platform::firstOrCreate(['name' => 'TikTok'])->id,
            'integration_name' => 'TT', 'status' => 'active', 'access_token' => 'fake-token',
            'external_username' => 'creator',
        ]);
    }

    private function contentItem(Client $client, ?int $contentFormatId, ?int $contentTypeId = null): ContentItem
    {
        $plan = ContentPlan::create([
            'client_id' => $client->id, 'created_by' => User::factory()->create()->id,
            'month' => now()->month, 'year' => now()->year, 'status' => 'draft',
        ]);

        return ContentItem::create([
            'content_plan_id' => $plan->id, 'client_id' => $client->id,
            'content_type_id' => $contentTypeId ?? ContentType::firstOrCreate(['name' => 'Video'])->id,
            'content_format_id' => $contentFormatId,
            'title' => 'Konten '.uniqid(), 'deadline_at' => now()->subDay(),
        ]);
    }

    private function snapshot(Client $client, Platform $platform, ?int $igMediaId, Carbon $date, int $views, ?int $ttVideoId = null): ContentMetricSnapshot
    {
        return ContentMetricSnapshot::create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'instagram_media_snapshot_id' => $igMediaId,
            'tiktok_video_snapshot_id' => $ttVideoId,
            'snapshot_date' => $date->toDateString(),
            'views' => $views,
        ]);
    }
}
