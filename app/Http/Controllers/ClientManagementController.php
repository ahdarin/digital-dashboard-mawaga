<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ClientManagementController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeManage();

        $search = $request->query('search');
        $status = $request->query('status', 'all');

        $clients = Client::query()
            ->with(['category', 'owner', 'activePackage'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('brand_name', 'like', "%{$search}%")
                        ->orWhereHas('owner', fn ($oq) => $oq->where('email', 'like', "%{$search}%"));
                });
            })
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('client-management.index', compact('clients', 'search', 'status'));
    }

    public function create()
    {
        $this->authorizeManage();

        $categories = ClientCategory::all();

        return view('client-management.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'brand_name' => 'required|string|max:255',
            'client_category_id' => 'required|exists:client_categories,id',
            'logo' => 'nullable|image|max:2048', // max 2MB
            'color' => 'nullable|string|max:7', 
            'owner_name' => 'required|string|max:255',
            'owner_email' => 'required|email|unique:users,email',
            'owner_phone' => 'required|string',
        ]);

        DB::transaction(function () use ($validated, $request) {
            $logoPath = $request->hasFile('logo')
                ? $request->file('logo')->store('client-logos', 'public')
                : null;

            $client = Client::create([
                'name' => $validated['name'],
                'brand_name' => $validated['brand_name'],
                'client_category_id' => $validated['client_category_id'],
                'logo_path' => $logoPath,
                'color' => $validated['color'] ?? null,
                'status' => 'active',
            ]);

            $ownerRole = Role::firstOrCreate(['name' => 'Client Owner']);

            User::create([
                'role_id' => $ownerRole->id,
                'client_id' => $client->id,
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'phone_number' => PhoneNumberNormalizer::normalize($validated['owner_phone']),
                'status' => 'invited',
            ]);
        });

        return redirect()->route('client-management.index')
            ->with('status', 'Client & akun Owner berhasil dibuat. Owner bisa login via WhatsApp menggunakan nomor yang didaftarkan.');
    }

    public function show(Client $client)
    {
        $this->authorizeManage();

        $client->load(['category', 'owner', 'activePackage', 'packages']);

        $recentContentItems = $client->contentItems()
            ->with(['contentType', 'workflow'])
            ->latest('created_at')
            ->take(10)
            ->get();

        $contentCount = $client->contentItems()->count();
        $planCount = $client->contentPlans()->count();

        return view('client-management.show', compact(
            'client', 'recentContentItems', 'contentCount', 'planCount'
        ));
    }

    public function edit(Client $client)
    {
        $this->authorizeManage();

        $categories = ClientCategory::all();
        $client->load('owner');

        return view('client-management.edit', compact('client', 'categories'));
    }

    public function update(Request $request, Client $client)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'brand_name' => 'required|string|max:255',
            'client_category_id' => 'required|exists:client_categories,id',
            'status' => 'required|in:active,past_due,paused',
            'logo' => 'nullable|image|max:2048',
            'remove_logo' => 'nullable|boolean',
            'color' => 'nullable|string|max:7',
            'owner_name' => 'nullable|string|max:255',
            'owner_email' => [
                'nullable', 'email',
                Rule::unique('users', 'email')->ignore($client->owner?->id),
            ],
            'owner_phone' => 'nullable|string',
        ]);

        DB::transaction(function () use ($validated, $client, $request) {
            $logoPath = $client->logo_path;

            if ($request->hasFile('logo')) {
                if ($client->logo_path) {
                    Storage::disk('public')->delete($client->logo_path);
                }
                $logoPath = $request->file('logo')->store('client-logos', 'public');
            } elseif ($request->boolean('remove_logo') && $client->logo_path) {
                Storage::disk('public')->delete($client->logo_path);
                $logoPath = null;
            }

            $client->update([
                'name' => $validated['name'],
                'brand_name' => $validated['brand_name'],
                'client_category_id' => $validated['client_category_id'],
                'status' => $validated['status'],
                'logo_path' => $logoPath,
                'color' => $validated['color'] ?? $client->color,
            ]);

            if ($client->owner && filled($validated['owner_name'] ?? null)) {
                $client->owner->update([
                    'name' => $validated['owner_name'],
                    'email' => $validated['owner_email'] ?? $client->owner->email,
                    'phone_number' => filled($validated['owner_phone'] ?? null)
                        ? PhoneNumberNormalizer::normalize($validated['owner_phone'])
                        : $client->owner->phone_number,
                ]);
            }
        });

        return redirect()->route('client-management.show', $client)
            ->with('status', 'Data client berhasil diperbarui.');
    }

    public function destroy(Client $client)
    {
        $this->authorizeManage();

        $hasHistory = $client->contentItems()->exists() || $client->contentPlans()->exists();

        if ($hasHistory) {
            $client->update(['status' => 'paused']);

            return redirect()->route('client-management.index')
                ->with('status', "{$client->brand_name} punya riwayat konten, jadi tidak dihapus permanen — status diubah jadi Paused.");
        }

        DB::transaction(function () use ($client) {
            if ($client->logo_path) {
                Storage::disk('public')->delete($client->logo_path);
            }
            $client->owner?->delete();
            $client->packages()->delete();
            $client->delete();
        });

        return redirect()->route('client-management.index')
            ->with('status', 'Client berhasil dihapus.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('client', 'manage'), 403);
    }
}