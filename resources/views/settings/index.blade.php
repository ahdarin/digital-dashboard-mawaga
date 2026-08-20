@extends('layouts.app')
@section('title', 'Settings')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <div class="mb-7">
        <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Pengaturan</h1>
        <p class="text-[var(--text-secondary)] text-sm mt-1">Kelola akun, master data, dan koneksi data performa kontenmu.</p>
    </div>

    {{-- Tab switcher --}}
    <div class="flex items-center gap-1 bg-[var(--surface-muted)] rounded-lg p-1 mb-6 w-fit">
        <a href="{{ route('settings') }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $section === 'umum' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
            Umum
        </a>
        <a href="{{ route('settings', ['tab' => 'data-pilihan']) }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $section === 'data-pilihan' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
            Master Data
        </a>
        <a href="{{ route('settings', ['tab' => 'integrasi']) }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $section === 'integrasi' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
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
                <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Account</h2>
                <a href="{{ route('profile.show', auth()->id()) }}" class="text-sm font-medium text-[var(--brand)] hover:underline">Lihat Profile</a>
            </div>

            <div class="flex items-center gap-4">
                @if ($user->avatar_url)
                    <img src="{{ $user->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-12 h-12 rounded-full object-cover">
                @else
                    <div class="w-12 h-12 rounded-full bg-[var(--brand-solid)] text-white text-base font-semibold flex items-center justify-center">
                        {{ strtoupper(substr($user->name, 0, 1)) }}
                    </div>
                @endif
                <div>
                    <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $user->name }}</p>
                    <p class="text-sm text-[var(--text-secondary)]">{{ $user->email }}</p>
                    <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $user->roleNamesLabel() }}</p>
                </div>
            </div>
        </div>

        {{-- System Connections - status koneksi service pihak ketiga yang
             dipakai sistem (Google Sign-In, WhatsApp/Fonnte, Gemini AI) --}}
        <div class="card p-6">
            <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1">System Connections</h2>
            <p class="text-xs text-[var(--text-muted)] mb-5">Status koneksi service pihak ketiga yang dipakai sistem ini.</p>

            <div class="grid grid-cols-3 gap-2.5 sm:gap-4">
                @foreach ($systemConnections as $conn)
                    <div class="border border-[var(--border)] rounded-xl p-2.5 sm:p-4">
                        <div class="flex items-center justify-between mb-2 sm:mb-3">
                            <div class="w-7 h-7 sm:w-9 sm:h-9 rounded-lg bg-[var(--brand-tint)] flex items-center justify-center">
                                <span class="material-symbols-outlined text-[var(--brand)] text-[14px] sm:text-[18px]">{{ $conn['icon'] }}</span>
                            </div>
                            <span class="badge {{ $conn['connected'] ? 'badge-success' : 'badge-danger' }}">
                                <span class="sm:hidden">{{ $conn['connected'] ? 'OK' : 'Off' }}</span>
                                <span class="hidden sm:inline">{{ $conn['connected'] ? 'Configured' : 'Not Configured' }}</span>
                            </span>
                        </div>
                        <p class="text-[11px] sm:text-sm font-medium text-[var(--text-primary)] truncate">{{ $conn['label'] }}</p>
                        <p class="text-[10px] sm:text-xs text-[var(--text-muted)] mt-0.5 hidden sm:block">{{ $conn['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Import Performance + Analytics Integration (PRD 7.3.4) --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <a href="{{ route('settings.import') }}" class="card p-6 flex items-center justify-between hover:border-[#044b46]/30 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-[var(--brand-tint)] flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[var(--brand)] text-[19px]">upload_file</span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-[var(--text-primary)]">Import Data Performa</p>
                        <p class="text-xs text-[var(--text-muted)]">Upload CSV metrik performa konten manual.</p>
                    </div>
                </div>
                <span class="material-symbols-outlined text-[var(--text-muted)] text-[19px] shrink-0">chevron_right</span>
            </a>

            <a href="{{ route('settings', ['tab' => 'integrasi']) }}" class="card p-6 flex items-center justify-between hover:border-[#044b46]/30 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-[var(--brand-tint)] flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-[var(--brand)] text-[19px]">hub</span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-[var(--text-primary)]">Analytics Integration &amp; Sync Log</p>
                        <p class="text-xs text-[var(--text-muted)]">Status koneksi API per platform dan riwayat sinkronisasi.</p>
                    </div>
                </div>
                <span class="material-symbols-outlined text-[var(--text-muted)] text-[19px] shrink-0">chevron_right</span>
            </a>
        </div>

        {{-- Anomaly Detection --}}
        <div class="card p-6">
            <div class="flex items-center gap-2.5 mb-1">
                <span class="material-symbols-outlined text-[var(--info-text)] text-[18px]">auto_awesome</span>
                <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Anomaly Detection</h2>
            </div>
            <p class="text-sm text-[var(--text-secondary)] mb-5">
                Otomatis bandingin performa konten hari ini vs rata-rata 30 hari terakhir, kirim notifikasi kalau ada lonjakan/penurunan signifikan.
                Berjalan otomatis tiap jam - ini buat trigger manual.
            </p>
            <form action="{{ route('settings.detect-anomalies') }}" method="POST">
                @csrf
                <button type="submit" class="bg-[var(--info-tint)] text-[var(--info-text)] text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[var(--info-tint-soft)] transition-colors flex items-center gap-2">
                    <span class="material-symbols-outlined text-[16px]">bolt</span> Jalankan Sekarang
                </button>
            </form>
        </div>

    </div>
    @endif
</div>

@endsection
