@extends('layouts.app')
@section('title', 'Client Management')
@section('content')

<div class="p-8">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-8">
        <h1 class="text-4xl font-extrabold text-[#191c1c]">Client Management</h1>

        <a href="{{ route('client-onboarding.create') }}"
           class="flex items-center gap-2 bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white text-sm font-semibold px-5 py-3 rounded-xl hover:opacity-90 transition-opacity duration-150 shrink-0 shadow-[0_6px_16px_rgba(4,75,70,0.25)]">
            <span class="material-symbols-outlined text-[18px]">person_add</span>
            Add Client
        </a>
    </div>

    @if (session('status'))
        <div class="bg-emerald-50 text-[#044b46] text-sm p-3 rounded-xl mb-4">{{ session('status') }}</div>
    @endif

    {{-- Search & Filter --}}
    <form method="GET" action="{{ route('client-onboarding.index') }}" class="flex items-center gap-4 mb-6">
        <div class="flex-1 relative">
            <span class="material-symbols-outlined absolute inset-y-0 left-0 flex items-center pl-4 text-gray-400 text-[20px]">
                search
            </span>
            <input
                type="text"
                name="search"
                value="{{ $search }}"
                placeholder="Search clients, emails, or plans..."
                class="w-full pl-11 pr-4 py-3 text-sm bg-white rounded-xl shadow-[0_4px_16px_rgba(15,23,42,0.06)] border-0 focus:outline-none focus:ring-2 focus:ring-[#044b46]/30"
            >
        </div>

        <div class="relative">
            <select
                name="status"
                onchange="this.form.submit()"
                class="appearance-none bg-white shadow-[0_4px_16px_rgba(15,23,42,0.06)] rounded-xl pl-4 pr-10 py-3 text-sm font-semibold text-[#191c1c] focus:outline-none focus:ring-2 focus:ring-[#044b46]/30 cursor-pointer"
            >
                <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Status: All</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
                <option value="past_due" {{ $status === 'past_due' ? 'selected' : '' }}>Past Due</option>
                <option value="paused" {{ $status === 'paused' ? 'selected' : '' }}>Paused</option>
            </select>
            <span class="material-symbols-outlined absolute inset-y-0 right-3 flex items-center pointer-events-none text-gray-400 text-[18px]">
                expand_more
            </span>
        </div>
    </form>

    {{-- Table --}}
    <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] overflow-hidden">
<table class="w-full text-sm text-left">
    <thead>
        <tr class="border-b border-gray-100">
            <th class="px-6 py-4 font-semibold text-gray-400 text-xs uppercase tracking-wide">Client Name</th>
            <th class="px-6 py-4 font-semibold text-gray-400 text-xs uppercase tracking-wide">Email</th>
            <th class="px-6 py-4 font-semibold text-gray-400 text-xs uppercase tracking-wide">Plan</th>
            <th class="px-6 py-4 font-semibold text-gray-400 text-xs uppercase tracking-wide">Status</th>
            <th class="px-6 py-4 font-semibold text-gray-400 text-xs uppercase tracking-wide">Owner Status</th>
            <th class="px-6 py-4 font-semibold text-gray-400 text-xs uppercase tracking-wide text-right">Actions</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($clients as $client)
            <tr class="border-b border-gray-50 last:border-0 hover:bg-gray-50/60 transition-colors duration-150">
                <td class="px-6 py-4">
                    <a href="{{ route('client-onboarding.show', $client) }}" class="flex items-center gap-3 group">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#044b46] to-[#0a8f76] text-white flex items-center justify-center text-sm font-bold shrink-0 shadow-[0_3px_8px_rgba(4,75,70,0.25)]">
                            {{ strtoupper(substr($client->brand_name ?? $client->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="font-semibold text-[#191c1c] group-hover:text-[#044b46] group-hover:underline">{{ $client->brand_name }}</p>
                            <p class="text-xs text-gray-400">{{ $client->category->name ?? '-' }}</p>
                        </div>
                    </a>
                </td>
                <td class="px-6 py-4 text-gray-500">{{ $client->owner->email ?? '-' }}</td>
                <td class="px-6 py-4 text-gray-500">{{ $client->activePackage->package_name_snapshot ?? '-' }}</td>
                <td class="px-6 py-4">
                    <span class="text-xs font-semibold px-3 py-1 rounded-full
                        {{ $client->status === 'active' ? 'bg-emerald-50 text-emerald-600' : '' }}
                        {{ $client->status === 'past_due' ? 'bg-rose-50 text-rose-600' : '' }}
                        {{ $client->status === 'paused' ? 'bg-gray-100 text-gray-500' : '' }}">
                        {{ $client->status === 'past_due' ? 'Past Due' : ucfirst($client->status) }}
                    </span>
                </td>
                <td class="px-6 py-4 text-gray-500">
                    {{ $client->owner ? ucfirst($client->owner->status) : 'Belum ada owner' }}
                </td>
                <td class="px-6 py-4">
                    <div class="flex items-center justify-end gap-1">
                        <a href="{{ route('client-onboarding.show', $client) }}"
                           class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-[#044b46] transition-colors duration-150"
                           title="Lihat detail">
                            <span class="material-symbols-outlined text-[18px]">visibility</span>
                        </a>
                        <a href="{{ route('client-onboarding.edit', $client) }}"
                           class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-[#044b46] transition-colors duration-150"
                           title="Edit">
                            <span class="material-symbols-outlined text-[18px]">edit</span>
                        </a>
                        <form action="{{ route('client-onboarding.destroy', $client) }}" method="POST"
                              onsubmit="return confirm('Yakin hapus {{ $client->brand_name }}? Kalau sudah punya riwayat konten, client hanya akan dinonaktifkan.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-rose-50 hover:text-rose-600 transition-colors duration-150"
                                title="Hapus">
                                <span class="material-symbols-outlined text-[18px]">delete</span>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="px-6 py-10 text-center text-gray-400">
                    Tidak ada client yang cocok dengan pencarian.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
    </div>

    {{-- Pagination --}}
    @if ($clients->total() > 0)
        <div class="flex items-center justify-between mt-6">
            <p class="text-sm text-gray-400">
                Showing {{ $clients->firstItem() }} to {{ $clients->lastItem() }} of {{ $clients->total() }} clients
            </p>
            <div class="flex items-center gap-2">
                {{ $clients->onEachSide(1)->links() }}
            </div>
        </div>
    @endif

</div>
@endsection