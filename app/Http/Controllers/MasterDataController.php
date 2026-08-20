<?php

namespace App\Http\Controllers;

use App\Models\ClientCategory;
use App\Models\ContentPillar;
use App\Models\ContentType;
use App\Models\Platform;
use Illuminate\Http\Request;

class MasterDataController extends Controller
{
    public const TYPES = [
        'content-pillar' => ContentPillar::class,
        'content-type' => ContentType::class,
        'platform' => Platform::class,
        'client-category' => ClientCategory::class,
    ];

    public function store(Request $request, string $type)
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        self::TYPES[$type]::create($validated);

        return back()->with('status', 'Data berhasil ditambahkan - langsung bisa dipilih di form terkait.');
    }

    public function destroy(string $type, int $id)
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);

        $model = self::TYPES[$type]::findOrFail($id);

        // Platform direferensikan 5 tabel berbeda (content_items,
        // content_publications, content_metrics, api_integrations,
        // analytics_sync_logs, audience_insights - semua RESTRICT tanpa
        // cascade), bukan cuma content_items - kalau cuma dicek satu,
        // platform yang sudah tidak dipakai di content item aktif tapi
        // masih punya riwayat publikasi/metrik lama bakal lolos guard ini
        // lalu gagal di level database (500 mentah, bukan pesan yang rapi).
        $inUse = match ($type) {
            'client-category' => $model->clients()->exists(),
            'platform' => $model->contentItems()->exists()
                || $model->publications()->exists()
                || $model->contentMetrics()->exists()
                || $model->apiIntegrations()->exists()
                || $model->analyticsSyncLogs()->exists()
                || $model->audienceInsights()->exists(),
            default => $model->contentItems()->exists(),
        };

        if ($inUse) {
            return back()->with('error', 'Tidak bisa dihapus, masih dipakai data lain.');
        }

        $model->delete();

        return back()->with('status', 'Data berhasil dihapus.');
    }
}