<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\ContentMetric;
use App\Models\ContentMetricSnapshot;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentPublication;
use App\Models\ContentStatusLog;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Platform;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * ⚠️ JANGAN JALANKAN DI DEV DB OPERASIONAL (`digidaw`) - sama seperti
 * DemoSeeder, ini bikin Client/User/ContentItem FIKTIF. Hanya aman di
 * database kosong/terpisah untuk mencoba fitur KPI (`/team-performance`).
 *
 * TIDAK dipanggil otomatis oleh DatabaseSeeder. Jalankan eksplisit:
 *   php artisan db:seed --class=KpiDemoDataSeeder
 *
 * Tujuan SATU-SATUNYA seeder ini: menghasilkan data yang secara SENGAJA
 * menembus SETIAP jalur atribusi KPI (lihat docs/kpi/ATTRIBUTION_RULES.md)
 * supaya bisa dicoba langsung di halaman /team-performance, bukan cuma
 * lewat PHPUnit sintetis:
 *
 * - Copywriter dapat KPI dari brief yang ditulis TANPA jadi PIC (Nadia).
 * - Content Creator/Graphic Designer dapat KPI dari PIC + aktivitas status
 *   log NYATA di periode ini (Budi, Sari, Fajar, Eko).
 * - Multi-PIC: dua Content Creator (Budi & Sari) di SATU content item yang
 *   sama - direct_outcome_score harus identik untuk keduanya.
 * - SMO dapat KPI dari publish yang BENAR-BENAR dia lakukan sendiri
 *   (Made, recorded_via=manual) - TERMASUK pada content yang PIC-nya orang
 *   lain (Eko) - dan Eko (PIC, bukan publisher) TIDAK dapat kredit SMO.
 * - Manager (Rani) dengan aktivitas PRODUKSI (PIC) di klien Griya Modern
 *   DAN LEADERSHIP (approval) di klien Griya Modern YANG SAMA -> di-merge
 *   jadi SATU baris. Rani JUGA leadership di Kopi Nusantara (klien
 *   BERBEDA) -> baris terpisah.
 * - Multi-client breakdown: Nadia (Copywriter) menulis brief untuk KEDUA
 *   klien di periode yang sama -> dua baris per-klien.
 * - Peer pool cukup (>=8 publication per client+platform+format) supaya
 *   direct_outcome_score/portfolio_outcome_score BENAR-BENAR terisi
 *   (bukan "Data Belum Cukup" semua).
 *
 * Semua aktivitas (brief/status log/publication/decision) sengaja
 * ditanggalkan di awal bulan "2 bulan lalu" (relatif ke kapan seeder ini
 * dijalankan, BUKAN tanggal hardcoded) - supaya window D+7 DAN D+30 sudah
 * pasti lewat kapan pun ini dijalankan (coverage Full, bukan Provisional),
 * dan supaya publication-nya tetap jatuh DI DALAM periode "2 bulan lalu"
 * yang harus dipilih di filter halaman Team Performance.
 *
 * TIDAK menjalankan kalkulasi KPI apa pun (sesuai desain - KPI dihitung
 * background job otomatis). Untuk lihat hasilnya SEKARANG tanpa menunggu
 * queue worker, jalankan manual (lihat instruksi yang dicetak di akhir
 * seeder ini):
 *   php artisan kpi:calculate --month=YYYY-MM
 */
class KpiDemoDataSeeder extends Seeder
{
    private Carbon $periodStart;

    private Carbon $periodEnd;

    private Platform $instagram;

    private ContentType $videoType;

    private ContentType $desainType;

    private ContentPillar $pillar;

