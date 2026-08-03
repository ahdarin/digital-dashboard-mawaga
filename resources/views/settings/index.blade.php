@extends('layouts.app')
@section('title', 'Settings')
@section('content')

<div class="p-8 max-w-3xl">

    <div class="mb-7">
        <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Settings</h1>
        <p class="text-[#5c6266] text-sm mt-1">Kelola akun dan koneksi data performa kontenmu.</p>
    </div>

    <div class="space-y-5">

        {{-- Account --}}
        <div class="card p-6">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-display text-lg font-semibold text-[#14181a]">Account</h2>
                <a href="{{ route('profile.me') }}" class="text-sm font-medium text-[#044b46] hover:underline">Lihat Profile</a>
            </div>

            <div class="flex items-center gap-4">
                @if ($user->avatar_url)
                    <img src="{{ $user->avatar_url }}" referrerpolicy="no-referrer" class="w-12 h-12 rounded-full object-cover">
                @else
                    <div class="w-12 h-12 rounded-full bg-[#044b46] text-white text-base font-semibold flex items-center justify-center">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                @endif
                <div>
                    <p class="text-sm font-semibold text-[#14181a]">{{ $user->name }}</p>
                    <p class="text-sm text-[#5c6266]">{{ $user->email }}</p>
                    <p class="text-xs text-[#9aa0a4] mt-0.5">{{ $user->role->name ?? '-' }}</p>
                </div>
            </div>
        </div>

        {{-- Analytics Integration --}}
        <div class="card p-6">
            <div class="mb-5">
                <h2 class="font-display text-lg font-semibold text-[#14181a]">Analytics Integration</h2>
                <p class="text-sm text-[#5c6266] mt-1">Koneksi API per platform untuk sinkronisasi data performa konten.</p>
            </div>

            <div class="space-y-2.5">
                @foreach ($integrations as $row)
                    <div class="flex items-center justify-between border border-[#eef0f4] rounded-xl px-4 py-3.5">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg bg-[#f0f5f4] flex items-center justify-center">
                                <span class="material-symbols-outlined text-[#044b46] text-[18px]">hub</span>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-[#14181a]">{{ $row['platform'] }}</p>
                                <p class="text-xs text-[#9aa0a4]">
                                    @if ($row['connected'])
                                        Terhubung via {{ $row['integration_name'] }} &middot; {{ $row['updated_at']?->diffForHumans() }}
                                    @else
                                        Belum terhubung
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <span class="text-xs font-medium px-2.5 py-1 rounded-full
                                {{ $row['connected'] ? 'bg-[#f0f5f4] text-[#0f7a5f]' : 'bg-[#f2f3f6] text-[#9aa0a4]' }}">
                                {{ $row['connected'] ? 'Connected' : 'Not Connected' }}
                            </span>
                            <button type="button"
                                    class="text-xs font-medium px-3.5 py-1.5 rounded-lg transition-colors
                                        {{ $row['connected'] ? 'bg-[#f7f8fc] text-[#5c6266] hover:bg-[#eef0f4]' : 'bg-[#044b46] text-white hover:bg-[#033b37]' }}">
                                {{ $row['connected'] ? 'Disconnect' : 'Connect' }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Import Performance Data --}}
        <div class="card p-6">
            <div class="mb-5">
                <h2 class="font-display text-lg font-semibold text-[#14181a]">Import Performance Data</h2>
                <p class="text-sm text-[#5c6266] mt-1">Upload file CSV berisi metrik performa konten manual.</p>
            </div>

            @if (session('import_success'))
                <div class="mb-4 bg-[#f0f5f4] border border-[#dbe6e4] rounded-lg p-3.5">
                    <p class="text-sm font-medium text-[#044b46] flex items-center gap-2">
                        <span class="material-symbols-outlined text-[17px]">check_circle</span>
                        {{ session('import_success') }}
                    </p>
                    @if (! empty(session('import_skipped')))
                        <ul class="mt-2 ml-6 list-disc text-xs text-[#5c6266] space-y-0.5">
                            @foreach (session('import_skipped') as $skip)
                                <li>{{ $skip }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @if (session('import_error'))
                <div class="mb-4 bg-[#fdf2f1] border border-[#f5d9d7] rounded-lg p-3.5">
                    <p class="text-sm font-medium text-[#b3423e] flex items-center gap-2">
                        <span class="material-symbols-outlined text-[17px]">error</span>
                        {{ session('import_error') }}
                    </p>
                </div>
            @endif

            <form action="{{ route('settings.import-performance') }}" method="POST" enctype="multipart/form-data" x-data="{ fileName: null }">
                @csrf

                <div class="mb-4">
                    <label class="text-xs font-medium text-[#9aa0a4] uppercase mb-1.5 block">Client</label>
                    <select name="client_id" required
                            class="w-full text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2.5 bg-white focus:outline-none focus:border-[#044b46]/40">
                        <option value="">Pilih client tujuan data ini...</option>
                        @foreach ($clientOptions as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <label for="csv-file"
                       class="cursor-pointer border border-dashed border-[#dadfe0] rounded-xl p-7 flex flex-col items-center justify-center text-center bg-[#f7f8fc] hover:border-[#044b46]/40 transition-colors block">
                    <input id="csv-file" type="file" name="file" accept=".csv,.txt" required class="hidden"
                           x-on:change="fileName = $event.target.files[0]?.name">
                    <div class="w-10 h-10 rounded-lg bg-[#044b46] flex items-center justify-center mb-3">
                        <span class="material-symbols-outlined text-white text-[20px]">upload_file</span>
                    </div>
                    <p class="text-sm font-medium text-[#14181a] mb-1" x-text="fileName ?? 'Klik untuk pilih file'"></p>
                    <p class="text-xs text-[#9aa0a4]">Format .csv, maksimal 5MB</p>
                </label>

                <details class="mt-3">
                    <summary class="text-xs font-medium text-[#044b46] cursor-pointer">Lihat format kolom CSV</summary>
                    <div class="mt-2 bg-[#f7f8fc] rounded-lg p-3 text-xs text-[#5c6266] font-mono overflow-x-auto space-y-2">
                        <div>
                            <span class="text-[#9aa0a4] not-italic font-sans">Wajib (semua tipe konten):</span><br>
                            content_title,platform,metric_date,views,engagement_rate<br>
                            Post Promo Ramadan,Instagram,2026-07-01,1200,4.5
                        </div>
                        <div>
                            <span class="text-[#9aa0a4] not-italic font-sans">Opsional, khusus Reels/TikTok:</span><br>
                            watch_time_avg,completion_rate,shares,saves<br>
                            18,64.5,120,340
                        </div>
                    </div>
                    <p class="text-[11px] text-[#9aa0a4] mt-1.5">Kosongkan kolom video (jangan isi 0) kalau konten Feed/foto yang memang nggak punya metrik ini.</p>
                </details>

                <button type="submit" class="mt-4 w-full bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors">
                    Upload &amp; Import
                </button>
            </form>
        </div>

        {{-- Notifications --}}
        <div class="card p-6">
            <h2 class="font-display text-lg font-semibold text-[#14181a] mb-4">Notifications</h2>
            <div class="space-y-3.5">
                @foreach (['Konten mendekati deadline', 'Ada revisi baru yang perlu direspons', 'Sinkronisasi data performa selesai/gagal'] as $label)
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-[#5c6266]">{{ $label }}</span>
                        <button type="button" class="w-10 h-[22px] rounded-full bg-[#044b46] relative">
                            <span class="absolute top-0.5 right-0.5 w-[18px] h-[18px] rounded-full bg-white"></span>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Anomaly Detection --}}
        <div class="card p-6">
            <div class="flex items-center gap-2.5 mb-1">
                <span class="material-symbols-outlined text-[#3452a8] text-[18px]">auto_awesome</span>
                <h2 class="font-display text-lg font-semibold text-[#14181a]">Anomaly Detection</h2>
            </div>
            <p class="text-sm text-[#5c6266] mb-5">
                Otomatis bandingin performa konten hari ini vs rata-rata 30 hari terakhir, kirim notifikasi kalau ada lonjakan/penurunan signifikan.
                Berjalan otomatis tiap jam - ini buat trigger manual.
            </p>
            <form action="{{ route('settings.detect-anomalies') }}" method="POST">
                @csrf
                <button type="submit" class="bg-[#eef2fb] text-[#3452a8] text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[#e2e8f8] transition-colors flex items-center gap-2">
                    <span class="material-symbols-outlined text-[16px]">bolt</span> Jalankan Sekarang
                </button>
            </form>
        </div>

    </div>
</div>

@endsection