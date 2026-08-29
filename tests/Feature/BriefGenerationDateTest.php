<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\Platform;
use App\Models\User;
use App\Services\BriefGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression untuk KI-07 (AI Brief - tanggal hallucinated tahun 2024) - lihat
 * docs/USER_MANUAL_SOURCE_OF_TRUTH.md Bagian 7 & Bagian 22. Tidak memanggil
 * Gemini API asli - respons di-fake supaya deterministik & tidak butuh
 * jaringan keluar.
 */
class BriefGenerationDateTest extends TestCase
{
    use RefreshDatabase;

    private function contentItem(): ContentItem
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);
        $client = Client::create([
            'client_category_id' => $category->id,
            'name' => 'Test Client '.uniqid(),
            'status' => 'active',
        ]);
        $contentType = ContentType::firstOrCreate(['name' => 'Video']);
        $platform = Platform::firstOrCreate(['name' => 'Instagram']);
        $plan = ContentPlan::create([
            'client_id' => $client->id,
            'created_by' => User::factory()->create()->id,
            'month' => now()->month,
            'year' => now()->year,
            'status' => 'draft',
        ]);

        return ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_type_id' => $contentType->id,
            'platform_id' => $platform->id,
            'title' => 'Konten Test',
            'brief' => 'Brief mentah',
            'deadline_at' => now()->addDays(10),
        ]);
    }

    private function fakeGeminiResponse(array $json): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode($json)]]]],
                ],
            ], 200),
        ]);
        config(['services.gemini.api_key' => 'fake-key-for-test']);
    }

    public function test_generate_replaces_hallucinated_past_date_with_a_sane_fallback(): void
    {
        $item = $this->contentItem();

        $this->fakeGeminiResponse([
            'hook_title' => 'Hook Test',
            'start_date' => '2024-01-15',
            'post_date' => '2024-01-18',
            'platform' => 'Instagram',
            'scenes' => [],
            'talent' => 'N/A',
            'properti' => 'N/A',
            'estimated_duration_seconds' => 30,
            'slide_count' => null,
            'talent_count' => 1,
            'location_count' => 1,
            'complexity_level' => 'simple',
        ]);

        $brief = (new BriefGenerationService)->generate($item);

        $today = Carbon::now()->startOfDay();
        $this->assertTrue($brief->start_date->gte($today), 'start_date tidak boleh di masa lalu.');
        $this->assertTrue($brief->post_date->gte($brief->start_date), 'post_date tidak boleh sebelum start_date.');
        $this->assertSame($today->copy()->addDay()->toDateString(), $brief->start_date->toDateString());
    }

    public function test_generate_keeps_a_valid_near_term_date_untouched(): void
    {
        $item = $this->contentItem();
        $validStart = now()->addDays(2)->toDateString();
        $validPost = now()->addDays(6)->toDateString();

        $this->fakeGeminiResponse([
            'hook_title' => 'Hook Test',
            'start_date' => $validStart,
            'post_date' => $validPost,
            'platform' => 'Instagram',
            'scenes' => [],
        ]);

        $brief = (new BriefGenerationService)->generate($item);

        $this->assertSame($validStart, $brief->start_date->toDateString());
        $this->assertSame($validPost, $brief->post_date->toDateString());
    }

    public function test_feasibility_is_not_critical_merely_because_of_a_hallucinated_past_date(): void
    {
        $item = $this->contentItem(); // deadline_at = now()->addDays(10)

        Http::fake(function ($request) {
            $body = $request->data();
            $prompt = $body['contents'][0]['parts'][0]['text'];

            // Panggilan pertama (buildGeneratePrompt) mengembalikan tanggal
            // hallucinated; panggilan kedua (buildFeasibilityPrompt) dinilai
            // dari data yang SUDAH disanitasi backend, bukan tanggal mentah AI.
            if (str_contains($prompt, 'menilai KELAYAKAN')) {
                return Http::response(['candidates' => [
                    ['content' => ['parts' => [['text' => json_encode([
                        'feasibility_level' => 'ok',
                        'feasibility_notes' => 'Margin waktu cukup, tidak ada bentrok jadwal.',
                    ])]]]],
                ]], 200);
            }

            return Http::response(['candidates' => [
                ['content' => ['parts' => [['text' => json_encode([
                    'hook_title' => 'Hook Test',
                    'start_date' => '2024-03-01',
                    'post_date' => '2024-03-03',
                ])]]]],
            ]], 200);
        });
        config(['services.gemini.api_key' => 'fake-key-for-test']);

        $brief = (new BriefGenerationService)->generate($item);

        $this->assertNotSame('critical', $brief->feasibility_level);
    }
}
