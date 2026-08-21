<?php

namespace App\Http\Controllers;

use App\Models\PackageTemplate;
use Illuminate\Http\Request;

class PackageTemplateController extends Controller
{
    public function store(Request $request)
    {
        $this->authorizeManage();

        $validated = $this->validated($request);

        PackageTemplate::create($validated);

        return back()->with('status', 'Paket berhasil ditambahkan.');
    }

    public function update(Request $request, PackageTemplate $packageTemplate)
    {
        $this->authorizeManage();

        $validated = $this->validated($request);

        $packageTemplate->update($validated);

        return back()->with('status', 'Paket berhasil diperbarui.');
    }

    public function destroy(PackageTemplate $packageTemplate)
    {
        $this->authorizeManage();

        if ($packageTemplate->clientPackages()->exists()) {
            return back()->with('error', 'Tidak bisa dihapus, masih dipakai client.');
        }

        $packageTemplate->delete();

        return back()->with('status', 'Paket berhasil dihapus.');
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'monthly_content_quota' => 'required|integer|min:0',
            'monthly_design_quota' => 'required|integer|min:0',
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('master_data', 'manage'), 403);
    }
}
