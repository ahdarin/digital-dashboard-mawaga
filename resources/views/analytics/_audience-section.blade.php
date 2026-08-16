{{-- Tab "Audience" - dipindah dari audience/index.blade.php, sekarang jadi
     bagian dari halaman Analytics (bukan halaman terpisah lagi). Client,
     dipilih dari filter bar bersama di atas. --}}

{{-- Import CSV --}}
<div class="card p-5 mb-6">
    @if (session('import_success'))
        <div class="mb-4 bg-[#f0f5f4] border border-[#dbe6e4] rounded-lg p-3.5">
            <p class="text-sm font-medium text-[#044b46] flex items-center gap-2">
                <span class="material-symbols-outlined text-[17px]">check_circle</span>
                {{ session('import_success') }}
            </p>
            @if (! empty(session('import_skipped')))
                <ul class="mt-2 ml-6 list-disc text-xs text-[#5c6266] space-y-0.5">
                    @foreach (session('import_skipped') as $skip)
                        <li>{{ $skip }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if (session('import_error'))
        <div class="mb-4 bg-[#fdf2f1] border border-[#f5d9d7] rounded-lg p-3.5">
            <p class="text-sm font-medium text-[#b3423e] flex items-center gap-2">
                <span class="material-symbols-outlined text-[17px]">error</span>
                {{ session('import_error') }}
            </p>
        </div>
    @endif

    <details {{ !empty($noInsightData) ? 'open' : '' }}>
        <summary class="cursor-pointer flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-[#f0f5f4] flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-[#044b46] text-[17px]">upload_file</span>
            </div>
            <div>
                <p class="text-sm font-medium text-[#14181a]">Import Audience Data (CSV)</p>
                <p class="text-xs text-[#767c80]">Followers, gender, usia, dan top lokasi untuk {{ $client->name ?? '' }}.</p>
            </div>
        </summary>

        <form action="{{ route('audience.import') }}" method="POST" enctype="multipart/form-data" class="mt-4 pl-11">
            @csrf
            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">

            <div class="flex items-center gap-3 flex-wrap">
                <input type="file" name="file" accept=".csv,.txt" required
                       class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-[#f0f5f4] file:text-[#044b46] file:text-xs file:font-medium">
                <button type="submit" class="btn-primary">
                    Upload &amp; Import
                </button>
            </div>

            <details class="mt-3">
                <summary class="text-xs font-medium text-[#044b46] cursor-pointer">Lihat format kolom CSV</summary>
                <div class="mt-2 bg-[#f7f8fc] rounded-lg p-3 text-xs text-[#5c6266] font-mono overflow-x-auto">
                    platform,snapshot_date,follower_count,gender_male_pct,age_13_17_pct,age_18_24_pct,age_25_34_pct,age_35_44_pct,age_45_plus_pct,location_1,location_1_pct,location_2,location_2_pct,location_3,location_3_pct<br>
                    Instagram,2026-07-01,15230,42,5,30,40,15,10,Jakarta,35,Bandung,20,Surabaya,15
                </div>
                <p class="text-[11px] text-[#767c80] mt-1.5">
                    gender_female_pct dihitung otomatis (100 - male). Kolom yang nggak diisi nggak menimpa data lama.
                </p>
            </details>
        </form>
    </details>
</div>

@if (! empty($noInsightData))
    <div class="card p-16 flex flex-col items-center justify-center text-center">
        <div class="w-14 h-14 rounded-full bg-[#fdf6ec] flex items-center justify-center mb-4">
            <span class="material-symbols-outlined text-[#8a6423] text-[26px]">database</span>
        </div>
        <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1.5">Belum ada data audience untuk {{ $client->name }}</h2>
        <p class="text-sm text-[#5c6266] max-w-sm">Buka panel "Import Audience Data" di atas buat upload CSV-nya.</p>
    </div>

@else

    {{-- Followers overview --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
        <div class="card p-6 bg-[#044b46] border-0">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-white/70 text-[17px]">person</span>
                <span class="text-xs font-medium tracking-wide text-white/70 uppercase">{{ $platform->name }} Followers</span>
            </div>
            <p class="font-display text-[34px] font-semibold text-white mb-1.5 [font-variant-numeric:tabular-nums]">{{ number_format($lastCount) }}</p>
            @if (! is_null($growth))
                <p class="text-xs font-medium flex items-center gap-1 {{ $growth >= 0 ? 'text-[#9fd8c4]' : 'text-[#f3b6b2]' }}">
                    <span class="material-symbols-outlined text-[13px]">{{ $growth >= 0 ? 'trending_up' : 'trending_down' }}</span>
                    {{ $growth >= 0 ? '+' : '' }}{{ $growth }}% dalam {{ $period }} hari
                </p>
            @else
                <p class="text-xs text-white/50">Belum cukup data historis.</p>
            @endif
        </div>

        <div class="lg:col-span-2 card p-6">
            <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1">Followers Growth</h2>
            <p class="text-xs text-[#767c80] mb-5">Tren jumlah follower {{ $platform->name }}.</p>

            @if ($followerTrend->isEmpty())
                <p class="text-sm text-[#767c80] text-center py-12">Belum ada histori followers pada periode ini.</p>
            @else
                <x-trend-chart :trend="$followerTrend" />
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">

        {{-- Gender --}}
        <div class="card p-6">
            <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Gender</h2>
            @if (empty($genderBreakdown))
                <p class="text-sm text-[#767c80] text-center py-8">Belum ada data.</p>
            @else
                @php
                    $genderColors = ['male' => '#3452a8', 'female' => '#b3427e', 'other' => '#9aa0a4'];
                    $genderLabels = ['male' => 'Laki-laki', 'female' => 'Perempuan', 'other' => 'Lainnya'];
                @endphp
                <div class="space-y-3.5">
                    @foreach ($genderBreakdown as $key => $value)
                        <div>
                            <div class="flex items-center justify-between mb-1.5 text-sm">
                                <span class="text-[#5c6266]">{{ $genderLabels[$key] ?? ucfirst($key) }}</span>
                                <span class="font-medium text-[#14181a]">{{ $value }}%</span>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
                                <div class="h-full rounded-full" style="width: {{ $value }}%; background-color: {{ $genderColors[$key] ?? '#9aa0a4' }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Age --}}
        <div class="card p-6">
            <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Age Range</h2>
            @if (empty($ageBreakdown))
                <p class="text-sm text-[#767c80] text-center py-8">Belum ada data.</p>
            @else
                <div class="space-y-3.5">
                    @foreach ($ageBreakdown as $range => $value)
                        <div>
                            <div class="flex items-center justify-between mb-1.5 text-sm">
                                <span class="text-[#5c6266]">{{ $range }} tahun</span>
                                <span class="font-medium text-[#14181a]">{{ $value }}%</span>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
                                <div class="h-full rounded-full bg-[#044b46]" style="width: {{ $value }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Top Locations --}}
        <div class="card p-6">
            <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Top Locations</h2>
            @if ($topLocations->isEmpty())
                <p class="text-sm text-[#767c80] text-center py-8">Belum ada data.</p>
            @else
                <div class="space-y-3.5">
                    @foreach ($topLocations->take(5) as $i => $loc)
                        <div>
                            <div class="flex items-center justify-between mb-1.5 text-sm">
                                <span class="text-[#5c6266] flex items-center gap-2">
                                    <span class="w-4 h-4 rounded-full bg-[#f7f0e0] text-[#8a6423] text-[9px] font-semibold flex items-center justify-center">{{ $i + 1 }}</span>
                                    {{ $loc['city'] }}
                                </span>
                                <span class="font-medium text-[#14181a]">{{ $loc['percentage'] }}%</span>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
                                <div class="h-full rounded-full bg-[#b8873a]" style="width: {{ $loc['percentage'] }}%"></div>
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
            <h2 class="font-display text-base font-semibold text-[#14181a]">Audience Active Hours</h2>
            @if ($peakHour && $peakHour['value'] > 0)
                <span class="badge badge-success">Paling aktif: {{ $peakHour['label'] }}</span>
            @endif
        </div>
        <p class="text-xs text-[#767c80] mb-5">Sebaran aktivitas audience per jam, berdasarkan snapshot terakhir.</p>

        @if (collect($activeHours)->sum('value') === 0)
            <p class="text-sm text-[#767c80] text-center py-12">Belum ada data jam aktif.</p>
        @else
            <x-trend-chart :trend="$activeHours" />
        @endif
    </div>

@endif
