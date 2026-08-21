@extends('layouts.app')
@section('title', 'Kelola Klien')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-7">
        <div>
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Kelola Klien</h1>
            <p class="text-[var(--text-secondary)] text-sm mt-1">Kelola portofolio klien dan paket langganan mereka.</p>
        </div>

        <a href="{{ route('client-management.create') }}"
           class="self-start btn-primary">
            <span class="material-symbols-outlined text-[17px]">person_add</span>
            Tambah Klien
        </a>
    </div>

    @if (session('status'))
        <div class="bg-[var(--brand-tint)] text-[var(--brand)] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    {{-- Search & Filter --}}
    <form method="GET" action="{{ route('client-management.index') }}" class="card p-4 mb-5 flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="flex-1 relative">
            <span class="material-symbols-outlined absolute inset-y-0 left-0 flex items-center pl-3.5 text-[var(--text-muted)] text-[19px]">search</span>
            <input type="text" name="search" value="{{ $search }}" placeholder="Cari nama klien atau brand..."
                   class="bg-[var(--surface-card)] w-full pl-10 pr-4 py-2.5 text-sm border border-[var(--border)] rounded-lg focus:outline-none focus:border-[#044b46]/40">
        </div>

        <select name="status" onchange="this.form.submit()"
                class="text-sm border border-[var(--border)] rounded-lg px-3.5 py-2.5 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
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
            <thead class="bg-[var(--surface-page)]">
                <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Nama Klien</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Paket</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Aset</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Portal Klien</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr class="border-t border-[var(--surface-muted)] hover:bg-[var(--surface-page)] transition-colors cursor-pointer"
                        onclick="navigateTo('{{ route('client-management.show', $client) }}')">
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden {{ $client->logo_url ? 'bg-[var(--brand-tint)]' : 'bg-[var(--brand-solid)] text-white' }}">
                                    @if ($client->logo_url)
                                        <img src="{{ $client->logo_url }}" alt="" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($client->brand_name ?? $client->name, 0, 1)) }}
                                    @endif
                                </div>
                                <div>
                                    <p class="font-medium text-[var(--text-primary)]">{{ $client->brand_name }}</p>
                                    <p class="text-xs text-[var(--text-muted)]">{{ $client->category->name ?? '-' }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $client->activePackage->package_name_snapshot ?? '-' }}</td>
                        <td class="px-6 py-3.5" onclick="event.stopPropagation()">
                            @if ($client->asset_link)
                                <a href="{{ $client->asset_link }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-1 text-[var(--brand)] hover:underline" title="{{ $client->asset_link }}">
                                    <span class="material-symbols-outlined text-[15px]">folder_open</span>
                                </a>
                            @else
                                <span class="text-[var(--text-muted)]">-</span>
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
                        <td class="px-6 py-3.5 whitespace-nowrap">
                            <span class="badge {{ $client->portal_access_enabled ? 'badge-success' : 'badge-neutral' }}">
                                {{ $client->portal_access_enabled ? 'Aktif' : 'Dinonaktifkan' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center">
                            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">apartment</span>
                            @if ($search || $status !== 'all')
                                <p class="text-sm text-[var(--text-muted)]">Tidak ada klien yang cocok dengan filter ini.</p>
                                <a href="{{ route('client-management.index') }}" class="text-xs text-[var(--brand)] font-medium hover:underline mt-1 inline-block">Reset filter</a>
                            @else
                                <p class="text-sm text-[var(--text-muted)]">Belum ada klien.</p>
                                <a href="{{ route('client-management.create') }}" class="text-xs text-[var(--brand)] font-medium hover:underline mt-1 inline-block">Tambah klien pertama</a>
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
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden {{ $client->logo_url ? 'bg-[var(--brand-tint)]' : 'bg-[var(--brand-solid)] text-white' }}">
                            @if ($client->logo_url)
                                <img src="{{ $client->logo_url }}" alt="" class="w-full h-full object-cover">
                            @else
                                {{ strtoupper(substr($client->brand_name ?? $client->name, 0, 1)) }}
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="font-medium text-[var(--text-primary)] truncate">{{ $client->brand_name }}</p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="badge
                                    {{ $client->status === 'active' ? 'badge-success' : '' }}
                                    {{ $client->status === 'past_due' ? 'badge-danger' : '' }}
                                    {{ $client->status === 'paused' ? 'badge-neutral' : '' }}">
                                    {{ match($client->status) { 'active' => 'Aktif', 'past_due' => 'Jatuh Tempo', 'paused' => 'Dijeda', default => ucfirst($client->status) } }}
                                </span>
                                <span class="text-xs text-[var(--text-muted)] truncate">{{ $client->activePackage->package_name_snapshot ?? '-' }}</span>
                            </div>
                        </div>
                    </div>
                    <span class="shrink-0 w-8 h-8 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[var(--text-muted)] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </span>
                </button>

                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Aset</span>
                        @if ($client->asset_link)
                            <a href="{{ $client->asset_link }}" target="_blank" rel="noopener" @click.stop
                               class="inline-flex items-center gap-1 text-[var(--brand)] hover:underline" title="{{ $client->asset_link }}">
                                <span class="material-symbols-outlined text-[15px]">folder_open</span>
                            </a>
                        @else
                            <span class="text-[var(--text-muted)]">-</span>
                        @endif
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Portal Klien</span>
                        <span class="badge {{ $client->portal_access_enabled ? 'badge-success' : 'badge-neutral' }}">
                            {{ $client->portal_access_enabled ? 'Aktif' : 'Dinonaktifkan' }}
                        </span>
                    </div>
                    <a href="{{ route('client-management.show', $client) }}"
                        class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors">
                        Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>
            </div>
        @empty
            <div class="card p-8 text-center">
                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">apartment</span>
                @if ($search || $status !== 'all')
                    <p class="text-sm text-[var(--text-muted)]">Tidak ada klien yang cocok dengan filter ini.</p>
                    <a href="{{ route('client-management.index') }}" class="text-xs text-[var(--brand)] font-medium hover:underline mt-1 inline-block">Reset filter</a>
                @else
                    <p class="text-sm text-[var(--text-muted)]">Belum ada klien.</p>
                    <a href="{{ route('client-management.create') }}" class="text-xs text-[var(--brand)] font-medium hover:underline mt-1 inline-block">Tambah klien pertama</a>
                @endif
            </div>
        @endforelse
    </div>

    @if ($clients->total() > 0)
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-5">
            <p class="text-sm text-[var(--text-muted)]">Menampilkan {{ $clients->firstItem() }} - {{ $clients->lastItem() }} dari {{ $clients->total() }} klien</p>
            <div class="flex items-center gap-2">{{ $clients->onEachSide(1)->links() }}</div>
        </div>
    @endif

</div>
@endsection