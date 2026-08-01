@extends('layouts.app')
@section('title', 'Audience Dashboard')
@section('content')

<div class="p-8">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-4xl font-extrabold text-[#191c1c]">Audience Dashboard</h1>
            <p class="text-gray-500 mt-2">Insight audience client: pertumbuhan follower, demografi, dan jam aktif.</p>
            <a href="{{ route('analytics') }}" class="text-xs font-semibold text-[#044b46] hover:underline inline-flex items-center gap-1 mt-2">
                <span class="material-symbols-outlined text-[14px]">arrow_back</span>
                Kembali ke Content Analytics
            </a>
        </div>

        <form method="GET" class="flex items-center gap-3">
            <select name="client_id" onchange="this.form.submit()"
                    class="text-sm font-medium border border-gray-200 rounded-xl px-4 py-2.5 bg-white focus:outline-none focus:border-[#044b46]">
                <option value="">Pilih Client...</option>
                @foreach ($clientOptions as $c)
                    <option value="{{ $c->id }}" {{ (string) $selectedClientId === (string) $c->id ? 'selected' : '' }}>
                        {{ $c->name }}
                    </option>
                @endforeach
            </select>

            @if (! empty($platforms) && $platforms->count() > 1)
                <select name="platform_id" onchange="this.form.submit()"
                        class="text-sm font-medium border border-gray-200 rounded-xl px-4 py-2.5 bg-white focus:outline-none focus:border-[#044b46]">
                    @foreach ($platforms as $p)
                        <option value="{{ $p->id }}" {{ (string) $selectedPlatformId === (string) $p->id ? 'selected' : '' }}>
                            {{ $p->name }}
                        </option>
                    @endforeach
                </select>
            @endif

            @if (! empty($period))
                <select name="period" onchange="this.form.submit()"
                        class="text-sm font-medium border border-gray-200 rounded-xl px-4 py-2.5 bg-white focus:outline-none focus:border-[#044b46]">
                    <option value="7" {{ $period === 7 ? 'selected' : '' }}>Last 7 Days</option>
                    <option value="30" {{ $period === 30 ? 'selected' : '' }}>Last 30 Days</option>
                    <option value="90" {{ $period === 90 ? 'selected' : '' }}>Last 90 Days</option>
                </select>
            @endif
        </form>
    </div>

    @if (! empty($selectedClientId))
        {{-- Import CSV Audience Data --}}
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-5 mb-6">
            @if (session('import_success'))
                <div class="mb-4 bg-emerald-50 border border-emerald-100 rounded-xl p-4">
                    <p class="text-sm font-semibold text-emerald-700 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">check_circle</span>
                        {{ session('import_success') }}
                    </p>
                    @if (! empty(session('import_skipped')))
                        <ul class="mt-2 ml-6 list-disc text-xs text-emerald-700/80 space-y-0.5">
                            @foreach (session('import_skipped') as $skip)
                                <li>{{ $skip }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @if (session('import_error'))
                <div class="mb-4 bg-rose-50 border border-rose-100 rounded-xl p-4">
                    <p class="text-sm font-semibold text-rose-700 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">error</span>
                        {{ session('import_error') }}
                    </p>
                </div>
            @endif

            <details {{ !empty($noInsightData) ? 'open' : '' }}>
                <summary class="cursor-pointer flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-[#044b46]/10 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[#044b46] text-[18px]">upload_file</span>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-[#191c1c]">Import Audience Data (CSV)</p>
                        <p class="text-xs text-gray-400">Followers, gender, usia, dan top lokasi untuk {{ $client->name }}.</p>
                    </div>
                </summary>

                <form action="{{ route('audience.import') }}" method="POST" enctype="multipart/form-data" class="mt-4 pl-12">
                    @csrf
                    <input type="hidden" name="client_id" value="{{ $selectedClientId }}">

                    <div class="flex items-center gap-3 flex-wrap">
                        <input type="file" name="file" accept=".csv,.txt" required
                               class="text-sm border border-gray-200 rounded-xl px-3.5 py-2 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-[#044b46]/10 file:text-[#044b46] file:text-xs file:font-semibold">
                        <button type="submit"
                                class="bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white text-sm font-semibold px-4 py-2 rounded-xl hover:opacity-90 transition-opacity shadow-[0_4px_10px_rgba(4,75,70,0.25)]">
                            Upload &amp; Import
                        </button>
                    </div>

                    <details class="mt-3">
                        <summary class="text-xs font-semibold text-[#044b46] cursor-pointer">Lihat format kolom CSV</summary>
                        <div class="mt-2 bg-gray-50 rounded-lg p-3 text-xs text-gray-500 font-mono overflow-x-auto">
                            platform,snapshot_date,follower_count,gender_male_pct,age_13_17_pct,age_18_24_pct,age_25_34_pct,age_35_44_pct,age_45_plus_pct,location_1,location_1_pct,location_2,location_2_pct,location_3,location_3_pct<br>
                            Instagram,2026-07-01,15230,42,5,30,40,15,10,Jakarta,35,Bandung,20,Surabaya,15
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1.5">
                            gender_female_pct dihitung otomatis (100 - male). Kolom yang nggak diisi nggak bakal menimpa data lama.
                            Jam aktif (active hours) belum bisa diimport lewat CSV ini.
                        </p>
                    </details>
                </form>
            </details>
        </div>
    @endif

    @if (! empty($noClientSelected))
        {{-- Empty state: belum pilih client --}}
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-16 flex flex-col items-center justify-center text-center">
            <div class="w-16 h-16 rounded-full bg-[#044b46]/10 flex items-center justify-center mb-5">
                <span class="material-symbols-outlined text-[#044b46] text-[30px]">groups</span>
            </div>
            <h2 class="text-xl font-extrabold text-[#191c1c] mb-2">Pilih client dulu, yuk</h2>
            <p class="text-sm text-gray-500 max-w-sm">
                Pilih salah satu client di dropdown atas untuk lihat insight audience-nya
                (followers, demografi, jam aktif).
            </p>
        </div>

    @elseif (! empty($noInsightData))
        {{-- Empty state: client dipilih tapi belum ada data insight --}}
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-16 flex flex-col items-center justify-center text-center">
            <div class="w-16 h-16 rounded-full bg-amber-50 flex items-center justify-center mb-5">
                <span class="material-symbols-outlined text-amber-500 text-[30px]">database</span>
            </div>
            <h2 class="text-xl font-extrabold text-[#191c1c] mb-2">Belum ada data audience untuk {{ $client->name }}</h2>
            <p class="text-sm text-gray-500 max-w-sm">
                Data followers &amp; demografi belum pernah di-import untuk client ini.
                Buka panel "Import Audience Data" di atas buat upload CSV-nya.
            </p>
        </div>

    @else

        {{-- Followers overview + trend --}}
        <div class="grid grid-cols-3 gap-6 mb-6">
            <div class="bg-gradient-to-br from-[#044b46] to-[#0a6b5c] rounded-2xl shadow-[0_8px_28px_rgba(4,75,70,0.35)] p-6 text-white">
                <div class="flex items-center gap-2 mb-4">
                    <div class="w-9 h-9 rounded-full bg-white/15 flex items-center justify-center">
                        <span class="material-symbols-outlined text-white text-[18px]">person</span>
                    </div>
                    <span class="text-xs font-bold tracking-wider text-white/70 uppercase">{{ $platform->name }} Followers</span>
                </div>
                <p class="text-4xl font-extrabold mb-2">{{ number_format($lastCount) }}</p>
                @if (! is_null($growth))
                    <p class="text-xs font-medium flex items-center gap-1 {{ $growth >= 0 ? 'text-emerald-200' : 'text-rose-200' }}">
                        <span class="material-symbols-outlined text-[14px]">{{ $growth >= 0 ? 'trending_up' : 'trending_down' }}</span>
                        {{ $growth >= 0 ? '+' : '' }}{{ $growth }}% dalam {{ $period }} hari
                    </p>
                @else
                    <p class="text-xs text-white/60">Belum cukup data historis untuk hitung growth.</p>
                @endif
            </div>

            <div class="col-span-2 bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
                <h2 class="text-sm font-bold text-gray-700 mb-1">Followers Growth</h2>
                <p class="text-xs text-gray-400 mb-4">Tren jumlah follower {{ $platform->name }} pada periode terpilih.</p>

                @if ($followerTrend->isEmpty())
                    <p class="text-sm text-gray-400 text-center py-12">Belum ada histori followers pada periode ini.</p>
                @else
                    <x-trend-chart :trend="$followerTrend" />
                @endif
            </div>
        </div>

        <div class="grid grid-cols-3 gap-6">

            {{-- Gender --}}
            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
                <h2 class="text-lg font-extrabold text-[#191c1c] mb-5">Gender</h2>

                @if (empty($genderBreakdown))
                    <p class="text-sm text-gray-400 text-center py-8">Belum ada data.</p>
                @else
                    @php
                        $genderColors = ['male' => '#0ea5e9', 'female' => '#ec4899', 'other' => '#9ca3af'];
                        $genderLabels = ['male' => 'Laki-laki', 'female' => 'Perempuan', 'other' => 'Lainnya'];
                    @endphp
                    <div class="space-y-4">
                        @foreach ($genderBreakdown as $key => $value)
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-sm font-medium text-gray-600">{{ $genderLabels[$key] ?? ucfirst($key) }}</span>
                                    <span class="text-sm font-semibold text-[#191c1c]">{{ $value }}%</span>
                                </div>
                                <div class="w-full h-2 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full rounded-full" style="width: {{ $value }}%; background-color: {{ $genderColors[$key] ?? '#9ca3af' }}"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Age --}}
            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
                <h2 class="text-lg font-extrabold text-[#191c1c] mb-5">Rentang Usia</h2>

                @if (empty($ageBreakdown))
                    <p class="text-sm text-gray-400 text-center py-8">Belum ada data.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($ageBreakdown as $range => $value)
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-sm font-medium text-gray-600">{{ $range }} tahun</span>
                                    <span class="text-sm font-semibold text-[#191c1c]">{{ $value }}%</span>
                                </div>
                                <div class="w-full h-2 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-400 to-indigo-500" style="width: {{ $value }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Top Locations --}}
            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
                <h2 class="text-lg font-extrabold text-[#191c1c] mb-5">Top Lokasi</h2>

                @if ($topLocations->isEmpty())
                    <p class="text-sm text-gray-400 text-center py-8">Belum ada data.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($topLocations->take(5) as $i => $loc)
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-sm font-medium text-gray-600 flex items-center gap-2">
                                        <span class="w-5 h-5 rounded-full bg-amber-50 text-amber-600 text-[10px] font-bold flex items-center justify-center shrink-0">
                                            {{ $i + 1 }}
                                        </span>
                                        {{ $loc['city'] }}
                                    </span>
                                    <span class="text-sm font-semibold text-[#191c1c]">{{ $loc['percentage'] }}%</span>
                                </div>
                                <div class="w-full h-2 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-amber-300" style="width: {{ $loc['percentage'] }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>

        {{-- Active hours --}}
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6 mt-6">
            <div class="flex items-center justify-between mb-1">
                <h2 class="text-lg font-extrabold text-[#191c1c]">Jam Aktif Audience</h2>
                @if ($peakHour && $peakHour['value'] > 0)
                    <span class="text-xs font-semibold px-3 py-1.5 rounded-full bg-[#044b46]/10 text-[#044b46]">
                        Paling aktif: {{ $peakHour['label'] }}
                    </span>
                @endif
            </div>
            <p class="text-xs text-gray-400 mb-4">Sebaran aktivitas audience per jam (24 jam), berdasarkan snapshot terakhir.</p>

            @if (collect($activeHours)->sum('value') === 0)
                <p class="text-sm text-gray-400 text-center py-12">Belum ada data jam aktif.</p>
            @else
                <x-trend-chart :trend="$activeHours" />
            @endif
        </div>

    @endif
</div>

@endsection