    public function run(): void
    {
        $this->call(MasterDataSeeder::class);

        $twoMonthsAgo = Carbon::now()->subMonthsNoOverflow(2);
        $this->periodStart = $twoMonthsAgo->copy()->startOfMonth();
        $this->periodEnd = $twoMonthsAgo->copy()->endOfMonth();

        $this->instagram = Platform::firstOrCreate(['name' => 'Instagram']);
        $this->videoType = ContentType::firstOrCreate(['name' => 'Video']);
        $this->desainType = ContentType::firstOrCreate(['name' => 'Desain']);
        $this->pillar = ContentPillar::first() ?? ContentPillar::create(['name' => 'Information']);

        $staff = $this->makeStaff();
        $clients = $this->makeClients();

        $plans = [
            $clients['kopi']->id => $this->makePlan($clients['kopi'], $staff['rani']),
            $clients['griya']->id => $this->makePlan($clients['griya'], $staff['rani']),
        ];

        // ===== Skenario utama - satu per satu, SENGAJA eksplisit (bukan loop acak) =====

        // 1. Copywriter (Nadia) dapat KPI dari brief KOPI NUSANTARA, TANPA jadi PIC.
        $itemKn1 = $this->makeContentItem($clients['kopi'], $plans[$clients['kopi']->id], $this->videoType, 'Kopi Nusantara - Reels Racikan Baru');
        $this->makeBrief($itemKn1, $staff['nadia']);
        // Multi-PIC: Budi & Sari SAMA-SAMA Content Creator di item ini.
        $this->makeAssignment($itemKn1, $staff['budi']);
        $this->makeAssignment($itemKn1, $staff['sari']);
        $this->makeProductionActivity($itemKn1, $staff['budi']);
        // Leadership: Rani approve item ini untuk Kopi Nusantara.
        $this->makeLeadershipDecision($itemKn1, $staff['rani']);
        // SMO: Made yang benar-benar publish (bukan Budi/Sari).
        $publicationKn1 = $this->makePublication($itemKn1, $staff['made']);
        $this->makeOutcomeSnapshots($itemKn1, $publicationKn1, viewsAt7: 4200, viewsAt30: 9800);

        // 2. Graphic Designer (Fajar) + brief Nadia untuk GRIYA MODERN juga
        //    (Nadia jadi bukti "satu user, dua klien, dua baris breakdown").
        $itemGm1 = $this->makeContentItem($clients['griya'], $plans[$clients['griya']->id], $this->desainType, 'Griya Modern - Carousel Promo Unit Baru');
        $this->makeBrief($itemGm1, $staff['nadia']);
        $this->makeAssignment($itemGm1, $staff['fajar']);
        $this->makeProductionActivity($itemGm1, $staff['fajar']);
        $publicationGm1 = $this->makePublication($itemGm1, $staff['made']);
        $this->makeOutcomeSnapshots($itemGm1, $publicationGm1, viewsAt7: 1800, viewsAt30: 3600);

        // 3. PIC BUKAN publisher: Eko jadi PIC, tapi Made yang publish -
        //    Eko TIDAK BOLEH dapat baris KPI SMO untuk item ini.
        $itemGm2 = $this->makeContentItem($clients['griya'], $plans[$clients['griya']->id], $this->videoType, 'Griya Modern - Reels Testimoni Penghuni');
        $this->makeAssignment($itemGm2, $staff['eko']);
        $this->makeProductionActivity($itemGm2, $staff['eko']);
        $publicationGm2 = $this->makePublication($itemGm2, $staff['made']);
        $this->makeOutcomeSnapshots($itemGm2, $publicationGm2, viewsAt7: 5100, viewsAt30: 11200);

        // 4. Manager (Rani) SEBAGAI PIC produksi di Griya Modern...
        $itemGm3 = $this->makeContentItem($clients['griya'], $plans[$clients['griya']->id], $this->videoType, 'Griya Modern - Reels Sapaan dari Manajemen');
        $this->makeAssignment($itemGm3, $staff['rani']);
        $this->makeProductionActivity($itemGm3, $staff['rani']);
        $publicationGm3 = $this->makePublication($itemGm3, $staff['made']);
        $this->makeOutcomeSnapshots($itemGm3, $publicationGm3, viewsAt7: 2600, viewsAt30: 5400);
        // ...DAN leadership (approve) di Griya Modern JUGA - klien SAMA
        //    dengan produksinya di atas -> harus MERGE jadi SATU baris.
        $itemGm4 = $this->makeContentItem($clients['griya'], $plans[$clients['griya']->id], $this->desainType, 'Griya Modern - Single Feed Promo Cicilan');
        $this->makeLeadershipDecision($itemGm4, $staff['rani']);

        // ===== Filler - memenuhi minimum peer pool (>=8) per client+platform+format =====
        // Supaya direct_outcome_score/portfolio_outcome_score item DI ATAS
        // benar-benar terisi (bukan "Data Belum Cukup" karena peer kurang).
        // SENGAJA pakai staf FILLER TERPISAH (bukan Budi/Sari/dkk dari
        // skenario di atas) - kalau filler kebetulan "nyasar" ke Budi/Sari,
        // direct_outcome_score AGREGAT mereka jadi tercampur item lain,
        // padahal maksud demo ini SPESIFIK menunjukkan skor identik untuk
        // SATU content item yang sama (bukan rata-rata seluruh bulan).
        $fillerStaff = $this->makeFillerStaff();

        foreach ($clients as $client) {
            foreach ([$this->videoType, $this->desainType] as $type) {
                for ($i = 1; $i <= 9; $i++) {
                    $pic = $fillerStaff->random();
                    $item = $this->makeContentItem($client, $plans[$client->id], $type, "{$client->name} - Konten Rutin {$type->name} #{$i}");
                    $this->makeAssignment($item, $pic);
                    $this->makeProductionActivity($item, $pic);
                    $publication = $this->makePublication($item, $staff['made']);
                    $this->makeOutcomeSnapshots($item, $publication, viewsAt7: rand(800, 6000), viewsAt30: rand(2000, 14000));
                }
            }

            // Follower growth (opsional, buat komponen portfolio ketiga) - dua
            // titik (awal & akhir periode) sudah cukup untuk self-trend.
            $baseFollowers = rand(5000, 30000);
            AudienceInsight::firstOrCreate(
                ['client_id' => $client->id, 'platform_id' => $this->instagram->id, 'snapshot_date' => $this->periodStart->toDateString(), 'source' => AudienceInsight::SOURCE_API, 'demographic_type' => AudienceInsight::TYPE_SUMMARY],
                ['follower_count' => $baseFollowers]
            );
            AudienceInsight::firstOrCreate(
                ['client_id' => $client->id, 'platform_id' => $this->instagram->id, 'snapshot_date' => $this->periodEnd->toDateString(), 'source' => AudienceInsight::SOURCE_API, 'demographic_type' => AudienceInsight::TYPE_SUMMARY],
                ['follower_count' => $baseFollowers + rand(100, 1500)]
            );
        }

        $this->command?->info('KpiDemoDataSeeder selesai.');
        $this->command?->info("Periode data: {$this->periodStart->translatedFormat('F Y')} ({$this->periodStart->toDateString()} s/d {$this->periodEnd->toDateString()}).");
        $this->command?->warn("Jalankan ini untuk menghitung KPI-nya sekarang (queue lokal tidak otomatis diproses tanpa worker):");
        $this->command?->line("  php artisan kpi:calculate --month={$this->periodStart->format('Y-m')}");
        $this->command?->info('Lalu buka /team-performance dan pilih periode di atas pada filter bulan.');
        $this->command?->line('Login sebagai user role Manager/CEO/Admin untuk bisa mengakses halaman ini (lihat PermissionSeeder).');
    }

