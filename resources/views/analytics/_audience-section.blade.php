{{-- Tab "Audience" - dipindah dari audience/index.blade.php, sekarang jadi
     bagian dari halaman Analytics (bukan halaman terpisah lagi). Client,
     dipilih dari filter bar bersama di atas.

     Precedence sumber data (lihat AnalyticsController::buildAudienceTabData()):
     - $audienceSource === 'instagram_api' -> section di bawah baca 3 row
       demographic_type terpisah (follower/reached/engaged) + 1 row summary,
       SEMUA field boleh null (belum tersedia dari Instagram), TIDAK PERNAH
       ditampilkan sebagai 0 palsu.
     - $audienceSource === 'csv' -> fallback, behavior identik sebelum API
       ada (1 snapshot/hari, generic). --}}

{{-- Import CSV --}}
<div class="card p-5 mb-6">
    @if (session('import_success'))
        <div class="mb-4 bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] rounded-lg p-3.5">
            <p class="text-sm font-medium text-[var(--brand)] flex items-center gap-2">
                <span class="material-symbols-outlined text-[17px]">check_circle</span>
                {{ session('import_success') }}
            </p>
            @if (! empty(session('import_skipped')))
                <ul class="mt-2 ml-6 list-disc text-xs text-[var(--text-secondary)] space-y-0.5">
                    @foreach (session('import_skipped') as $skip)
                        <li>{{ $skip }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if (session('import_error'))
        <div class="mb-4 bg-[var(--danger-tint)] border border-[var(--danger-border)] rounded-lg p-3.5">
            <p class="text-sm font-medium text-[var(--danger-text)] flex items-center gap-2">
                <span class="material-symbols-outlined text-[17px]">error</span>
                {{ session('import_error') }}
            </p>
        </div>
    @endif

    {{-- Phase 4.4 (Langkah 3) - CSV import MUTATING (analytics,manage,
         Phase 4.2), seluruh section ini murni fitur upload (tidak ada
         konten read-only yang perlu dipertahankan buat view-only user). --}}
    @if (auth()->user()->hasPermissionTo('analytics', 'manage'))
    <details {{ !empty($noInsightData) ? 'open' : '' }}>
        <summary class="cursor-pointer flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-[var(--brand-tint)] flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[var(--brand)] text-[17px]">upload_file</span>
            </div>
            <div>
                <p class="text-sm font-medium text-[var(--text-primary)]">Import Data Audiens (CSV)</p>
                <p class="text-xs text-[var(--text-muted)]">Followers, gender, usia, dan top lokasi untuk {{ $client->name ?? '' }}.</p>
            </div>
        </summary>

        <form action="{{ route('audience.import') }}" method="POST" enctype="multipart/form-data" class="mt-4 pl-11">
            @csrf
            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">

            <div class="flex items-center gap-3 flex-wrap">
                <input type="file" name="file" accept=".csv,.txt" required
                       class="text-sm border border-[var(--border)] rounded-lg px-3.5 py-2 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-[var(--brand-tint)] file:text-[var(--brand)] file:text-xs file:font-medium">
                <button type="submit" class="btn-primary">
                    Upload &amp; Import
                </button>
            </div>

            @if (in_array($audienceSource ?? null, ['instagram_api', 'tiktok_api'], true))
                <p class="text-[11px] text-[var(--warning-text)] mt-2">Klien ini sudah pakai data {{ $audienceSource === 'tiktok_api' ? 'TikTok API' : 'Instagram API' }} real - import CSV tetap tersimpan, tapi TIDAK ditampilkan di sini selama data API tersedia.</p>
            @endif

            <details class="mt-3">
                <summary class="text-xs font-medium text-[var(--brand)] cursor-pointer">Lihat format kolom CSV</summary>
                <div class="mt-2 bg-[var(--surface-page)] rounded-lg p-3 text-xs text-[var(--text-secondary)] font-mono overflow-x-auto">
                    platform,snapshot_date,follower_count,gender_male_pct,age_13_17_pct,age_18_24_pct,age_25_34_pct,age_35_44_pct,age_45_plus_pct,location_1,location_1_pct,location_2,location_2_pct,location_3,location_3_pct<br>
                    Instagram,2026-07-01,15230,42,5,30,40,15,10,Jakarta,35,Bandung,20,Surabaya,15
                </div>
                <p class="text-[11px] text-[var(--text-muted)] mt-1.5">
                    gender_female_pct dihitung otomatis (100 - male). Kolom yang nggak diisi nggak menimpa data lama.
                </p>
            </details>
        </form>
    </details>
    @endif
</div>

@if (! empty($noPlatformSelected))
    {{-- Filter Platform global = "Semua Platform" - audiens TIDAK PERNAH
         di-aggregate lintas platform (unit demografi beda per platform,
         gabungan jadi angka yang nggak berarti - Phase 1 item 5), jadi
         minta user pilih 1 platform dulu lewat filter global di atas. --}}
    <div class="card p-16 flex flex-col items-center justify-center text-center">
        <div class="w-14 h-14 rounded-full bg-[var(--brand-tint)] flex items-center justify-center mb-4">
            <span class="material-symbols-outlined text-[var(--brand)] text-[24px]">filter_alt</span>
        </div>
        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1.5">Pilih platform untuk melihat detail audiens</h2>
        <p class="text-sm text-[var(--text-secondary)] max-w-sm">Karakteristik audiens beda-beda per platform - pilih salah satu di filter Platform pada bar di atas.</p>
    </div>

@elseif (! empty($noInsightData))
    <div class="card p-16 flex flex-col items-center justify-center text-center">
        <div class="w-14 h-14 rounded-full bg-[var(--warning-tint)] flex items-center justify-center mb-4">
            <span class="material-symbols-outlined text-[var(--warning-text)] text-[26px]">database</span>
        </div>
        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1.5">Data audience belum disinkronkan untuk {{ $client->name }}</h2>
        <p class="text-sm text-[var(--text-secondary)] max-w-sm">Connect {{ $platform->name ?? 'platform ini' }} di Client Detail buat sync otomatis, atau buka panel "Import Data Audiens" di atas buat upload CSV.</p>
    </div>

@elseif (in_array($audienceSource ?? null, ['instagram_api', 'tiktok_api'], true))

    @php $isInstagramApi = $audienceSource === 'instagram_api'; @endphp

    <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
        <span class="badge badge-success inline-flex items-center gap-1">
            <span class="material-symbols-outlined text-[13px]">verified</span> {{ $isInstagramApi ? 'Instagram API' : 'TikTok API' }}
        </span>
        @if ($lastSyncAt)
            <span class="text-xs text-[var(--text-muted)]">Last Audience Sync: {{ \Illuminate\Support\Carbon::parse($lastSyncAt)->translatedFormat('d M Y, H:i') }}</span>
        @endif
    </div>

    {{-- PASS 3 (Langkah J, "DATA HEALTH UX") - default sehat TIDAK
         menampilkan apapun tambahan (cukup badge+freshness di atas) - HANYA
         muncul kalau memang ada keterbatasan genuine, ringkas & progressive
         disclosure (bukan banner permanen tiap kartu null - Langkah Q). --}}
    @if (! empty($dataHealthItems ?? []))
        <details class="mb-4">
            <summary class="text-xs font-medium text-[var(--warning-text)] cursor-pointer inline-flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[15px]">info</span>
                Sebagian data memiliki keterbatasan · Lihat kondisi data
            </summary>
            <div class="mt-2 pl-6 space-y-1">
                @foreach ($dataHealthItems as $healthItem)
                    <p class="text-xs text-[var(--text-muted)]">{{ $healthItem['label'] }} — {{ \App\Services\AvailabilityPresenter::labelForPlatform($healthItem['category'], $platform->name) }}</p>
                @endforeach
            </div>
        </details>
    @endif

    {{-- Followers + (Instagram only: Reach) overview - card followers
         GENERIC, dipakai Instagram MAUPUN TikTok (follower_count sama-sama
         disimpan lewat AudienceInsight summary row, lihat
         TikTokAnalyticsSyncService::saveProfileSnapshot()). --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
        <div class="card p-6 bg-[var(--brand-solid)] border-0">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-white/70 text-[17px]">person</span>
                <span class="text-xs font-medium tracking-wide text-white/70 uppercase">{{ $platform->name }} Followers</span>
            </div>
            @if (! is_null($lastCount))
                <p class="font-display text-[34px] font-semibold text-white mb-1.5 [font-variant-numeric:tabular-nums]">{{ number_format($lastCount) }}</p>
                @if (! is_null($growth))
                    <p class="text-xs font-medium flex items-center gap-1 {{ $growth >= 0 ? 'text-[var(--success-strong)]' : 'text-[var(--danger-border-soft)]' }}">
                        <span class="material-symbols-outlined text-[13px]">{{ $growth >= 0 ? 'trending_up' : 'trending_down' }}</span>
                        {{ $growth >= 0 ? '+' : '' }}{{ $growth }}% ({{ $periodLabel ?? 'periode terpilih' }})
                    </p>
                @else
                    <p class="text-xs text-white/50">{{ $growthMessage }}</p>
                @endif
            @else
                <p class="text-sm text-white/60 py-2">Data belum tersedia dari {{ $platform->name }}.</p>
            @endif
        </div>

        @if ($isInstagramApi)
            <div class="card p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-[var(--text-muted)] text-[17px]">visibility</span>
                    <span class="text-xs font-medium tracking-wide text-[var(--text-muted)] uppercase">Reach Akun (Hari Ini)</span>
                </div>
                @if (! is_null($latestReach))
                    <p class="font-display text-[34px] font-semibold text-[var(--text-primary)] mb-1.5 [font-variant-numeric:tabular-nums]">{{ number_format($latestReach) }}</p>
                    <p class="text-xs text-[var(--text-muted)]">Akun unik yang lihat konten hari ini.</p>
                @else
                    <p class="text-sm text-[var(--text-muted)] py-2">Data belum tersedia dari Instagram.</p>
                @endif
            </div>
        @endif

        <div class="card p-6 {{ $isInstagramApi ? '' : 'lg:col-span-2' }}">
            <h2 class="font-display text-sm font-semibold text-[var(--text-primary)] mb-1">Pertumbuhan Followers</h2>
            <p class="text-xs text-[var(--text-muted)] mb-3">Tren total follower {{ $platform->name }}.</p>
            @if ($followerTrend->isEmpty())
                <p class="text-xs text-[var(--text-muted)] text-center py-6">Belum ada histori followers pada periode ini.</p>
            @else
                <x-trend-chart :trend="$followerTrend" />
            @endif
        </div>
    </div>

    @if ($isInstagramApi)
        {{-- Reach trend --}}
        <div class="card p-6 mb-5">
            <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-1">Tren Reach</h2>
            <p class="text-xs text-[var(--text-muted)] mb-5">Tren reach akun harian {{ $platform->name }}.</p>
            @if ($reachTrend->isEmpty())
                <p class="text-sm text-[var(--text-muted)] text-center py-12">Belum ada histori reach pada periode ini.</p>
            @else
                <x-trend-chart :trend="$reachTrend" />
            @endif
        </div>

        {{-- Active hours --}}
        <div class="card p-6 mb-5">
            <div class="flex items-center justify-between mb-1">
                <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Jam Aktif Audiens</h2>
                @if ($peakHour && $peakHour['value'] > 0)
                    <span class="badge badge-success">Paling aktif: {{ $peakHour['label'] }}</span>
                @endif
            </div>
            <p class="text-xs text-[var(--text-muted)] mb-5">Sebaran follower online per jam, snapshot terakhir.</p>

            @if (is_null($activeHours))
                <p class="text-sm text-[var(--text-muted)] text-center py-12">Data belum tersedia dari Instagram untuk akun ini.</p>
            @else
                <x-trend-chart :trend="$activeHours" />
            @endif
        </div>

        {{-- Demographics: follower / reached / engaged - 3 row terpisah, tiap
             section boleh unavailable independen dari yang lain (Langkah 8/9). --}}
        @php
            $demographicMeta = [
                'follower' => ['title' => 'Follower Demographics', 'desc' => 'Karakteristik seluruh follower akun ini saat ini.'],
                'reached' => ['title' => 'Reached Audience', 'desc' => 'Karakteristik audiens yang dijangkau, 30 hari terakhir.'],
                'engaged' => ['title' => 'Engaged Audience', 'desc' => 'Karakteristik audiens yang berinteraksi, 30 hari terakhir.'],
            ];
            $genderColors = ['male' => '#3452a8', 'female' => '#b3427e', 'other' => '#9aa0a4'];
            $genderLabels = ['male' => 'Laki-laki', 'female' => 'Perempuan', 'other' => 'Lainnya'];
        @endphp

        @foreach ($demographicMeta as $type => $meta)
            @php $demo = $demographics[$type] ?? null; @endphp
            <div class="card p-6 mb-5">
                <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-1">{{ $meta['title'] }}</h2>
                <p class="text-xs text-[var(--text-muted)] mb-5">{{ $meta['desc'] }}</p>

                @if (! $demo)
                    <p class="text-sm text-[var(--text-muted)] text-center py-10">Data demografi {{ strtolower($meta['title']) }} belum tersedia dari Instagram untuk akun ini.</p>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                        {{-- Gender --}}
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Gender</h3>
                            @if (empty($demo['gender_breakdown']))
                                <p class="text-xs text-[var(--text-muted)] text-center py-6">Belum ada data.</p>
                            @else
                                <div class="space-y-3">
                                    @foreach ($demo['gender_breakdown'] as $key => $value)
                                        <div>
                                            <div class="flex items-center justify-between mb-1 text-xs">
                                                <span class="text-[var(--text-secondary)]">{{ $genderLabels[$key] ?? ucfirst($key) }}</span>
                                                <span class="font-medium text-[var(--text-primary)]">{{ $value }}%</span>
                                            </div>
                                            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                                                <div class="h-full rounded-full" style="width: {{ $value }}%; background-color: {{ $genderColors[$key] ?? '#9aa0a4' }}"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- Age --}}
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Rentang Usia</h3>
                            @if (empty($demo['age_breakdown']))
                                <p class="text-xs text-[var(--text-muted)] text-center py-6">Belum ada data.</p>
                            @else
                                <div class="space-y-3">
                                    @foreach ($demo['age_breakdown'] as $range => $value)
                                        <div>
                                            <div class="flex items-center justify-between mb-1 text-xs">
                                                <span class="text-[var(--text-secondary)]">{{ $range }} th</span>
                                                <span class="font-medium text-[var(--text-primary)]">{{ $value }}%</span>
                                            </div>
                                            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                                                <div class="h-full rounded-full bg-[var(--brand)]" style="width: {{ $value }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- Top cities --}}
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Kota Teratas</h3>
                            @if (empty($demo['top_locations']))
                                <p class="text-xs text-[var(--text-muted)] text-center py-6">Belum ada data.</p>
                            @else
                                <div class="space-y-3">
                                    @foreach (array_slice($demo['top_locations'], 0, 5) as $i => $loc)
                                        <div>
                                            <div class="flex items-center justify-between mb-1 text-xs">
                                                <span class="text-[var(--text-secondary)] truncate">{{ $loc['city'] }}</span>
                                                <span class="font-medium text-[var(--text-primary)] shrink-0 ml-2">{{ $loc['percentage'] }}%</span>
                                            </div>
                                            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                                                <div class="h-full rounded-full bg-[var(--warning-strong)]" style="width: {{ $loc['percentage'] }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- Top countries --}}
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Negara Teratas</h3>
                            @if (empty($demo['top_countries']))
                                <p class="text-xs text-[var(--text-muted)] text-center py-6">Belum ada data.</p>
                            @else
                                <div class="space-y-3">
                                    @foreach (array_slice($demo['top_countries'], 0, 5) as $i => $loc)
                                        <div>
                                            <div class="flex items-center justify-between mb-1 text-xs">
                                                <span class="text-[var(--text-secondary)] truncate">{{ $loc['country'] }}</span>
                                                <span class="font-medium text-[var(--text-primary)] shrink-0 ml-2">{{ $loc['percentage'] }}%</span>
                                            </div>
                                            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                                                <div class="h-full rounded-full bg-[#0d9488]" style="width: {{ $loc['percentage'] }}%"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    @else
        {{-- TikTok - Display API standar TIDAK menyediakan reach/active
             hours/demographic breakdown SAMA SEKALI (beda dari Instagram
             Graph API) - honest UNSUPPORTED state, BUKAN "belum tersedia"
             (yang menyiratkan mungkin muncul nanti kalau ditunggu/disync
             ulang - itu keliru buat TikTok, datanya memang tidak pernah
             disediakan API ini). Statistik profil & performa video TETAP
             disinkronkan otomatis lewat Analitik Konten TikTok di Client
             Detail - cuma insight audiens lanjutan yang tidak ada. --}}
        <div class="card p-6 mb-5">
            <div class="flex items-start gap-3">
                <div class="w-9 h-9 rounded-xl bg-[var(--surface-muted)] flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px]">info</span>
                </div>
                <div>
                    <p class="text-sm font-medium text-[var(--text-primary)] mb-1">Insight audiens lanjutan tidak tersedia melalui TikTok API yang digunakan</p>
                    <p class="text-xs text-[var(--text-secondary)] leading-relaxed">TikTok Display API standar tidak menyediakan reach, jam aktif, atau demografi follower/reached/engaged - berbeda dari Instagram Graph API. Statistik profil dan performa video tetap disinkronkan secara otomatis lewat "Analitik Konten" di Client Detail.</p>
                </div>
            </div>
        </div>
    @endif

@else

    {{-- CSV/legacy - behavior identik sebelum Instagram Audience API ada --}}
    <div class="flex items-center justify-end mb-4">
        <span class="badge badge-neutral inline-flex items-center gap-1">
            <span class="material-symbols-outlined text-[13px]">upload_file</span> CSV Import
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
        <div class="card p-6 bg-[var(--brand-solid)] border-0">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-white/70 text-[17px]">person</span>
                <span class="text-xs font-medium tracking-wide text-white/70 uppercase">{{ $platform->name }} Followers</span>
            </div>
            <p class="font-display text-[34px] font-semibold text-white mb-1.5 [font-variant-numeric:tabular-nums]">{{ number_format($lastCount) }}</p>
            @if (! is_null($growth))
                <p class="text-xs font-medium flex items-center gap-1 {{ $growth >= 0 ? 'text-[var(--success-strong)]' : 'text-[var(--danger-border-soft)]' }}">
                    <span class="material-symbols-outlined text-[13px]">{{ $growth >= 0 ? 'trending_up' : 'trending_down' }}</span>
                    {{ $growth >= 0 ? '+' : '' }}{{ $growth }}% ({{ $periodLabel ?? 'periode terpilih' }})
                </p>
            @else
                <p class="text-xs text-white/50">{{ $growthMessage ?? 'Belum cukup data historis.' }}</p>
            @endif
        </div>

        <div class="lg:col-span-2 card p-6">
            <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1">Pertumbuhan Followers</h2>
            <p class="text-xs text-[var(--text-muted)] mb-5">Tren jumlah follower {{ $platform->name }}.</p>

            @if ($followerTrend->isEmpty())
                <p class="text-sm text-[var(--text-muted)] text-center py-12">Belum ada histori followers pada periode ini.</p>
            @else
                <x-trend-chart :trend="$followerTrend" />
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">

        {{-- Gender --}}
        <div class="card p-6">
            <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-4">Gender</h2>
            @if (empty($genderBreakdown))
                <p class="text-sm text-[var(--text-muted)] text-center py-8">Belum ada data.</p>
            @else
                @php
                    $genderColors = ['male' => '#3452a8', 'female' => '#b3427e', 'other' => '#9aa0a4'];
                    $genderLabels = ['male' => 'Laki-laki', 'female' => 'Perempuan', 'other' => 'Lainnya'];
                @endphp
                <div class="space-y-3.5">
                    @foreach ($genderBreakdown as $key => $value)
                        <div>
                            <div class="flex items-center justify-between mb-1.5 text-sm">
                                <span class="text-[var(--text-secondary)]">{{ $genderLabels[$key] ?? ucfirst($key) }}</span>
                                <span class="font-medium text-[var(--text-primary)]">{{ $value }}%</span>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                                <div class="h-full rounded-full" style="width: {{ $value }}%; background-color: {{ $genderColors[$key] ?? '#9aa0a4' }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Age --}}
        <div class="card p-6">
            <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-4">Rentang Usia</h2>
            @if (empty($ageBreakdown))
                <p class="text-sm text-[var(--text-muted)] text-center py-8">Belum ada data.</p>
            @else
                <div class="space-y-3.5">
                    @foreach ($ageBreakdown as $range => $value)
                        <div>
                            <div class="flex items-center justify-between mb-1.5 text-sm">
                                <span class="text-[var(--text-secondary)]">{{ $range }} tahun</span>
                                <span class="font-medium text-[var(--text-primary)]">{{ $value }}%</span>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                                <div class="h-full rounded-full bg-[var(--brand)]" style="width: {{ $value }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Top Locations --}}
        <div class="card p-6">
            <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-4">Lokasi Teratas</h2>
            @if ($topLocations->isEmpty())
                <p class="text-sm text-[var(--text-muted)] text-center py-8">Belum ada data.</p>
            @else
                <div class="space-y-3.5">
                    @foreach ($topLocations->take(5) as $i => $loc)
                        <div>
                            <div class="flex items-center justify-between mb-1.5 text-sm">
                                <span class="text-[var(--text-secondary)] flex items-center gap-2">
                                    <span class="w-4 h-4 rounded-full bg-[var(--warning-tint-soft)] text-[var(--warning-text)] text-[9px] font-semibold flex items-center justify-center">{{ $i + 1 }}</span>
                                    {{ $loc['city'] }}
                                </span>
                                <span class="font-medium text-[var(--text-primary)]">{{ $loc['percentage'] }}%</span>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                                <div class="h-full rounded-full bg-[var(--warning-strong)]" style="width: {{ $loc['percentage'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Active hours --}}
    <div class="card p-6 mt-5">
        <div class="flex items-center justify-between mb-1">
            <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Jam Aktif Audiens</h2>
            @if ($peakHour && $peakHour['value'] > 0)
                <span class="badge badge-success">Paling aktif: {{ $peakHour['label'] }}</span>
            @endif
        </div>
        <p class="text-xs text-[var(--text-muted)] mb-5">Sebaran aktivitas audience per jam, berdasarkan snapshot terakhir.</p>

        @if (collect($activeHours)->sum('value') === 0)
            <p class="text-sm text-[var(--text-muted)] text-center py-12">Belum ada data jam aktif.</p>
        @else
            <x-trend-chart :trend="$activeHours" />
        @endif
    </div>

@endif
