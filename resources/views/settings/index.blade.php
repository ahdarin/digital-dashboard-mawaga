@extends('layouts.app')
@section('title', 'Settings')
@section('content')

<div class="p-8 max-w-5xl">

    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-4xl font-extrabold text-[#191c1c]">Settings</h1>
        <p class="text-gray-500 mt-2">Kelola akun dan koneksi data performa kontenmu.</p>
    </div>

    <div class="space-y-6">

        {{-- Account --}}
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-extrabold text-[#191c1c]">Account</h2>
                <a href="{{ route('profile.me') }}" class="text-sm font-semibold text-[#044b46] hover:underline">
                    Lihat Profile
                </a>
            </div>

            <div class="flex items-center gap-4">
                @if ($user->avatar_url)
                    <img src="{{ $user->avatar_url }}" referrerpolicy="no-referrer" class="w-14 h-14 rounded-full object-cover">
                @else
                    <div class="w-14 h-14 rounded-full bg-[#044b46] text-white text-lg font-bold flex items-center justify-center">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                @endif
                <div>
                    <p class="text-base font-bold text-[#191c1c]">{{ $user->name }}</p>
                    <p class="text-sm text-gray-500">{{ $user->email }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $user->role->name ?? '-' }}</p>
                </div>
            </div>
        </div>

        {{-- Analytics Integration Settings --}}
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <div class="mb-6">
                <h2 class="text-xl font-extrabold text-[#191c1c]">Analytics Integration</h2>
                <p class="text-sm text-gray-500 mt-1">Koneksi API per platform untuk sinkronisasi data performa konten.</p>
            </div>

            <div class="space-y-3">
                @foreach ($integrations as $row)
                    <div class="flex items-center justify-between border border-gray-100 rounded-xl px-4 py-3.5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-[#044b46]/10 flex items-center justify-center">
                                <span class="material-symbols-outlined text-[#044b46] text-[20px]">hub</span>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-[#191c1c]">{{ $row['platform'] }}</p>
                                <p class="text-xs text-gray-400">
                                    @if ($row['connected'])
                                        Terhubung via {{ $row['integration_name'] }}
                                        &middot; diperbarui {{ $row['updated_at']?->diffForHumans() }}
                                    @else
                                        Belum terhubung
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <span class="text-xs font-semibold px-3 py-1 rounded-full
                                {{ $row['connected'] ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $row['connected'] ? 'Connected' : 'Not Connected' }}
                            </span>
                            <button type="button"
                                    class="text-xs font-semibold px-4 py-2 rounded-lg transition-colors duration-150
                                        {{ $row['connected']
                                            ? 'bg-gray-50 text-gray-500 hover:bg-gray-100'
                                            : 'bg-[#044b46] text-white hover:bg-[#044b46]/90' }}">
                                {{ $row['connected'] ? 'Disconnect' : 'Connect' }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Import Performance Data --}}
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <div class="mb-5">
                <h2 class="text-xl font-extrabold text-[#191c1c]">Import Performance Data</h2>
                <p class="text-sm text-gray-500 mt-1">Upload file CSV/Excel berisi metrik performa konten manual.</p>
            </div>

            <div class="border-2 border-dashed border-gray-200 rounded-xl p-8 flex flex-col items-center justify-center text-center">
                <div class="w-12 h-12 rounded-full bg-[#044b46]/10 flex items-center justify-center mb-3">
                    <span class="material-symbols-outlined text-[#044b46] text-[24px]">upload_file</span>
                </div>
                <p class="text-sm font-semibold text-[#191c1c] mb-1">Tarik file ke sini atau klik untuk upload</p>
                <p class="text-xs text-gray-400 mb-4">Format .csv atau .xlsx, maksimal 5MB</p>
                <button type="button"
                        class="bg-[#044b46] text-white text-sm font-semibold px-5 py-2.5 rounded-xl hover:bg-[#044b46]/90 transition-colors duration-150">
                    Pilih File
                </button>
            </div>
        </div>

        {{-- Notifications --}}
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <h2 class="text-xl font-extrabold text-[#191c1c] mb-5">Notifications</h2>

            <div class="space-y-4">
                @foreach ([
                    'Konten mendekati deadline',
                    'Ada revisi baru yang perlu direspons',
                    'Sinkronisasi data performa selesai/gagal',
                ] as $label)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">{{ $label }}</span>
                        <button type="button"
                                class="w-11 h-6 rounded-full bg-[#044b46] relative transition-colors duration-150">
                            <span class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-white shadow"></span>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</div>

@endsection