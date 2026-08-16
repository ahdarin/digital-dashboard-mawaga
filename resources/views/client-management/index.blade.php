@extends('layouts.app')
@section('title', 'Kelola Klien')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-7">
        <div>
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">Kelola Klien</h1>
            <p class="text-[#5c6266] text-sm mt-1">Kelola portofolio klien dan paket langganan mereka.</p>
        </div>

        <a href="{{ route('client-management.create') }}"
           class="self-start btn-primary">
            <span class="material-symbols-outlined text-[17px]">person_add</span>
            Tambah Klien
        </a>
    </div>

    @if (session('status'))
        <div class="bg-[#f0f5f4] text-[#044b46] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    {{-- Search & Filter --}}
    <form method="GET" action="{{ route('client-management.index') }}" class="card p-4 mb-5 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="flex-1 relative">
            <span class="material-symbols-outlined absolute inset-y-0 left-0 flex items-center pl-3.5 text-[#767c80] text-[19px]">search</span>
            <input type="text" name="search" value="{{ $search }}" placeholder="Cari klien, email, atau paket..."
                   class="w-full pl-10 pr-4 py-2.5 text-sm border border-[#eef0f4] rounded-lg focus:outline-none focus:border-[#044b46]/40">
        </div>

        <select name="status" onchange="this.form.submit()"
                class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2.5 bg-white focus:outline-none focus:border-[#044b46]/40">
            <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Status: Semua</option>
            <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Aktif</option>
            <option value="past_due" {{ $status === 'past_due' ? 'selected' : '' }}>Jatuh Tempo</option>
            <option value="paused" {{ $status === 'paused' ? 'selected' : '' }}>Dijeda</option>
        </select>
    </form>

    {{-- Table --}}
    <div class="card overflow-hidden hidden sm:block">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc]">
                <tr class="text-[#767c80] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Nama Klien</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Email</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Paket</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Aset</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status Owner</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors">
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden {{ $client->logo_url ? 'bg-[#f0f5f4]' : 'bg-[#044b46] text-white' }}">
                                    @if ($client->logo_url)
                                        <img src="{{ $client->logo_url }}" alt="" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($client->brand_name ?? $client->name, 0, 1)) }}
                                    @endif
                                </div>
                                <div>
                                    <a href="{{ route('client-management.show', $client) }}" class="font-medium text-[#14181a] hover:underline">{{ $client->brand_name }}</a>
                                    <p class="text-xs text-[#767c80]">{{ $client->category->name ?? '-' }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $client->owner->email ?? '-' }}</td>
                        <td class="px-6 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $client->activePackage->package_name_snapshot ?? '-' }}</td>
                        <td class="px-6 py-3.5">
                            @if ($client->asset_link)
                                <a href="{{ $client->asset_link }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-1 text-[#044b46] hover:underline" title="{{ $client->asset_link }}">
                                    <span class="material-symbols-outlined text-[15px]">folder_open</span>
                                </a>
                            @else
                                <span class="text-[#767c80]">-</span>
                            @endif
                        </td>
                        <td class="px-6 py-3.5">
                            <span class="badge
                                {{ $client->status === 'active' ? 'badge-success' : '' }}
                                {{ $client->status === 'past_due' ? 'badge-danger' : '' }}
                                {{ $client->status === 'paused' ? 'badge-neutral' : '' }}">
                                {{ match($client->status) { 'active' => 'Aktif', 'past_due' => 'Jatuh Tempo', 'paused' => 'Dijeda', default => ucfirst($client->status) } }}
                            </span>
                        </td>
                        <td class="px-6 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $client->owner ? match($client->owner->status) { 'active' => 'Aktif', 'invited' => 'Diundang', 'inactive' => 'Nonaktif', default => ucfirst($client->owner->status) } : 'Belum ada owner' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">apartment</span>
                            @if ($search || $status !== 'all')
                                <p class="text-sm text-[#767c80]">Tidak ada client yang cocok dengan filter ini.</p>
                                <a href="{{ route('client-management.index') }}" class="text-xs text-[#044b46] font-medium hover:underline mt-1 inline-block">Reset filter</a>
                            @else
                                <p class="text-sm text-[#767c80]">Belum ada client.</p>
                                <a href="{{ route('client-management.create') }}" class="text-xs text-[#044b46] font-medium hover:underline mt-1 inline-block">Tambah client pertama</a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>

    {{-- Mobile accordion list --}}
    <div class="sm:hidden space-y-3">
        @forelse ($clients as $client)
            <div class="card p-3.5" x-data="{ open: false }">
                <button type="button" class="w-full text-left flex items-center justify-between gap-3 cursor-pointer" @click="open = !open" :aria-expanded="open">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden {{ $client->logo_url ? 'bg-[#f0f5f4]' : 'bg-[#044b46] text-white' }}">
                            @if ($client->logo_url)
                                <img src="{{ $client->logo_url }}" alt="" class="w-full h-full object-cover">
                            @else
                                {{ strtoupper(substr($client->brand_name ?? $client->name, 0, 1)) }}
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="font-medium text-[#14181a] truncate">{{ $client->brand_name }}</p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="badge
                                    {{ $client->status === 'active' ? 'badge-success' : '' }}
                                    {{ $client->status === 'past_due' ? 'badge-danger' : '' }}
                                    {{ $client->status === 'paused' ? 'badge-neutral' : '' }}">
                                    {{ match($client->status) { 'active' => 'Aktif', 'past_due' => 'Jatuh Tempo', 'paused' => 'Dijeda', default => ucfirst($client->status) } }}
                                </span>
                                <span class="text-xs text-[#767c80] truncate">{{ $client->activePackage->package_name_snapshot ?? '-' }}</span>
                            </div>
                        </div>
                    </div>
                    <span class="shrink-0 w-8 h-8 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[#767c80] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </span>
                </button>

                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[#f2f3f6] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Email</span>
                        <span class="text-[#14181a] font-medium truncate ml-3">{{ $client->owner->email ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Aset</span>
                        @if ($client->asset_link)
                            <a href="{{ $client->asset_link }}" target="_blank" rel="noopener" @click.stop
                               class="inline-flex items-center gap-1 text-[#044b46] hover:underline" title="{{ $client->asset_link }}">
                                <span class="material-symbols-outlined text-[15px]">folder_open</span>
                            </a>
                        @else
                            <span class="text-[#767c80]">-</span>
                        @endif
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Status Owner</span>
                        <span class="text-[#14181a] font-medium">{{ $client->owner ? match($client->owner->status) { 'active' => 'Aktif', 'invited' => 'Diundang', 'inactive' => 'Nonaktif', default => ucfirst($client->owner->status) } : 'Belum ada owner' }}</span>
                    </div>
                    <a href="{{ route('client-management.show', $client) }}"
                        class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] rounded-lg py-2 transition-colors">
                        Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>
            </div>
        @empty
            <div class="card p-8 text-center">
                <span class="material-symbols-outlined text-[#d4d7db] text-[28px] mb-2 block">apartment</span>
                @if ($search || $status !== 'all')
                    <p class="text-sm text-[#767c80]">Tidak ada client yang cocok dengan filter ini.</p>
                    <a href="{{ route('client-management.index') }}" class="text-xs text-[#044b46] font-medium hover:underline mt-1 inline-block">Reset filter</a>
                @else
                    <p class="text-sm text-[#767c80]">Belum ada client.</p>
                    <a href="{{ route('client-management.create') }}" class="text-xs text-[#044b46] font-medium hover:underline mt-1 inline-block">Tambah client pertama</a>
                @endif
            </div>
        @endforelse
    </div>

    @if ($clients->total() > 0)
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-5">
            <p class="text-sm text-[#767c80]">Menampilkan {{ $clients->firstItem() }} - {{ $clients->lastItem() }} dari {{ $clients->total() }} klien</p>
            <div class="flex items-center gap-2">{{ $clients->onEachSide(1)->links() }}</div>
        </div>
    @endif

</div>
@endsection