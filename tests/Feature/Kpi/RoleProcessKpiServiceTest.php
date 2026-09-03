<?php

namespace Tests\Feature\Kpi;

use App\Kpi\Services\RoleProcessKpiService;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RoleProcessKpiService: client waiting time TIDAK masuk active production
 * duration, client revision terpisah dari internal revision, internal
 * revision dibatasi periode KPI, first handoff = in_progress -> waiting_review.
 */
class RoleProcessKpiServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): RoleProcessKpiService
    {
        return app(RoleProcessKpiService::class);
    }

    /** Waktu tunggu klien (siklus revisi) TIDAK ikut dihitung sebagai active production duration. */
    public function test_client_waiting_time_is_excluded_from_active_production_duration(): void
    {
        $item = ContentItem::factory()->create(['deadline_at' => Carbon::parse('2026-06-20')]);
        $user = User::factory()->create();

        // Segmen kerja aktif pertama: 2 jam.
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'brief_ready', 'to_status' => 'in_progress',
            'changed_at' => Carbon::parse('2026-06-01 08:00'),
        ]);
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'in_progress', 'to_status' => 'waiting_review',
            'changed_at' => Carbon::parse('2026-06-01 10:00'),
        ]);

        // Klien minta revisi - konten "menunggu" 5 HARI di status revision
        // sebelum staf mulai kerja lagi (waktu tunggu klien, TIDAK boleh
        // ikut terhitung active production).
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'waiting_review', 'to_status' => 'revision',
            'changed_at' => Carbon::parse('2026-06-01 11:00'),
        ]);
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'revision', 'to_status' => 'in_progress',
            'changed_at' => Carbon::parse('2026-06-06 09:00'),
        ]);
        // Segmen kerja aktif kedua: 1 jam.
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'in_progress', 'to_status' => 'waiting_review',
            'changed_at' => Carbon::parse('2026-06-06 10:00'),
        ]);

        $breakdown = $this->service()->scoreProductionRole(
            $user->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'),
            collect([$item->id]), minSampleSize: 0
        );

        $medianHours = $breakdown->metrics['median_active_production_hours']['value'];

        // Total kerja aktif genuine = 2 jam + 1 jam = median dari [2, 1].
        // Kalau 5 hari waktu tunggu klien ikut terhitung, median akan
        // melonjak jadi puluhan/ratusan jam - test ini gagal kalau itu terjadi.
        $this->assertLessThan(24, $medianHours, 'Waktu tunggu klien (5 hari) tidak boleh ikut masuk active production duration.');
    }

    /** Koreksi: first handoff = in_progress -> waiting_review (BUKAN brief_ready -> in_progress). */
    public function test_first_handoff_is_in_progress_to_waiting_review_not_brief_ready_to_in_progress(): void
    {
        $item = ContentItem::factory()->create(['deadline_at' => Carbon::parse('2026-06-05 00:00')]);
        $user = User::factory()->create();

        // brief_ready -> in_progress terjadi SETELAH deadline (kalau ini
        // salah dianggap "handoff", hasilnya telat/on-time salah).
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'brief_ready', 'to_status' => 'in_progress',
            'changed_at' => Carbon::parse('2026-06-10 08:00'), // SETELAH deadline
        ]);
        // in_progress -> waiting_review (handoff SEBENARNYA) terjadi SEBELUM deadline.
        ContentStatusLog::factory()->create([
            'content_item_id' => $item->id, 'from_status' => 'in_progress', 'to_status' => 'waiting_review',
            'changed_at' => Carbon::parse('2026-06-04 08:00'), // SEBELUM deadline
        ]);

        $breakdown = $this->service()->scoreProductionRole(
            $user->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'),
            collect([$item->id]), minSampleSize: 0
        );

        $this->assertSame(100.0, $breakdown->metrics['first_handoff_on_time_rate']['value'], 'Handoff dinilai dari in_progress->waiting_review (sebelum deadline), bukan brief_ready->in_progress (setelah deadline).');
    }

    /** Client revision TIDAK menurunkan internal_revision_rate - hanya internal revision yang dihitung. */
    public function test_client_revision_is_separated_from_internal_revision_rate(): void
    {
        $client = Client::factory()->create();
        $itemWithClientRevisionOnly = ContentItem::factory()->create(['client_id' => $client->id]);
        $itemWithNoRevision = ContentItem::factory()->create(['client_id' => $client->id]);
        $user = User::factory()->create();

        ContentRevision::factory()->create([
            'content_item_id' => $itemWithClientRevisionOnly->id,
            'requested_by_user_id' => null,
            'requested_by_client_id' => $client->id,
            'created_at' => Carbon::parse('2026-06-15'),
        ]);

        $breakdown = $this->service()->scoreProductionRole(
            $user->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'),
            collect([$itemWithClientRevisionOnly->id, $itemWithNoRevision->id]), minSampleSize: 0
        );

        // internal_revision_rate disimpan sebagai (100 - rasio) - kalau
        // client revision ikut terhitung sebagai internal, nilainya akan
        // turun jadi 50. Harus tetap 100 (tidak ada internal revision sama
        // sekali di antara 2 item ini).
        $this->assertSame(100.0, $breakdown->metrics['internal_revision_rate']['value']);
    }

    /** Sekarang tambahkan internal revision - baru rate-nya turun. */
    public function test_internal_revision_lowers_the_inverse_rate_correctly(): void
    {
        $item = ContentItem::factory()->create();
        $otherItem = ContentItem::factory()->create();
        $user = User::factory()->create();
        $reviewer = User::factory()->create();

        ContentRevision::factory()->create([
            'content_item_id' => $item->id,
            'requested_by_user_id' => $reviewer->id,
            'requested_by_client_id' => null,
            'created_at' => Carbon::parse('2026-06-15'),
        ]);

        $breakdown = $this->service()->scoreProductionRole(
            $user->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'),
            collect([$item->id, $otherItem->id]), minSampleSize: 0
        );

        // 1 dari 2 item punya internal revision -> rasio revisi 50% ->
        // inverse rate = 50.
        $this->assertSame(50.0, $breakdown->metrics['internal_revision_rate']['value']);
    }

    /** Koreksi #5: internal revision DI LUAR periode KPI tidak ikut terhitung. */
    public function test_internal_revision_outside_period_is_not_counted(): void
    {
        $item = ContentItem::factory()->create();
        $otherItem = ContentItem::factory()->create();
        $user = User::factory()->create();
        $reviewer = User::factory()->create();

        // Revisi ini terjadi bulan JULI, sedangkan periode KPI yang diminta
        // adalah JUNI - tidak boleh ikut menurunkan rate periode Juni.
        ContentRevision::factory()->create([
            'content_item_id' => $item->id,
            'requested_by_user_id' => $reviewer->id,
            'requested_by_client_id' => null,
            'created_at' => Carbon::parse('2026-07-05'),
        ]);

        $breakdown = $this->service()->scoreProductionRole(
            $user->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'),
            collect([$item->id, $otherItem->id]), minSampleSize: 0
        );

        $this->assertSame(100.0, $breakdown->metrics['internal_revision_rate']['value'], 'Revisi di luar periode KPI tidak boleh ikut menurunkan rate periode ini.');
    }
}
