<?php

namespace App\Http\Controllers;

use App\Jobs\SyncInstagramAnalyticsJob;
use App\Jobs\SyncInstagramAudienceJob;
use App\Jobs\SyncTikTokAnalyticsJob;
use App\Models\AnalyticsSyncLog;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\PackageTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\PhoneNumberNormalizer;
use App\Services\PicReassignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
                        ->orWhereHas('owner', fn ($oq) => $oq->where('phone_number', 'like', "%{$search}%"));
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
        $packageTemplates = PackageTemplate::where('is_active', true)->orderBy('name')->get();

        return view('client-management.create', compact('categories', 'packageTemplates'));
    }

    public function store(Request $request)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'brand_name' => 'required|string|max:255',
            'client_category_id' => 'required|exists:client_categories,id',
            'logo' => 'nullable|image|max:2048', // max 2MB
            'asset_link' => 'nullable|url|max:255',
            'owner_name' => 'required|string|max:255',
            'owner_phone' => 'required|string',
            'package_template_id' => 'nullable|exists:package_templates,id',
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
                'asset_link' => $validated['asset_link'] ?? null,
                'status' => 'active',
            ]);

            $ownerRole = Role::firstOrCreate(['name' => 'Client Owner']);

            $owner = User::create([
                'client_id' => $client->id,
                'name' => $validated['owner_name'],
                'phone_number' => PhoneNumberNormalizer::normalize($validated['owner_phone']),
                'status' => 'invited',
                'role_id' => $ownerRole->id,
                'login_enabled' => true,
            ]);

            if (filled($validated['package_template_id'] ?? null)) {
                $this->assignPackage($client, PackageTemplate::find($validated['package_template_id']));
            }
        });

        return redirect()->route('client-management.index')
            ->with('status', 'Klien & akun Owner berhasil dibuat. Owner bisa login via WhatsApp menggunakan nomor yang didaftarkan.');
    }

    public function show(Client $client, PicReassignmentService $picReassignmentService)
    {
        // Otorisasi dipegang route middleware (permission:client,view +
        // client.scope:client,id) - dibuka ke semua role internal yang
        // di-assign ke client ini, bukan cuma client,manage seperti aksi
        // lain di controller ini. Tombol ubah data di-gate di view lewat
        // hasPermissionTo('client','manage').

        $client->load(['category', 'owner', 'activePackage', 'packages', 'assignedUsers.role']);

        $recentContentItems = $client->contentItems()
            ->with(['contentType', 'workflow'])
            ->latest('created_at')
            ->take(10)
            ->get();

        $contentCount = $client->contentItems()->count();
        $planCount = $client->contentPlans()->count();
        $packageTemplates = PackageTemplate::where('is_active', true)->orderBy('name')->get();
        $staffOptions = User::whereNull('client_id')->where('status', 'active')->orderBy('name')->get();

        // Berapa konten aktif per PIC yang di-assign ke client ini - dipakai
        // buat memutuskan apakah dia bisa dikeluarkan dari roster langsung
        // atau butuh reassignment dulu (lihat "Keluarkan dari PIC").
        $picActiveCounts = $client->assignedUsers->mapWithKeys(
            fn (User $staff) => [$staff->id => $picReassignmentService->countActive($staff, $client->id)]
        );

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

        // Integrasi TikTok client ini - MIRROR blok Instagram di atas persis,
        // TANPA Audience Insights (TikTok Display API standar tidak
        // menyediakan demografis - lihat docs/TIKTOK_INTEGRATION.md).
        $tiktokIntegration = $client->apiIntegrations()
            ->whereHas('platform', fn ($q) => $q->where('name', 'TikTok'))
            ->first();
        $tiktokOauthConfigured = filled(config('services.tiktok.client_key')) && filled(config('services.tiktok.client_secret'));

        $tiktokLastSyncLog = $tiktokIntegration
            ? AnalyticsSyncLog::where('api_integration_id', $tiktokIntegration->id)
                ->where('source_type', 'api_sync')->latest()->first()
            : null;
        $tiktokSyncing = false;
        if ($tiktokIntegration) {
            $lock = Cache::lock(SyncTikTokAnalyticsJob::cacheLockKey($tiktokIntegration->id), 10);
            if ($lock->get()) {
                $lock->release();
            } else {
                $tiktokSyncing = true;
            }
        }

        return view('client-management.show', compact(
            'client', 'recentContentItems', 'contentCount', 'planCount', 'packageTemplates', 'staffOptions', 'picActiveCounts',
            'instagramIntegration', 'instagramOauthConfigured',
            'instagramLastSyncLog', 'instagramSyncing',
            'instagramAudienceLastSyncLog', 'instagramAudienceLastSuccessAt', 'instagramAudienceSyncing',
            'tiktokIntegration', 'tiktokOauthConfigured', 'tiktokLastSyncLog', 'tiktokSyncing'
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
            'asset_link' => 'nullable|url|max:255',
            'owner_name' => $hasOwner ? 'nullable|string|max:255' : 'nullable|required_with:owner_phone|string|max:255',
            'owner_phone' => $hasOwner ? 'nullable|string' : 'nullable|required_with:owner_name|string',
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
                'asset_link' => $validated['asset_link'] ?? null,
            ]);

            if ($client->owner && filled($validated['owner_name'] ?? null)) {
                $client->owner->update([
                    'name' => $validated['owner_name'],
                    'phone_number' => filled($validated['owner_phone'] ?? null)
                        ? PhoneNumberNormalizer::normalize($validated['owner_phone'])
                        : $client->owner->phone_number,
                ]);
            } elseif (! $client->owner && filled($validated['owner_name'] ?? null)) {
                $ownerRole = Role::firstOrCreate(['name' => 'Client Owner']);

                $owner = User::create([
                    'client_id' => $client->id,
                    'name' => $validated['owner_name'],
                    'phone_number' => PhoneNumberNormalizer::normalize($validated['owner_phone']),
                    'status' => 'invited',
                    'role_id' => $ownerRole->id,
                    'login_enabled' => true,
                ]);
            }
        });

        return redirect()->route('client-management.show', $client)
            ->with('status', 'Data klien berhasil diperbarui.');
    }

    public function updatePackage(Request $request, Client $client)
    {
        $this->authorizeManage();

        $validated = $request->validate([
            'package_template_id' => 'required|exists:package_templates,id',
        ]);

        $template = PackageTemplate::findOrFail($validated['package_template_id']);

        DB::transaction(fn () => $this->assignPackage($client, $template));

        return redirect()->route('client-management.show', $client)
            ->with('status', "Paket {$client->brand_name} berhasil diperbarui.");
    }

    /**
     * Assign paket ke client - kalau sudah ada paket aktif, ditandai
     * 'ended' (bukan dihapus, biar riwayat kuota per periode tetap
     * kelihatan), lalu dibuatkan ClientPackage baru sebagai snapshot
     * (nama & kuota disalin dari template saat itu juga, supaya perubahan
     * template di kemudian hari nggak diam-diam mengubah kuota client yang
     * sudah berjalan).
     */
    private function assignPackage(Client $client, PackageTemplate $template): void
    {
        $client->activePackage?->update(['status' => 'ended', 'end_date' => now()]);

        ClientPackage::create([
            'client_id' => $client->id,
            'package_template_id' => $template->id,
            'package_name_snapshot' => $template->name,
            'monthly_content_quota' => $template->monthly_content_quota,
            'monthly_design_quota' => $template->monthly_design_quota,
            'start_date' => now(),
            'status' => 'active',
        ]);
    }

    public function destroy(Client $client)
    {
        $this->authorizeManage();

        $hasHistory = $client->contentItems()->exists() || $client->contentPlans()->exists();

        if ($hasHistory) {
            $client->update(['status' => 'paused']);

            return redirect()->route('client-management.index')
                ->with('status', "{$client->brand_name} punya riwayat konten, jadi tidak dihapus permanen — status diubah jadi Dijeda.");
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
            ->with('status', 'Klien berhasil dihapus.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionTo('client', 'manage'), 403);
    }
}