<?php

namespace Tests\Feature\Kpi;

use App\Enums\ContentFormatGroup;
use App\Enums\MeasurementWindow;
use App\Enums\UserRole;
use App\Kpi\Formula\KpiFormulaConfig;
use App\Kpi\Services\ContentOutcomeScoringService;
use App\Kpi\Services\KpiCalculationService;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentOutcomeResult;
use App\Models\ContentPublication;
use App\Models\ContentStatusLog;
use App\Models\ContentType;
use App\Models\KpiCalculationRun;
use App\Models\KpiFormulaVersion;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Models\UserKpiResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * KpiCalculationService end-to-end (data sintetis penuh, content_item_assignments
 * EXISTING + roles EXISTING - TIDAK ADA tabel assignment KPI khusus). Fokus:
 * idempotent, multi-platform dihitung 1x, paid content dikecualikan, partial
 * != full, access role tanpa PIC assignment tidak dapat KPI operasional.
 */
class KpiCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(Carbon $start, Carbon $end): KpiCalculationRun
    {
        $formula = KpiFormulaVersion::factory()->create();

        return KpiCalculationRun::create([
            'kpi_formula_version_id' => $formula->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'status' => KpiCalculationRun::STATUS_PENDING,
        ]);
    }

    private function contentCreator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::ContentCreator->value])->id);

        return $user;
    }

    private function videoItem(array $attrs = []): ContentItem
    {
        $videoType = ContentType::firstOrCreate(['name' => 'Video']);

        return ContentItem::factory()->create([...$attrs, 'content_type_id' => $videoType->id]);
    }

    /** Access role (Manager/CEO/dst) TANPA PIC assignment sama sekali tidak menghasilkan UserKpiResult apa pun. */
    public function test_access_role_without_assignment_gets_no_kpi_result(): void
    {
        $manager = User::factory()->create();
        $managerRole = Role::firstOrCreate(['name' => UserRole::Manager->value]);
        $manager->roles()->attach($managerRole->id);
        // TIDAK ADA content_item_assignments untuk user ini, dan tidak ada
        // decision/approval yang dilakukannya (jadi juga tidak leadership).

        $run = $this->makeRun(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        app(KpiCalculationService::class)->calculate($run);

        $this->assertSame(
            0,
            UserKpiResult::where('user_id', $manager->id)->count(),
            'User dengan access role tapi tanpa PIC assignment/decision tidak boleh punya UserKpiResult.'
        );
    }

    /** Content item dengan Instagram+TikTok tetap dihitung SATU KALI di content_outcome_results per window (bukan 2 baris terpisah). */
    public function test_multi_platform_content_item_counted_once_in_outcome_results(): void
    {
        $item = $this->videoItem(['deadline_at' => now()->subDays(20)]);
        $instagram = Platform::factory()->create(['name' => 'Instagram']);
        $tiktok = Platform::factory()->create(['name' => 'TikTok']);
        $user = $this->contentCreator();

        ContentItemAssignment::factory()->create([
            'content_item_id' => $item->id, 'user_id' => $user->id,
            'created_at' => now()->subDays(15),
        ]);

        foreach ([$instagram, $tiktok] as $platform) {
            ContentPublication::factory()->create([
                'content_item_id' => $item->id, 'platform_id' => $platform->id,
                'published_at' => now()->subDays(10),
            ]);
            ContentMetricSnapshot::factory()->create([
                'content_item_id' => $item->id, 'platform_id' => $platform->id, 'client_id' => $item->client_id,
                'snapshot_date' => now()->subDays(3)->toDateString(),
            ]);
        }

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        $this->assertSame(
            1,
            ContentOutcomeResult::where('kpi_calculation_run_id', $run->id)
                ->where('content_item_id', $item->id)
                ->where('measurement_window', 'd7')
                ->count(),
            'Satu content item multi-platform harus tetap 1 baris ContentOutcomeResult per window (platform digabung di dalamnya).'
        );
    }

    /** Publication paid TIDAK ikut peer pool organic (dikecualikan total dari candidatePublications()). */
    public function test_paid_publication_is_excluded_from_organic_peer_pool(): void
    {
        $item = $this->videoItem();
        $instagram = Platform::factory()->create(['name' => 'Instagram']);

        ContentPublication::factory()->paid()->create([
            'content_item_id' => $item->id, 'platform_id' => $instagram->id, 'published_at' => now()->subDays(40),
        ]);

        $service = app(ContentOutcomeScoringService::class);
        $peer = $service->buildPeerPool($item, $instagram->id, ContentFormatGroup::Video, MeasurementWindow::D30, KpiFormulaConfig::default());

        $this->assertSame(0, $peer['sample_size'], 'Publication paid tidak boleh masuk peer pool organic sama sekali.');
    }

    /** Menjalankan run yang sama dua kali (formula version sama, periode sama) menghasilkan angka composite yang identik - deterministic & idempotent. */
    public function test_recalculation_with_same_input_is_deterministic(): void
    {
        $item = $this->videoItem(['deadline_at' => now()->subDays(20)]);
        $instagram = Platform::factory()->create(['name' => 'Instagram']);
        $user = $this->contentCreator();

        ContentItemAssignment::factory()->create([
            'content_item_id' => $item->id, 'user_id' => $user->id,
            'created_at' => now()->subDays(15),
        ]);
        // Aktivitas produksi NYATA di periode ini (koreksi lanjutan #1).
        ContentStatusLog::factory()->create(['content_item_id' => $item->id]);
        ContentPublication::factory()->create([
            'content_item_id' => $item->id, 'platform_id' => $instagram->id, 'published_at' => now()->subDays(10),
        ]);
        ContentMetricSnapshot::factory()->create([
            'content_item_id' => $item->id, 'platform_id' => $instagram->id, 'client_id' => $item->client_id,
            'snapshot_date' => now()->subDays(3)->toDateString(),
        ]);

        $formula = KpiFormulaVersion::factory()->create();
        $service = app(KpiCalculationService::class);

        $runA = KpiCalculationRun::create([
            'kpi_formula_version_id' => $formula->id, 'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(), 'status' => KpiCalculationRun::STATUS_PENDING,
        ]);
        $service->calculate($runA);

        $runB = KpiCalculationRun::create([
            'kpi_formula_version_id' => $formula->id, 'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(), 'status' => KpiCalculationRun::STATUS_PENDING,
        ]);
        $service->calculate($runB);

        $resultA = UserKpiResult::where('kpi_calculation_run_id', $runA->id)->where('user_id', $user->id)->first();
        $resultB = UserKpiResult::where('kpi_calculation_run_id', $runB->id)->where('user_id', $user->id)->first();

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);
        // Dua run TERPISAH (histori tetap ada, TIDAK saling menimpa) tapi
        // angkanya identik untuk input yang sama persis - itulah "deterministic".
        $this->assertEquals($resultA->composite_score, $resultB->composite_score);
        $this->assertNotSame($runA->id, $runB->id, 'Setiap run harus jadi baris histori baru, bukan overwrite run lama.');
    }

    /** Fase 6: dua PIC berbeda di content item YANG SAMA (multi-PIC, content_item_assignments EXISTING) dapat direct_outcome_score identik - bukan dipecah/dibagi. */
    public function test_multi_pic_content_item_yields_identical_direct_outcome_for_every_pic(): void
    {
        $item = $this->videoItem(['deadline_at' => now()->subDays(20)]);
        $instagram = Platform::factory()->create(['name' => 'Instagram']);
        $userA = $this->contentCreator();
        $userB = $this->contentCreator();

        // SATU content item, DUA baris assignment (multi-PIC) - bukan
        // tabel/relasi khusus, cuma content_item_assignments EXISTING
        // dengan dua user_id berbeda untuk content_item_id yang sama.
        ContentItemAssignment::factory()->create([
            'content_item_id' => $item->id, 'user_id' => $userA->id, 'created_at' => now()->subDays(15),
        ]);
        ContentItemAssignment::factory()->create([
            'content_item_id' => $item->id, 'user_id' => $userB->id, 'created_at' => now()->subDays(15),
        ]);
        // Aktivitas produksi NYATA di periode ini (koreksi lanjutan #1) -
        // tanpa ini content item dianggap tidak aktif periode ini.
        ContentStatusLog::factory()->create(['content_item_id' => $item->id]);

        ContentPublication::factory()->create([
            'content_item_id' => $item->id, 'platform_id' => $instagram->id, 'published_at' => now()->subDays(10),
        ]);
        ContentMetricSnapshot::factory()->create([
            'content_item_id' => $item->id, 'platform_id' => $instagram->id, 'client_id' => $item->client_id,
            'snapshot_date' => now()->subDays(3)->toDateString(),
        ]);

        // Formula version dengan minimum peer sample DIKECILKAN ke 0 -
        // koreksi #9 tetap benar mengenforce minimum peer (lihat
        // ContentOutcomeScoringServiceTest), tapi test INI fokus ke
        // "identik antarPIC", bukan ke minimum sample-nya - dengan
        // minimum default (8 publikasi peer), satu content item apa pun
        // tidak akan pernah usable sama sekali (score selalu null untuk
        // SIAPA PUN), yang cuma membuktikan null===null, bukan mekanisme
        // pembagian outcome antarPIC yang sesungguhnya.
        $config = \App\Kpi\Formula\KpiFormulaConfig::default();
        $formula = KpiFormulaVersion::factory()->create([
            'config' => array_merge($config->toArray(), [
                'baseline' => array_merge($config->baseline, ['min_publications_for_client_platform_format' => 0]),
                'sample_size' => array_merge($config->sampleSize, ['min_publications_for_peer_baseline' => 0]),
            ]),
        ]);
        $run = KpiCalculationRun::create([
            'kpi_formula_version_id' => $formula->id,
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => KpiCalculationRun::STATUS_PENDING,
        ]);
        app(KpiCalculationService::class)->calculate($run);

        $resultA = UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $userA->id)->firstOrFail();
        $resultB = UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $userB->id)->firstOrFail();

        $this->assertNotNull($resultA->direct_outcome_score, 'Content sudah punya publication+metric - direct outcome tidak boleh null.');
        $this->assertEquals(
            $resultA->direct_outcome_score,
            $resultB->direct_outcome_score,
            'Satu content item multi-PIC harus menghasilkan direct_outcome_score IDENTIK untuk setiap PIC-nya, bukan dibagi/dipecah antarPIC.'
        );
    }

    /** Fase 6: Manager yang JUGA tercatat sebagai PIC (content_item_assignments) dapat KPI operasional (role_id=Manager, client_id NULL) - bukan hanya leadership. */
    public function test_manager_who_is_also_pic_gets_operational_kpi(): void
    {
        $item = $this->videoItem(['deadline_at' => now()->subDays(20)]);
        $manager = User::factory()->create();
        $managerRole = Role::firstOrCreate(['name' => UserRole::Manager->value]);
        $manager->roles()->attach($managerRole->id);

        ContentItemAssignment::factory()->create([
            'content_item_id' => $item->id, 'user_id' => $manager->id, 'created_at' => now()->subDays(15),
        ]);
        // Aktivitas produksi NYATA di periode ini (koreksi lanjutan #1) -
        // tanpa ini, item dianggap tidak aktif periode ini dan Manager
        // tidak dapat atribusi produksi apa pun lewat jalur PIC.
        \App\Models\ContentStatusLog::factory()->create(['content_item_id' => $item->id]);

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        // Koreksi lanjutan #4 - baris operasional SEKARANG per-klien (bukan
        // client_id NULL lagi) - client_id-nya klien content item itu sendiri.
        $operationalResult = UserKpiResult::where('kpi_calculation_run_id', $run->id)
            ->where('user_id', $manager->id)
            ->where('client_id', $item->client_id)
            ->first();

        $this->assertNotNull($operationalResult, 'Manager yang jadi PIC harus tetap dapat baris KPI operasional (role_id=Manager, client_id=klien content item) dari content_item_assignments EXISTING.');
        $this->assertSame($managerRole->id, $operationalResult->role_id);
    }

    /** Fase 6: dua user berbeda, masing-masing PIC di content item TERPISAH tapi milik KLIEN YANG SAMA - portfolio_outcome_score keduanya identik (bukan cuma diberikan ke satu PIC "utama"). */
    public function test_client_portfolio_outcome_given_to_every_eligible_pic(): void
    {
        $instagram = Platform::factory()->create(['name' => 'Instagram']);
        $client = \App\Models\Client::factory()->create();
        $peerClient = \App\Models\Client::factory()->create();

        $itemA = $this->videoItem(['client_id' => $client->id]);
        $itemB = $this->videoItem(['client_id' => $client->id]);
        $userA = $this->contentCreator();
        $userB = $this->contentCreator();

        ContentItemAssignment::factory()->create([
            'content_item_id' => $itemA->id, 'user_id' => $userA->id, 'created_at' => now()->subDays(15),
        ]);
        ContentItemAssignment::factory()->create([
            'content_item_id' => $itemB->id, 'user_id' => $userB->id, 'created_at' => now()->subDays(15),
        ]);
        // Aktivitas produksi NYATA di periode ini (koreksi lanjutan #1)
        // untuk KEDUA item - tanpa ini keduanya dianggap tidak aktif.
        ContentStatusLog::factory()->create(['content_item_id' => $itemA->id]);
        ContentStatusLog::factory()->create(['content_item_id' => $itemB->id]);

        // Data client sendiri (dipakai visibility_growth) - cukup 1 baris,
        // content_item_id mana pun boleh karena totalViewsDelta() hanya
        // memfilter berdasar client_id+platform_id, bukan per content item.
        ContentMetricSnapshot::factory()->create([
            'content_item_id' => $itemA->id, 'platform_id' => $instagram->id, 'client_id' => $client->id,
            'snapshot_date' => now()->subDays(3)->toDateString(), 'views' => 5000,
        ]);
        // Peer client - dibutuhkan supaya visibility_growth punya minimal
        // 1 peer growth rate (kalau kosong, percentile rank tidak dihitung
        // - koreksi #12, TIDAK PERNAH fallback ke 50 netral).
        ContentMetricSnapshot::factory()->create([
            'content_item_id' => null, 'platform_id' => $instagram->id, 'client_id' => $peerClient->id,
            'snapshot_date' => now()->subDays(3)->toDateString(), 'views' => 1000,
        ]);

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        $resultA = UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $userA->id)->firstOrFail();
        $resultB = UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $userB->id)->firstOrFail();

        $this->assertNotNull($resultA->portfolio_outcome_score, 'Client punya data visibility + minimal 1 peer - portfolio outcome tidak boleh null.');
        $this->assertEquals(
            $resultA->portfolio_outcome_score,
            $resultB->portfolio_outcome_score,
            'Dua PIC berbeda di client yang sama harus dapat portfolio_outcome_score IDENTIK (bukan cuma PIC "utama" yang dapat).'
        );
    }

    /** Koreksi lanjutan #1: assignment + aktivitas Januari TIDAK boleh ikut terhitung di KPI September hanya karena baris assignment-nya masih ada. */
    public function test_january_assignment_without_september_activity_is_excluded_from_september_kpi(): void
    {
        $item = $this->videoItem();
        $user = $this->contentCreator();

        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id, 'created_at' => '2026-01-10']);
        ContentStatusLog::factory()->create(['content_item_id' => $item->id, 'changed_at' => '2026-01-15']);

        $run = $this->makeRun(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        app(KpiCalculationService::class)->calculate($run);

        $this->assertSame(0, UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $user->id)->count(), 'Content tanpa aktivitas SEPTEMBER tidak boleh masuk KPI September walau assignment-nya masih ada dari Januari.');
    }

    /** Koreksi lanjutan #1: publication September masuk KPI September, BUKAN Oktober. */
    public function test_content_published_in_september_counts_toward_september_not_october(): void
    {
        $item = $this->videoItem();
        $instagram = Platform::factory()->create(['name' => 'Instagram']);
        ContentPublication::factory()->create(['content_item_id' => $item->id, 'platform_id' => $instagram->id, 'published_at' => '2026-09-15']);

        $septemberRun = $this->makeRun(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        app(KpiCalculationService::class)->calculate($septemberRun);
        $octoberRun = $this->makeRun(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        app(KpiCalculationService::class)->calculate($octoberRun);

        // 2 baris (d7 + d30) - dua measurement window untuk SATU publication, bukan duplikasi content item.
        $this->assertSame(2, ContentOutcomeResult::where('kpi_calculation_run_id', $septemberRun->id)->where('content_item_id', $item->id)->count());
        $this->assertSame(0, ContentOutcomeResult::where('kpi_calculation_run_id', $octoberRun->id)->where('content_item_id', $item->id)->count(), 'Publication September tidak boleh ikut terhitung di run Oktober.');
    }

    /** Satu user dengan DUA aktivitas berbeda pada content YANG SAMA (menulis brief-nya sebagai Copywriter, DAN jadi PIC produksi Content Creator) menghasilkan DUA baris atribusi terpisah - bukan dipaksa satu role. */
    public function test_user_with_two_provable_activities_on_same_content_gets_two_attributions(): void
    {
        $item = $this->videoItem();
        $user = $this->contentCreator();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::Copywriter->value])->id);

        \App\Models\ContentBriefDraft::factory()->create(['content_item_id' => $item->id, 'created_by' => $user->id, 'created_at' => now()->subDays(20)]);
        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $user->id, 'created_at' => now()->subDays(15)]);
        ContentStatusLog::factory()->create(['content_item_id' => $item->id, 'changed_at' => now()->subDays(10)]);

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        $roleNames = UserKpiResult::where('kpi_calculation_run_id', $run->id)
            ->where('user_id', $user->id)
            ->with('role')
            ->get()
            ->pluck('role.name')
            ->all();

        $this->assertContains('Copywriter', $roleNames);
        $this->assertContains('Content Creator', $roleNames);
        $this->assertCount(2, $roleNames, 'Dua aktivitas berbeda yang bisa dibuktikan (brief + produksi) harus jadi DUA baris terpisah, bukan digabung jadi satu role.');
    }

    /** Copywriter mendapat KPI dari brief yang dia tulis WALAU bukan PIC content_item_assignments sama sekali. */
    public function test_copywriter_gets_kpi_without_being_pic(): void
    {
        $item = $this->videoItem();
        $copywriter = User::factory()->create();
        $copywriter->roles()->attach(Role::firstOrCreate(['name' => UserRole::Copywriter->value])->id);

        \App\Models\ContentBriefDraft::factory()->finalized()->create([
            'content_item_id' => $item->id, 'created_by' => $copywriter->id, 'created_at' => now()->subDays(15),
        ]);

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        $result = UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $copywriter->id)->with('role')->first();

        $this->assertNotNull($result, 'Copywriter yang menulis brief harus dapat KPI walau tidak pernah jadi PIC content_item_assignments.');
        $this->assertSame('Copywriter', $result->role->name);
        $this->assertSame($item->client_id, $result->client_id);
    }

    /** SMO mendapat KPI dari publication yang BENAR-BENAR dia publikasikan (published_by, recorded_via=manual) WALAU bukan PIC content_item_assignments. */
    public function test_smo_gets_kpi_based_on_published_by_without_being_pic(): void
    {
        $item = $this->videoItem();
        $smo = User::factory()->create();
        $smo->roles()->attach(Role::firstOrCreate(['name' => UserRole::SMO->value])->id);

        ContentPublication::factory()->create([
            'content_item_id' => $item->id, 'published_by' => $smo->id,
            'recorded_via' => ContentPublication::RECORDED_VIA_MANUAL,
            'published_at' => now()->subDays(15),
        ]);

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        $result = UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $smo->id)->with('role')->first();

        $this->assertNotNull($result, 'SMO yang benar-benar mempublikasikan konten harus dapat KPI walau tidak pernah jadi PIC content_item_assignments.');
        $this->assertSame('SMO', $result->role->name);
    }

    /** PIC yang BUKAN publisher asli tidak mendapat baris KPI SMO apa pun - kredit publish HANYA untuk yang benar-benar mempublikasikan. */
    public function test_pic_who_is_not_publisher_gets_no_smo_kpi_row(): void
    {
        $item = $this->videoItem();
        $pic = $this->contentCreator();
        $pic->roles()->attach(Role::firstOrCreate(['name' => UserRole::SMO->value])->id);
        $actualPublisher = User::factory()->create();

        ContentItemAssignment::factory()->create(['content_item_id' => $item->id, 'user_id' => $pic->id, 'created_at' => now()->subDays(15)]);
        ContentStatusLog::factory()->create(['content_item_id' => $item->id, 'changed_at' => now()->subDays(12)]);
        ContentPublication::factory()->create([
            'content_item_id' => $item->id, 'published_by' => $actualPublisher->id,
            'recorded_via' => ContentPublication::RECORDED_VIA_MANUAL,
            'published_at' => now()->subDays(10),
        ]);

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        $picSmoRow = UserKpiResult::where('kpi_calculation_run_id', $run->id)
            ->where('user_id', $pic->id)
            ->whereHas('role', fn ($q) => $q->where('name', 'SMO'))
            ->first();

        $this->assertNull($picSmoRow, 'PIC yang punya role SMO tapi TIDAK mempublikasikan konten ini sendiri tidak boleh dapat baris KPI SMO untuk konten ini.');
    }

    /** Manager dengan aktivitas produksi (PIC) DAN leadership (approval) pada KLIEN BERBEDA mendapat dua baris terpisah (client_id beda). */
    public function test_manager_with_production_and_leadership_on_different_clients_gets_separate_rows(): void
    {
        $manager = User::factory()->create();
        $managerRole = Role::firstOrCreate(['name' => UserRole::Manager->value]);
        $manager->roles()->attach($managerRole->id);

        $clientA = \App\Models\Client::factory()->create();
        $clientB = \App\Models\Client::factory()->create();

        $productionItem = $this->videoItem(['client_id' => $clientA->id]);
        ContentItemAssignment::factory()->create(['content_item_id' => $productionItem->id, 'user_id' => $manager->id, 'created_at' => now()->subDays(15)]);
        ContentStatusLog::factory()->create(['content_item_id' => $productionItem->id, 'changed_at' => now()->subDays(12)]);

        $decidedItem = $this->videoItem(['client_id' => $clientB->id]);
        ContentStatusLog::factory()->create([
            'content_item_id' => $decidedItem->id, 'changed_by_user_id' => $manager->id,
            'from_status' => 'waiting_review', 'to_status' => 'approved', 'changed_at' => now()->subDays(5),
        ]);

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        $rows = UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $manager->id)->get();

        $this->assertCount(2, $rows, 'Manager dengan aktivitas produksi di klien A dan leadership di klien B harus punya DUA baris terpisah.');
        $this->assertTrue($rows->contains('client_id', $clientA->id));
        $this->assertTrue($rows->contains('client_id', $clientB->id));
    }

    /** Manager dengan aktivitas produksi (PIC) DAN leadership (approval) pada KLIEN YANG SAMA di-merge jadi SATU baris (bukan salah satu overwrite yang lain diam-diam). */
    public function test_manager_with_production_and_leadership_on_same_client_merges_into_one_row(): void
    {
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::firstOrCreate(['name' => UserRole::Manager->value])->id);
        $client = \App\Models\Client::factory()->create();

        $productionItem = $this->videoItem(['client_id' => $client->id]);
        ContentItemAssignment::factory()->create(['content_item_id' => $productionItem->id, 'user_id' => $manager->id, 'created_at' => now()->subDays(15)]);
        ContentStatusLog::factory()->create(['content_item_id' => $productionItem->id, 'changed_at' => now()->subDays(12)]);

        $decidedItem = $this->videoItem(['client_id' => $client->id]);
        ContentStatusLog::factory()->create([
            'content_item_id' => $decidedItem->id, 'changed_by_user_id' => $manager->id,
            'from_status' => 'waiting_review', 'to_status' => 'approved', 'changed_at' => now()->subDays(5),
        ]);

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        $rows = UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $manager->id)->get();

        $this->assertCount(1, $rows, 'Aktivitas produksi dan leadership pada KLIEN YANG SAMA harus digabung jadi satu baris (run,user,role,client) - bukan dua baris atau salah satu hilang.');
        $this->assertTrue($rows->first()->component_breakdown['merged_production_and_leadership'] ?? false);
    }

    /** Satu user dengan aktivitas (brief) untuk DUA klien berbeda di periode yang sama mendapat DUA baris breakdown per-klien. */
    public function test_user_with_activity_on_two_clients_gets_two_client_breakdown_rows(): void
    {
        $copywriter = User::factory()->create();
        $copywriter->roles()->attach(Role::firstOrCreate(['name' => UserRole::Copywriter->value])->id);

        $clientA = \App\Models\Client::factory()->create();
        $clientB = \App\Models\Client::factory()->create();
        $itemForA = $this->videoItem(['client_id' => $clientA->id]);
        $itemForB = $this->videoItem(['client_id' => $clientB->id]);

        \App\Models\ContentBriefDraft::factory()->create(['content_item_id' => $itemForA->id, 'created_by' => $copywriter->id, 'created_at' => now()->subDays(15)]);
        \App\Models\ContentBriefDraft::factory()->create(['content_item_id' => $itemForB->id, 'created_by' => $copywriter->id, 'created_at' => now()->subDays(10)]);

        $run = $this->makeRun(now()->subDays(30), now());
        app(KpiCalculationService::class)->calculate($run);

        $rows = UserKpiResult::where('kpi_calculation_run_id', $run->id)->where('user_id', $copywriter->id)->get();

        $this->assertCount(2, $rows, 'Satu user dengan aktivitas di dua klien berbeda harus punya breakdown DUA baris per-klien.');
        $this->assertTrue($rows->contains('client_id', $clientA->id));
        $this->assertTrue($rows->contains('client_id', $clientB->id));
    }
}
