<?php

namespace Database\Seeders;

use App\Models\AiStrategyInsight;
use App\Models\AiStrategyMessage;
use App\Models\AnalyticsSyncLog;
use App\Models\Attendance;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ClientPackage;
use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentMetric;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentPlanStatusLog;
use App\Models\ContentPublication;
use App\Models\ContentRevision;
use App\Models\ContentStatusLog;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\DelayRiskScore;
use App\Models\Notification;
use App\Models\PackageTemplate;
use App\Models\PerformanceAnomaly;
use App\Models\Pin;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use App\Models\UserClientAssignment;
use App\Services\AiStrategyService;
use App\Services\AttendanceService;
use App\Support\WorkflowTransitions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DATA DOKUMENTASI - BUKAN UNTUK PRODUCTION.
 *
 * Dataset khusus screenshot Buku Panduan Pengguna 523 Studio Platform.
 * SELURUH isinya fiktif: tidak ada nama client nyata, email/nomor telepon
 * orang nyata, API token, maupun URL posting media sosial nyata.
 *
 * Beda dari DemoSeeder:
 * - DemoSeeder = data eksplorasi/testing, sengaja acak & tidak idempoten.
 * - DocumentationSeeder = data KURATIF: tiap client, user, dan content item
 *   punya alasan kenapa dia ada (halaman/kondisi UI apa yang mau difoto),
 *   angkanya deterministik, dan seeder-nya aman dijalankan berulang.
 *
 * Jalankan eksplisit (TIDAK pernah dipanggil DatabaseSeeder):
 *   php artisan db:seed --class=DocumentationSeeder
 *
 * Rujukan lengkap dataset & rekomendasi screenshot: docs/DOCUMENTATION_DATASET.md
 *
 * CATATAN DESAIN PENTING
 * ----------------------
 * 1. TIDAK membuat ApiIntegration sama sekali. Baris ApiIntegration tanpa
 *    OAuth beneran bikin kartu Instagram/TikTok di Pengaturan tampil
 *    "Terhubung" padahal tidak - persis bug yang pernah diaudit & datanya
 *    dibersihkan (lihat catatan di DemoSeeder). Untuk buku, kondisi
 *    "Terhubung" HARUS difoto dari akun tester sungguhan.
 * 2. AudienceInsight & ContentMetric diberi provenance CSV/import, BUKAN
 *    instagram_api/tiktok_api - datanya memang tidak berasal dari API
 *    manapun, jadi menandainya sebagai hasil API akan menyesatkan.
 * 3. Delay Risk di-seed langsung (tanpa memanggil script Python ML), dan
 *    ContentWorkflow dibuat lewat withoutEvents() supaya ContentWorkflowObserver
 *    tidak ikut men-spawn proses prediksi saat seeding.
 * 4. Semua angka pakai PRNG deterministik internal (bukan rand()/mt_rand()),
 *    jadi dua kali seed menghasilkan angka yang sama - screenshot lama tidak
 *    jadi basi cuma gara-gara seeder dijalankan ulang.
 */
class DocumentationSeeder extends Seeder
{
    /**
     * Penanda provenance sekaligus jangkar idempotency. Baris ContentItem
     * dikunci lewat unique (import_source, external_reference) yang memang
     * sudah ada di schema, jadi seeder ini tidak perlu kolom/tabel baru.
     */
    public const MARKER = 'documentation_seeder';

    private const EMAIL_DOMAIN = '@example.test';

    private Carbon $now;
    private int $randState = 5230825;

    /** @var \Illuminate\Support\Collection<string, User> */
    private $users;
    /** @var \Illuminate\Support\Collection<string, Client> */
    private $clients;
    /** @var \Illuminate\Support\Collection<string, ContentPlan> */
    private $plans;
    /** @var \Illuminate\Support\Collection<string, ContentItem> */
    private $items;

    private array $pillars = [];
    private array $contentTypes = [];
    private array $platforms = [];

    public function run(): void
    {
        $this->guardEnvironment();

        $this->now = Carbon::now();
        $this->users = collect();
        $this->clients = collect();
        $this->plans = collect();
        $this->items = collect();

        $this->command?->warn('DOCUMENTATION DATA - NOT FOR PRODUCTION');
        $this->command?->info('Seluruh data yang dibuat seeder ini fiktif dan hanya untuk screenshot Buku Panduan.');

        $this->seedMasterData();
        $this->seedUsers();
        $this->seedClients();
        $this->purgePreviousRun();
        $this->seedAssignments();
        $this->seedPackages();
        $this->seedPlans();
        $this->seedContentItems();
        $this->seedBriefs();
        $this->seedRevisions();
        $this->seedPublications();
        $this->seedDelayRisk();
        $this->seedMetrics();
        $this->seedAudience();
        $this->seedSyncLogs();
        $this->seedAiStrategies();
        $this->seedAttendance();
        $this->seedNotifications();
        $this->seedPins();

        $this->report();
    }

    // =================================================================
    // Guard & housekeeping
    // =================================================================

    /**
     * Production dijaga dua lapis: environment Laravel DAN nama database -
     * environment bisa saja salah set di server, tapi database bernama
     * "production"/"prod" hampir pasti bukan tempat data fiktif.
     */
    private function guardEnvironment(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException(
                'DocumentationSeeder menolak berjalan di environment production. '
                .'Seeder ini membuat client/user/konten FIKTIF untuk screenshot buku panduan.'
            );
        }

