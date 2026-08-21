<?php

namespace App\Http\Controllers;

use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncInstagramAudienceJob;
use App\Models\AnalyticsSyncLog;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            'asset_link' => 'nullable|url|max:255',
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
                'asset_link' => $validated['asset_link'] ?? null,
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

        // Integrasi Instagram client ini (hasil OAuth connect) - dipakai
        // buat card "Integrasi Analytics" di halaman ini.
        $instagramIntegration = $client->apiIntegrations()
            ->whereHas('platform', fn ($q) => $q->where('name', 'Instagram'))
            ->first();
        $instagramOauthConfigured = filled(config('services.instagram.client_id')) && filled(config('services.instagram.client_secret'));

        // $instagramLastSyncLog buat nampilin HASIL sync terakhir (Synced/
        // Failed + pesan) - AnalyticsSyncLog tetap sumber yang benar buat
        // ini karena selalu ada begitu minimal 1 sync pernah selesai.
        // DIBATASI source_type='api_sync' (bukan latest() polos) - sejak
        // audience sync juga nulis ke tabel yang sama (source_type=
        // 'audience_api_sync'), latest() tanpa scope bisa kejaring log
        // audience dan salah ditampilkan sebagai status Content Analytics.
        //
        // $instagramSyncing ("lagi berjalan SEKARANG") SENGAJA BUKAN dari
        // AnalyticsSyncLog::where(status,pending) - log itu sekarang cuma
        // dibuat DI DALAM Job setelah lock didapat (lihat docblock
        // SyncInstagramAnalyticsJob soal fix stale pending log), jadi ada
        // celah singkat antara dispatch dan job mulai di mana belum ada
        // log 'pending' sama sekali walau syncnya beneran lagi antre/jalan.
        // Sumber yang benar: peek non-invasif ke lock yang sama dipakai
        // WithoutOverlapping - konsisten dengan cek yang sama di controller.
        $instagramLastSyncLog = $instagramIntegration
            ? AnalyticsSyncLog::where('api_integration_id', $instagramIntegration->id)
                ->where('source_type', 'api_sync')->latest()->first()
            : null;
        $instagramSyncing = false;
        if ($instagramIntegration) {
            $lock = Cache::lock(SyncInstagramAnalyticsJob::cacheLockKey($instagramIntegration->id), 10);
            if ($lock->get()) {
                $lock->release();
            } else {
                $instagramSyncing = true;
            }
        }

        // Audience Insights - card & lock TERPISAH dari Content Analytics
        // di atas (job beda, lock key beda - lihat SyncInstagramAudienceJob).
        // $instagramAudienceLastSyncLog = percobaan TERAKHIR (apapun
        // hasilnya) - dipakai buat badge status (Synced/Syncing/Failed).
        // $instagramAudienceLastSuccessAt = kapan TERAKHIR KALI beneran
        // berhasil nulis (dari AudienceInsight->updated_at, bukan log) -
        // dipakai buat teks "Last Audience Sync", sesuai spek: harus dari
        // sync SUKSES terakhir, bukan percobaan terakhir yang mungkin gagal.
        $instagramAudienceLastSyncLog = $instagramIntegration
            ? AnalyticsSyncLog::where('api_integration_id', $instagramIntegration->id)
                ->where('source_type', 'audience_api_sync')->latest()->first()
            : null;
        $instagramAudienceLastSuccessAt = $instagramIntegration
            ? AudienceInsight::where('client_id', $client->id)
                ->where('platform_id', $instagramIntegration->platform_id)
                ->apiSourced()->max('updated_at')
            : null;
        $instagramAudienceSyncing = false;
        if ($instagramIntegration) {
            $lock = Cache::lock(SyncInstagramAudienceJob::cacheLockKey($instagramIntegration->id), 10);
            if ($lock->get()) {
                $lock->release();
            } else {
                $instagramAudienceSyncing = true;
            }
        }

        return view('client-management.show', compact(
            'client', 'recentContentItems', 'contentCount', 'planCount',
            'instagramIntegration', 'instagramOauthConfigured',
            'instagramLastSyncLog', 'instagramSyncing',
            'instagramAudienceLastSyncLog', 'instagramAudienceLastSuccessAt', 'instagramAudienceSyncing'
        ));
    }

    /**
     * Tombol "Sync Audience Insights" di card Instagram Integration -
     * mirror persis pola SettingsController::syncInstagram() (dispatch
     * job lalu redirect langsung, TIDAK menahan request nunggu Instagram
     * API), tapi scoped ke {client} route-model-binding (bukan client_id
     * dari body) - integration SELALU diambil lewat $client->apiIntegrations(),
     * jadi ID integration milik client lain nggak mungkin kepakai walau
     * seseorang coba nebak/kirim ID lain (tidak ada input ID integration
     * sama sekali di flow ini).
     *
     * Reuse SyncInstagramAudienceJob apa adanya (Langkah 2, "jangan buat
     * Job baru") - TIDAK PERNAH backfill 180 hari dari tombol manual ini
     * (Langkah 11: "repeated manual sync -> current daily data saja").
     * Backfill tetap cuma lewat --backfill CLI eksplisit, dipakai sekali
     * pas integration baru connect.
     */
    public function syncInstagramAudience(Client $client)
    {
        $this->authorizeManage();

        $integration = $client->apiIntegrations()
            ->whereHas('platform', fn ($q) => $q->where('name', 'Instagram'))
            ->first();

        if (! $integration || ! filled($integration->access_token)) {
            return back()->with('import_error', 'Client ini belum connect Instagram (OAuth). Hubungkan dulu lewat tombol "Connect Instagram".');
        }

        // Peek non-invasif ke lock yang sama dipakai WithoutOverlapping di
        // SyncInstagramAudienceJob - kalau lagi dipegang, JANGAN dispatch
        // job kedua (middleware Job tetap source-of-truth concurrency,
        // ini cuma defense-in-depth + feedback instan ke user).
        $lock = Cache::lock(SyncInstagramAudienceJob::cacheLockKey($integration->id), 10);
        if (! $lock->get()) {
            return back()->with('import_error', 'Sinkronisasi Audience Instagram untuk akun ini sedang berjalan.');
        }
        $lock->release();

        SyncInstagramAudienceJob::dispatch($integration->id, auth()->id());

        return back()->with('import_success', 'Sinkronisasi Audience Instagram dimulai.');
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

        // Kalau client belum punya owner, ketiga field owner jadi
        // "all-or-nothing" (required bareng-bareng) - biar bisa langsung
        // dibuatkan akun ownernya dari sini juga, bukan cuma edit yang
        // sudah ada.
        $hasOwner = (bool) $client->owner;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'brand_name' => 'required|string|max:255',
            'client_category_id' => 'required|exists:client_categories,id',
            'status' => 'required|in:active,past_due,paused',
            'logo' => 'nullable|image|max:2048',
            'remove_logo' => 'nullable|boolean',
            'color' => 'nullable|string|max:7',
            'asset_link' => 'nullable|url|max:255',
            'owner_name' => $hasOwner ? 'nullable|string|max:255' : 'nullable|required_with:owner_email,owner_phone|string|max:255',
            'owner_email' => [
                $hasOwner ? 'nullable' : 'nullable|required_with:owner_name,owner_phone',
                'email',
                Rule::unique('users', 'email')->ignore($client->owner?->id),
            ],
            'owner_phone' => $hasOwner ? 'nullable|string' : 'nullable|required_with:owner_name,owner_email|string',
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
                'asset_link' => $validated['asset_link'] ?? null,
            ]);

            if ($client->owner && filled($validated['owner_name'] ?? null)) {
                $client->owner->update([
                    'name' => $validated['owner_name'],
                    'email' => $validated['owner_email'] ?? $client->owner->email,
                    'phone_number' => filled($validated['owner_phone'] ?? null)
                        ? PhoneNumberNormalizer::normalize($validated['owner_phone'])
                        : $client->owner->phone_number,
                ]);
            } elseif (! $client->owner && filled($validated['owner_name'] ?? null)) {
                $ownerRole = Role::firstOrCreate(['name' => 'Client Owner']);

                User::create([
                    'role_id' => $ownerRole->id,
                    'client_id' => $client->id,
                    'name' => $validated['owner_name'],
                    'email' => $validated['owner_email'],
                    'phone_number' => PhoneNumberNormalizer::normalize($validated['owner_phone']),
                    'status' => 'invited',
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
            // Hapus SEMUA user milik client ini (owner + staf tambahan), bukan cuma
            // owner - kalau tidak, user selain owner jadi punya client_id=NULL lewat
            // FK ON DELETE SET NULL, yang di sistem ini berarti "staf internal".
            $client->users()->delete();
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