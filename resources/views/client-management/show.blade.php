@extends('layouts.app')
@section('title', $client->brand_name)
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1300px] mx-auto">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-7">
        <div class="flex items-center gap-4">
            <a href="{{ route('client-management.index') }}"
               class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white text-[#5c6266] transition-colors shrink-0">
                <span class="material-symbols-outlined text-[19px]">arrow_back</span>
            </a>
            <div class="rounded-xl bg-[#f0f5f4] text-[#044b46] flex items-center justify-center text-lg font-semibold shrink-0 overflow-hidden" style="width:52px;height:52px">
                @if ($client->logo_url)
                    <img src="{{ $client->logo_url }}" class="w-full h-full object-cover">
                @else
                    {{ strtoupper(substr($client->brand_name, 0, 1)) }}
                @endif
            </div>
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="font-display text-2xl font-semibold text-[#14181a]">{{ $client->brand_name }}</h1>
                    <span class="text-xs font-medium px-2.5 py-1 rounded-full
                        {{ $client->status === 'active' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                        {{ $client->status === 'past_due' ? 'bg-[#fdf2f1] text-[#b3423e]' : '' }}
                        {{ $client->status === 'paused' ? 'bg-[#f2f3f6] text-[#9aa0a4]' : '' }}">
                        {{ $client->status === 'past_due' ? 'Past Due' : ucfirst($client->status) }}
                    </span>
                </div>
                <p class="text-sm text-[#9aa0a4] mt-0.5">{{ $client->name }} &middot; {{ $client->category->name ?? '-' }}</p>
            </div>
        </div>

        <a href="{{ route('client-management.edit', $client) }}"
           class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors shrink-0">
            <span class="material-symbols-outlined text-[17px]">edit</span> Edit Client
        </a>
    </div>

    @if (session('status'))
        <div class="bg-[#f0f5f4] text-[#044b46] text-sm p-3.5 rounded-lg mb-6">{{ session('status') }}</div>
    @endif

    <div class="flex flex-col lg:flex-row gap-5 items-stretch lg:items-start">

        <div class="flex-1 min-w-0 space-y-5">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="card p-6">
                    <p class="text-sm text-[#5c6266] mb-2">Total Content Plan</p>
                    <p class="font-display text-2xl font-semibold text-[#14181a]">{{ $planCount }}</p>
                </div>
                <div class="card p-6">
                    <p class="text-sm text-[#5c6266] mb-2">Total Content Created</p>
                    <p class="font-display text-2xl font-semibold text-[#14181a]">{{ $contentCount }}</p>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="font-display text-lg font-semibold text-[#14181a] mb-4">Recent Content</h2>

                @if ($recentContentItems->isEmpty())
                    <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada konten untuk client ini.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead>
                                <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                    <th class="pb-2.5 font-medium whitespace-nowrap">Title</th>
                                    <th class="pb-2.5 font-medium whitespace-nowrap">Type</th>
                                    <th class="pb-2.5 font-medium whitespace-nowrap">Deadline</th>
                                    <th class="pb-2.5 font-medium whitespace-nowrap">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentContentItems as $item)
                                    <tr class="border-t border-[#f2f3f6]">
                                        <td class="py-3 pr-4 font-medium text-[#14181a] whitespace-nowrap">{{ $item->title }}</td>
                                        <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item->contentType->name ?? '-' }}</td>
                                        <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item->deadline_at ? $item->deadline_at->translatedFormat('d M Y') : '-' }}</td>
                                        <td class="py-3 pr-4">
                                            <span class="text-xs px-2 py-1 rounded-full whitespace-nowrap {{ ($item->workflow->is_overdue ?? false) ? 'bg-[#fdf2f1] text-[#b3423e]' : 'bg-[#f0f5f4] text-[#044b46]' }}">
                                                {{ $item->workflow ? \App\Support\WorkflowTransitions::label($item->workflow->current_status) : '-' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

        </div>

        <div class="w-full lg:w-[320px] shrink-0 space-y-5">

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Owner Account</h2>

                @if ($client->owner)
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-9 h-9 rounded-full bg-[#044b46] text-white flex items-center justify-center text-sm font-semibold shrink-0">
                            {{ strtoupper(substr($client->owner->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="text-sm font-medium text-[#14181a]">{{ $client->owner->name }}</p>
                            <p class="text-xs text-[#9aa0a4]">{{ ucfirst($client->owner->status) }}</p>
                        </div>
                    </div>

                    <div class="space-y-2.5 text-sm">
                        <div class="flex items-center gap-2 text-[#5c6266]">
                            <span class="material-symbols-outlined text-[15px]">mail</span> {{ $client->owner->email }}
                        </div>
                        <div class="flex items-center gap-2 text-[#5c6266]">
                            <span class="material-symbols-outlined text-[15px]">call</span> {{ $client->owner->phone_number ?? '-' }}
                        </div>
                    </div>
                @else
                    <p class="text-sm text-[#9aa0a4]">Belum ada akun owner terdaftar.</p>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Active Package</h2>

                @if ($client->activePackage)
                    <p class="text-sm font-medium text-[#14181a]">{{ $client->activePackage->package_name_snapshot }}</p>
                    <div class="mt-3 space-y-1.5 text-xs text-[#5c6266]">
                        <p>Kuota Konten: {{ $client->activePackage->monthly_content_quota }} / bulan</p>
                        <p>Kuota Desain: {{ $client->activePackage->monthly_design_quota }} / bulan</p>
                        <p>Periode: {{ optional($client->activePackage->start_date)->translatedFormat('d M Y') }} &mdash;
                            {{ optional($client->activePackage->end_date)->translatedFormat('d M Y') ?? 'Berjalan' }}</p>
                    </div>
                @else
                    <p class="text-sm text-[#9aa0a4]">Belum ada paket aktif.</p>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Aset Klien</h2>

                @if ($client->asset_link)
                    <a href="{{ $client->asset_link }}" target="_blank" rel="noopener"
                       class="flex items-center gap-2.5 bg-[#f0f5f4] text-[#044b46] text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[#e4ede9] transition-colors">
                        <span class="material-symbols-outlined text-[17px]">folder_open</span>
                        Buka Google Drive
                        <span class="material-symbols-outlined text-[14px] ml-auto">open_in_new</span>
                    </a>
                @else
                    <p class="text-sm text-[#9aa0a4]">Belum ada link aset. <a href="{{ route('client-management.edit', $client) }}" class="text-[#044b46] hover:underline">Tambahkan di Edit Client</a>.</p>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="text-sm font-semibold text-[#b3423e] mb-3">Danger Zone</h2>
                <form action="{{ route('client-management.destroy', $client) }}" method="POST"
                      onsubmit="return appConfirm(this, 'Yakin hapus {{ addslashes($client->brand_name) }}? Kalau sudah punya riwayat konten, client hanya akan dinonaktifkan, bukan dihapus permanen.', { danger: true })">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="w-full bg-[#fdf2f1] text-[#b3423e] text-sm font-medium py-2.5 rounded-lg hover:bg-[#fbe2e0] transition-colors">
                        Hapus Client
                    </button>
                </form>
            </div>

        </div>

    </div>
</div>
@endsection