        $database = (string) DB::connection()->getDatabaseName();
        if (preg_match('/(^|[_-])(prod|production|live)([_-]|$)/i', $database)) {
            throw new \RuntimeException(
                "DocumentationSeeder menolak berjalan di database \"{$database}\" - namanya terbaca sebagai database production."
            );
        }
    }

    /**
     * xorshift32 deterministik. Sengaja TIDAK memakai rand()/mt_rand() global
     * supaya hasil seeder tidak berubah kalau ada kode lain yang kebetulan
     * ikut menarik angka acak dari generator yang sama.
     */
    private function rand(int $min, int $max): int
    {
        $x = $this->randState;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->randState = $x & 0xFFFFFFFF;

        return $min + ($this->randState % max(1, $max - $min + 1));
    }

    /**
     * Buang HANYA baris turunan milik run sebelumnya. ContentItem, Client,
     * User, dan ContentPlan-nya sendiri TIDAK dihapus (di-updateOrCreate)
     * supaya id-nya stabil - URL screenshot seperti /content-items/12 tetap
     * menunjuk konten yang sama setelah seeder dijalankan ulang.
     */
    private function purgePreviousRun(): void
    {
        $clientIds = $this->clients->pluck('id')->all();
        $userIds = $this->users->pluck('id')->all();

        $itemIds = ContentItem::withTrashed()
            ->where('import_source', self::MARKER)
            ->pluck('id')
            ->all();

        $planIds = empty($clientIds) ? [] : ContentPlan::whereIn('client_id', $clientIds)->pluck('id')->all();

        if (! empty($itemIds)) {
            // ai_strategy_insight_id dilepas duluan - kalau tidak, penghapusan
            // AiStrategyInsight di bawah kena FK constraint.
            ContentItem::withTrashed()->whereIn('id', $itemIds)->update(['ai_strategy_insight_id' => null]);

            DelayRiskScore::whereIn('content_item_id', $itemIds)->delete();
            PerformanceAnomaly::whereIn('content_item_id', $itemIds)->delete();
            ContentMetric::whereIn('content_item_id', $itemIds)->delete();
            ContentPublication::whereIn('content_item_id', $itemIds)->delete();
            ContentRevision::whereIn('content_item_id', $itemIds)->delete();
            ContentStatusLog::whereIn('content_item_id', $itemIds)->delete();
            ContentItemAssignment::whereIn('content_item_id', $itemIds)->delete();
            ContentBriefDraft::whereIn('content_item_id', $itemIds)->delete();
            ContentWorkflow::whereIn('content_item_id', $itemIds)->delete();
        }

        if (! empty($planIds)) {
            ContentPlanStatusLog::whereIn('content_plan_id', $planIds)->delete();
        }

        if (! empty($clientIds)) {
            $insightIds = AiStrategyInsight::whereIn('client_id', $clientIds)->pluck('id')->all();
            if (! empty($insightIds)) {
                AiStrategyMessage::whereIn('ai_strategy_insight_id', $insightIds)->delete();
                AiStrategyInsight::whereIn('id', $insightIds)->delete();
            }

            ContentMetric::whereIn('client_id', $clientIds)->delete();
            AnalyticsSyncLog::whereIn('client_id', $clientIds)->delete();
            AudienceInsight::whereIn('client_id', $clientIds)->delete();
        }

        if (! empty($userIds)) {
            Notification::whereIn('user_id', $userIds)->delete();
            Pin::whereIn('user_id', $userIds)->delete();
        }
    }

    // =================================================================
    // 1. Master data
    // =================================================================

    private function seedMasterData(): void
    {
        // Data pilihan (pillar/type/platform/kategori/paket) adalah referensi
        // resmi aplikasi, bukan data dokumentasi - dipakai apa adanya dari
        // MasterDataSeeder, TIDAK ditambah varian baru dari sini supaya tab
        // Data Pilihan tidak kotor oleh entri khusus buku.
        $this->call(MasterDataSeeder::class);

        $this->pillars = ContentPillar::pluck('id', 'name')->all();
        $this->contentTypes = ContentType::pluck('id', 'name')->all();
        $this->platforms = Platform::pluck('id', 'name')->all();
    }

    // =================================================================
    // 2. User internal
    // =================================================================

    private function seedUsers(): void
    {
        // Role & permission adalah bootstrap resmi aplikasi, bukan data
        // dokumentasi - seeder ini TIDAK memanggil RoleSeeder sendiri karena
        // RoleSeeder juga membuat akun CEO bootstrap dengan email asli, dan
        // itu tidak boleh ikut masuk lewat jalur "data untuk buku panduan".
        $expected = ['CEO', 'Manager', 'SMO', 'Copywriter', 'Content Creator', 'Desain Grafis'];
        $missing = array_diff($expected, Role::whereIn('name', $expected)->pluck('name')->all());

        if (! empty($missing)) {
            throw new \RuntimeException(
                'Role belum tersedia ('.implode(', ', $missing).'). '
                .'Jalankan `php artisan db:seed` lebih dulu untuk memasang role & permission dasar, baru DocumentationSeeder.'
            );
        }

        // 'login' = punya akses dashboard. Bayu sengaja false: itu kondisi
        // nyata "staf ada di roster tapi belum diberi akses login", yang di
        // Kelola Pengguna tampil sebagai badge "belum memiliki akses
        // dashboard" - salah satu state yang harus ada di buku.
        $defs = [
            ['key' => 'ceo',        'name' => 'Ardi Pratama',  'roles' => ['CEO'],                        'login' => true],
            ['key' => 'manager',    'name' => 'Nadia Putri',   'roles' => ['Manager'],                    'login' => true],
            ['key' => 'smo',        'name' => 'Raka Mahendra', 'roles' => ['SMO'],                        'login' => true],
            ['key' => 'copywriter', 'name' => 'Siti Rahma',    'roles' => ['Copywriter'],                 'login' => true],
            ['key' => 'creator',    'name' => 'Dimas Ardi',    'roles' => ['Content Creator'],            'login' => true],
            ['key' => 'designer',   'name' => 'Sarah Amelia',  'roles' => ['Desain Grafis'],              'login' => true],
            ['key' => 'designer2',  'name' => 'Lina Kartika',  'roles' => ['Desain Grafis', 'Copywriter'], 'login' => true],
            ['key' => 'designer3',  'name' => 'Bayu Saputra',  'roles' => ['Desain Grafis'],              'login' => false],
        ];

        foreach ($defs as $def) {
            $slug = str_replace(' ', '.', mb_strtolower($def['name']));

            $user = User::updateOrCreate(
                ['email' => $slug.self::EMAIL_DOMAIN],
                [
                    'name' => $def['name'],
                    'status' => 'active',
                    'login_enabled' => $def['login'],
                    'source' => self::MARKER,
                    'external_reference' => 'DOC:user:'.$def['key'],
                ]
            );

            // Role diambil dari RoleSeeder (tidak pernah membuat role baru) -
            // syncWithoutDetaching supaya role lain yang mungkin diberikan
            // manual saat uji coba tidak ikut terhapus.
            $roleIds = Role::whereIn('name', $def['roles'])->pluck('id')->all();
            $user->roles()->syncWithoutDetaching($roleIds);

            $this->users->put($def['key'], $user);
        }
    }

    // =================================================================
    // 3. Client
    // =================================================================

    private function seedClients(): void
    {
        $categories = ClientCategory::pluck('id', 'name');

        // Kategori dipilih dari master data yang memang ada (UMKM/Startup/
        // Korporat/Retail) - tidak menambah kategori baru cuma demi label
        // "Education"/"Property" di dataset dokumentasi.
        $defs = [
            [
                'key' => 'kopi', 'name' => 'Kopi Senja', 'brand' => 'Kopi Senja',
                'category' => 'UMKM', 'color' => '#7A4B2A',
                'asset' => 'https://example.com/aset/kopi-senja',
            ],
            [
                'key' => 'nusa', 'name' => 'Nusa Apparel', 'brand' => 'Nusa Apparel',
                'category' => 'Retail', 'color' => '#2F4858',
                'asset' => 'https://example.com/aset/nusa-apparel',
            ],
            [
                'key' => 'ruang', 'name' => 'Ruang Belajar', 'brand' => 'Ruang Belajar',
                'category' => 'Startup', 'color' => '#1F6F5C',
                'asset' => 'https://example.com/aset/ruang-belajar',
            ],
            [
                // Client "baru onboard": belum ada performa, belum ada akun
                // sosial tersambung - dipakai buat memotret empty state.
                'key' => 'sora', 'name' => 'Sora Residence', 'brand' => 'Sora Residence',
                'category' => 'Korporat', 'color' => '#8A6D3B',
                'asset' => null,
            ],
        ];

        foreach ($defs as $def) {
            // portal_token TIDAK pernah ikut ditulis di sini - dibuat sekali
            // oleh Client::booted() saat record pertama kali dibuat, lalu
            // dibiarkan apa adanya di run berikutnya.
            $client = Client::updateOrCreate(
                ['name' => $def['name']],
                [
                    'client_category_id' => $categories[$def['category']] ?? $categories->first(),
                    'brand_name' => $def['brand'],
                    'status' => 'active',
                    'color' => $def['color'],
                    'asset_link' => $def['asset'],
                    'portal_access_enabled' => true,
                ]
            );

            $this->clients->put($def['key'], $client);
        }
    }

    // =================================================================
    // 4. Penugasan tim ke client
    // =================================================================

    private function seedAssignments(): void
    {
        // CEO & Manager sengaja TIDAK di-assign: User::canSeeAllClients()
        // sudah memberi mereka visibility ke semua client, jadi baris
        // assignment untuk mereka cuma noise yang tidak mengubah apa pun.
        //
        // Sora Residence sengaja tanpa satu pun PIC - itu bagian dari
        // kondisi "client baru, belum ada tim" yang perlu difoto.
        $map = [
            'smo' => ['kopi', 'nusa'],
            'copywriter' => ['kopi', 'ruang'],
            'creator' => ['kopi', 'nusa'],
            'designer' => ['kopi', 'ruang'],
            'designer2' => ['nusa', 'ruang'],
            'designer3' => ['nusa'],
        ];

        foreach ($map as $userKey => $clientKeys) {
            foreach ($clientKeys as $clientKey) {
                UserClientAssignment::firstOrCreate([
                    'user_id' => $this->users[$userKey]->id,
                    'client_id' => $this->clients[$clientKey]->id,
                ]);
            }
        }
    }

    // =================================================================
    // 5. Paket client
    // =================================================================

    private function seedPackages(): void
    {
        // Kuota disalin PERSIS dari PackageTemplate resmi (snapshot pattern
        // di migration client_packages) - bukan angka bebas, supaya kartu
        // "Paket Aktif" di detail client konsisten dengan daftar paket di
        // Pengaturan > Data Pilihan > Paket.
        $map = [
            'kopi' => 'Paket Growth',
            'nusa' => 'Paket Growth',
            'ruang' => 'Paket Starter',
            'sora' => 'Paket Starter',
        ];

        foreach ($map as $clientKey => $templateName) {
            $template = PackageTemplate::where('name', $templateName)->first();
            if (! $template) {
                continue;
            }

            ClientPackage::updateOrCreate(
                ['client_id' => $this->clients[$clientKey]->id, 'status' => 'active'],
                [
                    'package_template_id' => $template->id,
                    'package_name_snapshot' => $template->name,
                    'monthly_content_quota' => $template->monthly_content_quota,
                    'monthly_design_quota' => $template->monthly_design_quota,
                    'start_date' => $this->now->copy()->subMonthsNoOverflow(3)->startOfMonth(),
                    'end_date' => null,
                ]
            );
        }
    }

    // =================================================================
    // 6. Rencana Konten
    // =================================================================

    /**
     * Plan dibuat dari bulan-bulan yang benar-benar dipakai content item
     * (lihat itemDefinitions()), bukan daftar tetap - jadi jumlahnya ikut
     * bergeser mengikuti kapan seeder dijalankan, tanpa pernah meninggalkan
     * content item yatim tanpa rencana.
     */
    private function seedPlans(): void
    {
        $needed = collect();
        foreach ($this->itemDefinitions() as $def) {
            $deadline = $this->deadlineFor($def);
            $needed->push([
                'client' => $def['client'],
                'month' => (int) $deadline->month,
                'year' => (int) $deadline->year,
            ]);
        }

        $currentMonth = $this->now->copy()->startOfMonth();

        foreach ($needed->unique(fn ($n) => $n['client'].'-'.$n['year'].'-'.$n['month']) as $need) {
            $client = $this->clients[$need['client']];
            $planMonth = Carbon::create($need['year'], $need['month'], 1);

            $status = $this->planStatusFor($need['client'], $planMonth, $currentMonth);

            $package = ClientPackage::where('client_id', $client->id)->where('status', 'active')->first();

            $plan = ContentPlan::updateOrCreate(
                ['client_id' => $client->id, 'month' => $need['month'], 'year' => $need['year']],
                [
                    'client_package_id' => $package?->id,
                    'created_by' => $this->users['copywriter']->id,
                    'approved_by' => $status === 'approved' ? $this->users['manager']->id : null,
                    'status' => $status,
                ]
            );

            $this->plans->put($need['client'].'-'.$need['year'].'-'.$need['month'], $plan);

            $this->seedPlanHistory($plan, $need['client'], $planMonth, $currentMonth, $status);
        }
    }

    private function planStatusFor(string $clientKey, Carbon $planMonth, Carbon $currentMonth): string
    {
        if ($planMonth->lt($currentMonth)) {
            return 'approved';
        }

        if ($planMonth->eq($currentMonth)) {
            // Sora Residence masih menyusun rencana pertamanya - jadi contoh
            // rencana berstatus Draf pada client yang memang baru mulai.
            return $clientKey === 'sora' ? 'draft' : 'approved';
        }

        // Bulan depan: satu rencana Draf (tombol "Ajukan Rencana") dan satu
        // rencana Menunggu Persetujuan (tombol "Setujui"/"Tolak") - dua
        // kondisi yang wajib ada screenshot-nya di buku.
        return match ($clientKey) {
            'nusa' => 'pending',
            default => 'draft',
        };
    }

    /**
     * Riwayat Keputusan. Rencana Nusa Apparel bulan berjalan sengaja punya
     * jejak lengkap Ajukan -> Tolak -> Kembalikan ke Draf -> Ajukan ulang ->
     * Setujui, supaya panel riwayat penolakan di halaman detail rencana ada
     * isinya dan bisa difoto.
     */
    private function seedPlanHistory(ContentPlan $plan, string $clientKey, Carbon $planMonth, Carbon $currentMonth, string $status): void
    {
        $manager = $this->users['manager'];
        $copywriter = $this->users['copywriter'];

        $log = function (?string $from, string $to, ?string $notes, Carbon $at, User $actor) use ($plan) {
            $row = ContentPlanStatusLog::create([
                'content_plan_id' => $plan->id,
                'changed_by_user_id' => $actor->id,
                'from_status' => $from,
                'to_status' => $to,
                'notes' => $notes,
                'changed_at' => $at,
            ]);
            $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        };

        if ($clientKey === 'nusa' && $planMonth->eq($currentMonth)) {
            $base = $this->now->copy()->subDays(21);
            $log(null, 'pending', 'Rencana konten bulan ini diajukan untuk ditinjau.', $base->copy(), $copywriter);
            $log('pending', 'rejected', 'Porsi konten promosi terlalu besar dibanding konten edukasi. Mohon ditinjau ulang sebelum diajukan lagi.', $base->copy()->addDays(2), $manager);
            $log('rejected', 'draft', 'Dikembalikan ke draf untuk diperbaiki.', $base->copy()->addDays(3), $copywriter);
            $log('draft', 'pending', 'Komposisi pilar sudah disesuaikan, diajukan ulang.', $base->copy()->addDays(5), $copywriter);
            $log('pending', 'approved', 'Komposisi sudah seimbang. Disetujui.', $base->copy()->addDays(6), $manager);

            return;
        }

        if ($status === 'approved') {
            $submitted = $planMonth->copy()->startOfMonth()->subDays(6)->setTime(10, 15);
            $log(null, 'pending', 'Rencana konten diajukan untuk ditinjau.', $submitted, $copywriter);
            $log('pending', 'approved', 'Rencana disetujui, silakan lanjut ke produksi.', $submitted->copy()->addDay(), $manager);

            return;
        }

        if ($status === 'pending') {
            $log(null, 'pending', 'Rencana konten bulan depan diajukan untuk ditinjau.', $this->now->copy()->subDays(2)->setTime(9, 40), $copywriter);
        }
    }

    // =================================================================
    // 7. Content Item + workflow
    // =================================================================

    /**
     * Katalog konten dokumentasi. Sengaja ditulis eksplisit satu per satu
     * (bukan digenerate acak) supaya tiap baris punya alasan: judulnya masuk
     * akal untuk brand-nya, statusnya menutupi seluruh papan Kanban, dan
     * beban kerja antar PIC-nya sengaja tidak rata.
     *
     * offset = jarak hari deadline dari hari seeder dijalankan.
     * pic     = key user penanggung jawab utama (null = belum ditugaskan).
     * extra   = penugasan pendukung, format [userKey => assignment_role].
     */
    private function itemDefinitions(): array
    {
        return [
            // ---------- Kopi Senja: client paling lengkap ----------
            ['ref' => 'KS-01', 'client' => 'kopi', 'title' => 'Cerita di Balik Kopi Senja',            'type' => 'Video',  'format' => 'Video',         'platform' => 'Instagram', 'pillar' => 'Education',        'status' => 'uploaded',       'offset' => -75, 'pic' => 'creator',   'extra' => ['copywriter' => 'copywriter', 'smo' => 'smo']],
            ['ref' => 'KS-02', 'client' => 'kopi', 'title' => 'Fakta Menarik Tentang Arabika',         'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Education',        'status' => 'uploaded',       'offset' => -62, 'pic' => 'designer',  'extra' => ['copywriter' => 'copywriter']],
            ['ref' => 'KS-03', 'client' => 'kopi', 'title' => 'Behind the Scene Proses Roasting',      'type' => 'Video',  'format' => 'Video',         'platform' => 'TikTok',    'pillar' => 'Entertainment',    'status' => 'uploaded',       'offset' => -50, 'pic' => 'creator',   'extra' => ['smo' => 'smo']],
            ['ref' => 'KS-04', 'client' => 'kopi', 'title' => 'Promo Weekend Kopi Susu',               'type' => 'Desain', 'format' => 'Single Feed',   'platform' => 'Instagram', 'pillar' => 'Hard Selling',     'status' => 'uploaded',       'offset' => -38, 'pic' => 'designer',  'extra' => ['smo' => 'smo']],
            ['ref' => 'KS-05', 'client' => 'kopi', 'title' => '3 Cara Menikmati Kopi di Pagi Hari',    'type' => 'Video',  'format' => 'Video',         'platform' => 'Instagram', 'pillar' => 'Education',        'status' => 'uploaded',       'offset' => -25, 'pic' => 'creator',   'extra' => ['copywriter' => 'copywriter']],
            ['ref' => 'KS-06', 'client' => 'kopi', 'title' => 'Menu Favorit Pelanggan Bulan Ini',      'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Product Highlight','status' => 'uploaded',       'offset' => -12, 'pic' => 'designer',  'extra' => []],
            ['ref' => 'KS-07', 'client' => 'kopi', 'title' => 'Racikan Baru: Kopi Senja Pandan',       'type' => 'Video',  'format' => 'Video',         'platform' => 'TikTok',    'pillar' => 'Product Highlight','status' => 'scheduled',      'offset' => 2,   'pic' => 'creator',   'extra' => ['smo' => 'smo']],
            ['ref' => 'KS-08', 'client' => 'kopi', 'title' => 'Tips Menyeduh Kopi Tanpa Alat Mahal',   'type' => 'Video',  'format' => 'Video',         'platform' => 'Instagram', 'pillar' => 'Education',        'status' => 'waiting_review', 'offset' => 5,   'pic' => 'creator',   'extra' => ['copywriter' => 'copywriter']],
            ['ref' => 'KS-09', 'client' => 'kopi', 'title' => 'Testimoni Pelanggan Setia',             'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Soft Selling',     'status' => 'revision',       'offset' => -2,  'pic' => 'designer',  'extra' => [], 'overdue' => true],
            ['ref' => 'KS-10', 'client' => 'kopi', 'title' => 'Kopi Senja Buka Cabang Baru',           'type' => 'Desain', 'format' => 'Single Feed',   'platform' => 'Instagram', 'pillar' => 'Information',      'status' => 'in_progress',    'offset' => -4,  'pic' => 'designer',  'extra' => [], 'overdue' => true],
            ['ref' => 'KS-11', 'client' => 'kopi', 'title' => 'Ngopi Sore Bareng Komunitas',           'type' => 'Video',  'format' => 'Video',         'platform' => 'TikTok',    'pillar' => 'Entertainment',    'status' => 'brief_ready',    'offset' => 11,  'pic' => 'creator',   'extra' => []],

            // ---------- Nusa Apparel: fokus produksi & revisi ----------
            ['ref' => 'NA-01', 'client' => 'nusa', 'title' => 'Inspirasi Outfit Kerja Minimalis',      'type' => 'Video',  'format' => 'Video',         'platform' => 'Instagram', 'pillar' => 'Soft Selling',     'status' => 'approved',       'offset' => 1,   'pic' => 'creator',   'extra' => []],
            ['ref' => 'NA-02', 'client' => 'nusa', 'title' => '5 Cara Mix and Match Outer',            'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Education',        'status' => 'approved',       'offset' => 4,   'pic' => 'designer2', 'extra' => []],
            ['ref' => 'NA-03', 'client' => 'nusa', 'title' => 'Koleksi Weekend Essentials',            'type' => 'Desain', 'format' => 'Single Feed',   'platform' => 'Instagram', 'pillar' => 'Product Highlight','status' => 'waiting_review', 'offset' => 3,   'pic' => 'designer2', 'extra' => []],
            ['ref' => 'NA-04', 'client' => 'nusa', 'title' => 'Behind the Scene Photoshoot',           'type' => 'Video',  'format' => 'Video',         'platform' => 'Instagram', 'pillar' => 'Entertainment',    'status' => 'in_progress',    'offset' => 6,   'pic' => 'creator',   'extra' => []],
            ['ref' => 'NA-05', 'client' => 'nusa', 'title' => 'Pilihan Warna Favorit Minggu Ini',      'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Product Highlight','status' => 'revision',       'offset' => -1,  'pic' => 'designer2', 'extra' => [], 'overdue' => true],
            ['ref' => 'NA-06', 'client' => 'nusa', 'title' => 'Panduan Ukuran Agar Tidak Salah Beli',  'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Information',      'status' => 'in_progress',    'offset' => 9,   'pic' => 'designer3', 'extra' => []],
            ['ref' => 'NA-07', 'client' => 'nusa', 'title' => 'Diskon Akhir Bulan Nusa Apparel',       'type' => 'Desain', 'format' => 'Single Feed',   'platform' => 'Instagram', 'pillar' => 'Hard Selling',     'status' => 'scheduled',      'offset' => 2,   'pic' => 'designer3', 'extra' => ['smo' => 'smo']],
            ['ref' => 'NA-08', 'client' => 'nusa', 'title' => 'Perawatan Bahan Katun Agar Awet',       'type' => 'Video',  'format' => 'Video',         'platform' => 'Instagram', 'pillar' => 'Education',        'status' => 'brief_ready',    'offset' => 14,  'pic' => 'creator',   'extra' => []],
            ['ref' => 'NA-09', 'client' => 'nusa', 'title' => 'Rekomendasi Outfit Hangout Akhir Pekan','type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Soft Selling',     'status' => 'waiting_review', 'offset' => 7,   'pic' => 'designer3', 'extra' => []],
            ['ref' => 'NA-10', 'client' => 'nusa', 'title' => 'Koleksi Kemeja Linen Musim Kemarau',    'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Product Highlight','status' => 'uploaded',       'offset' => -68, 'pic' => 'designer2', 'extra' => ['smo' => 'smo']],
            ['ref' => 'NA-11', 'client' => 'nusa', 'title' => 'Tren Warna Earth Tone Bulan Ini',       'type' => 'Video',  'format' => 'Video',         'platform' => 'Instagram', 'pillar' => 'Education',        'status' => 'uploaded',       'offset' => -44, 'pic' => 'creator',   'extra' => ['smo' => 'smo']],

            // ---------- Ruang Belajar: rencana konten & AI Brief ----------
            ['ref' => 'RB-01', 'client' => 'ruang', 'title' => 'Tips Belajar Fokus 25 Menit',                  'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Education',    'status' => 'approved',       'offset' => 2,  'pic' => 'designer',  'extra' => ['copywriter' => 'copywriter']],
            ['ref' => 'RB-02', 'client' => 'ruang', 'title' => 'Cara Menyusun Jadwal Belajar',                 'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Education',    'status' => 'waiting_review', 'offset' => 5,  'pic' => 'designer2', 'extra' => ['copywriter' => 'copywriter']],
            ['ref' => 'RB-03', 'client' => 'ruang', 'title' => 'Kesalahan Saat Persiapan Ujian',               'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Education',    'status' => 'in_progress',    'offset' => 8,  'pic' => 'designer',  'extra' => []],
            ['ref' => 'RB-04', 'client' => 'ruang', 'title' => 'Belajar Lebih Efektif dengan Active Recall',   'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Education',    'status' => 'revision',       'offset' => -3, 'pic' => 'designer2', 'extra' => [], 'overdue' => true],
            ['ref' => 'RB-05', 'client' => 'ruang', 'title' => 'Rekomendasi Aplikasi Pencatat Materi',         'type' => 'Desain', 'format' => 'Single Feed',   'platform' => 'Instagram', 'pillar' => 'Information',  'status' => 'scheduled',      'offset' => 3,  'pic' => 'designer',  'extra' => ['smo' => 'smo']],
            ['ref' => 'RB-06', 'client' => 'ruang', 'title' => 'Promo Paket Belajar Semester',                 'type' => 'Desain', 'format' => 'Single Feed',   'platform' => 'Instagram', 'pillar' => 'Hard Selling', 'status' => 'cancelled',      'offset' => -6, 'pic' => 'designer',  'extra' => []],

            // ---------- Sora Residence: empty state ----------
            ['ref' => 'SR-01', 'client' => 'sora', 'title' => 'Tips Memilih Rumah Pertama',            'type' => 'Desain', 'format' => 'Carousel Feed', 'platform' => 'Instagram', 'pillar' => 'Education',    'status' => 'brief_ready', 'offset' => 11, 'pic' => null, 'extra' => []],
            ['ref' => 'SR-02', 'client' => 'sora', 'title' => 'Mengenal Lingkungan Hunian Nyaman',     'type' => 'Desain', 'format' => 'Single Feed',   'platform' => 'Instagram', 'pillar' => 'Soft Selling', 'status' => 'brief_ready', 'offset' => 18, 'pic' => null, 'extra' => []],
        ];
    }

    private function deadlineFor(array $def): Carbon
    {
        return $this->now->copy()->addDays($def['offset'])->setTime(17, 0);
    }

    private function seedContentItems(): void
    {
        foreach ($this->itemDefinitions() as $def) {
            $client = $this->clients[$def['client']];
            $deadline = $this->deadlineFor($def);
            $planKey = $def['client'].'-'.$deadline->year.'-'.$deadline->month;
            $plan = $this->plans[$planKey];

            $isVideo = $def['type'] === 'Video';
            $isPosted = $def['status'] === 'uploaded';

            $item = ContentItem::updateOrCreate(
                ['import_source' => self::MARKER, 'external_reference' => 'DOC:'.$def['ref']],
                [
                    'content_plan_id' => $plan->id,
                    'client_id' => $client->id,
                    'content_pillar_id' => $this->pillars[$def['pillar']] ?? null,
                    'content_type_id' => $this->contentTypes[$def['type']] ?? null,
                    'content_format' => $def['format'],
                    'platform_id' => $this->platforms[$def['platform']] ?? null,
                    'title' => $def['title'],
                    'brief' => $this->briefTextFor($def),
                    'caption_draft' => $this->captionFor($def),
                    'deadline_at' => $deadline,
                    'footage_captured_at' => $isVideo && ! in_array($def['status'], ['brief_ready', 'cancelled'], true)
                        ? $deadline->copy()->subDays(4)->setTime(14, 30)
                        : null,
                    'content_file_link' => in_array($def['status'], ['waiting_review', 'revision', 'approved', 'scheduled', 'uploaded'], true)
                        ? 'https://example.com/aset/'.mb_strtolower($def['ref']).'-hasil-produksi'
                        : null,
                    'scheduled_upload_at' => in_array($def['status'], ['scheduled', 'uploaded'], true)
                        ? $deadline->copy()->setTime(19, 0)
                        : null,
                    'is_posted' => $isPosted,
                    'is_urgent' => $def['ref'] === 'KS-10',
                    'estimated_duration_seconds' => $isVideo ? $this->rand(18, 58) : null,
                    'estimated_slide_count' => ! $isVideo ? ($def['format'] === 'Carousel Feed' ? $this->rand(4, 8) : 1) : null,
                    'import_batch_id' => null,
                ]
            );

            $this->items->put($def['ref'], $item);

            $this->seedWorkflowFor($item, $def);
            $this->seedAssignmentsFor($item, $def);
            $this->seedStatusHistoryFor($item, $def);
        }
    }

    private function seedWorkflowFor(ContentItem $item, array $def): void
    {
        $pic = $def['pic'] ? $this->users[$def['pic']] : null;

        // withoutEvents(): ContentWorkflowObserver memanggil script prediksi
        // Delay Risk (Python) tiap kali workflow berstatus brief_ready dibuat.
        // Seeder dokumentasi tidak boleh bergantung pada runtime ML - skornya
        // di-seed langsung di seedDelayRisk().
        ContentWorkflow::withoutEvents(function () use ($item, $def, $pic) {
            $workflow = ContentWorkflow::create([
                'content_item_id' => $item->id,
                'current_pic_id' => $pic?->id,
                'current_status' => $def['status'],
                'is_overdue' => (bool) ($def['overdue'] ?? false),
            ]);

            $enteredAt = $this->statusEnteredAt($def);
            $workflow->forceFill(['created_at' => $enteredAt, 'updated_at' => $enteredAt])->save();

            $this->applyClientReview($workflow, $item, $def);
        });
    }

    /**
     * Jejak review dari Portal Klien. Nusa Apparel sengaja dibiarkan MENUNGGU
     * (client_reviewed_at null) supaya antrean persetujuan di portalnya ada
     * isinya; Kopi Senja & Ruang Belajar sudah pernah direview supaya bagian
     * "sudah ditinjau" juga tidak kosong.
     */
    private function applyClientReview(ContentWorkflow $workflow, ContentItem $item, array $def): void
    {
        $reviews = [
            'KS-08' => 'approved',
            'KS-09' => 'revision',
            'RB-02' => 'approved',
        ];

        if (! isset($reviews[$def['ref']])) {
            return;
        }

        $workflow->forceFill([
            'client_reviewed_at' => $this->now->copy()->subDays(2)->setTime(13, 20),
            'client_reviewed_by_client_id' => $item->client_id,
            'client_review_result' => $reviews[$def['ref']],
        ])->save();
    }

    private function statusEnteredAt(array $def): Carbon
    {
        $stuckDays = ($def['overdue'] ?? false) ? $this->rand(6, 14) : $this->rand(0, 5);

        return $this->now->copy()->subDays($stuckDays)->setTime(9, 0);
    }

    private function seedAssignmentsFor(ContentItem $item, array $def): void
    {
        if ($def['pic']) {
            ContentItemAssignment::create([
                'content_item_id' => $item->id,
                'user_id' => $this->users[$def['pic']]->id,
                'assignment_role' => $def['type'] === 'Video' ? 'content_creator' : 'designer',
            ]);
        }

        foreach ($def['extra'] ?? [] as $userKey => $role) {
            ContentItemAssignment::create([
                'content_item_id' => $item->id,
                'user_id' => $this->users[$userKey]->id,
                'assignment_role' => $role,
            ]);
        }
    }

    /**
     * Riwayat status ditelusuri mundur dari status sekarang lewat jalur yang
     * memang sah di WorkflowTransitions - jadi log-nya tidak pernah memuat
     * lompatan yang aplikasinya sendiri akan tolak.
     */
    private function seedStatusHistoryFor(ContentItem $item, array $def): void
    {
        $path = $this->statusPathTo($def['status'], $def['ref']);
        if (count($path) < 2) {
            return;
        }

        $actor = $def['pic'] ? $this->users[$def['pic']] : $this->users['manager'];
        $steps = count($path) - 1;
        $spanDays = max($steps * 2, 4);
        $cursor = $this->statusEnteredAt($def)->copy()->subDays($spanDays);

        for ($i = 1; $i < count($path); $i++) {
            $from = $path[$i - 1];
            $to = $path[$i];

            $changedBy = match ($to) {
                'approved' => $this->users['manager'],
                'scheduled', 'uploaded' => $this->users['smo'],
                'revision' => $this->users['manager'],
                default => $actor,
            };

            $log = ContentStatusLog::create([
                'content_item_id' => $item->id,
                'changed_by_user_id' => $changedBy->id,
                'from_status' => $from,
                'to_status' => $to,
                'approval_type' => $to === 'approved' ? 'internal' : null,
                'notes' => $this->statusNote($from, $to),
                'changed_at' => $cursor->copy(),
            ]);
            $log->forceFill(['created_at' => $cursor->copy(), 'updated_at' => $cursor->copy()])->save();

            $cursor->addDays(2);
        }
    }

    private function statusPathTo(string $status, string $ref): array
    {
        $base = ['brief_ready'];

        // Dua konten sengaja diberi jalur "pernah direvisi" yang lebih panjang
        // supaya tab Riwayat Status di Detail Konten punya contoh alur bolak-
        // balik, bukan cuma maju lurus.
        $withRevision = in_array($ref, ['KS-05', 'NA-11'], true);

        $forward = match ($status) {
            'brief_ready' => [],
            'in_progress' => ['in_progress'],
            'waiting_review' => ['in_progress', 'waiting_review'],
            'revision' => ['in_progress', 'waiting_review', 'revision'],
            'approved' => ['in_progress', 'waiting_review', 'approved'],
            'scheduled' => ['in_progress', 'waiting_review', 'approved', 'scheduled'],
            'uploaded' => $withRevision
                ? ['in_progress', 'waiting_review', 'revision', 'in_progress', 'waiting_review', 'approved', 'scheduled', 'uploaded']
                : ['in_progress', 'waiting_review', 'approved', 'scheduled', 'uploaded'],
            'cancelled' => ['cancelled'],
            default => [],
        };

        return array_merge($base, $forward);
    }

    private function statusNote(string $from, string $to): ?string
    {
        return match ($to) {
            'in_progress' => $from === 'revision' ? 'Perbaikan revisi mulai dikerjakan.' : 'Mulai dikerjakan sesuai brief.',
            'waiting_review' => 'Hasil produksi diajukan untuk ditinjau.',
            'revision' => 'Ada catatan perbaikan, dikembalikan ke PIC.',
            'approved' => 'Hasil disetujui, siap dijadwalkan.',
            'scheduled' => 'Dijadwalkan tayang sesuai kalender konten.',
            'uploaded' => 'Konten sudah tayang di akun klien.',
            'cancelled' => 'Dibatalkan atas permintaan klien.',
            default => null,
        };
    }

    private function briefTextFor(array $def): string
    {
        $context = [
            'kopi' => 'Kopi Senja - kedai kopi lokal yang mengangkat suasana hangat dan cerita di balik tiap cangkir.',
            'nusa' => 'Nusa Apparel - brand fashion siap pakai dengan gaya minimalis dan warna netral.',
            'ruang' => 'Ruang Belajar - platform bimbingan belajar yang menekankan cara belajar efektif.',
            'sora' => 'Sora Residence - hunian keluarga dengan lingkungan tenang dan akses mudah ke pusat kota.',
        ];

        return $context[$def['client']].' Fokus konten: '.mb_strtolower($def['title'])
            .'. Pilar '.$def['pillar'].', format '.$def['format'].' untuk '.$def['platform'].'.';
    }

    private function captionFor(array $def): ?string
    {
        if (in_array($def['status'], ['brief_ready', 'cancelled'], true)) {
            return null;
        }

        return $def['title'].'. Simpan dulu, siapa tahu perlu nanti. Ceritakan pengalamanmu di kolom komentar ya!';
    }

    // =================================================================
    // 8. AI Brief
    // =================================================================

    /**
     * Brief hasil AI di-seed langsung, TIDAK memanggil Gemini - biar seeder
     * bisa jalan tanpa API key, tanpa biaya, dan hasilnya sama tiap kali.
     * Tanggal mulai & tanggal posting selalu dihitung mundur dari deadline
     * konten, jadi tidak pernah jatuh di masa lampau yang janggal.
     */
    private function seedBriefs(): void
    {
        $defs = [
            ['ref' => 'KS-08', 'status' => 'finalized', 'complexity' => 'medium',  'feasibility' => 'ok'],
            ['ref' => 'KS-07', 'status' => 'finalized', 'complexity' => 'complex', 'feasibility' => 'warning'],
            ['ref' => 'RB-01', 'status' => 'finalized', 'complexity' => 'simple',  'feasibility' => 'ok'],
            ['ref' => 'KS-11', 'status' => 'draft',     'complexity' => 'medium',  'feasibility' => 'ok'],
            ['ref' => 'NA-08', 'status' => 'draft',     'complexity' => 'simple',  'feasibility' => 'ok'],
            ['ref' => 'NA-04', 'status' => 'discussing','complexity' => 'complex', 'feasibility' => 'critical'],
        ];

        foreach ($defs as $d) {
            $item = $this->items[$d['ref']];
            $def = collect($this->itemDefinitions())->firstWhere('ref', $d['ref']);
            $isVideo = $def['type'] === 'Video';

            $postDate = $item->deadline_at->copy()->addDays(2);
            $startDate = $item->deadline_at->copy()->subDays($d['complexity'] === 'complex' ? 6 : 3);

            $draft = ContentBriefDraft::create([
                'content_item_id' => $item->id,
                'created_by' => $this->users['copywriter']->id,
                'hook_title' => $this->hookFor($d['ref'], $def),
                'start_date' => $startDate,
                'post_date' => $postDate,
                'platform' => $def['platform'],
                'reference_link' => 'https://example.com/referensi/'.mb_strtolower($d['ref']),
                'take_by_user_id' => $def['pic'] ? $this->users[$def['pic']]->id : null,
                'copywriting_script' => null,
                'scenes' => $this->scenesFor($d['ref'], $def, $isVideo),
                'talent' => $isVideo ? '1 talent utama (barista/host), gaya bicara santai' : 'Tanpa talent, fokus pada visual produk',
                'properti' => $this->propsFor($def),
                'estimated_duration_seconds' => $isVideo ? ($d['complexity'] === 'complex' ? 52 : 34) : null,
                'slide_count' => $isVideo ? null : ($def['format'] === 'Carousel Feed' ? 6 : 1),
                'talent_count' => $isVideo ? 1 : 0,
                'location_count' => $d['complexity'] === 'complex' ? 2 : 1,
                'complexity_level' => $d['complexity'],
                'feasibility_level' => $d['feasibility'],
                'feasibility_notes' => $this->feasibilityNote($d['feasibility'], $startDate, $item->deadline_at),
                'status' => $d['status'],
                'chat_history' => $d['ref'] === 'NA-04' ? $this->briefDiscussion() : null,
                'finalized_at' => $d['status'] === 'finalized' ? $this->now->copy()->subDays(3)->setTime(11, 5) : null,
            ]);

            $created = $this->now->copy()->subDays(5)->setTime(10, 0);
            $draft->forceFill(['created_at' => $created, 'updated_at' => $created])->save();
        }
    }

    private function hookFor(string $ref, array $def): string
    {
        return match ($ref) {
            'KS-07' => 'Kopi pandan? Rasanya seaneh kedengarannya atau justru seenak itu?',
            'KS-08' => 'Nggak punya alat seduh mahal? Tiga langkah ini sudah cukup.',
            'KS-11' => 'Sore-sore di Kopi Senja itu bukan cuma soal kopinya.',
            'NA-04' => 'Yang kelihatan rapi di feed, di balik layarnya begini.',
            'NA-08' => 'Katun kesayangan cepat melar? Biasanya salah di langkah kedua.',
            'RB-01' => 'Belajar 25 menit terasa sebentar, tapi hasilnya beda.',
            default => $def['title'],
        };
    }

    private function scenesFor(string $ref, array $def, bool $isVideo): array
    {
        if (! $isVideo) {
            return [
                ['label' => 'Slide 1 - Hook', 'visual' => 'Judul besar di atas latar polos warna brand, satu kalimat pemancing.', 'talent_script' => null],
                ['label' => 'Slide 2 - Masalah', 'visual' => 'Ilustrasi/foto situasi yang biasa dialami audiens.', 'talent_script' => null],
                ['label' => 'Slide 3 - Poin utama', 'visual' => 'Tiga poin ringkas, satu ikon per poin.', 'talent_script' => null],
                ['label' => 'Slide 4 - Contoh', 'visual' => 'Contoh penerapan dalam satu foto produk/suasana.', 'talent_script' => null],
                ['label' => 'Slide 5 - Penutup & CTA', 'visual' => 'Logo brand, ajakan simpan dan bagikan.', 'talent_script' => null],
            ];
        }

        return [
            ['label' => 'Adegan 1 (0-3 detik)', 'visual' => 'Close up objek utama, gerak kamera pelan dari bawah ke atas.', 'talent_script' => 'Satu kalimat pembuka yang langsung menyebut manfaatnya.'],
            ['label' => 'Adegan 2 (3-12 detik)', 'visual' => 'Medium shot proses/aktivitas, potongan cepat tiap 2 detik.', 'talent_script' => 'Jelaskan langkahnya satu per satu, bahasa sehari-hari.'],
            ['label' => 'Adegan 3 (12-25 detik)', 'visual' => 'Detail hasil akhir, pencahayaan hangat.', 'talent_script' => 'Tegaskan hasil yang didapat penonton kalau ikut mencoba.'],
            ['label' => 'Adegan 4 (25-34 detik)', 'visual' => 'Wide shot suasana, logo brand muncul di ujung.', 'talent_script' => 'Ajakan singkat: simpan dulu, cobain akhir pekan ini.'],
        ];
    }

    private function propsFor(array $def): string
    {
        return match ($def['client']) {
            'kopi' => 'Gelas saji, biji kopi, timbangan, kain lap, meja kayu',
            'nusa' => 'Rak baju, hanger kayu, cermin, latar dinding netral',
            'ruang' => 'Buku catatan, timer belajar, alat tulis, meja belajar',
            default => 'Materi visual brand dan latar polos',
        };
    }

    private function feasibilityNote(string $level, Carbon $start, Carbon $deadline): string
    {
        $days = (int) $start->diffInDays($deadline, absolute: true);

        return match ($level) {
            'ok' => "Jarak tanggal mulai ke deadline {$days} hari, cukup untuk kompleksitas produksi ini. Beban PIC minggu tersebut masih wajar.",
            'warning' => "Hanya tersisa {$days} hari sebelum deadline untuk produksi dua lokasi. Masih bisa dikejar, tapi jadwal take sebaiknya dikunci lebih awal.",
            default => "Hanya {$days} hari sebelum deadline sementara PIC memegang beberapa konten aktif lain di minggu yang sama. Disarankan mundurkan tanggal posting atau kurangi jumlah adegan.",
        };
    }

    private function briefDiscussion(): array
    {
        return [
            ['role' => 'user', 'message' => 'Adegannya kepanjangan, bisa dipadatkan jadi 30 detik?'],
            ['role' => 'assistant', 'message' => 'Bisa. Adegan 2 dan 3 saya gabung jadi satu potongan proses, durasi turun ke sekitar 30 detik tanpa kehilangan alur ceritanya. Lokasi kedua juga bisa dihapus kalau ingin lebih ringan.'],
            ['role' => 'user', 'message' => 'Oke, tapi bagian hasil akhirnya jangan dipotong ya.'],
            ['role' => 'assistant', 'message' => 'Baik, adegan hasil akhir saya pertahankan penuh dan pemadatan hanya diambil dari bagian proses. Klik "Terapkan Perubahan" kalau versi ini sudah sesuai.'],
        ];
    }

    // =================================================================
    // 9. Revisi
    // =================================================================

    private function seedRevisions(): void
    {
        $manager = $this->users['manager'];

        // Konten A - satu putaran revisi yang sudah selesai.
        ContentRevision::create([
            'content_item_id' => $this->items['KS-05']->id,
            'requested_by_user_id' => $manager->id,
            'revision_round' => 1,
            'revision_note' => 'Perbesar logo pada bagian akhir video.',
            'status' => 'resolved',
        ]);

        // Konten B - dua putaran, yang terakhir masih terbuka. Statusnya
        // memang 'revision', jadi revisi terbuka di sini konsisten dengan
        // papan Kanban (tidak pernah ada revisi terbuka di konten yang sudah
        // disetujui atau tayang).
        ContentRevision::create([
            'content_item_id' => $this->items['NA-05']->id,
            'requested_by_user_id' => $manager->id,
            'revision_round' => 1,
            'revision_note' => 'Gunakan warna visual yang lebih sesuai dengan identitas brand.',
            'status' => 'resolved',
        ]);
        ContentRevision::create([
            'content_item_id' => $this->items['NA-05']->id,
            'requested_by_user_id' => $manager->id,
            'revision_round' => 2,
            'revision_note' => 'Caption perlu lebih singkat dan CTA diperjelas.',
            'status' => 'open',
        ]);

        // Konten C - revisi yang datang dari Portal Klien (bukan tim internal),
        // jadi actor-nya client, bukan user.
        ContentRevision::create([
            'content_item_id' => $this->items['KS-09']->id,
            'requested_by_client_id' => $this->clients['kopi']->id,
            'revision_round' => 1,
            'revision_note' => 'Ganti opening agar langsung menampilkan produk.',
            'status' => 'open',
        ]);

        ContentRevision::create([
            'content_item_id' => $this->items['RB-04']->id,
            'requested_by_user_id' => $manager->id,
            'revision_round' => 1,
            'revision_note' => 'Urutan slide dibalik: mulai dari kesalahan yang paling sering terjadi.',
            'status' => 'open',
        ]);
    }

    // =================================================================
    // 10. Publikasi
    // =================================================================

    private function seedPublications(): void
    {
        foreach ($this->itemDefinitions() as $def) {
            if ($def['status'] !== 'uploaded') {
                continue;
            }

            $item = $this->items[$def['ref']];
            $publishedAt = $item->deadline_at->copy()->setTime(19, 0);

            // URL sengaja memakai domain contoh - JANGAN pernah diganti link
            // Instagram/TikTok sungguhan di dataset dokumentasi.
            ContentPublication::create([
                'content_item_id' => $item->id,
                'platform_id' => $this->platforms[$def['platform']],
                'published_by' => $this->users['smo']->id,
                'published_at' => $publishedAt,
                'post_url' => 'https://example.com/posts/'.mb_strtolower($def['ref']),
                'caption_final' => $this->captionFor($def),
            ]);
        }
    }

    // =================================================================
    // 11. Delay Risk
    // =================================================================

    /**
     * Skor di-seed langsung, bukan hasil model. Nilainya dipilih supaya tiap
     * tingkat risiko punya contoh, dan dua item berisiko tinggi yang BELUM
     * overdue tetap ada - itu syarat munculnya panel prediktif di Dashboard
     * (panel itu memang hanya menampilkan item high risk yang belum telat).
     */
    private function seedDelayRisk(): void
    {
        $scores = [
            'KS-08' => [78, 'high',   'Deadline tinggal 5 hari sementara PIC memegang 6 konten aktif'],
            'NA-06' => [72, 'high',   'Konten carousel 8 slide dengan sisa waktu pengerjaan pendek'],
            'KS-09' => [66, 'high',   'Sudah melewati deadline dan masih menunggu perbaikan revisi'],
            'NA-05' => [61, 'high',   'Dua putaran revisi pada konten yang sudah lewat deadline'],
            'KS-10' => [46, 'medium', 'Sudah 9 hari berada di status Sedang Dikerjakan'],
            'RB-04' => [46, 'medium', 'Revisi terbuka menjelang akhir bulan'],
            'NA-04' => [41, 'medium', 'Produksi video dua lokasi dengan jadwal take belum dikunci'],
            'RB-03' => [33, 'medium', 'Beban PIC minggu ini di atas rata-rata'],
            'KS-11' => [18, 'low',    'Deadline masih 11 hari lagi'],
            'NA-08' => [18, 'low',    'Deadline masih lapang dan brief sudah tersedia'],
            'RB-01' => [15, 'low',    'Sudah disetujui, tinggal menunggu jadwal tayang'],
            'SR-01' => [22, 'low',    'Belum ada penanggung jawab, tapi deadline masih jauh'],
        ];

        foreach ($scores as $ref => [$score, $level, $factor]) {
            $item = $this->items[$ref];

            $row = DelayRiskScore::create([
                'content_item_id' => $item->id,
                'risk_score' => $score,
                'risk_level' => $level,
                'top_factor' => $factor,
                'features_snapshot' => [
                    'days_to_deadline' => (int) $this->now->diffInDays($item->deadline_at, absolute: false),
                    'current_status' => $item->workflow?->current_status,
                    'content_complexity' => $item->estimated_slide_count ? 'slides:'.$item->estimated_slide_count : 'duration:'.$item->estimated_duration_seconds,
                    'source' => 'documentation_seeder_synthetic',
                ],
            ]);

            $at = $this->now->copy()->subHours(6);
            $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    // =================================================================
    // 12. Metrik performa
    // =================================================================

    /**
     * Kurva peluruhan (bukan angka acak murni): tayangan tertinggi di hari
     * pertama lalu turun ke plateau. Tiap konten diberi profil sendiri -
     * high/average/low performer - supaya grafik Dashboard & Performa punya
     * bentuk yang masuk akal, bukan garis datar seragam.
     *
     * client_id WAJIB diisi: seluruh query Analytics membaca content_metrics
     * lewat client_id langsung, bukan lewat relasi content item.
     */
    private function seedMetrics(): void
    {
        $profiles = [
            'KS-01' => ['peak' => 3200,  'tau' => 5.0, 'er' => 3.4],
            'KS-02' => ['peak' => 900,   'tau' => 3.5, 'er' => 1.9],
            'KS-03' => ['peak' => 24000, 'tau' => 6.5, 'er' => 7.6],
            'KS-04' => ['peak' => 700,   'tau' => 3.0, 'er' => 1.6],
            'KS-05' => ['peak' => 4100,  'tau' => 5.5, 'er' => 4.2],
            'KS-06' => ['peak' => 8600,  'tau' => 6.0, 'er' => 5.5],
            'NA-10' => ['peak' => 2600,  'tau' => 4.5, 'er' => 3.1],
            'NA-11' => ['peak' => 15200, 'tau' => 6.2, 'er' => 6.8],
        ];

        foreach ($profiles as $ref => $profile) {
            $item = $this->items[$ref];
            $def = collect($this->itemDefinitions())->firstWhere('ref', $ref);
            $isVideo = $def['type'] === 'Video';

            $publishedAt = $item->deadline_at->copy()->startOfDay();
            $trackedDays = (int) $publishedAt->diffInDays($this->now, absolute: true);

            $plateau = (int) round($profile['peak'] * 0.06);

            for ($d = 0; $d <= $trackedDays; $d++) {
                $date = $publishedAt->copy()->addDays($d);
                if ($date->gt($this->now)) {
                    break;
                }

                $decayed = $plateau + ($profile['peak'] - $plateau) * exp(-$d / $profile['tau']);
                $jitter = $this->rand(92, 108) / 100;
                $views = max(60, (int) round($decayed * $jitter));

                $likes = (int) round($views * $this->rand(35, 85) / 1000);
                $comments = (int) round($views * $this->rand(4, 18) / 1000);
                $shares = (int) round($views * $this->rand(6, 26) / 1000);
                $saves = (int) round($views * $this->rand(12, 40) / 1000);
                $reach = (int) round($views * $this->rand(78, 106) / 100);
                $impressions = (int) round($views * $this->rand(115, 175) / 100);

                // Engagement rate dihitung dari interaksi terhadap reach,
                // lalu dijaga di rentang wajar 1.5%-8% - bukan angka acak
                // yang lepas dari likes/comments di baris yang sama.
                $engagement = $reach > 0
                    ? (($likes + $comments + $shares + $saves) / $reach) * 100
                    : $profile['er'];
                $engagement = round(min(8.0, max(1.5, $engagement)), 2);

                ContentMetric::create([
                    'content_item_id' => $item->id,
                    'client_id' => $item->client_id,
                    'platform_id' => $item->platform_id,
                    'imported_by' => $this->users['smo']->id,
                    'metric_date' => $date->toDateString(),
                    'views' => $views,
                    'engagement_rate' => $engagement,
                    'watch_time_avg' => $isVideo ? $this->rand(9, 38) : null,
                    'completion_rate' => $isVideo ? round($this->rand(3200, 8200) / 100, 2) : null,
                    'shares' => $shares,
                    'saves' => $saves,
                    'reach' => $reach,
                    'impressions' => $impressions,
                    'likes' => $likes,
                    'comments' => $comments,
                    'profile_visit' => (int) round($views * $this->rand(10, 48) / 1000),
                ]);
            }
        }

        $this->seedAnomalies();
    }

    /**
     * Anomali dihitung dari metrik yang baru saja dibuat (pola sama dengan
     * command DetectPerformanceAnomalies: bandingkan hari terakhir terhadap
     * rata-rata hari sebelumnya), bukan ditulis sebagai angka lepas.
     */
    private function seedAnomalies(): void
    {
        foreach (['KS-03', 'KS-06', 'NA-11'] as $ref) {
            $item = $this->items[$ref];
            $rows = ContentMetric::where('content_item_id', $item->id)->orderBy('metric_date')->get();

            if ($rows->count() < 5) {
                continue;
            }

            $peak = $rows->first();
            $baseline = $rows->slice(1)->avg('views');
            if (! $baseline || $baseline <= 0) {
                continue;
            }

            PerformanceAnomaly::create([
                'content_item_id' => $item->id,
                'type' => 'spike',
                'percent_change' => (int) round((($peak->views / $baseline) - 1) * 100),
                'views_on_date' => $peak->views,
                'baseline_avg_views' => (int) round($baseline),
                'detected_date' => $peak->metric_date,
            ]);
        }
    }

    // =================================================================
    // 13. Audience
    // =================================================================

    /**
     * Provenance 'csv_import': data ini memang dimasukkan sebagai data
     * sintetis, BUKAN hasil tarikan Instagram/TikTok API. Menandainya sebagai
     * instagram_api akan membuat halaman Audience mengklaim sumber yang tidak
     * pernah ada, dan itu justru menyesatkan pembaca buku panduan.
     */
    private function seedAudience(): void
    {
        $defs = [
            [
                'client' => 'kopi', 'platform' => 'Instagram', 'followers' => 12450, 'growth' => 14,
                'gender' => ['male' => 42, 'female' => 58],
                'age' => ['18-24' => 35, '25-34' => 42, '35-44' => 16, '45+' => 7],
                'locations' => [
                    ['city' => 'Padang', 'percentage' => 38],
                    ['city' => 'Bukittinggi', 'percentage' => 24],
                    ['city' => 'Pekanbaru', 'percentage' => 21],
                    ['city' => 'Jakarta', 'percentage' => 17],
                ],
                'peaks' => [11 => 82, 18 => 94, 20 => 100],
            ],
            [
                'client' => 'kopi', 'platform' => 'TikTok', 'followers' => 8730, 'growth' => 26,
                'gender' => ['male' => 47, 'female' => 53],
                'age' => ['18-24' => 51, '25-34' => 33, '35-44' => 11, '45+' => 5],
                'locations' => [
                    ['city' => 'Padang', 'percentage' => 33],
                    ['city' => 'Pekanbaru', 'percentage' => 26],
                    ['city' => 'Jakarta', 'percentage' => 24],
                    ['city' => 'Bandung', 'percentage' => 17],
                ],
                'peaks' => [12 => 70, 19 => 88, 21 => 100],
            ],
            [
                'client' => 'nusa', 'platform' => 'Instagram', 'followers' => 21380, 'growth' => 9,
                'gender' => ['male' => 31, 'female' => 69],
                'age' => ['18-24' => 28, '25-34' => 47, '35-44' => 19, '45+' => 6],
                'locations' => [
                    ['city' => 'Jakarta', 'percentage' => 41],
                    ['city' => 'Bandung', 'percentage' => 23],
                    ['city' => 'Surabaya', 'percentage' => 20],
                    ['city' => 'Padang', 'percentage' => 16],
                ],
                'peaks' => [10 => 74, 16 => 86, 20 => 100],
            ],
        ];

        foreach ($defs as $def) {
            $client = $this->clients[$def['client']];
            $platformId = $this->platforms[$def['platform']];

            $activeHours = collect(range(0, 23))->mapWithKeys(function ($hour) use ($def) {
                if (isset($def['peaks'][$hour])) {
                    return [(string) $hour => $def['peaks'][$hour]];
                }

                $base = match (true) {
                    $hour >= 0 && $hour <= 5 => 6,
                    $hour >= 6 && $hour <= 9 => 28,
                    $hour >= 10 && $hour <= 15 => 52,
                    $hour >= 16 && $hour <= 22 => 68,
                    default => 24,
                };

                return [(string) $hour => $base];
            })->all();

            for ($d = 89; $d >= 0; $d--) {
                $date = $this->now->copy()->subDays($d)->toDateString();
                $elapsed = 89 - $d;

                // Pertumbuhan follower linier + riak kecil, jadi grafik tren
                // naik wajar dan tidak bergerigi seperti angka acak.
                $followers = (int) round(
                    $def['followers'] * (1 - $def['growth'] / 100)
                    + ($def['followers'] * $def['growth'] / 100) * ($elapsed / 89)
                    + $this->rand(-14, 20)
                );

                AudienceInsight::updateOrCreate(
                    [
                        'client_id' => $client->id,
                        'platform_id' => $platformId,
                        'snapshot_date' => $date,
                        'source' => AudienceInsight::SOURCE_CSV,
                        'demographic_type' => AudienceInsight::TYPE_GENERIC,
                    ],
                    [
                        'follower_count' => max(0, $followers),
                        'reach' => (int) round($followers * $this->rand(28, 46) / 100),
                        'gender_breakdown' => $def['gender'],
                        'age_breakdown' => $def['age'],
                        'top_locations' => $def['locations'],
                        'top_countries' => null,
                        'active_hours' => $activeHours,
                    ]
                );
            }
        }
    }

    // =================================================================
    // 14. Riwayat sinkronisasi / import
    // =================================================================

    private function seedSyncLogs(): void
    {
        // source_type 'api_sync' sengaja TIDAK dipakai - tidak ada
        // ApiIntegration di dataset ini, jadi log sinkronisasi API akan
        // menunjuk koneksi yang tidak pernah ada.
        $defs = [
            ['client' => 'kopi',  'platform' => 'Instagram', 'type' => 'performance_csv_import', 'status' => 'success', 'synced' => 148, 'skipped' => 2,  'days' => 1],
            ['client' => 'kopi',  'platform' => 'Instagram', 'type' => 'audience_csv_import',    'status' => 'success', 'synced' => 90,  'skipped' => 0,  'days' => 1],
            ['client' => 'kopi',  'platform' => 'TikTok',    'type' => 'performance_csv_import', 'status' => 'success', 'synced' => 51,  'skipped' => 0,  'days' => 4],
            ['client' => 'nusa',  'platform' => 'Instagram', 'type' => 'performance_csv_import', 'status' => 'success', 'synced' => 112, 'skipped' => 5,  'days' => 2],
            ['client' => 'nusa',  'platform' => 'Instagram', 'type' => 'audience_csv_import',    'status' => 'success', 'synced' => 90,  'skipped' => 0,  'days' => 2],
            ['client' => 'ruang', 'platform' => 'Instagram', 'type' => 'performance_csv_import', 'status' => 'failed',  'synced' => 0,   'skipped' => 0,  'days' => 6, 'error' => 'Kolom "Tanggal" tidak ditemukan pada baris pertama berkas CSV.'],
        ];

        foreach ($defs as $def) {
            $log = AnalyticsSyncLog::create([
                'client_id' => $this->clients[$def['client']]->id,
                'platform_id' => $this->platforms[$def['platform']],
                'imported_by' => $this->users['smo']->id,
                'source_type' => $def['type'],
                'status' => $def['status'],
                'synced_count' => $def['synced'],
                'skipped_count' => $def['skipped'],
                'error_message' => $def['error'] ?? null,
                'sync_mode' => 'manual',
                'range_from' => $this->now->copy()->subDays(90)->toDateString(),
                'range_to' => $this->now->copy()->toDateString(),
            ]);

            $at = $this->now->copy()->subDays($def['days'])->setTime(14, 25);
            $log->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    // =================================================================
    // 15. AI Strategy
    // =================================================================

    /**
     * Insight di-seed langsung tanpa memanggil Gemini. Ringkasan & action
     * item-nya ditulis dari angka yang benar-benar ada di metrik seeder ini,
     * bukan kalimat generik - supaya halaman AI Strategy bisa difoto dalam
     * keadaan yang koheren dengan grafik di sebelahnya.
     */
    private function seedAiStrategies(): void
    {
        $periodStart = $this->now->copy()->subMonthNoOverflow()->startOfMonth();
        $periodEnd = $this->now->copy()->subMonthNoOverflow()->endOfMonth();

        $defs = [
            [
                'client' => 'kopi',
                'summary' => 'Selama '.$periodStart->translatedFormat('F Y').', konten edukasi Kopi Senja menghasilkan engagement yang jauh lebih stabil dibanding konten promosi langsung. Konten behind the scene di TikTok menjadi puncak performa bulan ini, sementara konten promo diskon justru mencatat views terendah. Pola ini konsisten sepanjang bulan, bukan hasil satu unggahan yang kebetulan viral.',
                'actions' => [
                    'Tingkatkan porsi konten edukasi menjadi sekitar 40% dari total unggahan bulan depan.',
                    'Pertahankan format video pendek di TikTok dengan durasi di bawah 40 detik.',
                    'Gunakan CTA interaktif (pertanyaan di caption) pada konten engagement, bukan ajakan beli langsung.',
                    'Kurangi konten promo murni menjadi maksimal dua unggahan per bulan dan tempelkan pada konten edukasi.',
                ],
                'split' => [
                    ['label' => 'Education', 'value' => 40],
                    ['label' => 'Entertainment', 'value' => 25],
                    ['label' => 'Product Highlight', 'value' => 20],
                    ['label' => 'Hard Selling', 'value' => 15],
                ],
                'applied' => true,
            ],
            [
                'client' => 'nusa',
                'summary' => 'Konten Nusa Apparel bulan '.$periodStart->translatedFormat('F Y').' didominasi format carousel, tetapi justru video tren warna yang mencatat views tertinggi. Audiens perempuan usia 25-34 menjadi kelompok terbesar, dan jam aktif memuncak pada pukul 20.00. Konten panduan ukuran memperoleh jumlah simpan tertinggi, tanda audiens memakainya sebagai rujukan.',
                'actions' => [
                    'Tambah satu konten video tren per bulan, jadwalkan tayang pukul 20.00.',
                    'Ubah konten panduan ukuran menjadi seri berkala karena angka simpannya paling tinggi.',
                    'Uji carousel dengan maksimal enam slide - slide ke-7 dan seterusnya jarang dibuka sampai habis.',
                ],
                'split' => [
                    ['label' => 'Product Highlight', 'value' => 35],
                    ['label' => 'Education', 'value' => 30],
                    ['label' => 'Soft Selling', 'value' => 20],
                    ['label' => 'Information', 'value' => 15],
                ],
                'applied' => false,
            ],
        ];

        foreach ($defs as $def) {
            $client = $this->clients[$def['client']];
            $performanceData = $this->buildPerformanceData($client, $periodStart, $periodEnd);

            $topPillars = collect($def['split'])->take(3)->map(fn ($row) => [
                'name' => $row['label'],
                'reasoning' => 'Pilar '.$row['label'].' mencatat rata-rata engagement tertinggi pada periode yang dianalisis.',
            ])->all();

            $ideas = $this->buildContentIdeas($def['client'], $def['split'], $performanceData['target_content_count']);

            // scoreContentIdeas() murni perhitungan lokal terhadap
            // performance_data (tidak memanggil API manapun) - dipakai supaya
            // skor prediksi tiap ide sama persis dengan yang dihasilkan
            // aplikasi kalau tombol Generate benar-benar ditekan.
            $ideas = app(AiStrategyService::class)->scoreContentIdeas($ideas, $performanceData);

            $insight = AiStrategyInsight::create([
                'client_id' => $client->id,
                'generated_by' => $this->users['smo']->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'performance_data' => $performanceData,
                'summary' => $def['summary'],
                'action_items' => $def['actions'],
                'suggested_split' => $def['split'],
                'top_pillars' => $topPillars,
                'content_ideas' => $ideas,
                'data_completeness_percent' => $def['client'] === 'kopi' ? 88 : 74,
                'status' => 'completed',
                'applied_at' => $def['applied'] ? $this->now->copy()->subDays(4)->setTime(15, 10) : null,
                'applied_by' => $def['applied'] ? $this->users['manager']->id : null,
            ]);

            $at = $this->now->copy()->subDays($def['applied'] ? 5 : 3)->setTime(9, 30);
            $insight->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            if ($def['applied']) {
                $this->seedStrategyDiscussion($insight);
            }
        }
    }

    private function buildPerformanceData(Client $client, Carbon $start, Carbon $end): array
    {
        $metrics = ContentMetric::where('client_id', $client->id)
            ->whereBetween('metric_date', [$start, $end])
            ->with(['contentItem.contentPillar', 'contentItem.contentType', 'platform'])
            ->get();

        $byPillar = $metrics->groupBy(fn ($m) => $m->contentItem?->contentPillar?->name ?? 'Tanpa Pilar')
            ->map(fn ($rows) => [
                'total_views' => (int) $rows->sum('views'),
                'avg_engagement' => round($rows->avg('engagement_rate'), 2),
                'content_count' => $rows->pluck('content_item_id')->unique()->count(),
            ]);

        $byPlatform = $metrics->groupBy(fn ($m) => $m->platform?->name ?? '-')
            ->map(fn ($rows) => ['total_views' => (int) $rows->sum('views')]);

        $top = $metrics->groupBy('content_item_id')
            ->map(fn ($rows) => [
                'title' => $rows->first()->contentItem?->title ?? '-',
                'pillar' => $rows->first()->contentItem?->contentPillar?->name ?? '-',
                'type' => $rows->first()->contentItem?->contentType?->name ?? '-',
                'platform' => $rows->first()->platform?->name ?? '-',
                'views' => (int) $rows->sum('views'),
                'engagement_rate' => round($rows->avg('engagement_rate'), 2),
            ])
            ->sortByDesc('views')->take(5)->values();

        $package = ClientPackage::where('client_id', $client->id)->where('status', 'active')->first();
        $target = $package ? ($package->monthly_content_quota + $package->monthly_design_quota) : 10;

        $anomalies = PerformanceAnomaly::whereHas('contentItem', fn ($q) => $q->where('client_id', $client->id))
            ->with('contentItem.contentPillar')
            ->get()
            ->map(fn ($a) => [
                'content_title' => $a->contentItem?->title ?? '-',
                'pillar' => $a->contentItem?->contentPillar?->name ?? '-',
                'type' => $a->type,
                'percent_change' => $a->percent_change,
                'date' => $a->detected_date->format('d M'),
            ])->values()->all();

        return [
            'client_name' => $client->name,
            'period' => $start->format('d M Y').' - '.$end->format('d M Y'),
            'total_views' => (int) $metrics->sum('views'),
            'avg_engagement_rate' => $metrics->count() > 0 ? round($metrics->avg('engagement_rate'), 2) : 0,
            'trend_vs_previous_period_percent' => $client->name === 'Kopi Senja' ? 34 : 12,
            'content_published_count' => $metrics->pluck('content_item_id')->unique()->count(),
            'tracked_days' => $metrics->pluck('metric_date')->unique()->count(),
            'period_days' => $start->daysInMonth,
            'performance_by_pillar' => $byPillar,
            'performance_by_platform' => $byPlatform,
            'top_5_content' => $top,
            'notable_anomalies' => $anomalies,
            'target_content_count' => $target,
        ];
    }

    private function buildContentIdeas(string $clientKey, array $split, int $target): array
    {
        $catalog = [
            'kopi' => [
                'Education' => ['Beda Arabika dan Robusta dalam 30 Detik', 'Cara Menyimpan Biji Kopi Agar Tidak Cepat Basi', 'Kenapa Air Panas Mendidih Bikin Kopi Pahit'],
                'Entertainment' => ['Sehari Jadi Barista di Kopi Senja', 'Reaksi Pelanggan Coba Menu Rahasia'],
                'Product Highlight' => ['Menu Baru: Kopi Senja Gula Aren', 'Ukuran Cup Baru untuk Dibawa Pulang'],
                'Hard Selling' => ['Promo Beli Dua Gratis Satu Akhir Pekan'],
                'Soft Selling' => ['Sudut Favorit Pelanggan untuk Kerja Sore'],
                'Information' => ['Jam Buka Baru Mulai Bulan Depan'],
            ],
            'nusa' => [
                'Product Highlight' => ['Koleksi Kemeja Oversize Warna Netral', 'Detail Jahitan yang Bikin Awet', 'Padu Padan Celana Kulot Baru'],
                'Education' => ['Cara Membaca Label Perawatan Baju', 'Memilih Ukuran Tanpa Perlu Coba di Toko'],
                'Soft Selling' => ['Satu Outer untuk Lima Gaya Berbeda', 'Isi Lemari Minimalis untuk Pekerja Kantor'],
                'Information' => ['Jadwal Restock Koleksi Terlaris'],
                'Hard Selling' => ['Diskon Awal Bulan untuk Member'],
                'Entertainment' => ['Menebak Harga Outfit Bareng Tim Nusa'],
            ],
        ];

        $pool = $catalog[$clientKey] ?? [];
        $splitSum = collect($split)->sum('value') ?: 100;
        $ideas = [];

        foreach ($split as $row) {
            $count = max(1, (int) round(($row['value'] / $splitSum) * $target));
            $titles = $pool[$row['label']] ?? [];

            for ($i = 0; $i < $count; $i++) {
                $title = $titles[$i % max(1, count($titles))] ?? ($row['label'].' - ide konten '.($i + 1));
                if ($i >= count($titles) && count($titles) > 0) {
                    $title .= ' (varian '.((int) floor($i / count($titles)) + 1).')';
                }

                $ideas[] = [
                    'pillar' => $row['label'],
                    'title' => $title,
                    'brief' => 'Angkat sudut pandang '.mb_strtolower($row['label']).' dengan bahasa sehari-hari, tutup dengan ajakan interaksi di caption.',
                    'type' => $i % 2 === 0 ? 'Video' : 'Desain',
                    'platform' => $clientKey === 'kopi' && $i % 3 === 0 ? 'TikTok' : 'Instagram',
                ];
            }
        }

        return $ideas;
    }

    private function seedStrategyDiscussion(AiStrategyInsight $insight): void
    {
        $conversation = [
            ['role' => 'user', 'message' => 'Kalau porsi edukasi dinaikkan ke 40%, konten promonya jadi terlalu sedikit tidak?'],
            ['role' => 'assistant', 'message' => 'Dari data bulan lalu, konten promo justru yang paling rendah views-nya, jadi menguranginya tidak menurunkan jangkauan. Yang saya sarankan bukan menghapus promo, tapi menempelkannya di akhir konten edukasi - jadi porsi tayangnya tetap ada tanpa memakan slot unggahan sendiri.'],
            ['role' => 'user', 'message' => 'Oke, tapi bulan depan kami ada peluncuran menu baru. Itu masuk hitungan yang mana?'],
            ['role' => 'assistant', 'message' => 'Peluncuran menu paling pas masuk pilar Product Highlight, dan untuk momen peluncuran wajar kalau porsinya naik sementara di minggu pertama. Klik "Perbarui Analisis dari Diskusi Ini" kalau ingin saya susun ulang komposisinya dengan memperhitungkan peluncuran tersebut.'],
        ];

        foreach ($conversation as $i => $msg) {
            $row = AiStrategyMessage::create([
                'ai_strategy_insight_id' => $insight->id,
                'user_id' => $msg['role'] === 'user' ? $this->users['smo']->id : null,
                'role' => $msg['role'],
                'message' => $msg['message'],
            ]);

            $at = $this->now->copy()->subDays(4)->setTime(15, 20)->addMinutes($i * 4);
            $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    // =================================================================
    // 16. Kehadiran
    // =================================================================

    /**
     * Jam kerja & ambang keterlambatan diambil dari AttendanceService (bukan
     * angka yang ditulis ulang di sini), jadi status "Tepat Waktu"/"Telat"
     * yang dihitung halaman Kehadiran selalu cocok dengan data yang di-seed.
     */
    private function seedAttendance(): void
    {
        $service = app(AttendanceService::class);

        // Pola per orang: berapa hari kerja sekali dia telat / tidak hadir /
        // lupa check-out. Dibuat berbeda-beda supaya rekap bulanan punya
        // variasi, bukan satu kolom angka yang sama untuk semua orang.
        $patterns = [
            'ceo'        => ['late' => 9,  'absent' => 0,  'forget' => 11],
            'manager'    => ['late' => 7,  'absent' => 12, 'forget' => 9],
            'smo'        => ['late' => 4,  'absent' => 9,  'forget' => 6],
            'copywriter' => ['late' => 6,  'absent' => 7,  'forget' => 8],
            'creator'    => ['late' => 3,  'absent' => 11, 'forget' => 5],
            'designer'   => ['late' => 8,  'absent' => 0,  'forget' => 7],
            'designer2'  => ['late' => 5,  'absent' => 8,  'forget' => 10],
            'designer3'  => ['late' => 4,  'absent' => 5,  'forget' => 12],
        ];

        // Kondisi hari ini dibuat eksplisit supaya tabel Absensi Harian punya
        // semua label sekaligus: tepat waktu, telat, dan belum check-in.
        $today = [
            'ceo' => 'on_time', 'manager' => 'on_time', 'designer' => 'on_time', 'copywriter' => 'on_time',
            'creator' => 'late', 'smo' => 'none', 'designer2' => 'none', 'designer3' => 'none',
        ];

        foreach ($patterns as $userKey => $pattern) {
            $user = $this->users[$userKey];
            $workdayIndex = 0;

            for ($d = 30; $d >= 0; $d--) {
                $date = $this->now->copy()->subDays($d)->startOfDay();

                if (! $service->isWorkday($date)) {
                    continue;
                }

                $workdayIndex++;

                if ($d === 0) {
                    $this->seedTodayAttendance($user, $date, $today[$userKey], $service);
                    continue;
                }

                $isAbsent = $pattern['absent'] > 0 && $workdayIndex % $pattern['absent'] === 0;
                if ($isAbsent) {
                    // Tidak hadir = memang tidak ada barisnya sama sekali.
                    // AttendanceService menyimpulkan "Tidak Hadir" dari
                    // ketiadaan record, bukan dari status tersimpan.
                    continue;
                }

                $isLate = $workdayIndex % $pattern['late'] === 0;
                $forgetCheckout = $workdayIndex % $pattern['forget'] === 0;

                $checkIn = $isLate
                    ? $service->shiftStart($date)->addMinutes($this->rand(20, 68))
                    : $service->shiftStart($date)->subMinutes($this->rand(2, 22));

                $checkOut = null;
                $checkOutStatus = null;
                if (! $forgetCheckout) {
                    $offset = $this->rand(-25, 75);
                    $checkOut = $service->shiftEnd($date)->addMinutes($offset);
                    $checkOutStatus = match (true) {
                        $offset < -AttendanceService::TOLERANCE_MINUTES => 'early',
                        $offset > AttendanceService::TOLERANCE_MINUTES => 'overtime',
                        default => 'normal',
                    };
                }

                Attendance::updateOrCreate(
                    ['user_id' => $user->id, 'date' => $date->toDateString()],
                    [
                        'check_in_at' => $checkIn,
                        'check_out_at' => $checkOut,
                        'check_in_status' => $isLate ? 'late' : 'on_time',
                        'check_out_status' => $checkOutStatus,
                    ]
                );
            }
        }
    }

    private function seedTodayAttendance(User $user, Carbon $date, string $mode, AttendanceService $service): void
    {
        if ($mode === 'none') {
            Attendance::where('user_id', $user->id)->where('date', $date->toDateString())->delete();

            return;
        }

        $checkIn = $mode === 'late'
            ? $service->shiftStart($date)->addMinutes(37)
            : $service->shiftStart($date)->subMinutes(6);

        Attendance::updateOrCreate(
            ['user_id' => $user->id, 'date' => $date->toDateString()],
            [
                'check_in_at' => $checkIn,
                'check_out_at' => null,
                'check_in_status' => $mode === 'late' ? 'late' : 'on_time',
                'check_out_status' => null,
            ]
        );
    }

    // =================================================================
    // 17. Notifikasi
    // =================================================================

    private function seedNotifications(): void
    {
        $defs = [
            ['user' => 'manager',    'title' => 'Rencana Konten Perlu Disetujui', 'type' => 'plan_submitted',  'body' => 'Rencana konten Nusa Apparel bulan depan diajukan oleh Siti Rahma.',                    'ref' => null,    'read' => false, 'hours' => 1],
            ['user' => 'manager',    'title' => 'Konten Menunggu Persetujuan',    'type' => 'approval',        'body' => '"Koleksi Weekend Essentials" sudah diajukan dan menunggu peninjauan.',                  'ref' => 'NA-03', 'read' => false, 'hours' => 3],
            ['user' => 'designer2',  'title' => 'Revisi Baru',                    'type' => 'revision',        'body' => 'Ada catatan revisi baru pada "Pilihan Warna Favorit Minggu Ini": caption perlu lebih singkat.', 'ref' => 'NA-05', 'read' => false, 'hours' => 5],
            ['user' => 'creator',    'title' => 'Brief Sudah Final',              'type' => 'brief_finalized', 'body' => 'Brief untuk "Tips Menyeduh Kopi Tanpa Alat Mahal" sudah difinalkan dan siap dikerjakan.', 'ref' => 'KS-08', 'read' => false, 'hours' => 8],
            ['user' => 'creator',    'title' => 'Konten Ditugaskan',              'type' => 'task',            'body' => 'Kamu ditugaskan sebagai penanggung jawab "Ngopi Sore Bareng Komunitas".',             'ref' => 'KS-11', 'read' => false, 'hours' => 20],
            ['user' => 'designer',   'title' => 'Konten Terlambat',               'type' => 'overdue',         'body' => '"Kopi Senja Buka Cabang Baru" sudah melewati deadline dan masih dikerjakan.',          'ref' => 'KS-10', 'read' => false, 'hours' => 26],
            ['user' => 'designer',   'title' => 'Revisi Baru dari Klien',         'type' => 'revision',        'body' => 'Kopi Senja meminta perbaikan pada "Testimoni Pelanggan Setia".',                       'ref' => 'KS-09', 'read' => true,  'hours' => 34],
            ['user' => 'copywriter', 'title' => 'Rencana Konten Disetujui',       'type' => 'plan_approved',   'body' => 'Rencana konten Nusa Apparel bulan ini sudah disetujui Nadia Putri.',                    'ref' => null,    'read' => true,  'hours' => 50],
            ['user' => 'smo',        'title' => 'Klien Sudah Setuju - Perlu Dicek','type' => 'client_approved','body' => '"Tips Menyeduh Kopi Tanpa Alat Mahal" sudah disetujui klien, tunggu pengecekan internal.', 'ref' => 'KS-08', 'read' => true,  'hours' => 58],
            ['user' => 'smo',        'title' => 'Import Performa Selesai',        'type' => 'system',          'body' => 'Import CSV performa Kopi Senja selesai: 148 baris masuk, 2 baris dilewati.',            'ref' => null,    'read' => true,  'hours' => 72],
            ['user' => 'ceo',        'title' => 'Konten Menunggu Persetujuan',    'type' => 'approval',        'body' => '"Cara Menyusun Jadwal Belajar" menunggu peninjauan internal.',                          'ref' => 'RB-02', 'read' => true,  'hours' => 80],
        ];

        foreach ($defs as $def) {
            $related = $def['ref'] ? $this->items[$def['ref']] : null;

            $notif = Notification::create([
                'user_id' => $this->users[$def['user']]->id,
                'title' => $def['title'],
                'type' => $def['type'],
                'body' => $def['body'],
                'related_type' => $related ? ContentItem::class : null,
                'related_id' => $related?->id,
                'is_read' => $def['read'],
            ]);

            $at = $this->now->copy()->subHours($def['hours']);
            $notif->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    // =================================================================
    // 18. Pin (fokus saya di Beranda)
    // =================================================================

    private function seedPins(): void
    {
        $pins = [
            'creator' => ['KS-08', 'NA-04'],
            'designer' => ['KS-10'],
            'copywriter' => ['RB-02'],
        ];

        foreach ($pins as $userKey => $refs) {
            foreach ($refs as $ref) {
                Pin::create([
                    'user_id' => $this->users[$userKey]->id,
                    'pinnable_type' => ContentItem::class,
                    'pinnable_id' => $this->items[$ref]->id,
                ]);
            }
        }
    }

    // =================================================================
    // Ringkasan
    // =================================================================

    private function report(): void
    {
        $itemIds = $this->items->pluck('id')->all();
        $clientIds = $this->clients->pluck('id')->all();

        $statuses = ContentWorkflow::whereIn('content_item_id', $itemIds)
            ->selectRaw('current_status, count(*) as total')
            ->groupBy('current_status')
            ->pluck('total', 'current_status');

        $lines = [
            'Users              : '.$this->users->count(),
            'Clients            : '.$this->clients->count(),
            'Content plans      : '.$this->plans->count(),
            'Content items      : '.$this->items->count(),
            'AI Brief drafts    : '.ContentBriefDraft::whereIn('content_item_id', $itemIds)->count(),
            'Revisions          : '.ContentRevision::whereIn('content_item_id', $itemIds)->count(),
            'Publications       : '.ContentPublication::whereIn('content_item_id', $itemIds)->count(),
            'Content metrics    : '.ContentMetric::whereIn('client_id', $clientIds)->count(),
            'Audience snapshots : '.AudienceInsight::whereIn('client_id', $clientIds)->count(),
            'AI Strategy        : '.AiStrategyInsight::whereIn('client_id', $clientIds)->count(),
            'Delay risk scores  : '.DelayRiskScore::whereIn('content_item_id', $itemIds)->count(),
            'Attendance         : '.Attendance::whereIn('user_id', $this->users->pluck('id'))->count(),
            'Notifications      : '.Notification::whereIn('user_id', $this->users->pluck('id'))->count(),
        ];

        foreach ($lines as $line) {
            $this->command?->line('  '.$line);
        }

        $coverage = collect(WorkflowTransitions::labels())
            ->map(fn ($label, $status) => $label.': '.($statuses[$status] ?? 0))
            ->implode(' | ');

        $this->command?->line('  Workflow coverage  : '.$coverage);

        // Token Portal Klien SENGAJA tidak pernah dicetak ke konsol - ambil
        // lewat halaman Detail Klien kalau perlu membuka portalnya.
        $this->command?->info('Selesai. Token Portal Klien tidak ditampilkan di sini - buka Kelola Klien > Detail Klien untuk menyalinnya.');
    }
}
