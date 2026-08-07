@extends('layouts.app')
@section('title', 'Team Performance')
@section('content')
<div class="p-8 max-w-[1400px]">

    <div class="flex items-center justify-between mb-7">
        <div>
            <h1 class="font-display text-[28px] font-semibold text-[#14181a]">Team Performance</h1>
            <p class="text-sm text-[#9aa0a4] mt-1">Beban kerja dan produktivitas tim internal</p>
        </div>
        <form method="GET">
            <select name="client_id" onchange="this.form.submit()"
                    class="border border-[#eef0f4] rounded-lg px-3.5 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40">
                <option value="">Semua Client</option>
                @foreach ($clientOptions as $client)
                    <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>
                        {{ $client->name }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- Kartu Ringkasan --}}
    <div class="grid grid-cols-3 gap-5 mb-5">
        <div class="card p-6">
            <div class="w-9 h-9 rounded-lg bg-[#f0f5f4] flex items-center justify-center mb-3">
                <span class="material-symbols-outlined text-[#044b46] text-[18px]">groups</span>
            </div>
            <p class="text-sm text-[#5c6266] mb-2">Personel Aktif</p>
            <p class="font-display text-2xl font-semibold text-[#14181a]">{{ $summary['personnel_active'] }}</p>
        </div>
        <div class="card p-6">
            <div class="w-9 h-9 rounded-lg bg-[#eef2fb] flex items-center justify-center mb-3">
                <span class="material-symbols-outlined text-[#3452a8] text-[18px]">assignment</span>
            </div>
            <p class="text-sm text-[#5c6266] mb-2">Total Item Aktif</p>
            <p class="font-display text-2xl font-semibold text-[#14181a]">{{ $summary['total_active_items'] }}</p>
        </div>
        <div class="card p-6">
            <div class="w-9 h-9 rounded-lg bg-[#fdf6ec] flex items-center justify-center mb-3">
                <span class="material-symbols-outlined text-[#b8873a] text-[18px]">history_edu</span>
            </div>
            <p class="text-sm text-[#5c6266] mb-2">Rata-rata Revisi / Orang</p>
            <p class="font-display text-2xl font-semibold text-[#14181a]">{{ $summary['avg_revision'] }}</p>
        </div>
    </div>

    {{-- Risk Indicators --}}
    @if ($overloadedMembers->isNotEmpty() || $overdueMembers->isNotEmpty())
    <div class="mb-5">
        <h3 class="text-sm font-semibold text-[#14181a] mb-3">Risk Indicators</h3>
        <div class="grid grid-cols-2 gap-5">
            @if ($overloadedMembers->isNotEmpty())
                <div class="bg-[#fdf2f1] border border-[#f5d9d7] rounded-xl p-4 flex items-start gap-3">
                    <span class="material-symbols-outlined text-[#b3423e] text-[19px]">warning</span>
                    <div>
                        <p class="text-sm font-semibold text-[#14181a]">Beban Kerja Tinggi</p>
                        @foreach ($overloadedMembers as $m)
                            <p class="text-xs text-[#5c6266] mt-0.5">{{ $m['user']->name }} memiliki {{ $m['active_count'] }} task aktif</p>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($overdueMembers->isNotEmpty())
                <div class="bg-[#fdf6ec] border border-[#f3e5c8] rounded-xl p-4 flex items-start gap-3">
                    <span class="material-symbols-outlined text-[#b8873a] text-[19px]">schedule</span>
                    <div>
                        <p class="text-sm font-semibold text-[#14181a]">Ada Task Overdue</p>
                        @foreach ($overdueMembers as $m)
                            <p class="text-xs text-[#5c6266] mt-0.5">{{ $m['user']->name }}: {{ $m['overdue_count'] }} task terlambat</p>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Tabel Team Members --}}
    <div>
        <h3 class="text-sm font-semibold text-[#14181a] mb-3">Team Members</h3>
        <div class="card overflow-hidden">
            <table class="w-full text-sm text-left">
                <thead class="bg-[#f7f8fc]">
                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium">Member</th>
                        <th class="px-4 py-3 font-medium">Active Tasks</th>
                        <th class="px-4 py-3 font-medium">Overdue</th>
                        <th class="px-4 py-3 font-medium">Selesai Bulan Ini</th>
                        <th class="px-4 py-3 font-medium">Jumlah Revisi</th>
                        <th class="px-4 py-3 font-medium">Avg. Delay Risk</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $m)
                        <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] cursor-pointer transition-colors"
                            onclick="window.location='{{ route('profile.show', $m['user']) }}'">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    @if ($m['user']->avatar_url)
                                        <img src="{{ $m['user']->avatar_url }}" referrerpolicy="no-referrer" class="w-9 h-9 rounded-full object-cover">
                                    @else
                                        <div class="w-9 h-9 rounded-full bg-[#044b46] text-white text-sm font-semibold flex items-center justify-center shrink-0">
                                            {{ strtoupper(substr($m['user']->name, 0, 1)) }}
                                        </div>
                                    @endif
                                    <div>
                                        <p class="font-medium text-[#14181a]">{{ $m['user']->name }}</p>
                                        <p class="text-xs text-[#9aa0a4]">{{ $m['user']->role->name ?? '-' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="flex items-center gap-1.5 text-[#5c6266]">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $m['is_overloaded'] ? 'bg-[#b3423e]' : 'bg-[#044b46]' }}"></span>
                                    {{ $m['active_count'] }} Active Tasks
                                </span>
                            </td>
                            <td class="px-4 py-3.5 {{ $m['overdue_count'] > 0 ? 'text-[#b3423e] font-semibold' : 'text-[#9aa0a4]' }}">
                                {{ $m['overdue_count'] }}
                            </td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $m['done_count'] }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $m['revision_count'] }}</td>
                            <td class="px-4 py-3.5">
                                @if ($m['avg_risk_score'] !== null)
                                    <span class="text-xs font-semibold px-2 py-0.5 rounded-full
                                        {{ $m['avg_risk_score'] >= 70 ? 'bg-[#fdf2f1] text-[#b3423e]' : ($m['avg_risk_score'] >= 40 ? 'bg-[#fdf6ec] text-[#8a6423]' : 'bg-[#f0f5f4] text-[#0f7a5f]') }}">
                                        {{ $m['avg_risk_score'] }}%
                                    </span>
                                @else
                                    <span class="text-xs text-[#c3c7cb]">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