    /** @return array<string, User> */
    private function makeStaff(): array
    {
        $defs = [
            'nadia' => ['name' => 'Nadia Kusuma', 'email' => 'nadia.kusuma@kpidemo.test', 'role' => UserRole::Copywriter],
            'budi' => ['name' => 'Budi Santoso', 'email' => 'budi.santoso@kpidemo.test', 'role' => UserRole::ContentCreator],
            'sari' => ['name' => 'Sari Amelia', 'email' => 'sari.amelia@kpidemo.test', 'role' => UserRole::ContentCreator],
            'eko' => ['name' => 'Eko Prasetyo', 'email' => 'eko.prasetyo@kpidemo.test', 'role' => UserRole::ContentCreator],
            'fajar' => ['name' => 'Fajar Nugroho', 'email' => 'fajar.nugroho@kpidemo.test', 'role' => UserRole::DesainGrafis],
            'made' => ['name' => 'Made Wirawan', 'email' => 'made.wirawan@kpidemo.test', 'role' => UserRole::SMO],
            'rani' => ['name' => 'Rani Kartika', 'email' => 'rani.kartika@kpidemo.test', 'role' => UserRole::Manager],
        ];

        return collect($defs)->mapWithKeys(function (array $def, string $key) {
            $user = User::firstOrCreate(
                ['email' => $def['email']],
                ['name' => $def['name'], 'status' => 'active', 'login_enabled' => true]
            );
            $user->roles()->syncWithoutDetaching([Role::firstOrCreate(['name' => $def['role']->value])->id]);

            return [$key => $user];
        })->all();
    }

