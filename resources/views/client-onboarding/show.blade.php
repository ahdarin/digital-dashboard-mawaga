@extends('layouts.app')
@section('title', $client->brand_name)
@section('content')

<div class="p-8">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-8">
        <div class="flex items-center gap-4">
            <a href="{{ route('client-onboarding.index') }}"
               class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-500 transition-colors duration-150 shrink-0">
                <span class="material-symbols-outlined text-[20px]">arrow_back</span>
            </a>
            <div class="w-14 h-14 rounded-2xl bg-[#044b46]/10 text-[#044b46] flex items-center justify-center text-xl font-bold shrink-0">
                {{ strtoupper(substr($client->brand_name, 0, 1)) }}
            </div>
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-3xl font-extrabold text-[#191c1c]">{{ $client->brand_name }}</h1>
                    <span class="text-xs font-semibold px-3 py-1 rounded-full
                        {{ $client->status === 'active' ? 'bg-emerald-50 text-emerald-600' : '' }}
                        {{ $client->status === 'past_due' ? 'bg-rose-50 text-rose-600' : '' }}
                        {{ $client->status === 'paused' ? 'bg-gray-100 text-gray-500' : '' }}">
                        {{ $client->status === 'past_due' ? 'Past Due' : ucfirst($client->status) }}
                    </span>
                </div>
                <p class="text-sm text-gray-400 mt-1">{{ $client->name }} &middot; {{ $client->category->name ?? '-' }}</p>
            </div>
        </div>

        <a href="{{ route('client-onboarding.edit', $client) }}"
           class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-semibold px-5 py-3 rounded-xl hover:bg-[#044b46]/90 transition-colors duration-150 shrink-0">
            <span class="material-symbols-outlined text-[18px]">edit</span>
            Edit Client
        </a>
    </div>

    @if (session('status'))
        <div class="bg-emerald-50 text-[#044b46] text-sm p-3 rounded-xl mb-6">{{ session('status') }}</div>
    @endif

    <div class="flex gap-6 items-start">

        {{-- Main column --}}
        <div class="flex-1 min-w-0 space-y-6">

            {{-- Quick stats --}}
            <div class="grid grid-cols-2 gap-6">
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <p class="text-sm font-semibold text-gray-500 mb-2">Total Content Plan</p>
                    <p class="text-3xl font-extrabold text-[#191c1c]">{{ $planCount }}</p>
                </div>
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <p class="text-sm font-semibold text-gray-500 mb-2">Total Konten Dibuat</p>
                    <p class="text-3xl font-extrabold text-[#191c1c]">{{ $contentCount }}</p>
                </div>
            </div>

            {{-- Recent content --}}
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h2 class="text-lg font-extrabold text-[#191c1c] mb-5">Konten Terbaru</h2>

                @if ($recentContentItems->isEmpty())
                    <p class="text-sm text-gray-400 py-6 text-center">Belum ada konten untuk client ini.</p>
                @else
                    <table class="w-full text-sm text-left">
                        <thead class="text-gray-400 text-xs uppercase">
                            <tr>
                                <th class="py-2 pr-4 font-semibold">Judul</th>
                                <th class="py-2 pr-4 font-semibold">Tipe</th>
                                <th class="py-2 pr-4 font-semibold">Deadline</th>
                                <th class="py-2 pr-4 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentContentItems as $item)
                                <tr class="border-t border-gray-50">
                                    <td class="py-3 pr-4 font-medium text-[#191c1c]">{{ $item->title }}</td>
                                    <td class="py-3 pr-4 text-gray-500">{{ $item->contentType->name ?? '-' }}</td>
                                    <td class="py-3 pr-4 text-gray-500">
                                        {{ $item->deadline_at ? $item->deadline_at->translatedFormat('d M Y') : '-' }}
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span class="text-xs px-2 py-1 rounded-full
                                            {{ ($item->workflow->is_overdue ?? false) ? 'bg-rose-100 text-rose-600' : 'bg-[#044b46]/10 text-[#044b46]' }}">
                                            {{ $item->workflow->current_status ?? '-' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

        </div>

        {{-- Sidebar info --}}
        <div class="w-[340px] shrink-0 space-y-6">

            {{-- Owner info --}}
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h2 class="text-lg font-extrabold text-[#191c1c] mb-5">Owner Account</h2>

                @if ($client->owner)
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-full bg-[#044b46] text-white flex items-center justify-center text-sm font-bold shrink-0">
                            {{ strtoupper(substr($client->owner->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-[#191c1c]">{{ $client->owner->name }}</p>
                            <p class="text-xs text-gray-400">{{ ucfirst($client->owner->status) }}</p>
                        </div>
                    </div>

                    <div class="space-y-3 text-sm">
                        <div class="flex items-center gap-2 text-gray-500">
                            <span class="material-symbols-outlined text-[16px]">mail</span>
                            {{ $client->owner->email }}
                        </div>
                        <div class="flex items-center gap-2 text-gray-500">
                            <span class="material-symbols-outlined text-[16px]">call</span>
                            {{ $client->owner->phone_number ?? '-' }}
                        </div>
                    </div>
                @else
                    <p class="text-sm text-gray-400">Belum ada akun owner terdaftar.</p>
                @endif
            </div>

            {{-- Package info --}}
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h2 class="text-lg font-extrabold text-[#191c1c] mb-5">Paket Aktif</h2>

                @if ($client->activePackage)
                    <p class="text-sm font-semibold text-[#191c1c]">{{ $client->activePackage->package_name_snapshot }}</p>
                    <div class="mt-3 space-y-2 text-xs text-gray-500">
                        <p>Kuota Konten: {{ $client->activePackage->monthly_content_quota }} / bulan</p>
                        <p>Kuota Desain: {{ $client->activePackage->monthly_design_quota }} / bulan</p>
                        <p>Periode: {{ optional($client->activePackage->start_date)->translatedFormat('d M Y') }} &mdash;
                            {{ optional($client->activePackage->end_date)->translatedFormat('d M Y') ?? 'Berjalan' }}</p>
                    </div>
                @else
                    <p class="text-sm text-gray-400">Belum ada paket aktif.</p>
                @endif
            </div>

            {{-- Danger zone --}}
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h2 class="text-sm font-bold text-rose-500 mb-3">Danger Zone</h2>
                <form action="{{ route('client-onboarding.destroy', $client) }}" method="POST"
                      onsubmit="return confirm('Yakin hapus {{ $client->brand_name }}? Kalau sudah punya riwayat konten, client hanya akan dinonaktifkan, bukan dihapus permanen.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="w-full bg-rose-50 text-rose-600 text-sm font-semibold py-2.5 rounded-xl hover:bg-rose-100 transition-colors duration-150">
                        Hapus Client
                    </button>
                </form>
            </div>

        </div>

    </div>
</div>
@endsection