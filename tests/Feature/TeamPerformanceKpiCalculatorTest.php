<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Models\ContentType;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMonthlyKpiResult;
use App\Services\TeamPerformanceKpiCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Formula KPI Team Performance (lihat docs/KPI_TEAM_PERFORMANCE.md) - semua
 * fixture pakai factory/data sintetis, TIDAK bergantung ke database lokal.
 */
class TeamPerformanceKpiCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $period;

    protected function setUp(): void
    {
        parent::setUp();

        // Bulan tetap (bukan now()) supaya test tidak flaky di sekitar
        // pergantian bulan - lihat catatan yang sama di DashboardScopeTest.
        $this->period = Carbon::create(2026, 3, 1);
    }

    private function calculator(): TeamPerformanceKpiCalculator
    {
        return app(TeamPerformanceKpiCalculator::class);
    }

    private function publishedItem(Client $client, array $itemOverrides = [], ?Carbon $publishedAt = null, array $publicationOverrides = []): ContentItem
    {
        $item = ContentItem::factory()->create(array_merge(['client_id' => $client->id], $itemOverrides));

        ContentPublication::factory()->create(array_merge([
            'content_item_id' => $item->id,
            'published_at' => $publishedAt ?? $this->period->copy()->addDays(5),
        ], $publicationOverrides));

        return $item;
    }

    public function test_user_with_multiple_roles_gets_a_single_kpi_row(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $user->roles()->attach([
            Role::create(['name' => 'Role A '.uniqid()])->id,
            Role::create(['name' => 'Role B '.uniqid()])->id,
        ]);

        $item = $this->publishedItem($client);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id]);

        $this->calculator()->calculateForPeriod($this->period);

        $this->assertSame(1, UserMonthlyKpiResult::where('user_id', $user->id)->count());
    }

    public function test_content_with_multiple_pics_credits_every_pic(): void
    {
        $client = Client::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $item = $this->publishedItem($client);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $userA->id]);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $userB->id]);

        $this->calculator()->calculateForPeriod($this->period);

        $resultA = UserMonthlyKpiResult::where('user_id', $userA->id)->first();
        $resultB = UserMonthlyKpiResult::where('user_id', $userB->id)->first();

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);
        $this->assertSame(1, $resultA->sample_size);
        $this->assertSame(1, $resultB->sample_size);
        $this->assertSame($item->id, $resultA->breakdown[0]['content_item_id']);
        $this->assertSame($item->id, $resultB->breakdown[0]['content_item_id']);
    }

    public function test_same_content_is_not_double_counted_for_the_same_user(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $item = $this->publishedItem($client);
        // Tiga sumber atribusi SEKALIGUS menunjuk ke user yang sama.
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id]);
        ContentBriefDraft::factory()->create(['content_item_id' => $item->id, 'created_by' => $user->id]);
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'approved', 'to_status' => 'scheduled',
            'changed_by_user_id' => $user->id, 'changed_at' => now(),
        ]);

        $this->calculator()->calculateForPeriod($this->period);

        $result = UserMonthlyKpiResult::where('user_id', $user->id)->first();

        $this->assertSame(1, $result->sample_size);
        $this->assertCount(1, $result->breakdown);
    }

    public function test_content_is_attributed_to_the_month_of_its_first_publication(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $item = ContentItem::factory()->create(['client_id' => $client->id]);
        // Publication pertama di periode ini, publication kedua (platform lain) di bulan berikutnya.
        ContentPublication::factory()->create(['content_item_id' => $item->id, 'published_at' => $this->period->copy()->addDays(2)]);
        ContentPublication::factory()->create(['content_item_id' => $item->id, 'published_at' => $this->period->copy()->addMonth()->addDays(2)]);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id]);

        $this->calculator()->calculateForPeriod($this->period);

        $result = UserMonthlyKpiResult::where('user_id', $user->id)
            ->where('period_start', $this->period->toDateString())
            ->first();

        $this->assertNotNull($result);
        $this->assertSame($item->id, $result->breakdown[0]['content_item_id']);
    }

    public function test_old_content_does_not_leak_into_the_following_month(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $item = $this->publishedItem($client, [], $this->period->copy()->addDays(2));
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id]);

        $nextMonth = $this->period->copy()->addMonth();
        $this->calculator()->calculateForPeriod($nextMonth);

        $this->assertNull(
            UserMonthlyKpiResult::where('user_id', $user->id)->where('period_start', $nextMonth->toDateString())->first()
        );
    }

    public function test_internal_revision_lowers_quality_score(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $reviewer = User::factory()->create();

        $clean = $this->publishedItem($client);
        ContentItemAssignment::factory()->create(['content_item_id' => $clean->id, 'user_id' => $user->id]);

        $revised = $this->publishedItem($client);
        ContentItemAssignment::factory()->create(['content_item_id' => $revised->id, 'user_id' => $user->id]);
        ContentRevision::factory()->create(['content_item_id' => $revised->id, 'requested_by_user_id' => $reviewer->id]);

        $this->calculator()->calculateForPeriod($this->period);

        $result = UserMonthlyKpiResult::where('user_id', $user->id)->first();

        $this->assertSame(50.0, $result->quality_score);
    }

    public function test_client_requested_revision_does_not_lower_quality_score(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $itemA = $this->publishedItem($client);
        ContentItemAssignment::factory()->create(['content_item_id' => $itemA->id, 'user_id' => $user->id]);

        $itemB = $this->publishedItem($client);
        ContentItemAssignment::factory()->create(['content_item_id' => $itemB->id, 'user_id' => $user->id]);
        ContentRevision::factory()->create(['content_item_id' => $itemB->id, 'requested_by_client_id' => $client->id, 'requested_by_user_id' => null]);

        $this->calculator()->calculateForPeriod($this->period);

        $result = UserMonthlyKpiResult::where('user_id', $user->id)->first();

        $this->assertSame(100.0, $result->quality_score);
    }

    public function test_content_without_time_data_is_not_treated_as_late(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        // Tidak ada scheduled_upload_at, tidak ada handoff in_progress->waiting_review.
        $item = $this->publishedItem($client, ['scheduled_upload_at' => null]);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id]);

        $this->calculator()->calculateForPeriod($this->period);

        $result = UserMonthlyKpiResult::where('user_id', $user->id)->first();

        $this->assertNull($result->timeliness_score);
        $this->assertNull($result->breakdown[0]['on_time']);
    }

    public function test_unavailable_analytics_does_not_lower_the_base_score(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        // Ada handoff tepat waktu, tanpa revisi, tapi TANPA snapshot analytics sama sekali.
        $item = $this->publishedItem($client, [
            'scheduled_upload_at' => null,
            'deadline_at' => $this->period->copy()->addDays(10),
        ]);
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'in_progress', 'to_status' => 'waiting_review',
            'changed_at' => $this->period->copy()->addDays(3),
        ]);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id]);
        // Perlu >= 3 content dengan data waktu supaya status "cukup" dan final_score numerik terlihat jelas.
        for ($i = 0; $i < 2; $i++) {
            $extra = $this->publishedItem($client, [
                'scheduled_upload_at' => null,
                'deadline_at' => $this->period->copy()->addDays(10),
            ]);
            ContentStatusLog::factory()->create([
                'content_item_id' => $extra->id, 'from_status' => 'in_progress', 'to_status' => 'waiting_review',
                'changed_at' => $this->period->copy()->addDays(3),
            ]);
            ContentItemAssignment::factory()->create(['content_item_id' => $extra->id, 'user_id' => $user->id]);
        }

        $this->calculator()->calculateForPeriod($this->period);

        $result = UserMonthlyKpiResult::where('user_id', $user->id)->first();

        $this->assertFalse($result->analytics_available);
        $this->assertNull($result->analytics_bonus);
        $expectedBase = ($result->timeliness_score * 0.6) + ($result->quality_score * 0.4);
        $this->assertEqualsWithDelta(round($expectedBase, 2), $result->final_score, 0.01);
    }

    public function test_video_content_is_never_compared_against_design_content(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $designType = ContentType::firstOrCreate(['name' => 'Desain']);

        // Baseline PALSU yang formatnya beda (Video, performa tinggi) - kalau
        // sampai ikut dipakai sebagai pembanding, konten desain akan dapat bonus.
        for ($i = 0; $i < 3; $i++) {
            $videoBaseline = $this->publishedItem(
                $client,
                ['content_type_id' => $videoType->id, 'content_format_id' => null],
                $this->period->copy()->subDays(20 + $i)
            );
            ContentMetricSnapshot::factory()->create([
                'content_item_id' => $videoBaseline->id,
                'client_id' => $client->id,
                'snapshot_date' => $this->period->copy()->subDays(20 + $i)->addDays(7),
                'views' => 100000,
                'engagement_rate' => 50,
            ]);
        }

        $designPublishedAt = $this->period->copy()->addDays(5);
        $designItem = $this->publishedItem(
            $client,
            ['content_type_id' => $designType->id, 'content_format_id' => null],
            $designPublishedAt
        );
        ContentItemAssignment::factory()->create(['content_item_id' => $designItem->id, 'user_id' => $user->id]);
        ContentMetricSnapshot::factory()->create([
            'content_item_id' => $designItem->id,
            'client_id' => $client->id,
            'snapshot_date' => $designPublishedAt->copy()->addDays(7),
            'views' => 500,
            'engagement_rate' => 1,
        ]);

        $this->calculator()->calculateForPeriod($this->period);

        $result = UserMonthlyKpiResult::where('user_id', $user->id)->first();

        $this->assertFalse($result->analytics_available);
        $this->assertNull($result->breakdown[0]['analytics_bonus']);
    }

    public function test_fewer_than_three_content_items_yields_provisional_status(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();

        $itemA = $this->publishedItem($client, ['scheduled_upload_at' => $this->period->copy()->addDays(10)]);
        ContentPublication::query()->where('content_item_id', $itemA->id)->update(['published_at' => $this->period->copy()->addDays(9)]);
        ContentItemAssignment::factory()->create(['content_item_id' => $itemA->id, 'user_id' => $user->id]);

        $itemB = $this->publishedItem($client, ['scheduled_upload_at' => $this->period->copy()->addDays(15)]);
        ContentPublication::query()->where('content_item_id', $itemB->id)->update(['published_at' => $this->period->copy()->addDays(14)]);
        ContentItemAssignment::factory()->create(['content_item_id' => $itemB->id, 'user_id' => $user->id]);

        $this->calculator()->calculateForPeriod($this->period);

        $result = UserMonthlyKpiResult::where('user_id', $user->id)->first();

        $this->assertSame(2, $result->sample_size);
        $this->assertSame(UserMonthlyKpiResult::STATUS_SEMENTARA, $result->status);
        $this->assertFalse($result->isSufficient());
    }

    public function test_manager_without_operational_contribution_gets_no_fake_score(): void
    {
        $client = Client::factory()->create();
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::create(['name' => 'Manager Test '.uniqid()])->id);

        $creator = User::factory()->create();
        $item = $this->publishedItem($client);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $creator->id]);

        // Manager cuma approve (waiting_review -> approved) - approval TIDAK
        // termasuk sumber atribusi (lihat docs/KPI_TEAM_PERFORMANCE.md).
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'waiting_review', 'to_status' => 'approved',
            'changed_by_user_id' => $manager->id, 'changed_at' => now(),
        ]);

        $this->calculator()->calculateForPeriod($this->period);

        $this->assertFalse(UserMonthlyKpiResult::where('user_id', $manager->id)->exists());
        $this->assertTrue(UserMonthlyKpiResult::where('user_id', $creator->id)->exists());
    }
}