    /**
     * Staf FILLER khusus untuk mengisi peer pool - TERPISAH TOTAL dari staf
     * skenario (Budi/Sari/dkk) supaya direct_outcome_score/portfolio_outcome_score
     * mereka tetap murni mencerminkan skenario yang didemonstrasikan, tidak
     * tercampur rata-rata dari content item filler yang tidak relevan.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function makeFillerStaff(): \Illuminate\Support\Collection
    {
        $defs = [
            ['name' => 'Wulan Setiawati (Filler)', 'email' => 'wulan.filler@kpidemo.test', 'role' => UserRole::ContentCreator],
            ['name' => 'Doni Firmansyah (Filler)', 'email' => 'doni.filler@kpidemo.test', 'role' => UserRole::ContentCreator],
            ['name' => 'Intan Permata (Filler)', 'email' => 'intan.filler@kpidemo.test', 'role' => UserRole::DesainGrafis],
        ];

        return collect($defs)->map(function (array $def) {
            $user = User::firstOrCreate(
                ['email' => $def['email']],
                ['name' => $def['name'], 'status' => 'active', 'login_enabled' => true]
            );
            $user->roles()->syncWithoutDetaching([Role::firstOrCreate(['name' => $def['role']->value])->id]);

            return $user;
        });
    }

    /** @return array{kopi: Client, griya: Client} */
    private function makeClients(): array
    {
        $category = ClientCategory::first() ?? ClientCategory::create(['name' => 'UMKM']);

        return [
            'kopi' => Client::firstOrCreate(['name' => 'Kopi Nusantara (Demo KPI)'], ['client_category_id' => $category->id, 'status' => 'active']),
            'griya' => Client::firstOrCreate(['name' => 'Griya Modern (Demo KPI)'], ['client_category_id' => $category->id, 'status' => 'active']),
        ];
    }

    private function makePlan(Client $client, User $creator): ContentPlan
    {
        return ContentPlan::firstOrCreate(
            ['client_id' => $client->id, 'month' => $this->periodStart->month, 'year' => $this->periodStart->year],
            ['created_by' => $creator->id, 'approved_by' => $creator->id, 'status' => 'approved']
        );
    }

    private function makeContentItem(Client $client, ContentPlan $plan, ContentType $type, string $title): ContentItem
    {
        $item = ContentItem::create([
            'content_plan_id' => $plan->id,
            'client_id' => $client->id,
            'content_pillar_id' => $this->pillar->id,
            'content_type_id' => $type->id,
            'title' => $title,
            'brief' => $title,
            'deadline_at' => $this->periodStart->copy()->addDays(3),
            // Sama dengan published_at di makePublication() - SMO
            // "publication_schedule_adherence_rate" jadi 100% (tepat
            // jadwal), bukan 0% karena kolom ini kosong.
            'scheduled_upload_at' => $this->periodStart->copy()->addDays(4),
            'is_posted' => true,
        ]);

        ContentWorkflow::create([
            'content_item_id' => $item->id,
            'current_status' => 'uploaded',
        ])->forceFill(['created_at' => $this->periodStart->copy()->addDays(2)])->save();

        return $item;
    }

    private function makeBrief(ContentItem $item, User $copywriter): ContentBriefDraft
    {
        $createdAt = $this->periodStart->copy()->addDay();

        $brief = ContentBriefDraft::create([
            'content_item_id' => $item->id,
            'created_by' => $copywriter->id,
            'status' => 'finalized',
            'finalized_at' => $createdAt,
            'returned_count' => 0,
        ]);
        $brief->forceFill(['created_at' => $createdAt])->save();

        return $brief;
    }

