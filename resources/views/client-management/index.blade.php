@extends('layouts.app')
@section('title', 'Kelola Klien')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px]">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-7">
        <div>
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">Kelola Klien</h1>
            <p class="text-[#5c6266] text-sm mt-1">Kelola portofolio klien dan paket langganan mereka.</p>
        </div>

        <a href="{{ route('client-management.create') }}"
           class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
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
            <span class="material-symbols-outlined absolute inset-y-0 left-0 flex items-center pl-3.5 text-[#c3c7cb] text-[19px]">search</span>
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
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="border-b border-[#f2f3f6] bg-[#f7f8fc]">
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Nama Client</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Email</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Paket</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Aset</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Status</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide whitespace-nowrap">Status Owner</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr class="border-b border-[#f2f3f6] last:border-0 hover:bg-[#f7f8fc] cursor-pointer transition-colors"
                        onclick="window.location='{{ route('client-management.show', $client) }}'">
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden {{ $client->logo_url ? 'bg-[#f0f5f4]' : 'bg-[#044b46] text-white' }}">
                                    @if ($client->logo_url)
                                        <img src="{{ $client->logo_url }}" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($client->brand_name ?? $client->name, 0, 1)) }}
                                    @endif
                                </div>
                                <div>
                                    <p class="font-medium text-[#14181a]">{{ $client->brand_name }}</p>
                                    <p class="text-xs text-[#9aa0a4]">{{ $client->category->name ?? '-' }}</p>
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
                                <span class="text-[#c3c7cb]">-</span>
                            @endif
                        </td>
                        <td class="px-6 py-3.5">
                            <span class="text-xs font-medium px-2.5 py-1 rounded-full whitespace-nowrap
                                {{ $client->status === 'active' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                                {{ $client->status === 'past_due' ? 'bg-[#fdf2f1] text-[#b3423e]' : '' }}
                                {{ $client->status === 'paused' ? 'bg-[#f2f3f6] text-[#9aa0a4]' : '' }}">
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
                                <p class="text-sm text-[#9aa0a4]">Tidak ada client yang cocok dengan filter ini.</p>
                                <a href="{{ route('client-management.index') }}" class="text-xs text-[#044b46] font-medium hover:underline mt-1 inline-block">Reset filter</a>
                            @else
                                <p class="text-sm text-[#9aa0a4]">Belum ada client.</p>
                                <a href="{{ route('client-management.create') }}" class="text-xs text-[#044b46] font-medium hover:underline mt-1 inline-block">Tambah client pertama</a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>

    @if ($clients->total() > 0)
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-5">
            <p class="text-sm text-[#9aa0a4]">Menampilkan {{ $clients->firstItem() }} - {{ $clients->lastItem() }} dari {{ $clients->total() }} klien</p>
            <div class="flex items-center gap-2">{{ $clients->onEachSide(1)->links() }}</div>
        </div>
    @endif

</div>
@endsection