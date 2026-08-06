@extends('layouts.app')
@section('title', 'Analytics Integration')
@section('content')

<div class="p-8 max-w-[1300px]">

    <div class="flex items-center justify-between mb-2">
        <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Analytics Integration</h1>
        <a href="{{ route('settings.import') }}" class="text-sm font-medium text-[#044b46] hover:underline flex items-center gap-1">
            <span class="material-symbols-outlined text-[15px]">upload_file</span> Import Performance Data
        </a>
    </div>
    <p class="text-[#5c6266] text-sm mb-6">Kelola koneksi API per platform dan pantau riwayat sinkronisasi/import data.</p>

    {{-- Data Sources --}}
    <div class="card p-6 mb-6">
        <h2 class="font-display text-lg font-semibold text-[#14181a] mb-5">Data Sources</h2>

        <div class="grid grid-cols-4 gap-4">
            @foreach ($integrations as $row)
                <div class="border border-[#eef0f4] rounded-xl p-4">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-9 h-9 rounded-lg bg-[#f0f5f4] flex items-center justify-center">
                            <span class="material-symbols-outlined text-[#044b46] text-[18px]">hub</span>
                        </div>
                        <span class="text-[10px] font-medium px-2 py-1 rounded-full
                            {{ $row['connected'] ? 'bg-[#f0f5f4] text-[#0f7a5f]' : 'bg-[#fdf2f1] text-[#b3423e]' }}">
                            {{ $row['connected'] ? 'Active' : 'Disconnected' }}
                        </span>
                    </div>
                    <p class="text-sm font-medium text-[#14181a]">{{ $row['platform'] }}</p>
                    <p class="text-xs text-[#9aa0a4] mt-0.5 mb-3">
                        {{ $row['connected'] ? 'Last sync: '.$row['updated_at']?->diffForHumans() : 'Belum terhubung' }}
                    </p>
                    <button type="button" disabled title="Belum tersedia - nunggu App Review Meta/TikTok kelar dulu"
                            class="text-xs font-medium text-[#c3c7cb] cursor-not-allowed">
                        {{ $row['connected'] ? 'Manage' : 'Reconnect' }} <span class="text-[10px]">(segera)</span>
                    </button>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Sync Log --}}
    <div class="card overflow-hidden">
        <div class="p-6 pb-4 flex items-center justify-between">
            <h2 class="font-display text-lg font-semibold text-[#14181a]">Sync Log</h2>

            <form method="GET" class="flex items-center gap-2.5">
                <select name="status" onchange="this.form.submit()"
                        class="text-sm border border-[#eef0f4] rounded-lg px-3 py-2 bg-white focus:outline-none focus:border-[#044b46]/40">
                    <option value="">All Statuses</option>
                    <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>Success</option>
                    <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                </select>
                <input type="date" name="date" value="{{ request('date') }}" onchange="this.form.submit()"
                       class="text-sm border border-[#eef0f4] rounded-lg px-3 py-2 focus:outline-none focus:border-[#044b46]/40">
                @if (request('status') || request('date'))
                    <a href="{{ route('settings.integrations') }}" class="text-xs text-[#9aa0a4] hover:text-[#5c6266]">Reset</a>
                @endif
            </form>
        </div>

        @php
            $sourceTypeLabels = [
                'performance_csv_import' => 'Performance CSV Import',
                'audience_csv_import' => 'Audience CSV Import',
                'api_sync' => 'API Sync',
            ];
        @endphp

        @if ($syncLogs->isEmpty())
            <div class="px-6 py-12 text-center">
                <span class="material-symbols-outlined text-[#d4d7db] text-[26px] mb-2 block">history</span>
                <p class="text-sm text-[#9aa0a4]">Belum ada riwayat sinkronisasi/import.</p>
            </div>
        @else
            <table class="w-full text-sm text-left">
                <thead class="bg-[#f7f8fc]">
                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium">Client</th>
                        <th class="px-4 py-3 font-medium">Platform</th>
                        <th class="px-4 py-3 font-medium">Source Type</th>
                        <th class="px-4 py-3 font-medium">Imported By</th>
                        <th class="px-6 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($syncLogs as $log)
                        <tr class="border-t border-[#f2f3f6]">
                            <td class="px-6 py-3.5 text-[#5c6266]">{{ $log->created_at->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-3.5 font-medium text-[#14181a]">{{ $log->client->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $log->platform->name ?? 'Campuran' }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $sourceTypeLabels[$log->source_type] ?? \Illuminate\Support\Str::headline($log->source_type) }}</td>
                            <td class="px-4 py-3.5 text-[#5c6266]">{{ $log->importedBy->name ?? '-' }}</td>
                            <td class="px-6 py-3.5">
                                <span class="text-xs font-medium px-2.5 py-1 rounded-full
                                    {{ $log->status === 'success' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                                    {{ $log->status === 'failed' ? 'bg-[#fdf2f1] text-[#b3423e]' : '' }}
                                    {{ $log->status === 'pending' ? 'bg-[#fdf6ec] text-[#b8873a]' : '' }}">
                                    {{ ucfirst($log->status) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-4 border-t border-[#f2f3f6]">{{ $syncLogs->links() }}</div>
        @endif
    </div>
</div>
@endsection