@extends('layouts.app')
@section('title', 'Client Management')
@section('content')

<div class="p-8 max-w-[1400px]">

    <div class="flex items-center justify-between mb-7">
        <div>
            <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Client Management</h1>
            <p class="text-[#5c6266] text-sm mt-1">Kelola portofolio client dan paket langganan mereka.</p>
        </div>

        <a href="{{ route('client-onboarding.create') }}"
           class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
            <span class="material-symbols-outlined text-[17px]">person_add</span>
            Tambah Client
        </a>
    </div>

    @if (session('status'))
        <div class="bg-[#f0f5f4] text-[#044b46] text-sm p-3.5 rounded-lg mb-5">{{ session('status') }}</div>
    @endif

    {{-- Search & Filter --}}
    <form method="GET" action="{{ route('client-onboarding.index') }}" class="card p-4 mb-5 flex items-center gap-3">
        <div class="flex-1 relative">
            <span class="material-symbols-outlined absolute inset-y-0 left-0 flex items-center pl-3.5 text-[#c3c7cb] text-[19px]">search</span>
            <input type="text" name="search" value="{{ $search }}" placeholder="Cari client, email, atau paket..."
                   class="w-full pl-10 pr-4 py-2.5 text-sm border border-[#eef0f4] rounded-lg focus:outline-none focus:border-[#044b46]/40">
        </div>

        <select name="status" onchange="this.form.submit()"
                class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2.5 bg-white focus:outline-none focus:border-[#044b46]/40">
            <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Status: All</option>
            <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
            <option value="past_due" {{ $status === 'past_due' ? 'selected' : '' }}>Past Due</option>
            <option value="paused" {{ $status === 'paused' ? 'selected' : '' }}>Paused</option>
        </select>
    </form>

    {{-- Table --}}
    <div class="card overflow-hidden">
        <table class="w-full text-sm text-left">
            <thead>
                <tr class="border-b border-[#f2f3f6] bg-[#f7f8fc]">
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide">Client Name</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide">Email</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide">Plan</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide">Status</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide">Owner Status</th>
                    <th class="px-6 py-3.5 font-medium text-[#9aa0a4] text-[11px] uppercase tracking-wide text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $client)
                    <tr class="border-b border-[#f2f3f6] last:border-0 hover:bg-[#f7f8fc] transition-colors">
                        <td class="px-6 py-3.5">
                            <a href="{{ route('client-onboarding.show', $client) }}" class="flex items-center gap-3 group">
                                <div class="w-9 h-9 rounded-full bg-[#044b46] text-white flex items-center justify-center text-sm font-semibold shrink-0 overflow-hidden">
                                    @if ($client->logo_url)
                                        <img src="{{ $client->logo_url }}" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($client->brand_name ?? $client->name, 0, 1)) }}
                                    @endif
                                </div>
                                <div>
                                    <p class="font-medium text-[#14181a] group-hover:text-[#044b46] group-hover:underline">{{ $client->brand_name }}</p>
                                    <p class="text-xs text-[#9aa0a4]">{{ $client->category->name ?? '-' }}</p>
                                </div>
                            </a>
                        </td>
                        <td class="px-6 py-3.5 text-[#5c6266]">{{ $client->owner->email ?? '-' }}</td>
                        <td class="px-6 py-3.5 text-[#5c6266]">{{ $client->activePackage->package_name_snapshot ?? '-' }}</td>
                        <td class="px-6 py-3.5">
                            <span class="text-xs font-medium px-2.5 py-1 rounded-full
                                {{ $client->status === 'active' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                                {{ $client->status === 'past_due' ? 'bg-[#fdf2f1] text-[#b3423e]' : '' }}
                                {{ $client->status === 'paused' ? 'bg-[#f2f3f6] text-[#9aa0a4]' : '' }}">
                                {{ $client->status === 'past_due' ? 'Past Due' : ucfirst($client->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3.5 text-[#5c6266]">{{ $client->owner ? ucfirst($client->owner->status) : 'Belum ada owner' }}</td>
                        <td class="px-6 py-3.5">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('client-onboarding.show', $client) }}"
                                   class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#f2f3f6] hover:text-[#044b46] transition-colors" title="Lihat detail">
                                    <span class="material-symbols-outlined text-[17px]">visibility</span>
                                </a>
                                <a href="{{ route('client-onboarding.edit', $client) }}"
                                   class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#f2f3f6] hover:text-[#044b46] transition-colors" title="Edit">
                                    <span class="material-symbols-outlined text-[17px]">edit_square</span>
                                </a>
                                <form action="{{ route('client-onboarding.destroy', $client) }}" method="POST" class="m-0"
                                      onsubmit="return confirm('Yakin hapus {{ $client->brand_name }}? Kalau sudah punya riwayat konten, client hanya akan dinonaktifkan.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="w-8 h-8 flex items-center justify-center rounded-lg text-[#9aa0a4] hover:bg-[#fdf2f1] hover:text-[#b3423e] transition-colors" title="Hapus">
                                        <span class="material-symbols-outlined text-[17px]">delete</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-10 text-center text-[#9aa0a4]">Tidak ada client yang cocok dengan pencarian.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($clients->total() > 0)
        <div class="flex items-center justify-between mt-5">
            <p class="text-sm text-[#9aa0a4]">Showing {{ $clients->firstItem() }} to {{ $clients->lastItem() }} of {{ $clients->total() }} clients</p>
            <div class="flex items-center gap-2">{{ $clients->onEachSide(1)->links() }}</div>
        </div>
    @endif

</div>
@endsection