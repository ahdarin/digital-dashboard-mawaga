<?php

namespace Tests\Feature\Kpi;

use App\Enums\UserRole;
use App\Kpi\Services\KpiRoleContextResolver;
use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentPublication;
use App\Models\ContentStatusLog;
use App\Models\ContentType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Koreksi lanjutan produk 2026-09-02 - atribusi role KPI SEKARANG berbasis
 * AKTIVITAS AKTOR yang benar-benar terbukti pada PERIODE yang dihitung
 * (bukan lagi "punya assignment yang dibuat sebelum akhir periode" tanpa
 * batas bawah, dan bukan lagi memilih satu role "utama" per user).
 */
class KpiRoleContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $periodStart;

    private Carbon $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->periodStart = Carbon::parse('2026-06-01');
        $this->periodEnd = Carbon::parse('2026-06-30');
    }

    private function resolver(): KpiRoleContextResolver
    {
        return app(KpiRoleContextResolver::class);
    }

    /** Copywriter: brief yang DIBUAT di periode ini - TIDAK PERLU jadi PIC content item sama sekali. */
    public function test_copywriter_activity_does_not_require_being_pic(): void
    {
        $item = ContentItem::factory()->create();
        $copywriter = User::factory()->create();
        ContentBriefDraft::factory()->create([
            'content_item_id' => $item->id, 'created_by' => $copywriter->id,
            'created_at' => Carbon::parse('2026-06-15'),
        ]);

        $activities = $this->resolver()->copywriterActivities($this->periodStart, $this->periodEnd);

        $this->assertCount(1, $activities);
        $this->assertSame($copywriter->id, $activities->first()['user_id']);
        $this->assertSame($item->id, $activities->first()['content_item_id']);
        $this->assertSame($item->client_id, $activities->first()['client_id']);
    }

    /** Brief yang dibuat DI LUAR periode tidak ikut - koreksi #1 (period eligibility). */
    public function test_copywriter_activity_outside_period_is_excluded(): void
    {
        $item = ContentItem::factory()->create();
        ContentBriefDraft::factory()->create([
            'content_item_id' => $item->id, 'created_by' => User::factory()->create()->id,
            'created_at' => Carbon::parse('2026-05-15'),
        ]);

        $activities = $this->resolver()->copywriterActivities($this->periodStart, $this->periodEnd);

        $this->assertCount(0, $activities);
    }

    /** Content Creator/Graphic Designer dipisah per content type - satu user dengan DUA role sekaligus mendapat DUA baris aktivitas terpisah kalau memang mengerjakan kedua jenis konten. */
    public function test_user_with_multiple_roles_is_separated_by_content_type_activity(): void
    {
        $videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $designType = ContentType::firstOrCreate(['name' => 'Desain']);

        $user = User::factory()->create();
        $user->roles()->attach([
            Role::firstOrCreate(['name' => UserRole::ContentCreator->value])->id,
            Role::firstOrCreate(['name' => UserRole::DesainGrafis->value])->id,
        ]);

        $videoItem = ContentItem::factory()->create(['content_type_id' => $videoType->id]);
        $designItem = ContentItem::factory()->create(['content_type_id' => $designType->id]);

        ContentItemAssignment::factory()->create(['content_item_id' => $videoItem->id, 'user_id' => $user->id]);
        ContentItemAssignment::factory()->create(['content_item_id' => $designItem->id, 'user_id' => $user->id]);
        ContentStatusLog::factory()->create(['content_item_id' => $videoItem->id, 'changed_at' => Carbon::parse('2026-06-10')]);
        ContentStatusLog::factory()->create(['content_item_id' => $designItem->id, 'changed_at' => Carbon::parse('2026-06-10')]);

        $activities = $this->resolver()->productionActivities($this->periodStart, $this->periodEnd);

        $byRole = $activities->groupBy('role_name');
        $this->assertSame([$videoItem->id], $byRole['Content Creator']->pluck('content_item_id')->all());
        $this->assertSame([$designItem->id], $byRole['Graphic Designer']->pluck('content_item_id')->all());
    }

    /** Koreksi #1 - PIC assignment TANPA aktivitas status log apa pun di periode ini TIDAK dihitung (assignment lama/konten tidak aktif tidak boleh bocor ke bulan lain). */
    public function test_production_activity_requires_status_log_activity_within_period(): void
    {
        $videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ContentCreator->value])->id);

        $item = ContentItem::factory()->create(['content_type_id' => $videoType->id]);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id]);
        // Status log-nya ada, TAPI di bulan Januari - BUKAN periode Juni yang dihitung.
        ContentStatusLog::factory()->create(['content_item_id' => $item->id, 'changed_at' => Carbon::parse('2026-01-10')]);

        $activities = $this->resolver()->productionActivities($this->periodStart, $this->periodEnd);

        $this->assertCount(0, $activities, 'Content item tanpa aktivitas status log DI DALAM periode ini tidak boleh ikut terhitung.');
    }

    /** Admin (view-only) tidak pernah dapat konteks role produksi apa pun walau punya assignment + aktivitas. */
    public function test_admin_role_never_gets_a_kpi_role_context(): void
    {
        $videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::Admin->value])->id);

        $item = ContentItem::factory()->create(['content_type_id' => $videoType->id]);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $admin->id]);
        ContentStatusLog::factory()->create(['content_item_id' => $item->id, 'changed_at' => Carbon::parse('2026-06-10')]);

        $activities = $this->resolver()->productionActivities($this->periodStart, $this->periodEnd);

        $this->assertCount(0, $activities);
    }

    /** Manager yang JUGA jadi PIC konten (bukan cuma leadership) tetap dapat konteks role produksi 'Manager' untuk item itu. */
    public function test_manager_assigned_as_pic_gets_manager_production_context(): void
    {
        $videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::firstOrCreate(['name' => UserRole::Manager->value])->id);

        $item = ContentItem::factory()->create(['content_type_id' => $videoType->id]);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $manager->id]);
        ContentStatusLog::factory()->create(['content_item_id' => $item->id, 'changed_at' => Carbon::parse('2026-06-10')]);

        $activities = $this->resolver()->productionActivities($this->periodStart, $this->periodEnd);

        $this->assertCount(1, $activities);
        $this->assertSame('Manager', $activities->first()['role_name']);
        $this->assertSame($item->id, $activities->first()['content_item_id']);
    }

    /** SMO: publish yang BENAR-BENAR dilakukan sendiri (recorded_via=manual) di periode ini - TIDAK PERLU jadi PIC content item sama sekali. */
    public function test_smo_activity_does_not_require_being_pic(): void
    {
        $item = ContentItem::factory()->create();
        $smo = User::factory()->create();
        ContentPublication::factory()->create([
            'content_item_id' => $item->id, 'published_by' => $smo->id,
            'recorded_via' => ContentPublication::RECORDED_VIA_MANUAL,
            'published_at' => Carbon::parse('2026-06-20'),
        ]);

        $activities = $this->resolver()->smoActivities($this->periodStart, $this->periodEnd);

        $this->assertCount(1, $activities);
        $this->assertSame($smo->id, $activities->first()['user_id']);
        $this->assertSame($item->id, $activities->first()['content_item_id']);
    }

    /** Koreksi: publication yang dibuat OTOMATIS saat sync (recorded_via=auto_sync) TIDAK dipakai atribusi SMO - published_by di situ cuma user yang kebetulan memicu sync, bukan publisher asli. */
    public function test_auto_sync_publication_is_excluded_from_smo_activity(): void
    {
        $item = ContentItem::factory()->create();
        $syncTrigger = User::factory()->create();
        ContentPublication::factory()->create([
            'content_item_id' => $item->id, 'published_by' => $syncTrigger->id,
            'recorded_via' => ContentPublication::RECORDED_VIA_AUTO_SYNC,
            'published_at' => Carbon::parse('2026-06-20'),
        ]);

        $activities = $this->resolver()->smoActivities($this->periodStart, $this->periodEnd);

        $this->assertCount(0, $activities, 'Publication hasil auto-sync tidak boleh dipakai sebagai bukti aktivitas publishing SMO.');
    }

    /** PIC content (Content Creator) yang BUKAN publisher asli tidak muncul di smoActivities() - kredit publish HANYA untuk yang benar-benar mempublikasikan. */
    public function test_pic_who_is_not_the_publisher_gets_no_smo_activity(): void
    {
        $item = ContentItem::factory()->create();
        $pic = User::factory()->create();
        $actualPublisher = User::factory()->create();

        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $pic->id]);
        ContentPublication::factory()->create([
            'content_item_id' => $item->id, 'published_by' => $actualPublisher->id,
            'recorded_via' => ContentPublication::RECORDED_VIA_MANUAL,
            'published_at' => Carbon::parse('2026-06-20'),
        ]);

        $activities = $this->resolver()->smoActivities($this->periodStart, $this->periodEnd);

        $this->assertCount(1, $activities);
        $this->assertSame($actualPublisher->id, $activities->first()['user_id'], 'Kredit publish harus jatuh ke publisher asli, bukan PIC content yang tidak mempublikasikannya.');
    }
}