    private function makeAssignment(ContentItem $item, User $pic): ContentItemAssignment
    {
        return ContentItemAssignment::updateOrCreate(
            ['content_item_id' => $item->id, 'user_id' => $pic->id],
            ['assignment_role' => 'primary']
        );
    }

    /** Transisi in_progress->waiting_review NYATA di periode ini - syarat wajib koreksi lanjutan #1 (content item dianggap "aktif periode ini"). */
    private function makeProductionActivity(ContentItem $item, User $actor): void
    {
        $day = $this->periodStart->copy()->addDays(3);

        foreach ([['brief_ready', 'in_progress'], ['in_progress', 'waiting_review'], ['waiting_review', 'approved']] as $i => [$from, $to]) {
            $changedAt = $day->copy()->addHours($i * 4);
            ContentStatusLog::create([
                'content_item_id' => $item->id,
                'changed_by_user_id' => $actor->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_at' => $changedAt,
            ])->forceFill(['created_at' => $changedAt])->save();
        }
    }

    /** Keputusan approve NYATA oleh Manager/CEO - dasar leadership KPI (ATTRIBUTION_RULES.md #9). */
    private function makeLeadershipDecision(ContentItem $item, User $leader): void
    {
        $changedAt = $this->periodStart->copy()->addDays(5);

        ContentStatusLog::create([
            'content_item_id' => $item->id,
            'changed_by_user_id' => $leader->id,
            'from_status' => 'waiting_review',
            'to_status' => 'approved',
            'changed_at' => $changedAt,
        ])->forceFill(['created_at' => $changedAt])->save();
    }

    /** `recorded_via=manual` SENGAJA eksplisit - inilah yang membuat published_by dipercaya sebagai atribusi SMO (lihat ContentPublication::isReliablyAttributedToPublisher()). */
    private function makePublication(ContentItem $item, User $publisher): ContentPublication
    {
        $publishedAt = $this->periodStart->copy()->addDays(4);

        $publication = ContentPublication::create([
            'content_item_id' => $item->id,
            'platform_id' => $this->instagram->id,
            'published_by' => $publisher->id,
            'recorded_via' => ContentPublication::RECORDED_VIA_MANUAL,
            'published_at' => $publishedAt,
            'post_url' => 'https://instagram.com/p/demo-'.$item->id,
        ]);

        // ContentMetric (BEDA dari ContentMetricSnapshot yang dipakai outcome
        // D7/D30 - ini yang dibaca "publication_analytics_match_rate" SMO)
        // - tanpa ini SEMUA publication dianggap "belum matched analytics",
        // bikin proses SMO 0% padahal skenarionya memang tidak menguji itu.
        ContentMetric::create([
            'content_item_id' => $item->id,
            'platform_id' => $this->instagram->id,
            'imported_by' => $publisher->id,
            'metric_date' => $publishedAt->toDateString(),
            'views' => 1000,
            'engagement_rate' => 5.0,
        ]);

        return $publication;
    }

    /** Snapshot PERSIS di published_at+7 dan +30 hari - Full coverage (bukan Provisional/Partial), lihat ContentOutcomeScoringService::computePublicationDelta(). */
    private function makeOutcomeSnapshots(ContentItem $item, ContentPublication $publication, int $viewsAt7, int $viewsAt30): void
    {
        foreach ([7 => $viewsAt7, 30 => $viewsAt30] as $days => $views) {
            ContentMetricSnapshot::create([
                'client_id' => $item->client_id,
                'platform_id' => $this->instagram->id,
                'content_item_id' => $item->id,
                'snapshot_date' => $publication->published_at->copy()->addDays($days)->toDateString(),
                'views' => $views,
                'reach' => (int) round($views * 0.85),
                'likes' => (int) round($views * 0.06),
                'comments' => (int) round($views * 0.01),
                'shares' => (int) round($views * 0.02),
                'saves' => (int) round($views * 0.015),
            ]);
        }
    }
}
