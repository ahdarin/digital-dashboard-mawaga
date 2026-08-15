@extends('layouts.app')
@section('title', 'Settings')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px]">

    <div class="mb-7">
        <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">Pengaturan</h1>
        <p class="text-[#5c6266] text-sm mt-1">Kelola akun, master data, dan koneksi data performa kontenmu.</p>
    </div>

    {{-- Tab switcher --}}
    <div class="flex items-center gap-1 bg-[#f2f3f6] rounded-lg p-1 mb-6 w-fit">
        <a href="{{ route('settings') }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $section === 'umum' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]' }}">
            Umum
        </a>
        <a href="{{ route('settings', ['tab' => 'data-pilihan']) }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $section === 'data-pilihan' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]' }}">
            Master Data
        </a>
        <a href="{{ route('settings', ['tab' => 'integrasi']) }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $section === 'integrasi' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]' }}">
            Integrasi
        </a>
    </div>

    @if ($section === 'data-pilihan')
        @include('master-data.partials.panel')
    @elseif ($section === 'integrasi')
        @include('settings.partials.integrations-panel')
    @else
    <div class="space-y-5">

        {{-- Account --}}
        <div class="card p-6">
            <div class="flex items-center justify-between mb-5">
                <h2 class="font-display text-lg font-semibold text-[#14181a]">Account</h2>
                <a href="{{ route('profile.show', auth()->id()) }}" class="text-sm font-medium text-[#044b46] hover:underline">Lihat Profile</a>
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

        {{-- System Connections - status koneksi service pihak ketiga yang
             dipakai sistem (Google Sign-In, WhatsApp/Fonnte, Gemini AI) --}}
        <div class="card p-6">
            <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1">System Connections</h2>
            <p class="text-xs text-[#9aa0a4] mb-5">Status koneksi service pihak ketiga yang dipakai sistem ini.</p>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach ($systemConnections as $conn)
                    <div class="border border-[#eef0f4] rounded-xl p-4">
                        <div class="flex items-center justify-between mb-3">
                            <div class="w-9 h-9 rounded-lg bg-[#f0f5f4] flex items-center justify-center">
                                <span class="material-symbols-outlined text-[#044b46] text-[18px]">{{ $conn['icon'] }}</span>
                            </div>
                            <span class="text-[10px] font-medium px-2 py-1 rounded-full
                                {{ $conn['connected'] ? 'bg-[#f0f5f4] text-[#0f7a5f]' : 'bg-[#fdf2f1] text-[#b3423e]' }}">
                                {{ $conn['connected'] ? 'Configured' : 'Not Configured' }}
                            </span>
                        </div>
                        <p class="text-sm font-medium text-[#14181a]">{{ $conn['label'] }}</p>
                        <p class="text-xs text-[#9aa0a4] mt-0.5">{{ $conn['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Import Performance + Analytics Integration (PRD 7.3.4) --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <a href="{{ route('settings.import') }}" class="card p-6 flex items-center justify-between hover:border-[#044b46]/30 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-[#f0f5f4] flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[#044b46] text-[19px]">upload_file</span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-[#14181a]">Import Data Performa</p>
                        <p class="text-xs text-[#9aa0a4]">Upload CSV metrik performa konten manual.</p>
                    </div>
                </div>
                <span class="material-symbols-outlined text-[#c3c7cb] text-[19px] shrink-0">chevron_right</span>
            </a>

            <a href="{{ route('settings', ['tab' => 'integrasi']) }}" class="card p-6 flex items-center justify-between hover:border-[#044b46]/30 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-[#f0f5f4] flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[#044b46] text-[19px]">hub</span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-[#14181a]">Analytics Integration &amp; Sync Log</p>
                        <p class="text-xs text-[#9aa0a4]">Status koneksi API per platform dan riwayat sinkronisasi.</p>
                    </div>
                </div>
                <span class="material-symbols-outlined text-[#c3c7cb] text-[19px] shrink-0">chevron_right</span>
            </a>
        </div>

        {{-- Notifications + Anomaly Detection --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 items-start">
            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-lg font-semibold text-[#14181a]">Notifications</h2>
                    <span class="text-[10px] font-medium text-[#9aa0a4] bg-[#f2f3f6] px-2 py-1 rounded-full">Semua aktif</span>
                </div>
                <p class="text-xs text-[#9aa0a4] mb-4 -mt-1">
                    Semua notifikasi di bawah ini otomatis aktif buat akunmu. Pengaturan on/off per jenis belum tersedia.
                </p>
                <div class="space-y-3.5">
                    @foreach (['Konten mendekati deadline', 'Ada revisi baru yang perlu direspons', 'Sinkronisasi data performa selesai/gagal'] as $label)
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-[#5c6266]">{{ $label }}</span>
                            <span title="Pengaturan on/off per jenis belum tersedia"
                                  class="inline-flex items-center gap-1 text-[11px] font-medium text-[#0f7a5f] bg-[#f0f5f4] px-2 py-1 rounded-full shrink-0">
                                <span class="material-symbols-outlined text-[13px]">check_circle</span> Aktif
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>

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
    @endif
</div>

@endsection
