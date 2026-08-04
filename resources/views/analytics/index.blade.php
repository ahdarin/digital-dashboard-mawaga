@extends('layouts.app')
@section('title', 'Content Analytics')
@section('content')

<div class="p-8 max-w-[1400px]">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-5">
        <div>
            <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Content Analytics</h1>
            <p class="text-[#5c6266] text-sm mt-1">Performa konten, breakdown audience, dan detail per item — semua dalam 1 tempat.</p>
        </div>

        @if ($selectedClientId && $tab === 'overview')
            <a href="{{ route('analytics.export', ['client_id' => $selectedClientId, 'period' => $period ?? 30]) }}"
               class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                <span class="material-symbols-outlined text-[17px]">download</span> Export
            </a>
        @endif
    </div>

    {{-- Tab nav --}}
    <div class="flex items-center gap-1 bg-[#f2f3f6] rounded-lg p-1 mb-5 w-fit">
        @foreach (['overview' => 'Overview', 'table' => 'Performance Table', 'audience' => 'Audience'] as $key => $label)
            <a href="{{ route('analytics', array_filter(['tab' => $key, 'client_id' => $selectedClientId])) }}"
               class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $tab === $key ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Filter bar - SATU aja buat semua tab --}}
    <form method="GET" class="card p-4 mb-6 flex items-center gap-3 flex-wrap">
        <input type="hidden" name="tab" value="{{ $tab }}">

        <select name="client_id" onchange="this.form.submit()"
                class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 bg-white focus:outline-none focus:border-[#044b46]/40">
            <option value="">Pilih Client...</option>
            @foreach ($clientOptions as $c)
                <option value="{{ $c->id }}" {{ (string) $selectedClientId === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
        </select>

        @if ($selectedClientId && in_array($tab, ['overview', 'audience']))
            <select name="period" onchange="this.form.submit()"
                    class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 bg-white focus:outline-none focus:border-[#044b46]/40">
                <option value="7" {{ ($period ?? 30) === 7 ? 'selected' : '' }}>Last 7 Days</option>
                <option value="30" {{ ($period ?? 30) === 30 ? 'selected' : '' }}>Last 30 Days</option>
                <option value="90" {{ ($period ?? 30) === 90 ? 'selected' : '' }}>Last 90 Days</option>
            </select>
        @endif

        @if ($selectedClientId && $tab === 'audience' && ! empty($platforms) && $platforms->count() > 1)
            <select name="platform_id" onchange="this.form.submit()"
                    class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 bg-white focus:outline-none focus:border-[#044b46]/40">
                @foreach ($platforms as $p)
                    <option value="{{ $p->id }}" {{ (string) ($selectedPlatformId ?? '') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                @endforeach
            </select>
        @endif
    </form>

    @if (! empty($noClientSelected))
        <div class="card p-16 flex flex-col items-center justify-center text-center">
            <div class="w-14 h-14 rounded-full bg-[#f0f5f4] flex items-center justify-center mb-4">
                <span class="material-symbols-outlined text-[#044b46] text-[26px]">filter_alt</span>
            </div>
            <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1.5">Pilih client dulu</h2>
            <p class="text-sm text-[#5c6266] max-w-sm">Pilih salah satu client di dropdown atas — berlaku buat ketiga tab (Overview, Table, Audience), nggak perlu pilih ulang tiap ganti tab.</p>
        </div>

    @elseif ($tab === 'audience' && ! empty($noInsightData))
        <div class="card p-16 flex flex-col items-center justify-center text-center">
            <div class="w-14 h-14 rounded-full bg-[#fdf6ec] flex items-center justify-center mb-4">
                <span class="material-symbols-outlined text-[#b8873a] text-[26px]">database</span>
            </div>
            <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1.5">Belum ada data audience untuk {{ $client->name }}</h2>
            <p class="text-sm text-[#5c6266] max-w-sm">Import data followers &amp; demografi dulu lewat <a href="{{ route('settings.import') }}" class="text-[#044b46] underline">Import Performance Data</a>.</p>
        </div>

    @elseif ($tab === 'overview')
        {{-- ============ TAB: OVERVIEW ============ --}}
        <div class="grid grid-cols-4 gap-4 mb-6">
            @foreach ($stats as $stat)
                <div class="card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-[13px] text-[#5c6266]">{{ $stat['label'] }}</span>
                        <span class="material-symbols-outlined text-[#c3c7cb] text-[18px]">{{ $stat['icon'] }}</span>
                    </div>
                    <p class="font-display text-[26px] font-semibold text-[#14181a] mb-2">{{ $stat['value'] }}</p>
                    <p class="text-xs flex items-center gap-1
                        {{ $stat['trend'] === 'up' ? 'text-[#0f7a5f]' : ($stat['trend'] === 'down' ? 'text-[#b3423e]' : 'text-[#9aa0a4]') }}">
                        @if ($stat['trend'] === 'up') <span class="material-symbols-outlined text-[13px]">trending_up</span>
                        @elseif ($stat['trend'] === 'down') <span class="material-symbols-outlined text-[13px]">trending_down</span> @endif
                        {{ $stat['change'] }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="flex gap-5 items-start">
            <div class="flex-1 min-w-0 space-y-5">
                <div class="card p-6">
                    <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1">Views Over Time</h2>
                    <p class="text-xs text-[#9aa0a4] mb-5">Total views seluruh konten pada periode terpilih.</p>
                    <x-trend-chart :trend="$trend" />
                </div>

                <div class="card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Top Performing Content</h2>
                        <a href="{{ route('analytics', ['tab' => 'table', 'client_id' => $selectedClientId]) }}" class="text-sm font-medium text-[#044b46] hover:underline">Lihat semua →</a>
                    </div>

                    @if ($topContent->isEmpty())
                        <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada konten dengan data performa.</p>
                    @else
                        <table class="w-full text-sm text-left">
                            <thead>
                                <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                    <th class="pb-2.5 font-medium">Konten</th>
                                    <th class="pb-2.5 font-medium">Platform</th>
                                    <th class="pb-2.5 font-medium">Views</th>
                                    <th class="pb-2.5 font-medium">Engagement</th>
                                    <th class="pb-2.5"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topContent as $content)
                                    <tr class="border-t border-[#f2f3f6]">
                                        <td class="py-3 pr-3 font-medium text-[#14181a]">
                                            {{ $content['title'] }}
                                            <p class="text-xs text-[#9aa0a4] font-normal mt-0.5">{{ $content['type'] }}</p>
                                        </td>
                                        <td class="py-3 pr-3 text-[#5c6266]">{{ $content['platform'] }}</td>
                                        <td class="py-3 pr-3 font-medium text-[#14181a]">{{ number_format($content['views']) }}</td>
                                        <td class="py-3 pr-3">
                                            <span class="text-xs px-2 py-1 rounded-full bg-[#f0f5f4] text-[#044b46]">{{ $content['engagement_rate'] }}%</span>
                                        </td>
                                        <td class="py-3 text-right">
                                            <a href="{{ route('analytics.show', $content['id']) }}" class="text-xs font-medium text-[#044b46] hover:underline">Detail</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            <div class="w-[300px] shrink-0">
                <div class="card p-6">
                    <h2 class="font-display text-lg font-semibold text-[#14181a] mb-5">Traffic per Platform</h2>
                    @if ($platformBreakdown->isEmpty())
                        <p class="text-sm text-[#9aa0a4] text-center py-6">Belum ada data.</p>
                    @else
                        @php $maxPlatform = max($platformBreakdown->max('value'), 1); @endphp
                        <div class="space-y-4">
                            @foreach ($platformBreakdown as $row)
                                <div>
                                    <div class="flex items-center justify-between mb-1.5 text-sm">
                                        <span class="text-[#5c6266]">{{ $row['label'] }}</span>
                                        <span class="font-medium text-[#14181a]">{{ number_format($row['value']) }}</span>
                                    </div>
                                    <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
                                        <div class="h-full bg-[#044b46] rounded-full" style="width: {{ max(($row['value'] / $maxPlatform) * 100, 3) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

    @elseif ($tab === 'table')
        {{-- ============ TAB: PERFORMANCE TABLE ============ --}}
        <form method="GET" class="card p-4 mb-5 flex items-center gap-2.5 flex-wrap">
            <input type="hidden" name="tab" value="table">
            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $dir }}">

            <div class="relative flex-1 min-w-[200px]">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[17px]">search</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari judul konten..."
                       class="w-full pl-9 pr-3 py-2 text-sm border border-[#eef0f4] rounded-lg focus:outline-none focus:border-[#044b46]/40">
            </div>

            <select name="platform_id" onchange="this.form.submit()" class="text-sm border border-[#eef0f4] rounded-lg px-3 py-2 bg-white focus:outline-none focus:border-[#044b46]/40">
                <option value="">Semua Platform</option>
                @foreach ($platformOptions as $p)
                    <option value="{{ $p->id }}" {{ (string) request('platform_id') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                @endforeach
            </select>

            <select name="content_type_id" onchange="this.form.submit()" class="text-sm border border-[#eef0f4] rounded-lg px-3 py-2 bg-white focus:outline-none focus:border-[#044b46]/40">
                <option value="">Semua Tipe</option>
                @foreach ($contentTypeOptions as $ct)
                    <option value="{{ $ct->id }}" {{ (string) request('content_type_id') === (string) $ct->id ? 'selected' : '' }}>{{ $ct->name }}</option>
                @endforeach
            </select>

            <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-[#033b37] transition-colors">Terapkan</button>
        </form>

        <div class="card overflow-hidden">
            @if ($items->isEmpty())
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    <span class="material-symbols-outlined text-[#d4d7db] text-[26px] mb-2">search_off</span>
                    <p class="text-sm text-[#9aa0a4]">Nggak ada konten yang cocok dengan filter ini.</p>
                </div>
            @else
                @php
                    $sortLink = fn ($col) => route('analytics', array_merge(request()->except(['sort', 'dir']), ['tab' => 'table', 'sort' => $col, 'dir' => $sort === $col && $dir === 'desc' ? 'asc' : 'desc']));
                    $sortIcon = fn ($col) => $sort === $col ? ($dir === 'desc' ? 'arrow_downward' : 'arrow_upward') : 'unfold_more';
                @endphp
                <table class="w-full text-sm text-left">
                    <thead class="bg-[#f7f8fc]">
                        <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                            <th class="px-6 py-3 font-medium"><a href="{{ $sortLink('title') }}" class="flex items-center gap-1 hover:text-[#044b46]">Konten <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('title') }}</span></a></th>
                            <th class="px-4 py-3 font-medium">Platform</th>
                            <th class="px-4 py-3 font-medium">Tipe</th>
                            <th class="px-4 py-3 font-medium"><a href="{{ $sortLink('total_views') }}" class="flex items-center gap-1 hover:text-[#044b46]">Views <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('total_views') }}</span></a></th>
                            <th class="px-4 py-3 font-medium"><a href="{{ $sortLink('avg_engagement') }}" class="flex items-center gap-1 hover:text-[#044b46]">Engagement <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('avg_engagement') }}</span></a></th>
                            <th class="px-4 py-3 font-medium"><a href="{{ $sortLink('deadline_at') }}" class="flex items-center gap-1 hover:text-[#044b46]">Deadline <span class="material-symbols-outlined text-[13px]">{{ $sortIcon('deadline_at') }}</span></a></th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors">
                                <td class="px-6 py-3.5 font-medium text-[#14181a] max-w-[240px] truncate">{{ $item->title }}</td>
                                <td class="px-4 py-3.5 text-[#5c6266]">{{ $item->platform->name ?? '-' }}</td>
                                <td class="px-4 py-3.5 text-[#5c6266]">{{ $item->contentType->name ?? '-' }}</td>
                                <td class="px-4 py-3.5 font-medium text-[#14181a]">{{ $item->total_views !== null ? number_format($item->total_views) : '-' }}</td>
                                <td class="px-4 py-3.5">
                                    @if ($item->avg_engagement !== null)
                                        <span class="text-xs px-2 py-1 rounded-full bg-[#f0f5f4] text-[#044b46]">{{ round($item->avg_engagement, 2) }}%</span>
                                    @else <span class="text-[#c3c7cb]">-</span> @endif
                                </td>
                                <td class="px-4 py-3.5 text-[#5c6266]">{{ $item->deadline_at?->format('d M Y') }}</td>
                                <td class="px-4 py-3.5">
                                    @if ($item->is_posted)
                                        <span class="text-[10px] font-medium px-2 py-1 rounded-full bg-[#044b46] text-white">Published</span>
                                    @elseif ($item->workflow?->is_overdue)
                                        <span class="text-[10px] font-medium px-2 py-1 rounded-full bg-[#fbe2e0] text-[#b3423e]">Overdue</span>
                                    @else
                                        <span class="text-[10px] font-medium px-2 py-1 rounded-full bg-[#fdf6ec] text-[#b8873a]">On Progress</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-right"><a href="{{ route('analytics.show', $item->id) }}" class="text-xs font-medium text-[#044b46] hover:underline">Detail</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-6 py-4 border-t border-[#f2f3f6]">{{ $items->links() }}</div>
            @endif
        </div>

    @elseif ($tab === 'audience')
        {{-- ============ TAB: AUDIENCE ============ --}}

        {{-- Import CSV Audience Data --}}
        <div class="card p-5 mb-5">
            @if (session('import_success'))
                <div class="mb-4 bg-[#f0f5f4] border border-[#dbe6e4] rounded-lg p-3.5">
                    <p class="text-sm font-medium text-[#044b46] flex items-center gap-2">
                        <span class="material-symbols-outlined text-[17px]">check_circle</span> {{ session('import_success') }}
                    </p>
                    @if (! empty(session('import_skipped')))
                        <ul class="mt-2 ml-6 list-disc text-xs text-[#5c6266] space-y-0.5">
                            @foreach (session('import_skipped') as $skip) <li>{{ $skip }}</li> @endforeach
                        </ul>
                    @endif
                </div>
            @endif
            @if (session('import_error'))
                <div class="mb-4 bg-[#fdf2f1] border border-[#f5d9d7] rounded-lg p-3.5">
                    <p class="text-sm font-medium text-[#b3423e] flex items-center gap-2">
                        <span class="material-symbols-outlined text-[17px]">error</span> {{ session('import_error') }}
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
                        <p class="text-xs text-[#9aa0a4]">Followers, gender, usia, dan top lokasi untuk {{ $client->name }}.</p>
                    </div>
                </summary>

                <form action="{{ route('audience.import') }}" method="POST" enctype="multipart/form-data" class="mt-4 pl-11">
                    @csrf
                    <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                    <div class="flex items-center gap-3 flex-wrap">
                        <input type="file" name="file" accept=".csv,.txt" required
                               class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-[#f0f5f4] file:text-[#044b46] file:text-xs file:font-medium">
                        <button type="submit" class="bg-[#044b46] text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-[#033b37] transition-colors">Upload &amp; Import</button>
                    </div>
                    <details class="mt-3">
                        <summary class="text-xs font-medium text-[#044b46] cursor-pointer">Lihat format kolom CSV</summary>
                        <div class="mt-2 bg-[#f7f8fc] rounded-lg p-3 text-xs text-[#5c6266] font-mono overflow-x-auto">
                            platform,snapshot_date,follower_count,gender_male_pct,age_13_17_pct,age_18_24_pct,age_25_34_pct,age_35_44_pct,age_45_plus_pct,location_1,location_1_pct,location_2,location_2_pct,location_3,location_3_pct<br>
                            Instagram,2026-07-01,15230,42,5,30,40,15,10,Jakarta,35,Bandung,20,Surabaya,15
                        </div>
                    </details>
                </form>
            </details>
        </div>

        @if (empty($noInsightData))
        <div class="grid grid-cols-3 gap-5 mb-6">
            <div class="card p-6 bg-[#044b46] border-0">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-white/70 text-[17px]">person</span>
                    <span class="text-xs font-medium tracking-wide text-white/70 uppercase">{{ $platform->name }} Followers</span>
                </div>
                <p class="font-display text-[34px] font-semibold text-white mb-1.5">{{ number_format($lastCount) }}</p>
                @if (! is_null($growth))
                    <p class="text-xs font-medium flex items-center gap-1 {{ $growth >= 0 ? 'text-[#9fd8c4]' : 'text-[#f3b6b2]' }}">
                        <span class="material-symbols-outlined text-[13px]">{{ $growth >= 0 ? 'trending_up' : 'trending_down' }}</span>
                        {{ $growth >= 0 ? '+' : '' }}{{ $growth }}% dalam {{ $period }} hari
                    </p>
                @else
                    <p class="text-xs text-white/50">Belum cukup data historis.</p>
                @endif
            </div>

            <div class="col-span-2 card p-6">
                <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1">Followers Growth</h2>
                <p class="text-xs text-[#9aa0a4] mb-5">Tren jumlah follower {{ $platform->name }}.</p>
                @if ($followerTrend->isEmpty())
                    <p class="text-sm text-[#9aa0a4] text-center py-12">Belum ada histori followers.</p>
                @else
                    <x-trend-chart :trend="$followerTrend" />
                @endif
            </div>
        </div>

        <div class="grid grid-cols-3 gap-5">
            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Gender</h2>
                @if (empty($genderBreakdown))
                    <p class="text-sm text-[#9aa0a4] text-center py-8">Belum ada data.</p>
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

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Rentang Usia</h2>
                @if (empty($ageBreakdown))
                    <p class="text-sm text-[#9aa0a4] text-center py-8">Belum ada data.</p>
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

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Top Lokasi</h2>
                @if ($topLocations->isEmpty())
                    <p class="text-sm text-[#9aa0a4] text-center py-8">Belum ada data.</p>
                @else
                    <div class="space-y-3.5">
                        @foreach ($topLocations->take(5) as $i => $loc)
                            <div>
                                <div class="flex items-center justify-between mb-1.5 text-sm">
                                    <span class="text-[#5c6266] flex items-center gap-2">
                                        <span class="w-4 h-4 rounded-full bg-[#f7f0e0] text-[#b8873a] text-[9px] font-semibold flex items-center justify-center">{{ $i + 1 }}</span>
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

        <div class="card p-6 mt-5">
            <div class="flex items-center justify-between mb-1">
                <h2 class="font-display text-base font-semibold text-[#14181a]">Jam Aktif Audience</h2>
                @if ($peakHour && $peakHour['value'] > 0)
                    <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-[#f0f5f4] text-[#044b46]">Paling aktif: {{ $peakHour['label'] }}</span>
                @endif
            </div>
            <p class="text-xs text-[#9aa0a4] mb-5">Sebaran aktivitas audience per jam, berdasarkan snapshot terakhir.</p>
            @if (collect($activeHours)->sum('value') === 0)
                <p class="text-sm text-[#9aa0a4] text-center py-12">Belum ada data jam aktif.</p>
            @else
                <x-trend-chart :trend="$activeHours" />
            @endif
        </div>
        @endif
    @endif

</div>
@endsection