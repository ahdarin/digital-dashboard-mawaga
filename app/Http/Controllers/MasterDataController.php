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

    public function index(Request $request)
    {
        $tab = $request->input('tab', 'content-pillar');
        abort_unless(array_key_exists($tab, self::TYPES), 404);

        $search = $request->input('search');

        $items = self::TYPES[$tab]::query()
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->get();

        return view('master-data.index', compact('tab', 'items', 'search'));
    }

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

        $inUse = $type === 'client-category'
            ? $model->clients()->exists()
            : $model->contentItems()->exists();

        if ($inUse) {
            return back()->with('error', 'Tidak bisa dihapus, masih dipakai data lain.');
        }

        $model->delete();

        return back()->with('status', 'Data berhasil dihapus.');
    }
}