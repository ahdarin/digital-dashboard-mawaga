@extends('layouts.app')
@section('title', 'Team Performance')
@section('content')
<div class="p-8">

    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-[#191c1c]">Team Performance</h2>
            <p class="text-sm text-gray-500 mt-1">Beban kerja dan produktivitas tim internal</p>
        </div>
        <form method="GET">
            <select name="client_id" onchange="this.form.submit()"
                    class="border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]">
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
    @php
        $summaryColors = ['#044b46', '#6366f1', '#f59e0b'];
        $summaryIcons = ['groups', 'assignment', 'history_edu'];
    @endphp
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="relative bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.06)] p-5 overflow-hidden">
            <div class="absolute -right-5 -top-5 w-16 h-16 rounded-full bg-[#044b46]/10 blur-lg"></div>
            <div class="w-8 h-8 rounded-lg bg-[#044b46]/10 flex items-center justify-center mb-3">
                <span class="material-symbols-outlined text-[#044b46] text-[16px]">groups</span>
            </div>
            <p class="text-xs text-gray-500 mb-1">Personel Aktif</p>
            <p class="text-3xl font-bold text-[#191c1c]">{{ $summary['personnel_active'] }}</p>
        </div>
        <div class="relative bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.06)] p-5 overflow-hidden">
            <div class="absolute -right-5 -top-5 w-16 h-16 rounded-full bg-indigo-200/30 blur-lg"></div>
            <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center mb-3">
                <span class="material-symbols-outlined text-indigo-500 text-[16px]">assignment</span>
            </div>
            <p class="text-xs text-gray-500 mb-1">Total Item Aktif</p>
            <p class="text-3xl font-bold text-[#191c1c]">{{ $summary['total_active_items'] }}</p>
        </div>
        <div class="relative bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.06)] p-5 overflow-hidden">
            <div class="absolute -right-5 -top-5 w-16 h-16 rounded-full bg-amber-200/30 blur-lg"></div>
            <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center mb-3">
                <span class="material-symbols-outlined text-amber-500 text-[16px]">history_edu</span>
            </div>
            <p class="text-xs text-gray-500 mb-1">Rata-rata Revisi / Orang</p>
            <p class="text-3xl font-bold text-[#191c1c]">{{ $summary['avg_revision'] }}</p>
        </div>
    </div>

    {{-- Risk Indicators --}}
    @if ($overloadedMembers->isNotEmpty() || $overdueMembers->isNotEmpty())
    <div class="mb-6">
        <h3 class="text-sm font-bold text-gray-700 mb-3">Risk Indicators</h3>
        <div class="grid grid-cols-2 gap-4">
            @if ($overloadedMembers->isNotEmpty())
                <div class="bg-gradient-to-br from-rose-50 to-white border border-rose-100 rounded-xl p-4 shadow-[0_4px_16px_rgba(244,63,94,0.06)] flex items-start gap-3">
                    <span class="material-symbols-outlined text-rose-500">warning</span>
                    <div>
                        <p class="text-sm font-bold text-gray-800">Beban Kerja Tinggi</p>
                        @foreach ($overloadedMembers as $m)
                            <p class="text-xs text-gray-600">{{ $m['user']->name }} memiliki {{ $m['active_count'] }} task aktif</p>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($overdueMembers->isNotEmpty())
                <div class="bg-gradient-to-br from-amber-50 to-white border border-amber-100 rounded-xl p-4 shadow-[0_4px_16px_rgba(245,158,11,0.06)] flex items-start gap-3">
                    <span class="material-symbols-outlined text-orange-500">schedule</span>
                    <div>
                        <p class="text-sm font-bold text-gray-800">Ada Task Overdue</p>
                        @foreach ($overdueMembers as $m)
                            <p class="text-xs text-gray-600">{{ $m['user']->name }}: {{ $m['overdue_count'] }} task terlambat</p>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Tabel Team Members --}}
    <div>
        <h3 class="text-sm font-bold text-gray-700 mb-3">Team Members</h3>
        <div class="bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.06)] overflow-hidden">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <tr>
                        <th class="px-4 py-3">Member</th>
                        <th class="px-4 py-3">Active Tasks</th>
                        <th class="px-4 py-3">Overdue</th>
                        <th class="px-4 py-3">Selesai Bulan Ini</th>
                        <th class="px-4 py-3">Jumlah Revisi</th>
                        <th class="px-4 py-3">Avg. Delay Risk</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $m)
                        <tr class="border-t border-gray-100 hover:bg-gray-50 cursor-pointer"
                            onclick="window.location='{{ route('profile.show', $m['user']) }}'">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    @if ($m['user']->avatar_url)
                                        <img src="{{ $m['user']->avatar_url }}" referrerpolicy="no-referrer" class="w-8 h-8 rounded-full object-cover">
                                    @else
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-[#044b46] to-[#0a8f76] text-white text-xs font-bold flex items-center justify-center">
                                            {{ strtoupper(substr($m['user']->name, 0, 1)) }}
                                        </div>
                                    @endif
                                    <div>
                                        <p class="font-semibold text-gray-800">{{ $m['user']->name }}</p>
                                        <p class="text-xs text-gray-400">{{ $m['user']->role->name ?? '-' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full {{ $m['is_overloaded'] ? 'bg-rose-500' : 'bg-[#044b46]' }}"></span>
                                    {{ $m['active_count'] }} Active Tasks
                                </span>
                            </td>
                            <td class="px-4 py-3 {{ $m['overdue_count'] > 0 ? 'text-rose-600 font-semibold' : 'text-gray-500' }}">
                                {{ $m['overdue_count'] }}
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $m['done_count'] }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $m['revision_count'] }}</td>
                            <td class="px-4 py-3">
                                @if ($m['avg_risk_score'] !== null)
                                    <span class="text-xs font-semibold {{ $m['avg_risk_score'] >= 70 ? 'text-rose-600' : ($m['avg_risk_score'] >= 40 ? 'text-amber-600' : 'text-emerald-600') }}">
                                        {{ $m['avg_risk_score'] }}%
                                    </span>
                                @else
                                    <span class="text-xs text-gray-300">-</span>
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