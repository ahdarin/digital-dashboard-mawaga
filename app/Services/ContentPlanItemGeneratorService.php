<?php

namespace App\Services;

use App\Models\ClientPackage;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Support\WorkflowTransitions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Generate slot content item kosong ("C1"/"D1" dst) begitu Content Plan
 * dibuat, sejumlah kuota paket aktif client - menggantikan form manual
 * "Tambah Konten" yang sudah dihapus. Item digenerate langsung berstatus
 * Draf (belum masuk workflow produksi) sampai copywriter melengkapi
 * briefnya dan SMO mengirimnya ke produksi (lihat WorkflowStatusService::
 * releaseToProduction()).
 */
class ContentPlanItemGeneratorService
{
    public function generate(ContentPlan $contentPlan, ClientPackage $package): Collection
    {
        $videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $desainType = ContentType::firstOrCreate(['name' => 'Desain']);

        // Placeholder - deadline_at wajib diisi (NOT NULL), tapi belum ada
        // tanggal kerja yang sebenarnya sampai SMO mengisi upload_deadline_at
        // pasca-approve (deadline_at otomatis dihitung ulang = upload - 2
        // hari saat itu). Akhir bulan plan dipakai sebagai placeholder
        // netral - item ini toh tidak muncul di kanban/kalender selama
        // masih Draf.
        $placeholderDeadline = Carbon::create($contentPlan->year, $contentPlan->month, 1)->endOfMonth()->endOfDay();

        $items = collect();

        for ($i = 1; $i <= max(0, (int) $package->monthly_content_quota); $i++) {
            $items->push($this->createSlot($contentPlan, "C{$i}", $videoType, $placeholderDeadline));
        }

        for ($i = 1; $i <= max(0, (int) $package->monthly_design_quota); $i++) {
            $items->push($this->createSlot($contentPlan, "D{$i}", $desainType, $placeholderDeadline));
        }

        return $items;
    }

    private function createSlot(ContentPlan $contentPlan, string $code, ContentType $type, Carbon $deadline): ContentItem
    {
        $item = ContentItem::create([
            'content_plan_id' => $contentPlan->id,
            'client_id' => $contentPlan->client_id,
            'content_type_id' => $type->id,
            'provisional_code' => $code,
            'title' => $code,
            'deadline_at' => $deadline,
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_pic_id' => null,
            'current_status' => WorkflowTransitions::DRAFT_STATUS,
            'is_overdue' => false,
        ]);

        return $item;
    }
}
