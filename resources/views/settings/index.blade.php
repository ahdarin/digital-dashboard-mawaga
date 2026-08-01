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
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
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
                    <div class="w-14 h-14 rounded-full bg-gradient-to-br from-[#044b46] to-[#0a8f76] text-white text-lg font-bold flex items-center justify-center shadow-[0_4px_12px_rgba(4,75,70,0.3)]">
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
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
            <div class="mb-6">
                <h2 class="text-xl font-extrabold text-[#191c1c]">Analytics Integration</h2>
                <p class="text-sm text-gray-500 mt-1">Koneksi API per platform untuk sinkronisasi data performa konten.</p>
            </div>

            <div class="space-y-3">
                @foreach ($integrations as $row)
                    <div class="flex items-center justify-between border border-gray-100 rounded-xl px-4 py-3.5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-100 to-sky-50 flex items-center justify-center">
                                <span class="material-symbols-outlined text-sky-600 text-[20px]">hub</span>
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
                                    class="text-xs font-semibold px-4 py-2 rounded-lg transition-all duration-150
                                        {{ $row['connected']
                                            ? 'bg-gray-50 text-gray-500 hover:bg-gray-100'
                                            : 'bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white hover:opacity-90 shadow-[0_4px_10px_rgba(4,75,70,0.25)]' }}">
                                {{ $row['connected'] ? 'Disconnect' : 'Connect' }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Import Performance Data --}}
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
            <div class="mb-5">
                <h2 class="text-xl font-extrabold text-[#191c1c]">Import Performance Data</h2>
                <p class="text-sm text-gray-500 mt-1">Upload file CSV berisi metrik performa konten manual.</p>
            </div>

            {{-- Flash message hasil import --}}
            @if (session('import_success'))
                <div class="mb-4 bg-emerald-50 border border-emerald-100 rounded-xl p-4">
                    <p class="text-sm font-semibold text-emerald-700 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">check_circle</span>
                        {{ session('import_success') }}
                    </p>
                    @if (! empty(session('import_skipped')))
                        <ul class="mt-2 ml-6 list-disc text-xs text-emerald-700/80 space-y-0.5">
                            @foreach (session('import_skipped') as $skip)
                                <li>{{ $skip }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @if (session('import_error'))
                <div class="mb-4 bg-rose-50 border border-rose-100 rounded-xl p-4">
                    <p class="text-sm font-semibold text-rose-700 flex items-center gap-2">
                        <span class="material-symbols-outlined text-[18px]">error</span>
                        {{ session('import_error') }}
                    </p>
                </div>
            @endif

            <form action="{{ route('settings.import-performance') }}" method="POST" enctype="multipart/form-data"
                  x-data="{ fileName: null }">
                @csrf

                <div class="mb-4">
                    <label class="text-xs font-semibold text-gray-500 uppercase mb-1.5 block">Client</label>
                    <select name="client_id" required
                            class="w-full text-sm font-medium border border-gray-200 rounded-xl px-4 py-2.5 bg-white focus:outline-none focus:border-[#044b46]">
                        <option value="">Pilih client tujuan data ini...</option>
                        @foreach ($clientOptions as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <label for="csv-file"
                       class="cursor-pointer border-2 border-dashed border-[#044b46]/20 rounded-xl p-8 flex flex-col items-center justify-center text-center bg-gradient-to-br from-[#f4f9f7] to-white hover:border-[#044b46]/40 transition-colors duration-150 block">
                    <input id="csv-file" type="file" name="file" accept=".csv,.txt" required class="hidden"
                           x-on:change="fileName = $event.target.files[0]?.name">

                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#044b46] to-[#0a8f76] flex items-center justify-center mb-3 shadow-[0_4px_12px_rgba(4,75,70,0.3)]">
                        <span class="material-symbols-outlined text-white text-[24px]">upload_file</span>
                    </div>
                    <p class="text-sm font-semibold text-[#191c1c] mb-1" x-text="fileName ?? 'Klik untuk pilih file'"></p>
                    <p class="text-xs text-gray-400">Format .csv, maksimal 5MB</p>
                </label>

                <details class="mt-3">
                    <summary class="text-xs font-semibold text-[#044b46] cursor-pointer">Lihat format kolom CSV</summary>
                    <div class="mt-2 bg-gray-50 rounded-lg p-3 text-xs text-gray-500 font-mono overflow-x-auto space-y-2">
                        <div>
                            <span class="text-gray-400 not-italic font-sans">Wajib (semua tipe konten):</span><br>
                            content_title,platform,metric_date,views,engagement_rate<br>
                            Post Promo Ramadan,Instagram,2026-07-01,1200,4.5
                        </div>
                        <div>
                            <span class="text-gray-400 not-italic font-sans">Opsional, khusus Reels/TikTok (boleh kosong/tidak ada):</span><br>
                            watch_time_avg,completion_rate,shares,saves<br>
                            18,64.5,120,340
                        </div>
                    </div>
                </details>
                <p class="text-[11px] text-gray-400 mt-1">watch_time_avg = rata-rata detik ditonton, completion_rate = % nonton sampai habis. Kosongkan kolomnya (jangan isi 0) kalau konten Feed/foto yang memang nggak punya metrik ini.</p>

                <button type="submit"
                        class="mt-4 w-full bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white text-sm font-semibold px-5 py-3 rounded-xl hover:opacity-90 transition-opacity duration-150 shadow-[0_4px_10px_rgba(4,75,70,0.25)]">
                    Upload &amp; Import
                </button>
            </form>
        </div>

        {{-- Notifications --}}
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
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
                                class="w-11 h-6 rounded-full bg-gradient-to-r from-[#044b46] to-[#0a8f76] relative transition-colors duration-150">
                            <span class="absolute top-0.5 right-0.5 w-5 h-5 rounded-full bg-white shadow"></span>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Anomaly Detection --}}
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
            <div class="flex items-center gap-3 mb-1">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-400 flex items-center justify-center shadow-[0_4px_10px_rgba(99,102,241,0.3)]">
                    <span class="material-symbols-outlined text-white text-[20px]">auto_awesome</span>
                </div>
                <h2 class="text-xl font-extrabold text-[#191c1c]">Anomaly Detection</h2>
            </div>
            <p class="text-sm text-gray-500 mb-5 ml-[52px]">
                Otomatis bandingin performa konten hari ini vs rata-rata 30 hari terakhir, kirim notifikasi kalau ada lonjakan/penurunan signifikan.
                Berjalan otomatis tiap jam. Ini buat trigger manual (misal buat testing).
            </p>

            <form action="{{ route('settings.detect-anomalies') }}" method="POST">
                @csrf
                <button type="submit"
                        class="bg-gradient-to-r from-indigo-500 to-indigo-400 text-white text-sm font-semibold px-5 py-2.5 rounded-xl hover:opacity-90 transition-opacity shadow-[0_4px_10px_rgba(99,102,241,0.25)] flex items-center gap-2">
                    <span class="material-symbols-outlined text-[16px]">bolt</span>
                    Jalankan Sekarang
                </button>
            </form>
        </div>

    </div>
</div>

@endsection